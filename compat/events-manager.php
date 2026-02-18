<?php
if ( ! function_exists( 'em_content' ) ) {
	return;
}

if ( ! apply_filters( 'siteorigin_panels_compat_events_manager', true ) ) {
	return;
}

$em_pb_removed = false;
$siteorigin_panels_em_is_duplicating = false;

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

/**
 * Flag Events Manager duplication so we can avoid unsafe SQL inserts for panels_data.
 *
 * Events Manager duplicates post meta with a raw SQL insert statement. If Page Builder
 * content contains quotes, the insert can fail. We skip panels_data in that query and
 * copy it safely after duplication completes.
 *
 * @return void
 */
function siteorigin_panels_event_manager_duplicate_pre() {
	global $siteorigin_panels_em_is_duplicating;
	$siteorigin_panels_em_is_duplicating = true;
}
add_action( 'em_event_duplicate_pre', 'siteorigin_panels_event_manager_duplicate_pre' );

/**
 * Remove Page Builder data from Events Manager's raw SQL duplication payload.
 *
 * @param array $event_meta Event post meta.
 *
 * @return array
 */
function siteorigin_panels_event_manager_filter_duplicate_meta( $event_meta ) {
	global $siteorigin_panels_em_is_duplicating;

	if ( ! $siteorigin_panels_em_is_duplicating || ! is_array( $event_meta ) ) {
		return $event_meta;
	}

	unset( $event_meta['panels_data'] );

	return $event_meta;
}
add_filter( 'em_event_get_event_meta', 'siteorigin_panels_event_manager_filter_duplicate_meta' );

/**
 * Copy Page Builder data to the duplicated event using safe WordPress APIs.
 *
 * @param mixed $duplicated_event The duplicated event object, or false on failure.
 * @param mixed $source_event     The original source event object.
 *
 * @return mixed
 */
function siteorigin_panels_event_manager_duplicate_copy_panels_data( $duplicated_event, $source_event ) {
	global $siteorigin_panels_em_is_duplicating;
	$siteorigin_panels_em_is_duplicating = false;

	if (
		empty( $duplicated_event ) ||
		! is_object( $duplicated_event ) ||
		! is_object( $source_event ) ||
		empty( $duplicated_event->post_id ) ||
		empty( $source_event->post_id )
	) {
		return $duplicated_event;
	}

	$source_panels_data = get_post_meta( (int) $source_event->post_id, 'panels_data', true );

	if ( empty( $source_panels_data ) ) {
		delete_post_meta( (int) $duplicated_event->post_id, 'panels_data' );
		return $duplicated_event;
	}

	if ( is_callable( array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) ) ) {
		$source_panels_data = map_deep( $source_panels_data, array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) );
	}

	update_post_meta( (int) $duplicated_event->post_id, 'panels_data', $source_panels_data );

	return $duplicated_event;
}
add_filter( 'em_event_duplicate', 'siteorigin_panels_event_manager_duplicate_copy_panels_data', 10, 2 );
