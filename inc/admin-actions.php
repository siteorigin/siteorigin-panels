<?php

define('SITEORIGIN_PANELS_LAYOUT_URL', 'http://layouts.siteorigin.com/');

/**
 * Get builder content based on the submitted panels_data.
 */
function siteorigin_panels_ajax_builder_content(){
	header('content-type: text/html');

	if( !current_user_can('edit_post', $_POST['post_id'] ) ) wp_die();

	if( empty( $_POST['post_id'] ) || empty( $_POST['panels_data'] ) ) {
		echo '';
		wp_die();
	}

	// echo the content
	$panels_data = json_decode( wp_unslash( $_POST['panels_data'] ), true);
	$panels_data['widgets'] = siteorigin_panels_process_raw_widgets($panels_data['widgets']);
	$panels_data = siteorigin_panels_styles_sanitize_all( $panels_data );
	echo siteorigin_panels_render( intval($_POST['post_id']), false, $panels_data );

	wp_die();
}
add_action('wp_ajax_so_panels_builder_content', 'siteorigin_panels_ajax_builder_content');

/**
 * Display a widget form with the provided data
 */
function siteorigin_panels_ajax_widget_form(){
	if( empty( $_REQUEST['widget'] ) ) wp_die();
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	$request = array_map('stripslashes_deep', $_REQUEST);

	$widget = $request['widget'];

	$instance = !empty($request['instance']) ? json_decode( $request['instance'] , true ) : array();

	$form = siteorigin_panels_render_form( $widget, $instance, $_REQUEST['raw'] == 'true' );
	$form = apply_filters('siteorigin_panels_ajax_widget_form', $form, $widget, $instance);

	echo $form;
	wp_die();
}
add_action('wp_ajax_so_panels_widget_form', 'siteorigin_panels_ajax_widget_form');

/**
 * Admin action for loading a list of prebuilt layouts based on the given type
 */
function siteorigin_panels_ajax_prebuilt_layouts(){
	if( empty($_REQUEST['type']) ) wp_die();
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	// Get any layouts that the current user could edit.
	header('content-type: application/json');

	$return = array();

	if( $_REQUEST['type'] == 'prebuilt' ) {
		// Display the prebuilt layouts that come with the theme.
		$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

		foreach($layouts as $id => $vals) {
			$return[$id] = array(
				'name' => $vals['name'],
				'description' => isset($vals['description']) ? $vals['description'] : __('No description', 'siteorigin-panels')
			);
		}

		if( !empty($return) ) {
			echo json_encode( $return );
		}
		else {
			$message = '';
			$message .= __("Your theme doesn't have any prebuilt layouts.", 'siteorigin-panels') . ' ';
			$message .= __("You can still clone existing pages though.", 'siteorigin-panels') . ' ';
			echo json_encode( array(
				'error_message' => $message,
			) );
		}


	}
	elseif( strpos( $_REQUEST['type'], 'clone_' ) === 0 ) {
		// Check that the user can view the given page types
		$post_type = str_replace('clone_', '', $_REQUEST['type'] );
		global $wpdb;

		$user_can_read_private = ( $post_type == 'post' && current_user_can( 'read_private_posts' ) || ( $post_type == 'page' && current_user_can( 'read_private_pages' ) ));
		$include_private = $user_can_read_private ? "OR posts.post_status = 'private' " : "";
		// Select only the posts with the given post type that also have panels_data
		$results = $wpdb->get_results( $wpdb->prepare("
			SELECT ID, post_title, meta.meta_value
			FROM {$wpdb->posts} AS posts
			JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
			WHERE
				posts.post_type = %s
				AND meta.meta_key = 'panels_data'
				AND ( posts.post_status = 'publish' OR posts.post_status = 'draft' " . $include_private . ")
			ORDER BY post_title
			LIMIT 200
		", $post_type ) );

		foreach( $results as $result ) {
			$meta_value = unserialize( $result->meta_value );
			if( empty($meta_value['widgets']) ) continue;

			// Create the return array
			$return[$result->ID] = array(
				'name' => $result->post_title,
				'description' => __('Clone', 'siteorigin-panels')
			);
		}

		if( !empty($return) ) {
			echo json_encode( $return );
		}
		else {
			$type_object = get_post_type_object( $post_type );
			if( empty($type_object->labels->name) ) {
				$type_name = ucfirst( $post_type );
			}
			else {
				$type_name = $type_object->labels->name;
			}

			$message = '';
			// TRANSLATORS: Indicate if there are items to clone. %s will be pages, posts, etc.
			$message .= sprintf( __("There are no %s with Page Builder content to clone.", 'siteorigin-panels') , $type_name );
			echo json_encode( array(
				'error_message' => $message,
			) );
		}

	}
	else {
		// Send back an error
	}

	wp_die();
}
add_action('wp_ajax_so_panels_prebuilt_layouts', 'siteorigin_panels_ajax_prebuilt_layouts');

/**
 * Ajax handler to get an individual prebuilt layout
 */
function siteorigin_panels_ajax_get_prebuilt_layout(){
	if( empty( $_REQUEST['type'] ) ) wp_die();
	if( empty( $_REQUEST['lid'] ) ) wp_die();
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	header('content-type: application/json');

	if( $_REQUEST['type'] == 'prebuilt' ) {
		$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
		if( empty( $layouts[ $_REQUEST['lid'] ] ) ) {
			// Display an error message
			wp_die();
		}

		$layout = $layouts[ $_REQUEST['lid'] ];
		if( isset($layout['name']) ) unset($layout['name']);

		$layout = apply_filters('siteorigin_panels_prebuilt_layout', $layout);

		echo json_encode( $layout );
		wp_die();
	}
	elseif( current_user_can('edit_post', $_REQUEST['lid']) ) {
		$panels_data = get_post_meta( $_REQUEST['lid'], 'panels_data', true );
		$panels_data = apply_filters('siteorigin_panels_data', $panels_data);
		echo json_encode( $panels_data );
		wp_die();
	}
}
add_action('wp_ajax_so_panels_get_prebuilt_layout', 'siteorigin_panels_ajax_get_prebuilt_layout');

/**
 * Ajax handler to import a layout
 */
function siteorigin_panels_ajax_import_layout(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	if( !empty($_FILES['panels_import_data']['tmp_name']) ) {
		header('content-type:application/json');
		$json = file_get_contents( $_FILES['panels_import_data']['tmp_name'] );
		@unlink( $_FILES['panels_import_data']['tmp_name'] );
		echo $json;
	}
	wp_die();
}
add_action('wp_ajax_so_panels_import_layout', 'siteorigin_panels_ajax_import_layout');

/**
 * Ajax handler to export a layout
 */
function siteorigin_panels_ajax_export_layout(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	header('content-type: application/json');
	header('Content-Disposition: attachment; filename=layout-' . date('dmY') . '.json');

	$export_data = wp_unslash( $_POST['panels_export_data'] );
	echo $export_data;

	wp_die();
}
add_action('wp_ajax_so_panels_export_layout', 'siteorigin_panels_ajax_export_layout');

/**
 * We want users to be informed of what the layout directory is, so they need to enable it.
 */
function siteorigin_panels_ajax_directory_enable(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	$user = get_current_user_id();
	update_user_meta( $user, 'so_panels_directory_enabled', true );

	wp_die();
}
add_action('wp_ajax_so_panels_directory_enable', 'siteorigin_panels_ajax_directory_enable');

/**
 * Query the layout directory for a list of layouts
 */
function siteorigin_panels_ajax_directory_query(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	$query = array();
	if( !empty($_GET['search']) ) {
		$query['search'] = urlencode( $_GET['search'] );
	}
	if( !empty($_GET['page']) ) {
		$query['page'] = intval( $_GET['page'] );
	}

	// Lets start by contacting the remote server
	$url = add_query_arg( $query, SITEORIGIN_PANELS_LAYOUT_URL . '/wp-admin/admin-ajax.php?action=query_layouts');
	$response = wp_remote_get( $url );

	if( $response['response']['code'] == 200 ) {
		$results = json_decode( $response['body'] );
		if ( empty( $results ) ) {
			$results = array();
		}

		// For now, we'll just create a pretend list of items
		header( 'content-type: application/json' );
		echo json_encode( $results );
		wp_die();
	}
	else {
		// Display some sort of error message
	}
}
add_action('wp_ajax_so_panels_directory_query', 'siteorigin_panels_ajax_directory_query');

/**
 * Query the layout directory for a specific item
 */
function siteorigin_panels_ajax_directory_item_json(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();
	if( empty( $_REQUEST['layout_slug'] ) ) wp_die();

	$response = wp_remote_get(
		SITEORIGIN_PANELS_LAYOUT_URL . '/layout/' . urlencode($_REQUEST['layout_slug']) . '/?action=download'
	);

	// var_dump($response['body']);
	if( $response['response']['code'] == 200 ) {
		// For now, we'll just pretend to load this
		header('content-type: application/json');
		echo $response['body'];
		wp_die();
	}
	else {
		// Display some sort of error message
	}

}
add_action('wp_ajax_so_panels_directory_item', 'siteorigin_panels_ajax_directory_item_json');