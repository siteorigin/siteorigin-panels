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
 * - A REST route to read every meta row of a post as stored: the meta ID, the
 *   stored key and the stored value string.
 * - A REST route that writes post meta the way a remote publishing endpoint
 *   does: a capability check on the supplied key, then the core function with
 *   that same key. Options set up three conditions first: a metadata filter
 *   that reports no panels_data value, a meta cache read before the
 *   panels_data row was inserted by SQL, and a write with sanitize_key() of
 *   the key that was checked.
 * - A REST route that counts the database queries of the edit_post_meta
 *   capability check for a list of keys.
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

		register_rest_route(
			'panels-e2e/v1',
			'/post-meta/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;

					// Read the database directly: no metadata filters, no object cache.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", (int) $request['id'] ) );

					$meta = array();
					foreach ( $rows as $row ) {
						$meta[] = array(
							'meta_id'    => (int) $row->meta_id,
							'meta_key'   => $row->meta_key,
							'meta_value' => $row->meta_value,
						);
					}

					return array( 'meta' => $meta );
				},
			)
		);

		register_rest_route(
			'panels-e2e/v1',
			'/meta-write',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
				},
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;

					$post_id   = (int) $request->get_param( 'post_id' );
					$key       = (string) $request->get_param( 'key' );
					$operation = (string) $request->get_param( 'operation' );
					$result    = array();

					if ( $request->get_param( 'hide_layout_meta' ) ) {
						// A metadata filter that reports no panels_data value, whatever is stored.
						add_filter(
							'get_post_metadata',
							function ( $value, $object_id, $meta_key ) {
								return $meta_key === 'panels_data' ? false : $value;
							},
							10,
							3
						);
					}

					if ( $request->get_param( 'stale_cache_layout' ) !== null ) {
						// Read the post's meta into the cache, then insert the panels_data row
						// by SQL, so the cache does not hold it.
						update_meta_cache( 'post', array( $post_id ) );

						$result['inserted_value'] = maybe_serialize( $request->get_param( 'stale_cache_layout' ) );
						$wpdb->insert(
							$wpdb->postmeta,
							array(
								'post_id'    => $post_id,
								'meta_key'   => 'panels_data',
								'meta_value' => $result['inserted_value'],
							)
						);
					}

					// The key the caller writes: the key it checked, or sanitize_key() of it.
					$write_key = $request->get_param( 'write_key' ) === 'sanitized' ? sanitize_key( $key ) : $key;

					if ( $operation === 'update' ) {
						$allowed = current_user_can( 'edit_post_meta', $post_id, $key );
						if ( $allowed ) {
							update_post_meta( $post_id, $write_key, $request->get_param( 'value' ) );
						}
					} elseif ( $operation === 'delete' ) {
						$allowed = current_user_can( 'delete_post_meta', $post_id, $key );
						if ( $allowed ) {
							delete_post_meta( $post_id, $write_key );
						}
					} else {
						return new WP_Error( 'panels_e2e_bad_operation', 'Unknown operation.', array( 'status' => 400 ) );
					}

					$result['allowed'] = $allowed;

					return $result;
				},
			)
		);

		register_rest_route(
			'panels-e2e/v1',
			'/cap-queries',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
				},
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;

					$post_id = (int) $request->get_param( 'post_id' );

					// Load the post, the user and their caches first, so only the checks are counted.
					current_user_can( 'edit_post_meta', $post_id, 'panels_e2e_warm_up' );

					$before  = $wpdb->num_queries;
					$allowed = array();
					foreach ( (array) $request->get_param( 'keys' ) as $key ) {
						$allowed[] = current_user_can( 'edit_post_meta', $post_id, (string) $key );
					}

					return array(
						'queries' => $wpdb->num_queries - $before,
						'allowed' => $allowed,
					);
				},
			)
		);
	}
);
