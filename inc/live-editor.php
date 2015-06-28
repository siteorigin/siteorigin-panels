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
	if( $meta_key == 'panels_data' && !empty( $_GET['siteorigin_panels_live_editor'] ) && current_user_can( 'edit_post', $post_id ) ) {
		$data = json_decode( wp_unslash( $_POST['siteorigin_panels_data'] ), true );
		return array($data);
	}
}
add_action('get_post_metadata', 'siteorigin_panels_live_editor', 10, 3);

/**
 * Hide the admin bar for the live editor
 *
 * @return bool
 */
function siteorigin_panels_live_editor_admin_bar() {
	return empty( $_GET['siteorigin_panels_live_editor'] );
}
add_filter('show_admin_bar', 'siteorigin_panels_live_editor_admin_bar');