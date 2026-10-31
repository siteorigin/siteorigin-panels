<?php
/**
 * Plugin Name: Page Builder e2e WP Rocket stand-in
 * Description: Test-only must-use plugin for the WP Rocket widget purge e2e test. Never shipped, and never installed beside real WP Rocket, because it defines the same function.
 *
 * With the panels_e2e_wp_rocket_on option set:
 * - A stand-in for rocket_widget_update_callback, attached the way WP Rocket
 *   attaches it. It counts each call in panels_e2e_wp_rocket_purges, keyed by
 *   the request's action.
 * - A witness callback on the same filter, which shows other callbacks still run.
 * - A prebuilt layout, a non-public post type that uses the builder, and the
 *   Customizer's widgets on a block theme.
 *
 * With panels_e2e_wp_rocket_baseline set too, the stand-in is attached again
 * after the compatibility code has run, which gives the behavior without it.
 *
 * With the option off the function doesn't exist, so the compatibility code
 * doesn't load and other specs are unaffected.
 */

/**
 * Report whether the compatibility code loaded.
 */
add_filter(
	'rest_post_dispatch',
	function ( $response ) {
		if ( $response instanceof WP_HTTP_Response ) {
			$response->header( 'X-Panels-E2E-WP-Rocket-Compat', function_exists( 'siteorigin_panels_wp_rocket_skip_preview_purge' ) ? '1' : '0' );
		}

		return $response;
	}
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'panels-e2e/v1',
			'/wp-rocket/nonces',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function () {
					return array(
						'panels_action'        => wp_create_nonce( 'panels_action' ),
						'live_editor_preview'  => wp_create_nonce( 'live-editor-preview' ),
						'layout_block_preview' => wp_create_nonce( 'layout-block-preview' ),
					);
				},
			)
		);
	}
);

if ( get_option( 'panels_e2e_wp_rocket_on' ) ) {
	function rocket_widget_update_callback( $instance ) {
		if ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) {
			$key = $_REQUEST['action'];
		} else {
			$key = defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : 'page';
		}

		// The Customizer captures option writes during a widget update, so the count goes straight to the table.
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'panels_e2e_wp_rocket_purges' ) );
		$counts = $stored ? maybe_unserialize( $stored ) : array();
		$counts[ $key ] = ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) + 1;
		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => 'panels_e2e_wp_rocket_purges',
				'option_value' => serialize( $counts ),
				'autoload'     => 'off',
			)
		);
		wp_cache_delete( 'panels_e2e_wp_rocket_purges', 'options' );

		return $instance;
	}

	function panels_e2e_wp_rocket_witness( $instance ) {
		if ( is_array( $instance ) ) {
			$instance['panels_e2e_witness'] = 'kept';
		}

		return $instance;
	}

	add_action(
		'plugins_loaded',
		function () {
			add_filter( 'widget_update_callback', 'rocket_widget_update_callback' );
			add_filter( 'widget_update_callback', 'panels_e2e_wp_rocket_witness', 11 );
		}
	);

	if ( get_option( 'panels_e2e_wp_rocket_baseline' ) ) {
		$panels_e2e_wp_rocket_attach = function () {
			add_filter( 'widget_update_callback', 'rocket_widget_update_callback' );
		};
		add_action( 'init', $panels_e2e_wp_rocket_attach, 101 );
		add_action( 'template_redirect', $panels_e2e_wp_rocket_attach, 11 );
	}

	add_action(
		'init',
		function () {
			register_post_type(
				'panels_e2e_private',
				array(
					'label'    => 'Panels E2E Private',
					'public'   => false,
					'show_ui'  => true,
					'supports' => array( 'title', 'editor' ),
				)
			);
		}
	);

	// A block theme doesn't load the Customizer's widgets.
	add_filter(
		'customize_loaded_components',
		function ( $components ) {
			$components[] = 'widgets';

			return $components;
		}
	);

	add_filter(
		'siteorigin_panels_settings',
		function ( $settings ) {
			$settings['post-types'][] = 'panels_e2e_private';

			return $settings;
		}
	);

	add_filter(
		'siteorigin_panels_prebuilt_layouts',
		function ( $layouts ) {
			$layouts['panels-e2e-rocket'] = array(
				'name'       => 'Panels E2E Rocket',
				'widgets'    => array(
					array(
						'text'        => 'Prebuilt rocket text',
						'panels_info' => array(
							'class' => 'Panels_E2E_Text_Widget',
							'grid'  => 0,
							'cell'  => 0,
							'id'    => 0,
						),
					),
				),
				'grids'      => array( array( 'cells' => 1 ) ),
				'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1 ) ),
			);

			return $layouts;
		}
	);
}
