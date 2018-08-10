<?php

/**
 * Class SiteOrigin_Panels_Revisions
 *
 * Handles Page Builder revisions.
 */
class SiteOrigin_Panels_Revisions {

	function __construct() {
		add_action( 'save_post', array( $this, 'save_post' ), 11, 2 );
		add_action( 'wp_restore_post_revision', array( $this, 'revisions_restore' ), 10, 2 );

		add_filter( '_wp_post_revision_fields', array( $this, 'revisions_fields' ) );
		add_filter( '_wp_post_revision_field_panels_data_field', array( $this, 'revisions_field' ), 10, 3 );
	}

	/**
	 * @return SiteOrigin_Panels_Admin
	 */
	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Store the Page Builder meta in the revision.
	 *
	 * @param $post_id
	 * @param $post
	 */
	function save_post( $post_id, $post ) {
		if( is_preview() ) return;

		$parent_id = wp_is_post_revision( $post_id );
		if ( $parent_id ) {
			// Check whether the panels data needs to be copied to the revision.
			$panels_data = get_metadata( 'post', $post_id, 'panels_data', true );
			if ( empty( $panels_data ) ) {
				// If the panels data meta exists for the post parent, copy it into the revision.
				$panels_data = get_post_meta( $parent_id, 'panels_data', true );
				if ( ! empty( $panels_data ) ) {
					add_metadata( 'post', $post_id, 'panels_data', $panels_data );
				}
			}
		}
	}

	/**
	 * Restore a revision.
	 *
	 * @param $post_id
	 * @param $revision_id
	 */
	function revisions_restore( $post_id, $revision_id ) {
		$panels_data = get_metadata( 'post', $revision_id, 'panels_data', true );
		if ( ! empty( $panels_data ) ) {
			update_post_meta( $post_id, 'panels_data', map_deep( $panels_data, array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) ) );
		} else {
			delete_post_meta( $post_id, 'panels_data' );
		}
	}

	/**
	 * Add the Page Builder content revision field.
	 *
	 * @param $fields
	 *
	 * @return mixed
	 */
	function revisions_fields( $fields ) {
		// Prevent the autosave message.
		// TODO figure out how to include Page Builder data into the autosave.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $fields;
		}

		$screen = get_current_screen();
		if ( ! empty( $screen ) && $screen->base == 'post' ) {
			return $fields;
		}

		$fields['panels_data_field'] = __( 'Page Builder Content', 'siteorigin-panels' );

		return $fields;
	}

	/**
	 * Display the Page Builder content for the revision.
	 *
	 * @param $value
	 * @param $field
	 * @param $revision
	 *
	 * @return string
	 */
	function revisions_field( $value, $field, $revision ) {
		$parent_id   = wp_is_post_revision( $revision->ID );
		$panels_data = get_metadata( 'post', $revision->ID, 'panels_data', true );

		if ( empty( $panels_data ) ) {
			return '';
		}

		return siteorigin_panels_render( $parent_id, false, $panels_data );
	}
}
