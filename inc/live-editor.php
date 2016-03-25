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
		$value = array( $data );
	}

	return $value;
}
add_action('get_post_metadata', 'siteorigin_panels_live_editor', 10, 3);

// Don't display the admin bar when in live editor mode
add_filter('show_admin_bar', '__return_false');
