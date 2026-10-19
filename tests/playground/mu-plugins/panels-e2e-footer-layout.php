<?php
/**
 * Plugin Name: Page Builder footer layout e2e helper
 * Description: Test-only must-use plugin. Prints a layout from a wp_footer callback, as a popup, off-canvas panel or theme widget area would. Never shipped (the release build excludes tests/).
 *
 * Query arguments, so parallel tests share no site state:
 * - panels_e2e_footer_layout: "widget" prints a Layout Builder widget; "nested" prints one holding another;
 *   a published post ID prints that post's layout.
 * - panels_e2e_footer_priority: the wp_footer priority to print at, "max" for PHP_INT_MAX or "max-1". Default 10.
 * - panels_e2e_footer_repeat: with the widget layout, print it again from a later wp_footer callback.
 *   "same" repeats the layout; "changed" prints other cell widths under the same layout ID.
 * - panels_e2e_repeat_priority: the priority of that repeat, or "max". Default 150.
 * - panels_e2e_discard_returned: a published post ID. At priority 30, after the footer scripts, renders
 *   that post's layout and asks for its CSS as a string, then throws both away.
 * - panels_e2e_discard_render: a published post ID. At PHP_INT_MAX, renders that post's layout and
 *   throws the HTML away. Added on load, so it runs before any print Page Builder adds at PHP_INT_MAX.
 * - panels_e2e_render_filter_inner: any value. A siteorigin_panels_render filter on the footer widget
 *   layout renders another layout with its CSS turned off, and throws it away.
 * - panels_e2e_body_layout: any value prints a Layout Builder widget on wp_body_open, after wp_head.
 * - panels_e2e_css_location: the Layout CSS Output Location setting (auto, header or footer).
 * - panels_e2e_no_widget_area: any value hides the e2e widget area, which other test files may fill,
 *   so a page can hold no Page Builder layout but the ones these arguments print.
 */

if ( isset( $_GET['panels_e2e_no_widget_area'] ) ) {
	add_filter(
		'is_active_sidebar',
		function ( $is_active, $index ) {
			return $index === 'panels-e2e-sidebar' ? false : $is_active;
		},
		10,
		2
	);
}

if ( isset( $_GET['panels_e2e_css_location'] ) ) {
	add_filter(
		'siteorigin_panels_settings',
		function ( $settings ) {
			$settings['output-css-header'] = sanitize_key( $_GET['panels_e2e_css_location'] );

			return $settings;
		}
	);
}

/**
 * A layout with one row of two cells, each holding a text widget.
 */
function panels_e2e_two_cell_layout( $weights = array( 0.5, 0.5 ) ) {
	$cell = array( 'grid' => 0, 'style' => array() );
	$text = function ( $text, $cell_index ) {
		return array(
			'text'        => $text,
			'panels_info' => array( 'class' => 'Panels_E2E_Text_Widget', 'grid' => 0, 'cell' => $cell_index, 'id' => $cell_index, 'style' => array() ),
		);
	};

	return array(
		'widgets'    => array( $text( 'Cell one', 0 ), $text( 'Cell two', 1 ) ),
		'grids'      => array( array( 'cells' => 2, 'style' => array() ) ),
		'grid_cells' => array(
			$cell + array( 'index' => 0, 'weight' => $weights[0] ),
			$cell + array( 'index' => 1, 'weight' => $weights[1] ),
		),
	);
}

/**
 * A wp_footer priority from a query argument: a number, "max" for PHP_INT_MAX or "max-1".
 */
function panels_e2e_priority( $name, $default ) {
	if ( ! isset( $_GET[ $name ] ) ) {
		return $default;
	}

	if ( $_GET[ $name ] === 'max' ) {
		return PHP_INT_MAX;
	}

	return $_GET[ $name ] === 'max-1' ? PHP_INT_MAX - 1 : (int) $_GET[ $name ];
}

/**
 * Print a Layout Builder widget, as a widget area would.
 */
function panels_e2e_print_layout_widget( $builder_id, $panels_data = null ) {
	the_widget(
		'SiteOrigin_Panels_Widgets_Layout',
		array(
			'builder_id'  => $builder_id,
			'panels_data' => $panels_data ? $panels_data : panels_e2e_two_cell_layout(),
		)
	);
}

// A layout in the page body, after wp_head, as a classic theme's widget area would print it.
if ( isset( $_GET['panels_e2e_body_layout'] ) ) {
	add_action(
		'wp_body_open',
		function () {
			echo '<div id="panels-e2e-body-layout">';
			panels_e2e_print_layout_widget( 'panelse2ebody' );
			echo '</div>';
		}
	);
}

if ( isset( $_GET['panels_e2e_footer_layout'] ) ) {
	add_action(
		'wp_footer',
		function () {
			$layout = sanitize_key( $_GET['panels_e2e_footer_layout'] );

			echo '<div id="panels-e2e-footer-layout">';

			if ( $layout === 'widget' ) {
				panels_e2e_print_layout_widget( 'panelse2efooter' );
			} elseif ( $layout === 'nested' ) {
				// One cell holding a Layout Builder widget with the two-cell layout.
				panels_e2e_print_layout_widget(
					'panelse2efooter',
					array(
						'widgets'    => array(
							array(
								'builder_id'  => 'panelse2enested',
								'panels_data' => panels_e2e_two_cell_layout(),
								'panels_info' => array( 'class' => 'SiteOrigin_Panels_Widgets_Layout', 'grid' => 0, 'cell' => 0, 'id' => 0, 'style' => array() ),
							),
						),
						'grids'      => array( array( 'cells' => 1, 'style' => array() ) ),
						'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
					)
				);
			} elseif ( get_post_status( (int) $layout ) === 'publish' ) {
				echo siteorigin_panels_render( (int) $layout );
			}

			echo '</div>';
		},
		panels_e2e_priority( 'panels_e2e_footer_priority', 10 )
	);
}

if ( isset( $_GET['panels_e2e_footer_repeat'] ) ) {
	add_action(
		'wp_footer',
		function () {
			$weights = $_GET['panels_e2e_footer_repeat'] === 'changed' ? array( 0.3, 0.7 ) : array( 0.5, 0.5 );

			echo '<div id="panels-e2e-footer-repeat">';
			panels_e2e_print_layout_widget( 'panelse2efooter', panels_e2e_two_cell_layout( $weights ) );
			echo '</div>';
		},
		panels_e2e_priority( 'panels_e2e_repeat_priority', 150 )
	);
}

if ( isset( $_GET['panels_e2e_discard_returned'] ) ) {
	add_action(
		'wp_footer',
		function () {
			$post_id = (int) $_GET['panels_e2e_discard_returned'];

			if ( get_post_status( $post_id ) === 'publish' ) {
				siteorigin_panels_render( $post_id );
				SiteOrigin_Panels::renderer()->print_inline_css( true );
			}
		},
		30
	);
}

if ( isset( $_GET['panels_e2e_discard_render'] ) ) {
	add_action(
		'wp_footer',
		function () {
			$post_id = (int) $_GET['panels_e2e_discard_render'];

			if ( get_post_status( $post_id ) === 'publish' ) {
				siteorigin_panels_render( $post_id );
			}
		},
		PHP_INT_MAX
	);
}

if ( isset( $_GET['panels_e2e_render_filter_inner'] ) ) {
	add_filter(
		'siteorigin_panels_render',
		function ( $html, $post_id ) {
			if ( $post_id === 'wpanelse2efooter' ) {
				SiteOrigin_Panels::renderer()->render( 'wpanelse2einner', false, panels_e2e_two_cell_layout() );
			}

			return $html;
		},
		10,
		2
	);
}
