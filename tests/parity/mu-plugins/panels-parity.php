<?php
/**
 * Plugin Name: Page Builder parity harness
 * Description: Test-only must-use plugin for tests/parity. Never shipped (the release build excludes tests/).
 *
 * Active only when the Playground blueprint defines PANELS_PARITY_HARNESS.
 *
 * - Header user: X-Parity-User: admin|author.
 * - Renderer pin: X-Parity-Renderer: modern|legacy (siteorigin_panels_settings filter).
 * - [parity_form] shortcode.
 * - ?parity=<action> endpoints that read and seed stored data.
 */

if ( ! defined( 'PANELS_PARITY_HARNESS' ) || ! PANELS_PARITY_HARNESS ) {
	return;
}

// Batch endpoints of the generator test (tests/parity/php, mounted by the harness).
if ( file_exists( WP_CONTENT_DIR . '/parity-php/batch.php' ) ) {
	require_once WP_CONTENT_DIR . '/parity-php/batch.php';
}

add_shortcode(
	'parity_form',
	function () {
		return '<form class="parity-form"><input name="q"></form>';
	}
);

add_filter(
	'determine_current_user',
	function ( $uid ) {
		$h = isset( $_SERVER['HTTP_X_PARITY_USER'] ) ? $_SERVER['HTTP_X_PARITY_USER'] : '';

		if ( $h === 'admin' ) {
			return 1;
		}

		if ( $h === 'author' ) {
			$u = get_user_by( 'login', 'pauthor' );

			return $u ? $u->ID : 0;
		}

		return $uid;
	},
	99
);

add_action(
	'init',
	function () {
		// One author. The display name is fixed, so the theme's post-author block is the same in every run.
		$existing = get_user_by( 'login', 'pauthor' );

		if ( $existing ) {
			// On multisite the user is global; make it an author of the site of this request too.
			if ( is_multisite() && ! is_user_member_of_blog( $existing->ID, get_current_blog_id() ) ) {
				add_user_to_blog( get_current_blog_id(), $existing->ID, 'author' );
			}

			return;
		}

		$uid = wp_insert_user(
			array(
				'user_login'   => 'pauthor',
				'user_pass'    => 'parity-author-pass',
				'user_email'   => 'pauthor@example.com',
				'role'         => 'author',
				'display_name' => 'Parity Author',
			)
		);

		// On multisite a new user is not a member of the site until added.
		if ( is_multisite() && ! is_wp_error( $uid ) ) {
			add_user_to_blog( get_current_blog_id(), $uid, 'author' );
		}
	},
	1
);

add_filter(
	'siteorigin_panels_settings',
	function ( $settings ) {
		$h = isset( $GLOBALS['panels_parity_renderer'] ) ? $GLOBALS['panels_parity_renderer'] : ( isset( $_SERVER['HTTP_X_PARITY_RENDERER'] ) ? $_SERVER['HTTP_X_PARITY_RENDERER'] : '' );

		if ( $h === 'legacy' ) {
			$settings['legacy-layout'] = 'always';
		} elseif ( $h === 'modern' ) {
			$settings['legacy-layout'] = 'never';
		}

		// The custom home page screen, for the home page save cases. Admin only: it adds a menu item.
		if ( is_admin() ) {
			$settings['home-page'] = true;
		}

		return $settings;
	},
	PHP_INT_MAX
);

// A widget area for the widget cases, printed in the footer only when it holds widgets.
add_action(
	'widgets_init',
	function () {
		register_sidebar(
			array(
				'id'   => 'panels-parity-sidebar',
				'name' => 'Parity Sidebar',
			)
		);
	}
);

add_action(
	'wp_footer',
	function () {
		if ( is_active_sidebar( 'panels-parity-sidebar' ) ) {
			echo '<div id="panels-parity-sidebar">';
			dynamic_sidebar( 'panels-parity-sidebar' );
			echo '</div>';
		}
	}
);

/**
 * The raw stored value of an option, read from the database.
 */
function panels_parity_raw_option( $name ) {
	global $wpdb;

	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

function panels_parity_send( $data ) {
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	exit;
}

function panels_parity_dump( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_content, post_content_filtered, post_excerpt, post_status, post_author, post_type, post_title FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );

	if ( empty( $row ) ) {
		return array( 'missing' => true );
	}
	$meta      = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ('_edit_lock','_edit_last','_pingme','_encloseme') ORDER BY meta_key, meta_id", $id ), ARRAY_A );
	$revisions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $id ) );

	return array(
		'row'         => $row,
		'content_b64' => base64_encode( $row['post_content'] ),
		'meta'        => $meta,
		'meta_b64'    => base64_encode( serialize( $meta ) ),
		'revisions'   => $revisions,
	);
}

function panels_parity_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		panels_parity_send( array( 'error' => 'forbidden' ) );
	}
}

add_action(
	'wp_loaded',
	function () {
		if ( empty( $_GET['parity'] ) ) {
			return;
		}
		global $wpdb, $wp_version;
		$action = $_GET['parity'];
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$body   = json_decode( file_get_contents( 'php://input' ), true );

		if ( $action === 'info' ) {
			$author = get_user_by( 'login', 'pauthor' );
			panels_parity_send(
				array(
					'wp'                           => $wp_version,
					'php'                          => PHP_VERSION,
					'multisite'                    => is_multisite(),
					'blog_id'                      => get_current_blog_id(),
					'sow_version'                  => defined( 'SOW_BUNDLE_VERSION' ) ? SOW_BUNDLE_VERSION : null,
					'panels_version'               => defined( 'SITEORIGIN_PANELS_VERSION' ) ? SITEORIGIN_PANELS_VERSION : null,
					'premium_version'              => defined( 'SITEORIGIN_PREMIUM_VERSION' ) ? SITEORIGIN_PREMIUM_VERSION : null,
					'premium_addons'               => get_option( 'siteorigin_premium_active' ),
					'panels_dir'                   => defined( 'SITEORIGIN_PANELS_BASE_FILE' ) ? dirname( SITEORIGIN_PANELS_BASE_FILE ) : null,
					'renderer'                     => class_exists( 'SiteOrigin_Panels' ) ? get_class( SiteOrigin_Panels::renderer() ) : null,
					'legacy_layout_setting'        => function_exists( 'siteorigin_panels_setting' ) ? siteorigin_panels_setting( 'legacy-layout' ) : null,
					'current_user'                 => get_current_user_id(),
					'current_user_unfiltered_html' => current_user_can( 'unfiltered_html' ),
					'author_id'                    => $author ? $author->ID : 0,
					'author_unfiltered_html'       => $author ? user_can( $author, 'unfiltered_html' ) : null,
					'active_plugins'               => get_option( 'active_plugins' ),
					'network_plugins'              => is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins' ) ) : null,
					'theme'                        => get_stylesheet(),
					'has_abilities_api'            => function_exists( 'wp_register_ability' ),
					'home'                         => home_url( '/' ),
				)
			);
		}

		if ( $action === 'setup' ) {
			// The sample post, page and comment carry the install time. Give them a fixed date so
			// the theme's post list is the same in every run.
			panels_parity_require_admin();
			$d = '2026-01-01 09:00:00';
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_date = %s, post_date_gmt = %s, post_modified = %s, post_modified_gmt = %s", $d, $d, $d, $d ) );
			$c = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_date = %s, comment_date_gmt = %s", $d, $d ) );
			wp_cache_flush();
			panels_parity_send(
				array(
					'posts'    => $n,
					'comments' => $c,
				)
			);
		}

		if ( $action === 'nonces' ) {
			panels_parity_send(
				array(
					'user'   => get_current_user_id(),
					'update'      => wp_create_nonce( 'update-post_' . $id ),
					'panels'      => wp_create_nonce( 'save' ),
					'savewidgets' => wp_create_nonce( 'save-sidebar-widgets' ),
					'home'        => wp_create_nonce( 'save' ),
					'sidebars'    => wp_get_sidebars_widgets(),
				)
			);
		}

		if ( $action === 'dump' ) {
			panels_parity_send( panels_parity_dump( $id ) );
		}

		if ( $action === 'seed' ) {
			// Direct database write. No save filter and no plugin code runs: this stands for
			// data that was stored by an earlier version.
			panels_parity_require_admin();
			$author = isset( $body['author'] ) && $body['author'] === 'author' ? get_user_by( 'login', 'pauthor' )->ID : 1;
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'           => $author,
					'post_date'             => '2026-01-15 10:00:00',
					'post_date_gmt'         => '2026-01-15 10:00:00',
					'post_modified'         => '2026-01-15 10:00:00',
					'post_modified_gmt'     => '2026-01-15 10:00:00',
					'post_content'          => isset( $body['content'] ) ? $body['content'] : '',
					'post_title'            => $body['title'],
					'post_excerpt'          => '',
					'post_status'           => isset( $body['status'] ) ? $body['status'] : 'publish',
					'post_name'             => sanitize_title( $body['title'] ),
					'post_type'             => isset( $body['type'] ) ? $body['type'] : 'post',
					'to_ping'               => '',
					'pinged'                => '',
					'post_content_filtered' => '',
				)
			);
			$new = (int) $wpdb->insert_id;
			$wpdb->update( $wpdb->posts, array( 'guid' => home_url( '/?p=' . $new ) ), array( 'ID' => $new ) );

			if ( isset( $body['panels'] ) ) {
				$wpdb->insert(
					$wpdb->postmeta,
					array(
						'post_id'    => $new,
						'meta_key'   => 'panels_data',
						'meta_value' => serialize( $body['panels'] ),
					)
				);
			}

			if ( isset( $body['panels_serialized_b64'] ) ) {
				$wpdb->insert(
					$wpdb->postmeta,
					array(
						'post_id'    => $new,
						'meta_key'   => 'panels_data',
						'meta_value' => base64_decode( $body['panels_serialized_b64'] ),
					)
				);
			}
			clean_post_cache( $new );
			panels_parity_send( array( 'id' => $new ) );
		}

		if ( $action === 'css' ) {
			// The CSS the pinned renderer generates for a stored classic layout, or for the layout of a
			// stored Layout Builder widget (source=widget&number=<n>).
			$layout_id = $id;

			if ( isset( $_GET['source'] ) && $_GET['source'] === 'widget' ) {
				$instances = get_option( 'widget_siteorigin-panels-builder' );
				$number    = isset( $_GET['number'] ) ? (int) $_GET['number'] : 0;
				$instance  = is_array( $instances ) && isset( $instances[ $number ] ) ? $instances[ $number ] : array();
				$pd        = isset( $instance['panels_data'] ) ? $instance['panels_data'] : array();
				$pd        = is_string( $pd ) ? json_decode( $pd, true ) : $pd;
				$layout_id = 'w' . ( isset( $instance['builder_id'] ) ? $instance['builder_id'] : '' );
			} else {
				$pd = get_post_meta( $id, 'panels_data', true );
			}

			$out = array(
				'renderer' => get_class( SiteOrigin_Panels::renderer() ),
				'has_meta' => ! empty( $pd ),
			);

			if ( ! empty( $pd ) && is_array( $pd ) ) {
				try {
					$out['css'] = SiteOrigin_Panels::renderer()->generate_css( $layout_id, $pd );
				} catch ( \Throwable $e ) {
					$out['error'] = get_class( $e ) . ': ' . $e->getMessage();
				}
			}
			panels_parity_send( $out );
		}

		if ( $action === 'option' ) {
			panels_parity_require_admin();
			update_option( $body['name'], $body['value'] );
			panels_parity_send(
				array(
					'ok'    => true,
					'value' => get_option( $body['name'] ),
				)
			);
		}

		if ( $action === 'seed_widget' ) {
			// Direct write of one Layout Builder widget in the parity widget area. No widget update() runs:
			// this stands for a widget stored by an earlier version.
			panels_parity_require_admin();
			$number = (int) $body['number'];
			update_option(
				'widget_siteorigin-panels-builder',
				array(
					$number        => array(
						'panels_data' => $body['panels_data'],
						'builder_id'  => isset( $body['builder_id'] ) ? $body['builder_id'] : 'a1b2c3d4e5f60',
					),
					'_multiwidget' => 1,
				)
			);
			$sidebars                          = get_option( 'sidebars_widgets', array() );
			$sidebars['panels-parity-sidebar'] = array( 'siteorigin-panels-builder-' . $number );
			update_option( 'sidebars_widgets', $sidebars );
			panels_parity_send( array( 'number' => $number ) );
		}

		if ( $action === 'clear_widget' ) {
			panels_parity_require_admin();
			update_option( 'widget_siteorigin-panels-builder', array( '_multiwidget' => 1 ) );
			$sidebars                          = get_option( 'sidebars_widgets', array() );
			$sidebars['panels-parity-sidebar'] = array();
			update_option( 'sidebars_widgets', $sidebars );
			panels_parity_send( array( 'ok' => true ) );
		}

		if ( $action === 'seed_home' ) {
			// Direct insert of a front page with a layout, and the options a home page save sets.
			panels_parity_require_admin();
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'           => 1,
					'post_date'             => '2026-01-15 10:00:00',
					'post_date_gmt'         => '2026-01-15 10:00:00',
					'post_modified'         => '2026-01-15 10:00:00',
					'post_modified_gmt'     => '2026-01-15 10:00:00',
					'post_content'          => '',
					'post_title'            => $body['title'],
					'post_excerpt'          => '',
					'post_status'           => 'publish',
					'post_name'             => sanitize_title( $body['title'] ),
					'post_type'             => 'page',
					'comment_status'        => 'closed',
					'to_ping'               => '',
					'pinged'                => '',
					'post_content_filtered' => '',
				)
			);
			$new = (int) $wpdb->insert_id;
			$wpdb->update( $wpdb->posts, array( 'guid' => home_url( '/?page_id=' . $new ) ), array( 'ID' => $new ) );
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $new,
					'meta_key'   => 'panels_data',
					'meta_value' => serialize( $body['panels'] ),
				)
			);
			clean_post_cache( $new );
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $new );
			update_option( 'siteorigin_panels_home_page_id', $new );
			panels_parity_send( array( 'id' => $new ) );
		}

		if ( $action === 'clear_home' ) {
			panels_parity_require_admin();
			$page_id = (int) get_option( 'siteorigin_panels_home_page_id' );

			if ( $page_id ) {
				wp_delete_post( $page_id, true );
			}
			update_option( 'show_on_front', 'posts' );
			delete_option( 'page_on_front' );
			delete_option( 'siteorigin_panels_home_page_id' );
			panels_parity_send( array( 'deleted' => $page_id ) );
		}

		if ( $action === 'dump_option' ) {
			$names = array_filter( array_map( 'trim', explode( ',', isset( $_GET['name'] ) ? $_GET['name'] : '' ) ) );
			$out   = array();

			foreach ( $names as $name ) {
				$out[ $name ] = panels_parity_raw_option( $name );
			}
			panels_parity_send( $out );
		}

		panels_parity_send( array( 'error' => 'unknown action' ) );
	},
	9998
);
