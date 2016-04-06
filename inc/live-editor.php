<?php

/**
 * Edit the page builder data when we're viewing the live editor version
 *
 * @param $value
 * @param $post_id
 * @param $meta_key
 *
 * @return array
 */
function siteorigin_panels_live_editor($value, $post_id, $meta_key){
	if( $meta_key == 'panels_data' && current_user_can( 'edit_post', $post_id ) && !empty( $_POST['live_editor_panels_data'] ) ) {
		$data = json_decode( wp_unslash( $_POST['live_editor_panels_data'] ), true );

		if(
			!empty( $data['widgets'] ) && (
				!class_exists( 'SiteOrigin_Widget_Field_Class_Loader' ) ||
				method_exists( 'SiteOrigin_Widget_Field_Class_Loader', 'extend' )
			)
		) {
			$data['widgets'] = siteorigin_panels_process_raw_widgets( $data['widgets'] );
		}

		$value = array( $data );
	}

	return $value;
}
add_action('get_post_metadata', 'siteorigin_panels_live_editor', 10, 3);

// Don't display the admin bar when in live editor mode
add_filter('show_admin_bar', '__return_false');

/**
 * Load the frontend scripts for the live editor
 */
function siteorigin_panels_live_editor_frontend_scripts(){
	wp_enqueue_script(
		'live-editor-front',
		plugin_dir_url(SITEORIGIN_PANELS_BASE_FILE) . '/js/live-editor/live-editor-front' . SITEORIGIN_PANELS_JS_SUFFIX . '.js',
		array( 'jquery' ),
		SITEORIGIN_PANELS_VERSION
	);

	wp_enqueue_script(
		'live-editor-scrollto',
		plugin_dir_url(SITEORIGIN_PANELS_BASE_FILE) . '/js/live-editor/jquery.scrollTo' . SITEORIGIN_PANELS_JS_SUFFIX . '.js',
		array( 'jquery' ),
		SITEORIGIN_PANELS_VERSION
	);

	wp_enqueue_style(
		'live-editor-front',
		plugin_dir_url(SITEORIGIN_PANELS_BASE_FILE) . '/css/live-editor-front.css',
		array(),
		SITEORIGIN_PANELS_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'siteorigin_panels_live_editor_frontend_scripts' );
