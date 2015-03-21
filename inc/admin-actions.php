<?php

/**
 * Get builder content based on the submitted panels_data.
 */
function siteorigin_panels_ajax_builder_content(){
	header('content-type: text/html');

	$request = filter_input_array( INPUT_POST, array(
		'post_id' => FILTER_VALIDATE_INT,
		'panels_data' => FILTER_DEFAULT
	) );

	if( !current_user_can('edit_post', $request['post_id'] ) ) wp_die();

	if( empty( $request['post_id'] ) || empty( $request['panels_data'] ) ) {
		echo '';
		wp_die();
	}

	// echo the content
	echo siteorigin_panels_render( $request['post_id'], false, json_decode( $request['panels_data'], true ) );

	wp_die();
}
add_action('wp_ajax_so_panels_builder_content', 'siteorigin_panels_ajax_builder_content');

/**
 * Display a widget form with the provided data
 */
function siteorigin_panels_ajax_widget_form(){
	// Verify the nonce
	$nonce = filter_input( INPUT_GET, '_panelsnonce', FILTER_DEFAULT );
	if( !wp_verify_nonce($nonce, 'panels_action') ) wp_die();

	$request = filter_input_array( INPUT_POST, array(
		'widget' => FILTER_SANITIZE_STRING,
		'raw' => FILTER_VALIDATE_BOOLEAN,
		'instance' => FILTER_DEFAULT,
		'_panelsnonce' => FILTER_DEFAULT
	) );

	if( empty( $request['widget'] ) ) wp_die();

	$widget = $request['widget'];
	$instance = !empty($request['instance']) ? json_decode( $request['instance'], true ) : array();

	$form = siteorigin_panels_render_form( $widget, $instance, $request['raw'] );
	$form = apply_filters('siteorigin_panels_ajax_widget_form', $form, $widget, $instance);

	echo $form;
	wp_die();
}
add_action('wp_ajax_so_panels_widget_form', 'siteorigin_panels_ajax_widget_form');

/**
 * Admin action for loading a list of prebuilt layouts based on the given type
 */
function siteorigin_panels_ajax_prebuilt_layouts(){
	// Verify the nonce
	$nonce = filter_input( INPUT_GET, '_panelsnonce', FILTER_DEFAULT );
	if( !wp_verify_nonce($nonce, 'panels_action') ) wp_die();

	// The type of prebuilt layouts we want
	$type = filter_input( INPUT_GET, 'type', FILTER_SANITIZE_STRING );
	if( empty($type) ) wp_die();

	// Get any layouts that the current user could edit.
	header('content-type: application/json');

	$return = array();

	if( $type == 'prebuilt' ) {
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
	elseif( strpos( $type, 'clone_' ) === 0 ) {
		// Check that the user can view the given page types
		$post_type = str_replace('clone_', '', $type );
		global $wpdb;

		// Select only the posts with the given post type that also have panels_data
		$results = $wpdb->get_results( $wpdb->prepare("
			SELECT ID, post_title, meta.meta_value
			FROM {$wpdb->posts} AS posts
			JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
			WHERE
				posts.post_type = %s
				AND meta.meta_key = 'panels_data'
				AND ( posts.post_status = 'publish' OR posts.post_status = 'draft' )
			ORDER BY post_title
			LIMIT 200
		", $post_type) );

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
	// Verify the nonce
	$nonce = filter_input( INPUT_GET, '_panelsnonce', FILTER_DEFAULT );
	if( !wp_verify_nonce($nonce, 'panels_action') ) wp_die();

	$request = filter_input_array( INPUT_POST, array(
		'type' => FILTER_SANITIZE_STRING,
		'lid' => FILTER_SANITIZE_STRING,
	) );


	if( empty( $request['type'] ) ) wp_die();
	if( empty( $request['lid'] ) ) wp_die();

	header('content-type: application/json');

	if( $request['type'] == 'prebuilt' ) {
		$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
		if( empty( $layouts[ $request['lid'] ] ) ) {
			// Display an error message
			wp_die();
		}

		$layout = $layouts[ $request['lid'] ];
		if( isset($layout['name']) ) unset($layout['name']);

		$layout = apply_filters('siteorigin_panels_prebuilt_layout', $layout);

		echo json_encode( $layout );
		wp_die();
	}
	elseif( current_user_can('edit_post', $request['lid']) ) {
		$panels_data = get_post_meta( $request['lid'], 'panels_data', true );
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
	$nonce = filter_input( INPUT_GET, '_panelsnonce', FILTER_DEFAULT );
	if( !wp_verify_nonce($nonce, 'panels_action') ) wp_die();

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
	$nonce = filter_input( INPUT_POST, '_panelsnonce', FILTER_DEFAULT );
	if( !wp_verify_nonce($nonce, 'panels_action') ) wp_die();

	header('content-type: application/json');
	header('Content-Disposition: attachment; filename=layout-' . date('dmY') . '.json');

	$export_data = filter_input( INPUT_POST, 'panels_export_data' );
	echo $export_data;

	wp_die();
}
add_action('wp_ajax_so_panels_export_layout', 'siteorigin_panels_ajax_export_layout');
