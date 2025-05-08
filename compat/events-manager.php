<?php
if ( ! function_exists( 'em_content' ) ) {
	return;
}

if ( ! apply_filters( 'siteorigin_panels_compat_events_manager', true ) ) {
	return;
}

$em_pb_removed = false;

/**
 * Disable Page Builder for Events Manager post types.
 *
 * This function checks if the current post is an Events Manager post type
 * and if Page Builder is enabled for it. If both conditions are met, it
 * disables Page Builder for the content. This is done to prevent Page Builder
 * from interfering with the Events Manager content, and vice versa.
 *
 * `loop_start` is used due to when the Events Manager plugin sets up its
 * content replacement.
 *
 * @return void
 */
function siteorigin_panels_event_manager_loop_start() {
	$em_post_types = array( 'event-recurring', 'event' );

	// Is the current post an $em_post_types post?
	$post_type = get_post_type();
	if ( ! in_array( $post_type, $em_post_types ) ) {
		return;
	}

	// Is Page Builder enabled for Events Manager post types?
	$pb_post_types = siteorigin_panels_setting( 'post-types' );
	if ( empty( $pb_post_types ) || ! array_intersect( $em_post_types, $pb_post_types ) ) {
		return;
	}

	global $em_pb_removed;
	$em_pb_removed = true;

	add_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
}
add_action( 'loop_start', 'siteorigin_panels_event_manager_loop_start' );

/**
 * Re-enable Page Builder for `the_content` filter if it
 * was disabled at the start of the loop.
 */
function siteorigin_panels_event_manager_loop_end() {
	global $em_pb_removed;

	if ( $em_pb_removed ) {
		remove_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
	}
}
add_action( 'loop_end', 'siteorigin_panels_event_manager_loop_end' );
