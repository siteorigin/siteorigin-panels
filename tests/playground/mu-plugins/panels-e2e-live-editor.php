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
	}
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

	add_action(
		'widgets_init',
		function () {
			register_widget( 'Panels_E2E_Wrapped_Text_Widget' );
		}
	);
}
