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


function siteorigin_panels_ajax_get_prebuilt_layouts(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) wp_die();

	// Get any layouts that the current user could edit.
	header('content-type: application/json');

	$type = !empty( $_REQUEST['type'] ) ? $_REQUEST['type'] : 'directory';
	$search = !empty($_REQUEST['search']) ? trim( strtolower( $_REQUEST['search'] ) ) : '';
	$page = !empty( $_REQUEST['page'] ) ? intval( $_REQUEST['page'] ) : 1;

	$return = array(
		'title' => '',
		'items' => array()
	);
	if( $type == 'prebuilt' ) {
		$return['title'] = __( 'Theme Defined Layouts', 'siteorigin-panels' );

		// This is for theme bundled prebuilt directories
		$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

		foreach($layouts as $id => $vals) {
			if( !empty($search) && strpos( strtolower($vals['name']), $search ) === false ) {
				continue;
			}

			$return['items'][] = array(
				'title' => $vals['name'],
				'id' => $id,
				'type' => 'prebuilt',
				'description' => isset($vals['description']) ? $vals['description'] : '',
				'screenshot' => !empty($vals['screenshot']) ? $vals['screenshot'] : ''
			);
		}

		$return['max_num_pages'] = 1;
	}
	elseif( $type == 'directory' ) {
		$return['title'] = __( 'Layouts Directory', 'siteorigin-panels' );

		// This is a query of the prebuilt layout directory
		$query = array();
		if( !empty($search) ) $query['search'] = $search;
		$query['page'] = $page;

		$url = add_query_arg( $query, SITEORIGIN_PANELS_LAYOUT_URL . '/wp-admin/admin-ajax.php?action=query_layouts');
		$response = wp_remote_get( $url );

		if( is_array($response) && $response['response']['code'] == 200 ) {
			$results = json_decode( $response['body'], true );
			if ( !empty( $results ) && !empty($results['items'])  ) {
				foreach( $results['items'] as $item ) {
					$item['id'] = $item['slug'];
					$item['screenshot'] = 'http://s.wordpress.com/mshots/v1/' . urlencode( $item['preview'] ) . '?w=400';
					$item['type'] = 'directory';
					$return['items'][] = $item;
				}
			}

			$return['max_num_pages'] = $results['max_num_pages'];
		}
	}
	elseif ( strpos( $type, 'clone_' ) !== false ) {
		// Check that the user can view the given page types
		$post_type = str_replace('clone_', '', $type );

		$return['title'] = sprintf( __( 'Clone %s', 'siteorigin-panels' ), esc_html( ucfirst( $post_type ) ) );

		global $wpdb;
		$user_can_read_private = ( $post_type == 'post' && current_user_can( 'read_private_posts' ) || ( $post_type == 'page' && current_user_can( 'read_private_pages' ) ));
		$include_private = $user_can_read_private ? "OR posts.post_status = 'private' " : "";

		// Select only the posts with the given post type that also have panels_data
		$results = $wpdb->get_results( "
			SELECT SQL_CALC_FOUND_ROWS DISTINCT ID, post_title, meta.meta_value
			FROM {$wpdb->posts} AS posts
			JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
			WHERE
				posts.post_type = '" . esc_sql( $post_type ) . "'
				AND meta.meta_key = 'panels_data'
				" . ( !empty($search) ? 'AND posts.post_title LIKE "%' . esc_sql( $search ) . '%"' : '' ) . "
				AND ( posts.post_status = 'publish' OR posts.post_status = 'draft' " . $include_private . ")
			ORDER BY post_date DESC
			LIMIT 16 OFFSET " . intval( ( $page - 1 ) * 16 ) );
		$total_posts = $wpdb->get_var( "SELECT FOUND_ROWS();" );

		foreach( $results as $result ) {
			$thumbnail = get_the_post_thumbnail_url( $result->ID, array( 400,300 ) );
			$return['items'][] = array(
				'id' => $result->ID,
				'title' => $result->post_title,
				'type' => $type,
				'screenshot' => !empty($thumbnail) ? $thumbnail : ''
			);
		}

		$return['max_num_pages'] = ceil( $total_posts / 16 );

	}
	else {
		// An invalid type. Display an error message.
	}

	// Add the search part to the title
	if( !empty($search) ) {
		$return['title'] .= __(' - Results For:', 'siteorigin-panels') . ' <em>' . esc_html( $search ) . '</em>';
	}

	echo json_encode( $return );

	wp_die();
}
add_action('wp_ajax_so_panels_layouts_query', 'siteorigin_panels_ajax_get_prebuilt_layouts');

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
		$layout = apply_filters('siteorigin_panels_data', $layout);

		echo json_encode( $layout );
		wp_die();
	}
	if( $_REQUEST['type'] == 'directory' ) {
		$response = wp_remote_get(
			SITEORIGIN_PANELS_LAYOUT_URL . '/layout/' . urlencode($_REQUEST['lid']) . '/?action=download'
		);

		// var_dump($response['body']);
		if( $response['response']['code'] == 200 ) {
			// For now, we'll just pretend to load this
			echo $response['body'];
			wp_die();
		}
		else {
			// Display some sort of error message
		}
	}
	elseif( current_user_can('edit_post', $_REQUEST['lid']) ) {
		$panels_data = get_post_meta( $_REQUEST['lid'], 'panels_data', true );
		$panels_data = apply_filters('siteorigin_panels_data', $panels_data);
		echo json_encode( $panels_data );
		wp_die();
	}
}
add_action('wp_ajax_so_panels_get_layout', 'siteorigin_panels_ajax_get_prebuilt_layout');

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
