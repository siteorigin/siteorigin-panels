<?php
if ( ! function_exists( 'em_content' ) ) {
	return;
}

if ( ! apply_filters( 'siteorigin_panels_compat_events_manager', true ) ) {
	return;
}

class SiteOrigin_Panels_Compat_Events_Manager {
	private $is_pb_removed = false;
	private $is_duplicating = false;

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function __construct() {
		add_action( 'loop_start', array( $this, 'loop_start' ) );
		add_action( 'loop_end', array( $this, 'loop_end' ) );

		add_action( 'em_event_duplicate_pre', array( $this, 'duplicate_pre' ) );
		add_filter( 'em_event_get_event_meta', array( $this, 'filter_duplicate_meta' ) );
		add_filter( 'em_event_duplicate', array( $this, 'duplicate_copy_panels_data' ), 10, 2 );
	}

	/**
	 * Disable Page Builder for Events Manager post types.
	 *
	 * @return void
	 */
	public function loop_start() {
		$em_post_types = array( 'event-recurring', 'event' );

		$post_type = get_post_type();
		if ( ! in_array( $post_type, $em_post_types ) ) {
			return;
		}

		$pb_post_types = siteorigin_panels_setting( 'post-types' );
		if ( empty( $pb_post_types ) || ! array_intersect( $em_post_types, $pb_post_types ) ) {
			return;
		}

		$this->is_pb_removed = true;
		add_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
	}

	/**
	 * Re-enable Page Builder for `the_content` filter if it
	 * was disabled at the start of the loop.
	 *
	 * @return void
	 */
	public function loop_end() {
		if ( $this->is_pb_removed ) {
			remove_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
			$this->is_pb_removed = false;
		}
	}

	/**
	 * Flag Events Manager duplication so we can avoid unsafe SQL inserts for panels_data.
	 *
	 * @return void
	 */
	public function duplicate_pre() {
		$this->is_duplicating = true;
	}

	/**
	 * Remove Page Builder data from Events Manager's raw SQL duplication payload.
	 *
	 * @param array $event_meta Event post meta.
	 *
	 * @return array
	 */
	public function filter_duplicate_meta( $event_meta ) {
		if ( ! $this->is_duplicating || ! is_array( $event_meta ) ) {
			return $event_meta;
		}

		unset( $event_meta['panels_data'] );

		return $event_meta;
	}

	/**
	 * Copy Page Builder data to the duplicated event using safe WordPress APIs.
	 *
	 * @param mixed $duplicated_event The duplicated event object, or false on failure.
	 * @param mixed $source_event     The original source event object.
	 *
	 * @return mixed
	 */
	public function duplicate_copy_panels_data( $duplicated_event, $source_event ) {
		$this->is_duplicating = false;

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
			return $duplicated_event;
		}

		$source_panels_data = map_deep( $source_panels_data, array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) );
		update_post_meta( (int) $duplicated_event->post_id, 'panels_data', $source_panels_data );

		return $duplicated_event;
	}
}

SiteOrigin_Panels_Compat_Events_Manager::single();
