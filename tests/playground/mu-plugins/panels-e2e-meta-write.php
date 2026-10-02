<?php
/**
 * Plugin Name: Page Builder e2e meta write helpers
 * Description: Test-only must-use plugin for the meta write authorization e2e test. Never shipped (the release build excludes tests/).
 *
 * - Defines DISALLOW_UNFILTERED_HTML for a single request that carries the
 *   X-Panels-E2E-Disallow-Unfiltered-HTML header. WordPress reads that constant
 *   at each capability check, so it must exist before any request handler runs;
 *   a must-use plugin loads early enough. Keeping it to one request means no
 *   other test is affected.
 * - A REST route to report whether the site is a network.
 * - A REST route to read a post and its panels_data rows straight from the
 *   database, with the meta IDs the custom_fields route needs.
 */

if ( ! empty( $_SERVER['HTTP_X_PANELS_E2E_DISALLOW_UNFILTERED_HTML'] ) && ! defined( 'DISALLOW_UNFILTERED_HTML' ) ) {
	define( 'DISALLOW_UNFILTERED_HTML', true );
}

add_action(
	'rest_api_init',
	function () {
		$permission = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			'panels-e2e/v1',
			'/site',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function () {
					return array( 'multisite' => is_multisite() );
				},
			)
		);

		register_rest_route(
			'panels-e2e/v1',
			'/meta-rows/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;

					$id = (int) $request['id'];

					// Read the database directly: no metadata or post filters, no object cache.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC", $id, 'panels_data' ) );
					$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_content, post_status, post_author FROM {$wpdb->posts} WHERE ID = %d", $id ) );

					$meta = array();
					foreach ( $rows as $row ) {
						$meta[] = array(
							'meta_id' => (int) $row->meta_id,
							// Test-only: a value this site just stored.
							'value'   => maybe_unserialize( $row->meta_value ),
						);
					}

					return array(
						'exists'  => ! empty( $post ),
						'title'   => $post ? $post->post_title : null,
						'content' => $post ? $post->post_content : null,
						'status'  => $post ? $post->post_status : null,
						'author'  => $post ? (int) $post->post_author : null,
						'meta'    => $meta,
					);
				},
			)
		);
	}
);
