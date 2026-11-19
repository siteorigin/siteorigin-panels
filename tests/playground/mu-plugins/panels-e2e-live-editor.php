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
 * - Style-only update (#1426) fixtures, each for that browser only:
 *   - the cookie panels_e2e_tablet_layout=1 turns on Tablet Layout;
 *   - the cookie panels_e2e_css_footer=1 prints the layout CSS in the footer, not the header;
 *   - the cookie panels_e2e_panels_style=1 appends a style.panels-style block to the rendered layout, whose
 *     text holds a hash of the layout data, so it changes with every edit;
 *   - Panels_E2E_Measure_Widget sets an inline width from its parent's width on DOMContentLoaded and on resize.
 * - Add and delete (#1425) fixtures, each for that browser only:
 *   - the cookie panels_e2e_position_style=1 prints an empty element with an inline style that holds the
 *     widget's position number inside every widget, so a renumbered widget renders differently (the value
 *     'move' keeps the style the same and moves it between before and after the content by position);
 *   - the cookie panels_e2e_row_embed=1 prints an iframe at the start of every row, outside any widget (the value
 *     'button' prints a button, markup a script on the page could set up);
 *   - panels_e2e_swap also allow-lists Panels_E2E_Measure_Widget.
 * - The post date block is left out of preview responses: the theme prints it as a relative time ("20 seconds
 *   ago") that differs between two renders a second apart, so the page compare would see a different page.
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
			$classes[] = 'Panels_E2E_Measure_Widget';
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

if ( ! empty( $_GET['siteorigin_panels_live_editor'] ) ) {
	add_filter( 'render_block_core/post-date', '__return_empty_string' );
}

if (
	! empty( $_COOKIE['panels_e2e_inline_styles'] ) ||
	! empty( $_COOKIE['panels_e2e_zero_gutter'] ) ||
	! empty( $_COOKIE['panels_e2e_tablet_layout'] ) ||
	! empty( $_COOKIE['panels_e2e_css_footer'] )
) {
	add_filter(
		'siteorigin_panels_settings',
		function ( $settings ) {
			if ( ! empty( $_COOKIE['panels_e2e_inline_styles'] ) ) {
				$settings['inline-styles'] = true;
			}

			if ( ! empty( $_COOKIE['panels_e2e_zero_gutter'] ) ) {
				$settings['margin-sides'] = 0;
			}

			if ( ! empty( $_COOKIE['panels_e2e_tablet_layout'] ) ) {
				$settings['tablet-layout'] = true;
			}

			if ( ! empty( $_COOKIE['panels_e2e_css_footer'] ) ) {
				$settings['output-css-header'] = 'footer';
			}

			return $settings;
		}
	);
}

if ( ! empty( $_COOKIE['panels_e2e_panels_style'] ) && ! empty( $_GET['siteorigin_panels_live_editor'] ) ) {
	$panels_e2e_style_hash = '';

	add_action(
		'siteorigin_panels_after_render',
		function ( $panels_data ) use ( &$panels_e2e_style_hash ) {
			$panels_e2e_style_hash = md5( wp_json_encode( $panels_data ) );
		},
		10,
		1
	);

	add_filter(
		'siteorigin_panels_render',
		function ( $html, $post_id ) use ( &$panels_e2e_style_hash ) {
			return $html . sprintf(
				"<style class='panels-style' data-panels-style-for-post='%d'>/* %s */ #pl-%d { outline: 0 solid transparent; }</style>",
				(int) $post_id,
				esc_html( $panels_e2e_style_hash ),
				(int) $post_id
			);
		},
		10,
		2
	);
}

if ( ! empty( $_COOKIE['panels_e2e_position_style'] ) ) {
	$panels_e2e_move = 'move' === $_COOKIE['panels_e2e_position_style'];

	add_filter(
		'siteorigin_panels_inside_widget_before',
		function ( $html, $widget_info ) use ( $panels_e2e_move ) {
			$position = isset( $widget_info['widget_index'] ) ? (int) $widget_info['widget_index'] : 0;

			if ( $panels_e2e_move ) {
				// Two empty elements in every widget; the same style sits on the first for an even position and on the second for an odd one.
				return $html . sprintf(
					'<span class="panels-e2e-first"%1$s></span><span class="panels-e2e-second"%2$s></span>',
					0 === $position % 2 ? ' style="order:1"' : '',
					0 === $position % 2 ? '' : ' style="order:1"'
				);
			}

			return $html . sprintf( '<span class="panels-e2e-position" style="order:%d"></span>', $position );
		},
		10,
		2
	);
}

if ( ! empty( $_COOKIE['panels_e2e_row_embed'] ) ) {
	add_filter(
		'siteorigin_panels_inside_row_before',
		function ( $html ) {
			if ( 'button' === $_COOKIE['panels_e2e_row_embed'] ) {
				return $html . '<button type="button" class="panels-e2e-row-button">e2e</button>';
			}

			return $html . '<iframe class="panels-e2e-row-embed" src="about:blank" title="e2e"></iframe>';
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

	/**
	 * A wrapped widget whose script sets an inline width from its parent on DOMContentLoaded and on resize.
	 */
	class Panels_E2E_Measure_Widget extends Panels_E2E_Text_Widget {
		public function __construct() {
			WP_Widget::__construct(
				'panels_e2e_measure',
				'Panels E2E Measure',
				array( 'description' => 'Sets an inline width from its parent, for the Live Editor tests.' )
			);
		}

		public function widget( $args, $instance ) {
			echo $args['before_widget'];
			echo '<div class="panels-e2e-measure">' . esc_html( isset( $instance['text'] ) ? $instance['text'] : '' ) . '</div>';
			echo $args['after_widget'];

			// The script is printed once in the footer, outside the layout, like an enqueued script.
			if ( ! has_action( 'wp_footer', 'panels_e2e_measure_script' ) ) {
				add_action( 'wp_footer', 'panels_e2e_measure_script' );
			}
		}
	}

	function panels_e2e_measure_script() {
		?>
		<script>
		( function () {
			var measure = function () {
				document.querySelectorAll( '.panels-e2e-measure' ).forEach( function ( el ) {
					el.style.width = Math.floor( el.parentNode.clientWidth / 2 ) + 'px';
				} );
			};
			document.addEventListener( 'DOMContentLoaded', measure );
			window.addEventListener( 'resize', measure );
			measure();
		}() );
		</script>
		<?php
	}

	add_action(
		'widgets_init',
		function () {
			register_widget( 'Panels_E2E_Measure_Widget' );
			register_widget( 'Panels_E2E_Wrapped_Text_Widget' );
			register_widget( 'Panels_E2E_Random_Widget' );
		}
	);
}
