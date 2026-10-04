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
 *
 * This file sorts after panels-e2e-builder.php, so Panels_E2E_Text_Widget exists when it loads.
 */

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
