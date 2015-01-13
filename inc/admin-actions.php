<?php

/**
 * Get builder content based on the submitted panels_data.
 */
function siteorigin_panels_ajax_builder_content(){
	header('content-type: text/html');

	if( !current_user_can('edit_post', $_POST['post_id'] ) ) exit();

	if( empty( $_POST['post_id'] ) || empty( $_POST['panels_data'] ) ) {
		echo '';
		exit();
	}

	// echo the content
	echo siteorigin_panels_render( intval($_POST['post_id']), false, json_decode( wp_unslash($_POST['panels_data']), true ) );

	exit();
}
add_action('wp_ajax_so_panels_builder_content', 'siteorigin_panels_ajax_builder_content');

/**
 * Display a widget form with the provided data
 */
function siteorigin_panels_ajax_widget_form(){
	if( empty( $_REQUEST['widget'] ) ) exit();
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) exit();

	$request = array_map('stripslashes_deep', $_REQUEST);

	$widget = $request['widget'];
	$instance = !empty($request['instance']) ? json_decode( $request['instance'], true ) : array();

	$form = siteorigin_panels_render_form( $widget, $instance, $_REQUEST['raw'] == 'true' );
	$form = apply_filters('siteorigin_panels_ajax_widget_form', $form, $widget, $instance);

	echo $form;
	exit();
}
add_action('wp_ajax_so_panels_widget_form', 'siteorigin_panels_ajax_widget_form');

/**
 * Admin action for loading a list of prebuilt layouts based on the given type
 */
function siteorigin_panels_ajax_prebuilt_layouts(){
	if( empty($_REQUEST['type']) ) exit();
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) exit();

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
	elseif( strpos($_REQUEST['type'], 'clone_') === 0 ) {
		// Check that the user can view the given page types
		$post_type = str_replace('clone_', '', $_REQUEST['type']);
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

	exit();
}
add_action('wp_ajax_so_panels_prebuilt_layouts', 'siteorigin_panels_ajax_prebuilt_layouts');

/**
 * Ajax handler to get an individual prebuilt layout
 */
function siteorigin_panels_ajax_get_prebuilt_layout(){
	if( empty( $_REQUEST['type'] ) ) exit();
	if( empty( $_REQUEST['lid'] ) ) exit();
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) exit();

	header('content-type: application/json');

	if( $_REQUEST['type'] == 'prebuilt' ) {
		$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
		if( empty( $layouts[$_REQUEST['lid']] ) ) {
			// Display an error message
			exit();
		}

		$layout = $layouts[$_REQUEST['lid']];
		if( isset($layout['name']) ) unset($layout['name']);

		$layout = apply_filters('siteorigin_panels_prebuilt_layout', $layout);

		echo json_encode( $layout );
		exit();
	}
	elseif( current_user_can('edit_post', $_REQUEST['lid']) ) {
		$panels_data = get_post_meta( $_REQUEST['lid'], 'panels_data', true );
		$panels_data = apply_filters('siteorigin_panels_data', $panels_data);
		echo json_encode( $panels_data );
		exit();
	}
}
add_action('wp_ajax_so_panels_get_prebuilt_layout', 'siteorigin_panels_ajax_get_prebuilt_layout');

/**
 * Admin ajax handler for loading a single prebuilt layout.
 *
 * @TODO check if this is still being used
 */
function siteorigin_panels_ajax_action_prebuilt(){
	if( empty( $_REQUEST['_panelsnonce'] ) || !wp_verify_nonce($_REQUEST['_panelsnonce'], 'panels_action') ) exit();

	// Get any layouts that the current user could edit.
	$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

	if( empty($_GET['layout']) ) exit();
	if( empty($layouts[$_GET['layout']]) ) exit();

	header('content-type: application/json');

	$layout = !empty( $layouts[$_GET['layout']] ) ? $layouts[$_GET['layout']] : array();
	$layout = apply_filters('siteorigin_panels_prebuilt_layout', $layout);

	echo json_encode( $layout );
	exit();
}
add_action('wp_ajax_so_panels_prebuilt_layout', 'siteorigin_panels_ajax_action_prebuilt');