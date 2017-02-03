<?php

class SiteOrigin_Panels_Renderer {

	private $inline_css;

	function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 1 );

		$this->inline_css = null;
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Add CSS that needs to go inline.
	 *
	 * @param $css
	 */
	public function add_inline_css( $post_id, $css ) {
		if ( is_null( $this->inline_css ) ) {
			$this->inline_css = array();
			add_action( 'wp_head', array( $this, 'print_inline_css' ), 12 );
			add_action( 'wp_footer', array( $this, 'print_inline_css' ) );
		}

		$this->inline_css[ $post_id ] = $css;
	}

	/**
	 * Generate the CSS for the page layout.
	 *
	 * @param $post_id
	 * @param $panels_data
	 *
	 * @return string
	 */
	function generate_css( $post_id, $panels_data = false ) {
		// Exit if we don't have panels data
		if ( empty( $panels_data ) ) {
			$panels_data = get_post_meta( $post_id, 'panels_data', true );
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post_id );
		}
		if ( empty( $panels_data ) || empty( $panels_data['grids'] ) ) {
			return;
		}

		// Get some of the default settings
		$settings                      = siteorigin_panels_setting();
		$panels_tablet_width           = $settings['tablet-width'];
		$panels_mobile_width           = $settings['mobile-width'];
		$panels_margin_bottom          = $settings['margin-bottom'];
		$panels_margin_bottom_last_row = $settings['margin-bottom-last-row'];

		$css = new SiteOrigin_Panels_Css_Builder();

		$ci = 0;
		foreach ( $panels_data['grids'] as $gi => $grid ) {

			$cell_count = intval( $grid['cells'] );

			// Add the cell sizing
			for ( $i = 0; $i < $cell_count; $i ++ ) {
				$cell = $panels_data['grid_cells'][ $ci ++ ];

				if ( $cell_count > 1 ) {
					$width = round( $cell['weight'] * 100, 3 ) . '%';
					$width = apply_filters( 'siteorigin_panels_css_cell_width', $width, $grid, $gi, $cell, $ci - 1, $panels_data, $post_id );

					// Add the width and ensure we have correct formatting for CSS.
					$css->add_cell_css( $post_id, intval( $gi ), $i, '', array(
						'width' => str_replace( ',', '.', $width )
					) );
				}
			}

			// Add the bottom margin to any grids that aren't the last
			if ( $gi != count( $panels_data['grids'] ) - 1 || ! empty( $grid['style']['bottom_margin'] ) || ! empty( $panels_margin_bottom_last_row ) ) {
				// Filter the bottom margin for this row with the arguments
				$css->add_row_css( $post_id, intval( $gi ), '', array(
					'margin-bottom' => apply_filters( 'siteorigin_panels_css_row_margin_bottom', $panels_margin_bottom . 'px', $grid, $gi, $panels_data, $post_id )
				) );
			}

			$collapse_order = ! empty( $grid['style']['collapse_order'] ) ? $grid['style']['collapse_order'] : ( ! is_rtl() ? 'left-top' : 'right-top' );

			if ( $cell_count > 1 ) {
				$css->add_cell_css( $post_id, intval( $gi ), false, '', array(
					// Float right for RTL
					'float' => $collapse_order == 'left-top' ? 'left' : 'right'
				) );
			} else {
				$css->add_cell_css( $post_id, intval( $gi ), false, '', array(
					// Float right for RTL
					'float' => 'none'
				) );
			}

			if ( $settings['responsive'] ) {

				if ( $settings['tablet-layout'] && $cell_count >= 3 && $panels_tablet_width > $panels_mobile_width ) {
					// Tablet Responsive
					$css->add_cell_css( $post_id, intval( $gi ), false, '', array(
						'width' => '50%'
					), $panels_tablet_width );
				}

				// Mobile Responsive
				$css->add_cell_css( $post_id, intval( $gi ), false, '', array(
					'float' => 'none',
					'width' => 'auto'
				), $panels_mobile_width );

				for ( $i = 0; $i < $cell_count; $i ++ ) {
					if ( ( $collapse_order == 'left-top' && $i != $cell_count - 1 ) || ( $collapse_order == 'right-top' && $i !== 0 ) ) {
						$css->add_cell_css( $post_id, intval( $gi ), $i, '', array(
							'margin-bottom' => $panels_margin_bottom . 'px',
						), $panels_mobile_width );
					}
				}
			}
		}

		// Add the bottom margins
		$css->add_cell_css( $post_id, false, false, '.so-panel', array(
			'margin-bottom' => apply_filters( 'siteorigin_panels_css_cell_margin_bottom', $panels_margin_bottom . 'px', $grid, $gi, $panels_data, $post_id )
		) );
		$css->add_cell_css( $post_id, false, false, '.so-panel:last-child', array(
			'margin-bottom' => apply_filters( 'siteorigin_panels_css_cell_last_margin_bottom', '0px', $grid, $gi, $panels_data, $post_id )
		) );

		if ( $settings['responsive'] ) {
			// Add CSS to prevent overflow on mobile resolution.
			$css->add_row_css( $post_id, false, '', array(
				'margin-left'  => 0,
				'margin-right' => 0,
			), $panels_mobile_width );

			$css->add_cell_css( $post_id, false, false, '', array(
				'padding' => 0,
			), $panels_mobile_width );

			// Hide empty cells on mobile
			$css->add_row_css( $post_id, false, '.panel-grid-cell-empty', array(
				'display' => 'none',
			), $panels_mobile_width );

			// Hide empty cells on mobile
			$css->add_row_css( $post_id, false, '.panel-grid-cell-mobile-last', array(
				'margin-bottom' => '0px',
			), $panels_mobile_width );
		}

		// Let other plugins customize various aspects of the rows (grids)
		foreach ( $panels_data['grids'] as $gi => $grid ) {
			// Let other themes and plugins change the gutter.
			$gutter = apply_filters( 'siteorigin_panels_css_row_gutter', $settings['margin-sides'] . 'px', $grid, $gi, $panels_data );

			if ( ! empty( $gutter ) ) {
				// We actually need to find half the gutter.
				preg_match( '/([0-9\.,]+)(.*)/', $gutter, $match );
				if ( ! empty( $match[1] ) ) {
					$margin_half = ( floatval( $match[1] ) / 2 ) . $match[2];
					$css->add_row_css( $post_id, intval( $gi ), '', array(
						'margin-left'  => '-' . $margin_half,
						'margin-right' => '-' . $margin_half,
					) );
					$css->add_cell_css( $post_id, intval( $gi ), false, '', array(
						'padding-left'  => $margin_half,
						'padding-right' => $margin_half,
					) );

				}
			}
		}

		foreach ( $panels_data['widgets'] as $widget_id => $widget ) {
			if ( ! empty( $widget['panels_info']['style']['link_color'] ) ) {
				$selector = '#panel-' . $post_id . '-' . $widget['panels_info']['grid'] . '-' . $widget['panels_info']['cell'] . '-' . $widget['panels_info']['cell_index'] . ' a';
				$css->add_css( $selector, array(
					'color' => $widget['panels_info']['style']['link_color']
				) );
			}
		}

		// Let other plugins and components filter the CSS object.
		$css = apply_filters( 'siteorigin_panels_css_object', $css, $panels_data, $post_id );

		return $css->get_css();
	}

	/**
	 * Render the panels
	 *
	 * @param int|string|bool $post_id The Post ID or 'home'.
	 * @param bool $enqueue_css Should we also enqueue the layout CSS.
	 * @param array|bool $panels_data Existing panels data. By default load from settings or post meta.
	 *
	 * @return string
	 */
	function render( $post_id = false, $enqueue_css = true, $panels_data = false ) {
		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();
		}

		global $siteorigin_panels_current_post;
		$old_current_post               = $siteorigin_panels_current_post;
		$siteorigin_panels_current_post = $post_id;

		// Try get the cached panel from in memory cache.
		global $siteorigin_panels_cache;
		if ( ! empty( $siteorigin_panels_cache ) && ! empty( $siteorigin_panels_cache[ $post_id ] ) ) {
			return $siteorigin_panels_cache[ $post_id ];
		}

		if ( empty( $panels_data ) ) {
			if ( strpos( $post_id, 'prebuilt:' ) === 0 ) {
				list( $null, $prebuilt_id ) = explode( ':', $post_id, 2 );
				$layouts     = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
				$panels_data = ! empty( $layouts[ $prebuilt_id ] ) ? $layouts[ $prebuilt_id ] : array();
			} else if ( $post_id == 'home' ) {
				$page_id = get_option( 'page_on_front' );
				if ( empty( $page_id ) ) {
					$page_id = get_option( 'siteorigin_panels_home_page_id' );
				}

				$panels_data = ! empty( $page_id ) ? get_post_meta( $page_id, 'panels_data', true ) : null;

				if ( is_null( $panels_data ) ) {
					// Load the default layout
					$layouts     = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
					$prebuilt_id = siteorigin_panels_setting( 'home-page-default' ) ? siteorigin_panels_setting( 'home-page-default' ) : 'home';

					$panels_data = ! empty( $layouts[ $prebuilt_id ] ) ? $layouts[ $prebuilt_id ] : current( $layouts );
				}
			} else {
				if ( post_password_required( $post_id ) ) {
					return false;
				}
				$panels_data = get_post_meta( $post_id, 'panels_data', true );
			}
		}

		$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post_id );
		if ( empty( $panels_data ) || empty( $panels_data['grids'] ) ) {
			return '';
		}

		// Filter the widgets to add indexes
		if ( ! empty( $panels_data['widgets'] ) ) {
			$last_gi = 0;
			$last_ci = 0;
			$last_wi = 0;
			foreach ( $panels_data['widgets'] as $wid => &$widget_info ) {

				if ( $widget_info['panels_info']['grid'] != $last_gi ) {
					$last_gi = $widget_info['panels_info']['grid'];
					$last_ci = 0;
					$last_wi = 0;
				} elseif ( $widget_info['panels_info']['cell'] != $last_ci ) {
					$last_ci = $widget_info['panels_info']['cell'];
					$last_wi = 0;
				}
				$widget_info['panels_info']['cell_index'] = $last_wi ++;
			}
		}

		// Create the skeleton of the grids
		$grids = array();
		if ( ! empty( $panels_data['grids'] ) && ! empty( $panels_data['grids'] ) ) {
			foreach ( $panels_data['grids'] as $gi => $grid ) {
				$gi           = intval( $gi );
				$grids[ $gi ] = array();
				for ( $i = 0; $i < $grid['cells']; $i ++ ) {
					$grids[ $gi ][ $i ] = array();
				}
			}
		}

		// We need this to migrate from the old $panels_data that put widget meta into the "info" key instead of "panels_info"
		if ( ! empty( $panels_data['widgets'] ) && is_array( $panels_data['widgets'] ) ) {
			foreach ( $panels_data['widgets'] as $i => $widget ) {
				if ( empty( $panels_data['widgets'][ $i ]['panels_info'] ) ) {
					$panels_data['widgets'][ $i ]['panels_info'] = $panels_data['widgets'][ $i ]['info'];
					unset( $panels_data['widgets'][ $i ]['info'] );
				}

				$panels_data['widgets'][ $i ]['panels_info']['widget_index'] = $i;
			}
		}

		if ( ! empty( $panels_data['widgets'] ) && is_array( $panels_data['widgets'] ) ) {
			foreach ( $panels_data['widgets'] as $widget ) {
				// Put the widgets in the grids
				$grids[ intval( $widget['panels_info']['grid'] ) ][ intval( $widget['panels_info']['cell'] ) ][] = $widget;
			}
		}

		ob_start();

		// Add the panel layout wrapper
		$panel_layout_classes    = apply_filters( 'siteorigin_panels_layout_classes', array(), $post_id, $panels_data );
		$panel_layout_attributes = apply_filters( 'siteorigin_panels_layout_attributes', array(
			'class' => implode( ' ', $panel_layout_classes ),
			'id'    => 'pl-' . $post_id
		), $post_id, $panels_data );
		echo '<div';
		foreach ( $panel_layout_attributes as $name => $value ) {
			if ( $value ) {
				echo ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		echo '>';

		if ( $enqueue_css && ! isset( $this->inline_css[ $post_id ] ) ) {
			wp_enqueue_style( 'siteorigin-panels-front' );
			$this->add_inline_css( $post_id, $this->generate_css( $post_id, $panels_data ) );
		}

		echo apply_filters( 'siteorigin_panels_before_content', '', $panels_data, $post_id );

		foreach ( $grids as $gi => $cells ) {

			$grid_classes = apply_filters( 'siteorigin_panels_row_classes', array( 'panel-grid' ), $panels_data['grids'][ $gi ] );

			$grid_attributes = apply_filters( 'siteorigin_panels_row_attributes', array(
				'class' => implode( ' ', $grid_classes ),
				'id'    => 'pg-' . $post_id . '-' . $gi,
			), $panels_data['grids'][ $gi ] );

			// This allows other themes and plugins to add html before the row
			echo apply_filters( 'siteorigin_panels_before_row', '', $panels_data['grids'][ $gi ], $grid_attributes );

			echo '<div ';
			foreach ( $grid_attributes as $name => $value ) {
				echo $name . '="' . esc_attr( $value ) . '" ';
			}
			echo '>';

			$style_attributes = array();
			if ( ! empty( $panels_data['grids'][ $gi ]['style']['class'] ) ) {
				$style_attributes['class'] = array( 'panel-row-style-' . $panels_data['grids'][ $gi ]['style']['class'] );
			}

			// Themes can add their own attributes to the style wrapper
			$row_style_wrapper = $this->start_style_wrapper( 'row', $style_attributes, ! empty( $panels_data['grids'][ $gi ]['style'] ) ? $panels_data['grids'][ $gi ]['style'] : array() );
			if ( ! empty( $row_style_wrapper ) ) {
				echo $row_style_wrapper;
			}

			$collapse_order = ! empty( $panels_data['grids'][ $gi ]['style']['collapse_order'] ) ? $panels_data['grids'][ $gi ]['style']['collapse_order'] : ( ! is_rtl() ? 'left-top' : 'right-top' );

			if ( $collapse_order == 'right-top' ) {
				$cells = array_reverse( $cells, true );
			}

			foreach ( $cells as $ci => $widgets ) {
				$cell_classes = array( 'panel-grid-cell' );
				if ( empty( $widgets ) ) {
					$cell_classes[] = 'panel-grid-cell-empty';
				}
				if ( $ci == count( $cells ) - 2 && count( $cells[ $ci + 1 ] ) == 0 ) {
					$cell_classes[] = 'panel-grid-cell-mobile-last';
				}
				// Themes can add their own styles to cells
				$cell_classes    = apply_filters( 'siteorigin_panels_row_cell_classes', $cell_classes, $panels_data );
				$cell_attributes = apply_filters( 'siteorigin_panels_row_cell_attributes', array(
					'class' => implode( ' ', $cell_classes ),
					'id'    => 'pgc-' . $post_id . '-' . $gi . '-' . $ci
				), $panels_data );

				echo '<div ';
				foreach ( $cell_attributes as $name => $value ) {
					echo $name . '="' . esc_attr( $value ) . '" ';
				}
				echo '>';

				$cell_style_wrapper = $this->start_style_wrapper( 'cell', array(), ! empty( $panels_data['grids'][ $gi ]['style'] ) ? $panels_data['grids'][ $gi ]['style'] : array() );
				if ( ! empty( $cell_style_wrapper ) ) {
					echo $cell_style_wrapper;
				}

				foreach ( $widgets as $pi => $widget_info ) {
					// TODO this wrapper should go in the before/after widget arguments
					$widget_style_wrapper = $this->start_style_wrapper( 'widget', array(), ! empty( $widget_info['panels_info']['style'] ) ? $widget_info['panels_info']['style'] : array() );
					$this->the_widget( $widget_info['panels_info'], $widget_info, $gi, $ci, $pi, $pi == 0, $pi == count( $widgets ) - 1, $post_id, $widget_style_wrapper );
				}

				if ( ! empty( $cell_style_wrapper ) ) {
					echo '</div>';
				}
				echo '</div>';
			}

			echo '</div>';

			// Close the
			if ( ! empty( $row_style_wrapper ) ) {
				echo '</div>';
			}

			// This allows other themes and plugins to add html after the row
			echo apply_filters( 'siteorigin_panels_after_row', '', $panels_data['grids'][ $gi ], $grid_attributes );
		}

		echo apply_filters( 'siteorigin_panels_after_content', '', $panels_data, $post_id );

		echo '</div>';

		do_action( 'siteorigin_panels_after_render', $panels_data, $post_id );

		$html = ob_get_clean();

		// Reset the current post
		$siteorigin_panels_current_post = $old_current_post;

		return apply_filters( 'siteorigin_panels_render', $html, $post_id, ! empty( $post ) ? $post : null );
	}

	/**
	 * Echo the style wrapper and return if there was a wrapper
	 *
	 * @param $name
	 * @param $style_attributes
	 * @param array $style_args
	 *
	 * @return bool Is there a style wrapper
	 */
	function start_style_wrapper( $name, $style_attributes, $style_args = array() ) {

		$style_wrapper = '';

		if ( empty( $style_attributes['class'] ) ) {
			$style_attributes['class'] = array();
		}
		if ( empty( $style_attributes['style'] ) ) {
			$style_attributes['style'] = '';
		}

		$style_attributes = apply_filters( 'siteorigin_panels_' . $name . '_style_attributes', $style_attributes, $style_args );

		if ( empty( $style_attributes['class'] ) ) {
			unset( $style_attributes['class'] );
		}
		if ( empty( $style_attributes['style'] ) ) {
			unset( $style_attributes['style'] );
		}

		if ( ! empty( $style_attributes ) ) {
			if ( empty( $style_attributes['class'] ) ) {
				$style_attributes['class'] = array();
			}
			$style_attributes['class'][] = 'panel-' . $name . '-style';
			$style_attributes['class']   = array_unique( $style_attributes['class'] );

			// Filter and sanitize the classes
			$style_attributes['class'] = apply_filters( 'siteorigin_panels_' . $name . '_style_classes', $style_attributes['class'], $style_attributes, $style_args );
			$style_attributes['class'] = array_map( 'sanitize_html_class', $style_attributes['class'] );

			$style_wrapper = '<div ';
			foreach ( $style_attributes as $name => $value ) {
				if ( is_array( $value ) ) {
					$style_wrapper .= $name . '="' . esc_attr( implode( " ", array_unique( $value ) ) ) . '" ';
				} else {
					$style_wrapper .= $name . '="' . esc_attr( $value ) . '" ';
				}
			}
			$style_wrapper .= '>';

			return $style_wrapper;
		}

		return $style_wrapper;
	}

	/**
	 * Render the widget.
	 *
	 * @param array $widget_info The widget info.
	 * @param array $instance The widget instance
	 * @param int $grid The grid number.
	 * @param int $cell The cell number.
	 * @param int $panel the panel number.
	 * @param bool $is_first Is this the first widget in the cell.
	 * @param bool $is_last Is this the last widget in the cell.
	 * @param bool $post_id
	 * @param string $style_wrapper The start of the style wrapper
	 */
	function the_widget( $widget_info, $instance, $grid, $cell, $panel, $is_first, $is_last, $post_id = false, $style_wrapper = '' ) {

		global $wp_widget_factory;

		// Set widget class to $widget
		$widget = $widget_info['class'];

		// Load the widget from the widget factory and give themes and plugins a chance to provide their own
		$the_widget = ! empty( $wp_widget_factory->widgets[ $widget ] ) ? $wp_widget_factory->widgets[ $widget ] : false;
		$the_widget = apply_filters( 'siteorigin_panels_widget_object', $the_widget, $widget, $instance );

		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();
		}

		$classes = array( 'so-panel' );
		if ( siteorigin_panels_setting( 'add-widget-class' ) ) {
			$classes[] = 'widget';
		}
		if ( ! empty( $the_widget ) && ! empty( $the_widget->id_base ) ) {
			$classes[] = 'widget_' . $the_widget->id_base;
		}
		if ( ! empty( $the_widget ) && is_array( $the_widget->widget_options ) && ! empty( $the_widget->widget_options['classname'] ) ) {
			$classes[] = $the_widget->widget_options['classname'];
		}
		if ( $is_first ) {
			$classes[] = 'panel-first-child';
		}
		if ( $is_last ) {
			$classes[] = 'panel-last-child';
		}
		$id = 'panel-' . $post_id . '-' . $grid . '-' . $cell . '-' . $panel;

		// Filter and sanitize the classes
		$classes = apply_filters( 'siteorigin_panels_widget_classes', $classes, $widget, $instance, $widget_info );
		$classes = explode( ' ', implode( ' ', $classes ) );
		$classes = array_filter( $classes );
		$classes = array_unique( $classes );
		$classes = array_map( 'sanitize_html_class', $classes );

		$title_html = siteorigin_panels_setting( 'title-html' );
		if ( strpos( $title_html, '{{title}}' ) !== false ) {
			list( $before_title, $after_title ) = explode( '{{title}}', $title_html, 2 );
		} else {
			$before_title = '<h3 class="widget-title">';
			$after_title  = '</h3>';
		}

		$args = array(
			'before_widget' => '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="' . $id . '" data-index="' . $widget_info['widget_index'] . '">',
			'after_widget'  => '</div>',
			'before_title'  => $before_title,
			'after_title'   => $after_title,
			'widget_id'     => 'widget-' . $grid . '-' . $cell . '-' . $panel
		);

		// Let other themes and plugins change the arguments that go to the widget class.
		$args = apply_filters( 'siteorigin_panels_widget_args', $args );

		// If there is a style wrapper, add it.
		if ( ! empty( $style_wrapper ) ) {
			$args['before_widget'] = $args['before_widget'] . $style_wrapper;
			$args['after_widget']  = '</div>' . $args['after_widget'];
		}

		if ( ! empty( $the_widget ) && is_a( $the_widget, 'WP_Widget' ) ) {
			$the_widget->widget( $args, $instance );
		} else {
			// This gives themes a chance to display some sort of placeholder for missing widgets
			echo apply_filters( 'siteorigin_panels_missing_widget', $args['before_widget'] . $args['after_widget'], $widget, $args, $instance );
		}
	}

	/**
	 * Print inline CSS in the header and footer.
	 */
	function print_inline_css() {
		if ( ! empty( $this->inline_css ) ) {
			$the_css = '';
			foreach ( $this->inline_css as $post_id => $css ) {
				if ( empty( $css ) ) {
					continue;
				}
				$the_css .= '/* Layout ' . esc_attr( $post_id ) . ' */ ';
				$the_css .= $css;
			}

			// Reset the inline CSS
			$this->inline_css = null;

			switch ( current_filter() ) {
				case 'wp_head' :
					$css_id = 'head';
					break;

				case 'wp_footer' :
					$css_id = 'footer';
					break;

				default :
					$css_id = sanitize_html_class( current_filter() );
					break;
			}

			if ( ! empty( $the_css ) ) {
				?>
				<style type="text/css" media="all"
				       id="siteorigin-panels-grids-<?php echo esc_attr( $css_id ) ?>"><?php echo $the_css ?></style><?php
			}
		}
	}

	/**
	 * Enqueue the required styles
	 */
	function enqueue_styles() {
		// Register the style to support possible lazy loading
		wp_register_style( 'siteorigin-panels-front', plugin_dir_url( __FILE__ ) . '../css/front.css', array(), SITEORIGIN_PANELS_VERSION );

		if ( is_singular() && get_post_meta( get_the_ID(), true ) != '' ) {
			wp_enqueue_style( 'siteorigin-panels-front' );
			$this->add_inline_css( get_the_ID(), $this->generate_css( get_the_ID() ) );
		}
	}
}
