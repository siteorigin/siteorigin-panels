<?php
/**
 * Plugin Name: Page Builder Live Editor e2e helpers
 * Description: Test-only must-use plugin for the Live Editor browser tests. Never shipped (the release build excludes tests/).
 *
 * - The cookie panels_e2e_isolation=1 turns on client-side media processing. WordPress then sends
 *   Document-Isolation-Policy on the editor screens to Chromium 137+, as it does on an https site.
 *   Playground serves 127.0.0.1, which WordPress does not treat as a secure context.
 * - A text widget that prints the widget wrapper (before_widget and after_widget), so the preview
 *   holds a .so-panel element for each widget. The Live Editor binds to those elements.
 * - The cookie panels_e2e_preview_block blocks Live Editor preview requests (any request with the
 *   siteorigin_panels_live_editor query argument) before WordPress loads plugins, like a firewall:
 *   403 answers 403 "Forbidden", 200 answers 200 "Blocked by test" (not a preview), timeout sleeps
 *   6 seconds and then lets the preview render.
 * - The cookie panels_e2e_preview_timeout_ms sets panelsOptions.live_editor_preview_timeout on admin
 *   screens, so a test does not wait the 30 second default.
 * - The cookie panels_e2e_inline_styles=1 turns on the inline-styles setting for that browser only, so a
 *   test cannot leave the setting on for later tests.
 * - The cookie panels_e2e_zero_gutter=1 sets the column gutter (margin-sides) to 0 for that browser only.
 * - The cookie panels_e2e_no_body_class=1 removes every body class on the front end, like a theme
 *   that does not call body_class().
 * - Single-widget update (Phase 3) fixtures:
 *   - the cookie panels_e2e_swap=1 adds Panels_E2E_Wrapped_Text_Widget to the swap allow list (plus a
 *     duplicate and two non-strings, which the plugin must drop);
 *   - the cookie panels_e2e_csp=1 sends a permissive Content-Security-Policy on preview responses;
 *   - Panels_E2E_Random_Widget prints a new random number on every render;
 *   - a request for /?panels-e2e-inert-probe=1 answers 204, uncached, so each real load is counted;
 *   - panels_e2e_preview_block=slow delays every preview response by 2 seconds, slow5 by 5 seconds.
 * - No login autofocus: its 200 ms timer focuses and selects the username field, and can catch a test's
 *   password typing (the username then holds the password and the login fails).
 *
 * This file sorts after panels-e2e-builder.php, so Panels_E2E_Text_Widget exists when it loads.
 */

if ( ! empty( $_GET['siteorigin_panels_live_editor'] ) && ! empty( $_COOKIE['panels_e2e_preview_block'] ) ) {
	switch ( $_COOKIE['panels_e2e_preview_block'] ) {
		case '403':
			http_response_code( 403 );
			header( 'Content-Type: text/plain' );
			echo 'Forbidden';
			exit;

		case '200':
			http_response_code( 200 );
			header( 'Content-Type: text/plain' );
			echo 'Blocked by test';
			exit;

		case 'timeout':
			sleep( 6 );
			break;

		case 'slow':
			sleep( 2 );
			break;

		case 'slow5':
			sleep( 5 );
			break;
	}
}

if ( isset( $_GET['panels-e2e-inert-probe'] ) ) {
	nocache_headers();
	status_header( 204 );
	exit;
}

if ( ! empty( $_COOKIE['panels_e2e_swap'] ) ) {
	add_filter(
		'siteorigin_panels_live_editor_swap_widgets',
		function ( $classes ) {
			$classes[] = 'Panels_E2E_Wrapped_Text_Widget';
			$classes[] = 'WP_Widget_Text';
			$classes[] = 123;
			$classes[] = array( 'Not_A_String' );

			return $classes;
		}
	);
}

if ( ! empty( $_COOKIE['panels_e2e_csp'] ) && ! empty( $_GET['siteorigin_panels_live_editor'] ) ) {
	add_action(
		'send_headers',
		function () {
			header( "Content-Security-Policy: script-src 'self' 'unsafe-inline' 'unsafe-eval'" );
		}
	);
}

if ( ! empty( $_COOKIE['panels_e2e_preview_timeout_ms'] ) && absint( $_COOKIE['panels_e2e_preview_timeout_ms'] ) > 0 ) {
	add_action(
		'admin_print_footer_scripts',
		function () {
			printf(
				'<script>if(window.panelsOptions){panelsOptions.live_editor_preview_timeout=%d;}</script>',
				absint( $_COOKIE['panels_e2e_preview_timeout_ms'] )
			);
		},
		100
	);
}

add_filter( 'enable_login_autofocus', '__return_false' );

if ( ! empty( $_COOKIE['panels_e2e_inline_styles'] ) || ! empty( $_COOKIE['panels_e2e_zero_gutter'] ) ) {
	add_filter(
		'siteorigin_panels_settings',
		function ( $settings ) {
			if ( ! empty( $_COOKIE['panels_e2e_inline_styles'] ) ) {
				$settings['inline-styles'] = true;
			}

			if ( ! empty( $_COOKIE['panels_e2e_zero_gutter'] ) ) {
				$settings['margin-sides'] = 0;
			}

			return $settings;
		}
	);
}

if ( ! empty( $_COOKIE['panels_e2e_no_body_class'] ) ) {
	add_filter( 'body_class', '__return_empty_array', PHP_INT_MAX );
}

if ( ! empty( $_COOKIE['panels_e2e_isolation'] ) ) {
	add_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
}

if ( class_exists( 'Panels_E2E_Text_Widget' ) ) {
	/**
	 * Panels_E2E_Text_Widget with the widget wrapper. It keeps the parent form and update.
	 */
	class Panels_E2E_Wrapped_Text_Widget extends Panels_E2E_Text_Widget {
		public function __construct() {
			WP_Widget::__construct(
				'panels_e2e_wrapped_text',
				'Panels E2E Wrapped Text',
				array( 'description' => 'One text field in the widget wrapper, for the Live Editor tests.' )
			);
		}

		public function widget( $args, $instance ) {
			echo $args['before_widget'];
			echo '<div class="panels-e2e-text">' . esc_html( isset( $instance['text'] ) ? $instance['text'] : '' ) . '</div>';
			echo $args['after_widget'];
		}
	}

	/**
	 * A wrapped widget whose output changes on every render.
	 */
	class Panels_E2E_Random_Widget extends Panels_E2E_Text_Widget {
		public function __construct() {
			WP_Widget::__construct(
				'panels_e2e_random',
				'Panels E2E Random',
				array( 'description' => 'Prints a random number, for the Live Editor tests.' )
			);
		}

		public function widget( $args, $instance ) {
			echo $args['before_widget'];
			echo '<div class="panels-e2e-random">' . (int) wp_rand() . '</div>';
			echo $args['after_widget'];
		}
	}

	add_action(
		'widgets_init',
		function () {
			register_widget( 'Panels_E2E_Wrapped_Text_Widget' );
			register_widget( 'Panels_E2E_Random_Widget' );
		}
	);
}
