<?php
/**
 * Plugin Name: Page Builder e2e helpers
 * Description: Test-only must-use plugin for the Playwright e2e tests. Never shipped (the release build excludes tests/).
 *
 * - A probe widget that stores its text unfiltered and counts its renders, so
 *   any change to stored markup comes from Page Builder or WordPress.
 * - A listener on siteorigin_panels_layout_update_pre_write that records each
 *   call and passes, returns a WP_Error, or returns false.
 * - REST routes to control the listener and read raw storage.
 */

/**
 * Probe widget.
 */
class Panels_E2E_Probe_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'panels_e2e_probe', 'Panels E2E Probe' );
	}

	public function widget( $args, $instance ) {
		update_option( 'panels_e2e_probe_renders', (int) get_option( 'panels_e2e_probe_renders', 0 ) + 1, false );

		echo '<div class="panels-e2e-probe">' . wp_kses_post( isset( $instance['text'] ) ? $instance['text'] : '' ) . '</div>';
	}

	public function update( $new_instance, $old_instance ) {
		return array( 'text' => isset( $new_instance['text'] ) ? (string) $new_instance['text'] : '' );
	}

	public function form( $instance ) {
		return 'noform';
	}
}

add_action(
	'widgets_init',
	function () {
		register_widget( 'Panels_E2E_Probe_Widget' );
	}
);

/*
 * Deny unfiltered_html to one user, the way WordPress core does on multisite
 * for a user who is not a super admin (map_meta_cap()). Set through the state
 * route's deny_unfiltered_html parameter; 0 turns it off.
 */
add_filter(
	'map_meta_cap',
	function ( $caps, $cap, $user_id ) {
		if ( $cap === 'unfiltered_html' && (int) $user_id === (int) get_option( 'panels_e2e_deny_unfiltered_html', 0 ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	},
	10,
	3
);

add_filter(
	'siteorigin_panels_layout_update_pre_write',
	function ( $result, $panels_data, $post_id, $storage, $block_index ) {
		$log   = get_option( 'panels_e2e_pre_write_log', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'post_id'     => $post_id,
			'storage'     => $storage,
			'block_index' => $block_index,
			'panels_data' => $panels_data,
		);
		update_option( 'panels_e2e_pre_write_log', $log, false );

		$mode = get_option( 'panels_e2e_pre_write_mode', 'pass' );

		if ( $mode === 'wp_error' ) {
			return new WP_Error( 'panels_e2e_blocked', 'Blocked by the e2e listener.', array( 'status' => 409 ) );
		}

		if ( $mode === 'false' ) {
			return false;
		}

		return $result;
	},
	10,
	5
);

add_action(
	'rest_api_init',
	function () {
		$permission = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			'panels-e2e/v1',
			'/state',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $permission,
					'callback'            => function () {
						return array(
							'mode'          => get_option( 'panels_e2e_pre_write_mode', 'pass' ),
							'log'           => array_values( (array) get_option( 'panels_e2e_pre_write_log', array() ) ),
							'probe_renders' => (int) get_option( 'panels_e2e_probe_renders', 0 ),
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $permission,
					'callback'            => function ( WP_REST_Request $request ) {
						$mode = (string) $request->get_param( 'mode' );
						if ( ! in_array( $mode, array( 'pass', 'wp_error', 'false' ), true ) ) {
							return new WP_Error( 'panels_e2e_bad_mode', 'Unknown mode.', array( 'status' => 400 ) );
						}

						update_option( 'panels_e2e_pre_write_mode', $mode, false );
						update_option( 'panels_e2e_pre_write_log', array(), false );
						update_option( 'panels_e2e_probe_renders', 0, false );

						if ( $request->has_param( 'deny_unfiltered_html' ) ) {
							update_option( 'panels_e2e_deny_unfiltered_html', (int) $request->get_param( 'deny_unfiltered_html' ), false );
						}

						return array( 'mode' => $mode );
					},
				),
			)
		);

		register_rest_route(
			'panels-e2e/v1',
			'/raw/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;

					$id = (int) $request['id'];

					// Read the database directly: no metadata or post filters, no object cache.
					$rows    = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC", $id, 'panels_data' ) );
					$content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );

					$blocks = array();
					foreach ( parse_blocks( (string) $content ) as $block ) {
						if ( $block['blockName'] === 'siteorigin-panels/layout-block' ) {
							$blocks[] = isset( $block['attrs']['panelsData'] ) ? $block['attrs']['panelsData'] : null;
						}
					}

					return array(
						'meta_rows'    => count( $rows ),
						'meta_exists'  => count( $rows ) > 0,
						// Test-only: a value this site just stored.
						'meta'         => $rows ? maybe_unserialize( $rows[0] ) : null,
						'post_content' => $content,
						'blocks'       => $blocks,
					);
				},
			)
		);
	}
);
