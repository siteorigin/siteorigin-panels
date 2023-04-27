<?php

class SiteOrigin_Panels_Renderer {
	private $inline_css;
	private $container;

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 1 );
		$this->inline_css = null;
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Add CSS that needs to go inline.
	 */
	public function add_inline_css( $post_id, $css ) {
		if ( is_null( $this->inline_css ) ) {
			// Initialize the inline CSS array and add actions to handle printing.
			$this->inline_css = array();
			$css_output_set = false;
			$output_css = siteorigin_panels_setting( 'output-css-header' );

			if ( is_admin() || SiteOrigin_Panels_Admin::is_block_editor() || $output_css == 'auto' ) {
				add_action( 'wp_head', array( $this, 'print_inline_css' ), 12 );
				add_action( 'wp_footer', array( $this, 'print_inline_css' ) );
				$css_output_set = true;
			}

			// The CSS can only be output in the header if the page is powered by the Classic Editor.
			// $post_id won't be a number if the current page is powered by the Block Editor.
			if ( ! $css_output_set && $output_css == 'header' && is_numeric( $post_id ) ) {
				add_action( 'wp_head', array( $this, 'print_inline_css' ), 12 );
				$css_output_set = true;
			}

			if ( ! $css_output_set ) {
				add_action( 'wp_footer', array( $this, 'print_inline_css' ) );
			}
		}

		$this->inline_css[ $post_id ] = $css;

		// Enqueue the front styles, if they haven't already been enqueued
		if ( ! wp_style_is( 'siteorigin-panels-front', 'enqueued' ) ) {
			wp_enqueue_style( 'siteorigin-panels-front' );
		}
	}

	/**
	 * Generate the CSS for the page layout.
	 *
	 * @return string
	 */
	public function generate_css( $post_id, $panels_data = false, $layout_data = false ) {
		// Exit if we don't have panels data
		if ( empty( $panels_data ) ) {
			$panels_data = get_post_meta( $post_id, 'panels_data', true );
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post_id );

			if ( empty( $panels_data ) ) {
				return '';
			}
		}

		if ( empty( $layout_data ) ) {
			$layout_data = $this->get_panels_layout_data( $panels_data );
			$layout_data = apply_filters( 'siteorigin_panels_layout_data', $layout_data, $post_id );
		}

		if ( empty( $this->container ) ) {
			$this->container = SiteOrigin_Panels::container_settings();
		}

		// Get some of the default settings
		$settings = siteorigin_panels_setting();
		$panels_tablet_width = $settings['tablet-width'];
		$panels_mobile_width = $settings['mobile-width'];
		$panels_margin_bottom_last_row = $settings['margin-bottom-last-row'];

		$css = new SiteOrigin_Panels_Css_Builder();

		$ci = 0;

		foreach ( $layout_data as $ri => $row ) {
			// Filter the bottom margin for this row with the arguments
			$panels_margin_bottom = apply_filters( 'siteorigin_panels_css_row_margin_bottom', $settings['margin-bottom'] . 'px', $row, $ri, $panels_data, $post_id );
			$panels_mobile_margin_bottom = apply_filters( 'siteorigin_panels_css_row_mobile_margin_bottom', $settings['row-mobile-margin-bottom'] . 'px', $row, $ri, $panels_data, $post_id );

			if ( empty( $row['cells'] ) ) {
				continue;
			}

			// Let other themes and plugins change the gutter.
			$gutter = apply_filters( 'siteorigin_panels_css_row_gutter', $settings['margin-sides'] . 'px', $row, $ri, $panels_data );
			preg_match( '/([0-9\.,]+)(.*)/', $gutter, $gutter_parts );

			$cell_count = count( $row['cells'] );

			// If the CSS Container Breaker is enabled, and this row is using it,
			// we need to remove the cell widths on mobile.
			$css_container_cutoff = $this->container['css_override'] && isset( $row['style']['row_stretch'] ) && $row['style']['row_stretch'] == 'full' ? ':' . ( $panels_mobile_width + 1 ) : 1920;

			if (
				$this->container['css_override'] &&
				! $this->container['full_width'] &&
				! empty( $row['style'] ) &&
				! empty( $row['style']['row_stretch'] ) &&
				 (
				 	$row['style']['row_stretch'] == 'full' ||
				 	$row['style']['row_stretch'] == 'full-stretched' ||
				 	$row['style']['row_stretch'] == 'full-stretched-padded'
				 )
			) {
				$this->container['full_width'] = true;
			}

			// Add the cell sizing
			foreach ( $row['cells'] as $ci => $cell ) {
				$weight = apply_filters( 'siteorigin_panels_css_cell_weight', $cell['weight'], $row, $ri, $cell, $ci - 1, $panels_data, $post_id );
				$rounded_width = round( $weight * 100, 4 ) . '%';
				$calc_width = 'calc(' . $rounded_width . ' - ( ' . ( 1 - $weight ) . ' * ' . $gutter . ' ) )';

				// Add the width and ensure we have correct formatting for CSS.
				$css->add_cell_css( $post_id, $ri, $ci, '', array(
					'width' => array(
						// For some locales PHP uses ',' for decimal separation.
						// This seems to happen when a plugin calls `setlocale(LC_ALL, 'de_DE');` or `setlocale(LC_NUMERIC, 'de_DE');`
						// This should prevent issues with column sizes in these cases.
						str_replace( ',', '.', $rounded_width ),
						str_replace( ',', '.', (int) $gutter ? $calc_width : '' ), // Exclude if there's a zero gutter
					),
				), $css_container_cutoff );

				// Add in any widget specific CSS
				foreach ( $cell['widgets'] as $wi => $widget ) {
					$widget_style_data = ! empty( $widget['panels_info']['style'] ) ? $widget['panels_info']['style'] : array();
					$widget_css = apply_filters(
						'siteorigin_panels_css_widget_css',
						array(),
						$widget_style_data,
						$row,
						$ri,
						$cell,
						$ci - 1,
						$widget,
						$wi,
						$panels_data,
						$post_id
					);

					$css->add_widget_css(
						$post_id,
						$ri,
						$ci,
						$wi,
						'',
						$widget_css,
						1920,
						true
					);

					$panels_mobile_widget_mobile_margin = apply_filters(
						'siteorigin_panels_css_widget_mobile_margin',
						! empty( $widget['panels_info']['style']['mobile_margin'] ) ? $widget['panels_info']['style']['mobile_margin'] : false,
						$widget,
						$wi,
						$panels_data,
						$post_id
					);

					if ( empty( $panels_mobile_widget_mobile_margin ) && ! empty( $settings['widget-mobile-margin-bottom'] ) ) {
						$panels_mobile_widget_mobile_margin = '0 0 ' . $settings[ 'widget-mobile-margin-bottom'] . 'px';
					}

					if ( ! empty( $panels_mobile_widget_mobile_margin ) ) {
						$css->add_widget_css(
							$post_id,
							$ri,
							$ci,
							$wi,
							'',
							array(
								'margin' => $panels_mobile_widget_mobile_margin . ( siteorigin_panels_setting( 'inline-styles' ) ? ' !important' : '' ),
							),
							$panels_mobile_width,
							true
						);
					}
				}
			}

			if ( ! siteorigin_panels_setting( 'inline-styles' ) ) {
				if (
					$ri != count( $layout_data ) - 1 ||
					! empty( $row['style']['bottom_margin'] ) ||
					! empty( $panels_margin_bottom_last_row )
				) {
					$css->add_row_css( $post_id, $ri, '', array(
						'margin-bottom' => $panels_margin_bottom,
					) );
				}
			}

			$collapse_order = ! empty( $row['style']['collapse_order'] ) ? $row['style']['collapse_order'] : ( ! is_rtl() ? 'left-top' : 'right-top' );

			// Let other themes and plugins change the row collapse point.
			$collapse_point = apply_filters( 'siteorigin_panels_css_row_collapse_point', '', $row, $ri, $panels_data );

			if ( $settings['responsive'] && empty( $row['style']['collapse_behaviour'] ) ) {
				// The default collapse behaviour
				if (
					$settings['tablet-layout'] &&
					$cell_count >= 3 &&
					$panels_tablet_width > $panels_mobile_width &&
					empty( $collapse_point )
				) {
					// Tablet responsive css for the row

					$css->add_row_css( $post_id, $ri, array(
						'.panel-no-style',
						'.panel-has-style > .panel-row-style',
					), array(
						'-ms-flex-wrap'     => $collapse_order == 'left-top' ? 'wrap' : 'wrap-reverse',
						'-webkit-flex-wrap' => $collapse_order == 'left-top' ? 'wrap' : 'wrap-reverse',
						'flex-wrap'         => $collapse_order == 'left-top' ? 'wrap' : 'wrap-reverse',
					), $panels_tablet_width . ':' . ( $panels_mobile_width + 1 ) );

					$css->add_cell_css( $post_id, $ri, false, '', array(
						'-ms-flex'      => '0 1 50%',
						'-webkit-flex'  => '0 1 50%',
						'flex'          => '0 1 50%',
						'margin-right'  => '0',
						'margin-bottom' => $panels_margin_bottom,
					), $panels_tablet_width . ':' . ( $panels_mobile_width + 1 ) );

					$remove_bottom_margin = ':nth-';

					if ( $collapse_order == 'left-top' ) {
						$remove_bottom_margin .= 'last-child(' . ( count( $row['cells'] ) % 2 == 0 ? '-n+2' : '1' ) . ')';
					} else {
						$remove_bottom_margin .= 'child(-n+2)';
					}

					if ( ! empty( $gutter_parts[1] ) ) {
						// Tablet responsive css for cells

						$css->add_cell_css( $post_id, $ri, false, ':nth-child(even)', array(
							'padding-left' => ( (float) $gutter_parts[1] / 2 . $gutter_parts[2] ),
						), $panels_tablet_width . ':' . ( $panels_mobile_width + 1 ) );

						$css->add_cell_css( $post_id, $ri, false, ':nth-child(odd)', array(
							'padding-right' => ( (float) $gutter_parts[1] / 2 . $gutter_parts[2] ),
						), $panels_tablet_width . ':' . ( $panels_mobile_width + 1 ) );
					}
				}

				// Mobile Responsive
				$collapse_point = ! empty( $collapse_point ) ? $collapse_point : $panels_mobile_width;
				// Uses rows custom collapse point or sets mobile collapse point set on settings page.
				$css->add_row_css( $post_id, $ri, array(
					'.panel-no-style',
					'.panel-has-style > .panel-row-style',
					// When CSS override is enabled, a full width row has a special wrapper so need to account for that.
					$this->container['css_override'] && isset( $row['style']['row_stretch'] ) && $row['style']['row_stretch'] == 'full' ? ' .so-panels-full-wrapper' : '',
				), array(
					'-webkit-flex-direction' => $collapse_order == 'left-top' ? 'column' : 'column-reverse',
					'-ms-flex-direction'     => $collapse_order == 'left-top' ? 'column' : 'column-reverse',
					'flex-direction'         => $collapse_order == 'left-top' ? 'column' : 'column-reverse',
				), $collapse_point );

				// Uses rows custom collapse point or sets mobile collapse point set on settings page.
				$css->add_cell_css( $post_id, $ri, false, '', array(
					'width' => '100%',
					'margin-right' => 0,
				), $collapse_point );

				foreach ( $row['cells'] as $ci => $cell ) {
					if ( ( $collapse_order == 'left-top' && $ci != $cell_count - 1 ) || ( $collapse_order == 'right-top' && $ci !== 0 ) ) {
						$css->add_cell_css( $post_id, $ri, $ci, '', array(
							'margin-bottom' => apply_filters(
								'siteorigin_panels_css_cell_mobile_margin_bottom',
								$settings['mobile-cell-margin'] . 'px',
								$cell,
								$ci,
								$row,
								$ri,
								$panels_data,
								$post_id
							),
						), $collapse_point );
					}
				}

				if (
					$settings['tablet-layout'] &&
					$panels_tablet_width > $collapse_point &&
					! empty( $row['style']['tablet_bottom_margin'] )
				) {
					$css->add_row_css( $post_id, $ri, '', array(
						'margin-bottom' => $row['style']['tablet_bottom_margin'],
					), "$panels_tablet_width:$collapse_point" );
				}

				if ( $panels_mobile_margin_bottom != $panels_margin_bottom && ! empty( $panels_mobile_margin_bottom ) ) {
					$css->add_row_css( $post_id, $ri, '', array(
						'margin-bottom' => $panels_mobile_margin_bottom,
					), $collapse_point );
				}
			} // End of responsive code
		}

		// Add the bottom margins.
		if ( ! siteorigin_panels_setting( 'inline-styles' ) ) {
			$css->add_widget_css( $post_id, false, false, false, '', array(
				'margin-bottom' => apply_filters( 'siteorigin_panels_css_cell_margin_bottom', $settings['margin-bottom'] . 'px', false, false, $panels_data, $post_id ),
			) );
			$css->add_widget_css( $post_id, false, false, false, ':last-of-type', array(
				'margin-bottom' => apply_filters( 'siteorigin_panels_css_cell_last_margin_bottom', '0px', false, false, $panels_data, $post_id ),
			) );
		}

		if ( $settings['responsive'] ) {
			$css->add_cell_css( $post_id, false, false, '', array(
				'padding' => 0,
			), $panels_mobile_width );

			// Hide empty cells on mobile
			$css->add_row_css( $post_id, false, ' .panel-grid-cell-empty', array(
				'display' => 'none',
			), $panels_mobile_width );

			// Hide empty cells on mobile
			$css->add_row_css( $post_id, false, ' .panel-grid-cell-mobile-last', array(
				'margin-bottom' => '0px',
			), $panels_mobile_width );
		}

		// Do we need to remove the theme container on this page?
		if (
			$this->container['css_override'] &&
			$this->container['full_width'] && // Does this layout have full width layouts?
			! defined( 'siteorigin_css_override' )
		) {
			// Prevent this CSS from being added again.
			define( 'siteorigin_css_override', true );

			$css->add_css(
				esc_html( $this->container['selector'] ),
				array(
					'max-width' => 'none',
					// Clear horizontal spacing from container to prevent any indents.
					'padding-right' => '0',
					'padding-left' => '0',
					'margin-right' => '0',
					'margin-left' => '0',
				),
				1920
			);

			$css->add_css(
				'.so-panels-full-wrapper, .panel-grid.panel-no-style, .panel-row-style:not([data-stretch-type])',
				array(
					'max-width' => esc_attr( $this->container['width'] ),
					'margin' => '0 auto',
				),
				1920
			);

			// Allow .so-panels-full-wrapper to handle columns correctly.
			$css->add_css(
				'.so-panels-full-wrapper',
				array(
					'display' => 'flex',
					'flex-wrap' => 'nowrap',
					'justify-content' => 'space-between',
					'align-items' => 'flex-start',
					'width' => '100%',
				),
				1920
			);

			// Ensure cells inside of .so-panels-full-wrapper are full width when collapsed.
			$css->add_css(
				'.so-panels-full-wrapper .panel-grid-cell',
				array(
					'width' => '100%',
				),
				siteorigin_panels_setting( 'mobile-width' )
			);
		}

		// Let other plugins and components filter the CSS object.
		$css = apply_filters( 'siteorigin_panels_css_object', $css, $panels_data, $post_id, $layout_data );

		return $css->get_css();
	}

	/**
	 * Render the panels.
	 *
	 * @param int|string|bool $post_id     The Post ID or 'home'.
	 * @param bool            $enqueue_css Should we also enqueue the layout CSS.
	 * @param array|bool      $panels_data Existing panels data. By default load from settings or post meta.
	 * @param array           $layout_data Reformatted panels_data that includes data about the render.
	 *
	 * @return string
	 */
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, & $layout_data = array(), $is_preview = false ) {
		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();

			if ( class_exists( 'WooCommerce' ) && is_shop() ) {
				$post_id = wc_get_page_id( 'shop' );
			}
		}

		global $siteorigin_panels_current_post;
		// If $panels_data is empty, and the current post being processed is the same as the last one, don't process it.
		if (
			empty( $panels_data ) &&
			! empty( $siteorigin_panels_current_post ) &&
			apply_filters( 'siteorigin_panels_renderer_current_post_check', true ) &&
			$siteorigin_panels_current_post == $post_id
		) {
			trigger_error( __( 'Prevented SiteOrigin layout from repeated rendering.', 'siteorigin-panels' ) );

			return;
		}

		$old_current_post = $siteorigin_panels_current_post;
		$siteorigin_panels_current_post = $post_id;

		// Try get the cached panel from in memory cache.
		global $siteorigin_panels_cache;

		if ( ! empty( $siteorigin_panels_cache ) && ! empty( $siteorigin_panels_cache[ $post_id ] ) ) {
			return $siteorigin_panels_cache[ $post_id ];
		}

		if ( empty( $panels_data ) ) {
			$panels_data = $this->get_panels_data_for_post( $post_id );

			if ( $panels_data === false ) {
				return false;
			}
		} elseif ( is_string( $panels_data ) ) {
			// If $panels_data is a string, it's likely json, try decoding it.
			$panels_data = json_decode( $panels_data, true );
		}

		$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post_id );

		if ( empty( $panels_data ) || empty( $panels_data['grids'] ) ) {
			return '';
		}

		if ( empty( $this->container ) ) {
			$this->container = SiteOrigin_Panels::container_settings();
		}

		if ( $is_preview ) {
			$GLOBALS[ 'SITEORIGIN_PANELS_PREVIEW_RENDER' ] = true;
		}

		if ( empty( $layout_data ) ) {
			$layout_data = $this->get_panels_layout_data( $panels_data );
			$layout_data = apply_filters( 'siteorigin_panels_layout_data', $layout_data, $post_id );
		}

		ob_start();

		// Add the panel layout wrapper
		$layout_classes = apply_filters( 'siteorigin_panels_layout_classes', array( 'panel-layout' ), $post_id, $panels_data );

		if ( is_rtl() ) {
			$layout_classes[] = 'panel-is-rtl';
		}
		$layout_attributes = apply_filters( 'siteorigin_panels_layout_attributes', array(
			'id'    => 'pl-' . $post_id,
			'class' => implode( ' ', $layout_classes ),
		), $post_id, $panels_data );

		$this->render_element( 'div', $layout_attributes );

		echo apply_filters( 'siteorigin_panels_before_content', '', $panels_data, $post_id );

		foreach ( $layout_data as $ri => & $row ) {
			if ( apply_filters( 'siteorigin_panels_output_row', true, $row, $ri, $panels_data, $post_id ) ) {
				$this->render_row( $post_id, $ri, $row, $panels_data );
			}
		}

		echo apply_filters( 'siteorigin_panels_after_content', '', $panels_data, $post_id );

		echo '</div>';

		do_action( 'siteorigin_panels_after_render', $panels_data, $post_id );

		$html = ob_get_clean();

		if ( $enqueue_css && ! isset( $this->inline_css[ $post_id ] ) ) {
			wp_enqueue_style( 'siteorigin-panels-front' );
			$this->add_inline_css( $post_id, $this->generate_css( $post_id, $panels_data, $layout_data ) );
		}

		// Reset the current post
		$siteorigin_panels_current_post = $old_current_post;

		$rendered_layout = apply_filters( 'siteorigin_panels_render', $html, $post_id, ! empty( $post ) ? $post : null );

		if ( $is_preview ) {
			$widget_css = '@import url(' . SiteOrigin_Panels::front_css_url() . '); ';
			$widget_css .= SiteOrigin_Panels::renderer()->generate_css( $post_id, $panels_data, $layout_data );
			$widget_css = preg_replace( '/\s+/', ' ', $widget_css );
			$type_attr = current_theme_supports( 'html5', 'style' ) ? '' : ' type="text/css"';
			$rendered_layout .= "\n\n" .
								"<style$type_attr class='panels-style' data-panels-style-for-post='" . esc_attr( $post_id ) . "'>" .
								$widget_css .
								'</style>';
		}

		unset( $GLOBALS[ 'SITEORIGIN_PANELS_PREVIEW_RENDER' ] );

		return $rendered_layout;
	}

	/**
	 * Echo the style wrapper and return if there was a wrapper
	 *
	 * @param string      $name  The name of the style wrapper
	 * @param array       $style The style wrapper args. Used as an argument for siteorigin_panels_{$name}_style_attributes
	 * @param string|bool $for   An identifier of what this style wrapper is for
	 *
	 * @return bool Is there a style wrapper
	 */
	private function start_style_wrapper( $name, $style = array(), $for = false ) {
		$attributes = array();

		if ( empty( $attributes['class'] ) ) {
			$attributes['class'] = array();
		}

		if ( empty( $attributes['style'] ) ) {
			$attributes['style'] = '';
		}

		// Check if Page Builder is set to output certain styles inline and if it is, do so.
		if ( siteorigin_panels_setting( 'inline-styles' ) ) {
			if ( ! empty( $style['padding'] ) ) {
				$attributes['style'] .= 'padding: ' . $style['padding'] . ';';
			}

			if ( ! empty( $style['margin'] ) ) {
				$attributes['style'] .= 'margin: ' . $style['margin'] . ';';
			}

			if ( ! empty( $style['border_color'] ) ) {
				$attributes['style'] .= 'border: ' . ( ! empty( $style['border_thickness'] ) ? $style['border_thickness'] : '1px' ) . ' solid ' . $style['border_color'] . ';';
			}
		}

		// Get everything related to the style wrapper
		$attributes = apply_filters( 'siteorigin_panels_' . $name . '_style_attributes', $attributes, $style );
		$attributes = apply_filters( 'siteorigin_panels_general_style_attributes', $attributes, $style );

		$standard_css = array();
		$standard_css = apply_filters( 'siteorigin_panels_' . $name . '_style_css', $standard_css, $style );
		$standard_css = apply_filters( 'siteorigin_panels_general_style_css', $standard_css, $style );

		$tablet_css = array();
		$tablet_css = siteorigin_panels_setting( 'tablet-layout' ) ? apply_filters( 'siteorigin_panels_' . $name . '_style_tablet_css', $tablet_css, $style ) : '';
		$tablet_css = apply_filters( 'siteorigin_panels_general_style_tablet_css', $tablet_css, $style );

		$mobile_css = array();
		$mobile_css = apply_filters( 'siteorigin_panels_' . $name . '_style_mobile_css', $mobile_css, $style );
		$mobile_css = apply_filters( 'siteorigin_panels_general_style_mobile_css', $mobile_css, $style );

		// Remove anything we didn't actually use
		if ( empty( $attributes['class'] ) ) {
			unset( $attributes['class'] );
		}

		if ( empty( $attributes['style'] ) ) {
			unset( $attributes['style'] );
		}

		$style_wrapper = '';

		if ( ! empty( $attributes ) || ! empty( $standard_css ) || ! empty( $tablet_css ) || ! empty( $mobile_css ) ) {
			if ( empty( $attributes['class'] ) ) {
				$attributes['class'] = array();
			}
			$attributes['class'][] = 'panel-' . $name . '-style';

			if ( ! empty( $for ) ) {
				$attributes['class'][] = 'panel-' . $name . '-style-for-' . sanitize_html_class( $for );
			}
			$attributes['class'] = array_unique( $attributes['class'] );

			// Filter and sanitize the classes
			$attributes['class'] = apply_filters( 'siteorigin_panels_' . $name . '_style_classes', $attributes['class'], $attributes, $style );
			$attributes['class'] = array_map( 'sanitize_html_class', $attributes['class'] );

			$style_wrapper = '<div ';

			foreach ( $attributes as $name => $value ) {
				// Attributes start with _ are used for internal communication between filters, so are not added to the HTML
				// We don't make use of this in our styling, so its left as a mechanism for other plugins.
				if ( substr( $name, 0, 1 ) === '_' ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$style_wrapper .= $name . '="' . esc_attr( implode( ' ', array_unique( $value ) ) ) . '" ';
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
	 * @param array  $widget_info   The widget info.
	 * @param array  $instance      The widget instance
	 * @param int    $grid_index    The grid index.
	 * @param int    $cell_index    The cell index.
	 * @param int    $widget_index  The index of this widget.
	 * @param bool   $is_first      Is this the first widget in the cell.
	 * @param bool   $is_last       Is this the last widget in the cell.
	 * @param bool   $post_id
	 * @param string $style_wrapper The start of the style wrapper
	 */
	public function the_widget( $widget_info, $instance, $grid_index, $cell_index, $widget_index, $is_first, $is_last, $post_id = false, $style_wrapper = '' ) {
		// Set widget class to $widget
		$widget_class = $widget_info['class'];
		$widget_class = apply_filters( 'siteorigin_panels_widget_class', $widget_class );

		// Load the widget from the widget factory and give themes and plugins a chance to provide their own
		$the_widget = SiteOrigin_Panels::get_widget_instance( $widget_class );
		$the_widget = apply_filters( 'siteorigin_panels_widget_object', $the_widget, $widget_class, $instance );

		// Allow other themes/plugins to override the instance.
		$instance = apply_filters( 'siteorigin_panels_widget_instance', $instance, $the_widget, $widget_class );

		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();

			if ( class_exists( 'WooCommerce' ) && is_shop() ) {
				$post_id = wc_get_page_id( 'shop' );
			}
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
		$id = 'panel-' . $post_id . '-' . $grid_index . '-' . $cell_index . '-' . $widget_index;

		// Filter and sanitize the classes
		$classes = apply_filters( 'siteorigin_panels_widget_classes', $classes, $widget_class, $instance, $widget_info );
		$classes = explode( ' ', implode( ' ', $classes ) );
		$classes = array_filter( $classes );
		$classes = array_unique( $classes );
		$classes = array_map( 'sanitize_html_class', $classes );

		$title_html = siteorigin_panels_setting( 'title-html' );

		if ( strpos( $title_html, '{{title}}' ) !== false ) {
			list( $before_title, $after_title ) = explode( '{{title}}', $title_html, 2 );
		} else {
			$before_title = '<h3 class="widget-title">';
			$after_title = '</h3>';
		}

		// Attributes of the widget wrapper
		$attributes = array(
			'id'         => $id,
			'class'      => implode( ' ', $classes ),
			'data-index' => $widget_info['widget_index'],
		);

		if ( siteorigin_panels_setting( 'inline-styles' ) && ! $is_last ) {
			$widget_bottom_margin = apply_filters( 'siteorigin_panels_css_cell_margin_bottom', siteorigin_panels_setting('margin-bottom') . 'px', false, false, array(), $post_id );
			if ( ! empty( $widget_bottom_margin ) ) {
				$attributes['style'] = 'margin-bottom: ' . $widget_bottom_margin;
			}
		}

		$attributes = apply_filters( 'siteorigin_panels_widget_attributes', $attributes, $widget_info );

		$before_widget = '<div ';

		foreach ( $attributes as $k => $v ) {
			$before_widget .= esc_attr( $k ) . '="' . esc_attr( $v ) . '" ';
		}
		$before_widget .= '>';

		$args = array(
			'before_widget' => $before_widget,
			'after_widget'  => '</div>',
			'before_title'  => $before_title,
			'after_title'   => $after_title,
			'widget_id'     => 'widget-' . $grid_index . '-' . $cell_index . '-' . $widget_index,
		);

		// Let other themes and plugins change the arguments that go to the widget class.
		$args = apply_filters( 'siteorigin_panels_widget_args', $args );

		// If there is a style wrapper, add it.
		if ( ! empty( $style_wrapper ) ) {
			$args['before_widget'] = $args['before_widget'] . $style_wrapper;
			$args['after_widget'] = '</div>' . $args['after_widget'];
		}

		// This allows other themes and plugins to add HTML inside of the widget before and after the contents.
		$args['before_widget'] .= apply_filters( 'siteorigin_panels_inside_widget_before', '', $widget_info );
		$args['after_widget'] = apply_filters( 'siteorigin_panels_inside_widget_after', '', $widget_info ) . $args['after_widget'];

		// This gives other plugins the chance to take over rendering of widgets
		$widget_html = apply_filters( 'siteorigin_panels_the_widget_html', '', $the_widget, $args, $instance );

		if ( ! empty( $widget_html ) ) {
			echo $args['before_widget'];
			echo $widget_html;
			echo $args['after_widget'];
		} elseif ( ! empty( $the_widget ) && is_a( $the_widget, 'WP_Widget' ) ) {
			$the_widget->widget( $args, $instance );
		} else {
			// This gives themes a chance to display some sort of placeholder for missing widgets
			echo apply_filters( 'siteorigin_panels_missing_widget', $args['before_widget'] . $args['after_widget'], $widget_class, $args, $instance );
		}
	}

	/**
	 * Print inline CSS in the header and footer.
	 */
	public function print_inline_css() {
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
				case 'wp_head':
					$css_id = 'head';
					break;

				case 'wp_footer':
					$css_id = 'footer';
					break;

				default:
					$css_id = sanitize_html_class( current_filter() );
					break;
			}

			// Allow third party developers to change the inline styles or remove them completely.
			$the_css = apply_filters( 'siteorigin_panels_inline_styles', $the_css );

			if ( ! empty( $the_css ) ) {
				?>
                <style<?php echo current_theme_supports( 'html5', 'style' ) ? '' : ' type="text/css"'; ?> media="all"
                       id="siteorigin-panels-layouts-<?php echo esc_attr( $css_id ); ?>"><?php echo $the_css; ?></style><?php
			}
		}
	}

	/**
	 * Enqueue the required styles
	 */
	public function enqueue_styles() {
		// Register the style to support possible lazy loading
		wp_register_style( 'siteorigin-panels-front', SiteOrigin_Panels::front_css_url(), array(), SITEORIGIN_PANELS_VERSION );
	}

	/**
	 * Retrieve panels data for a post or a prebuilt layout or the home page layout.
	 *
	 * @param string $post_id
	 *
	 * @return array
	 */
	private function get_panels_data_for_post( $post_id ) {
		if ( SiteOrigin_Panels::is_live_editor() ) {
			if (
				current_user_can( 'edit_post', $post_id ) &&
				! empty( $_POST['live_editor_panels_data'] ) &&
				$_POST['live_editor_post_ID'] == $post_id
			) {
				$panels_data = json_decode( wp_unslash( $_POST['live_editor_panels_data'] ), true );

				if ( ! empty( $panels_data['widgets'] ) ) {
					$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'] );
				}
			}
		} elseif ( strpos( $post_id, 'prebuilt:' ) === 0 ) {
			list( $null, $prebuilt_id ) = explode( ':', $post_id, 2 );
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
			$panels_data = ! empty( $layouts[ $prebuilt_id ] ) ? $layouts[ $prebuilt_id ] : array();
		} elseif ( $post_id == 'home' ) {
			$page_id = get_option( 'page_on_front' );

			if ( empty( $page_id ) ) {
				$page_id = get_option( 'siteorigin_panels_home_page_id' );
			}

			$panels_data = ! empty( $page_id ) ? get_post_meta( $page_id, 'panels_data', true ) : null;

			if ( is_null( $panels_data ) ) {
				// Load the default layout
				$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
				$prebuilt_id = siteorigin_panels_setting( 'home-page-default' ) ? siteorigin_panels_setting( 'home-page-default' ) : 'home';

				$panels_data = ! empty( $layouts[ $prebuilt_id ] ) ? $layouts[ $prebuilt_id ] : current( $layouts );
			}
		}

		if ( ! empty( $post_id ) && empty( $panels_data ) ) {
			if ( post_password_required( $post_id ) ) {
				return false;
			}
			$panels_data = get_post_meta( $post_id, 'panels_data', true );
		}

		return $panels_data;
	}

	/**
	 * Transform flat panels data into a hierarchical structure.
	 *
	 * @param array $panels_data Flat panels data containing `grids`, `grid_cells`, and `widgets`.
	 *
	 * @return array Hierarchical structure of rows => cells => widgets.
	 */
	public function get_panels_layout_data( $panels_data ) {
		$layout_data = array();

		foreach ( $panels_data['grids'] as $grid ) {
			$layout_data[] = array(
				'style'           => ! empty( $grid['style'] ) ? $grid['style'] : array(),
				'ratio'           => ! empty( $grid['ratio'] ) ? $grid['ratio'] : '',
				'ratio_direction' => ! empty( $grid['ratio_direction'] ) ? $grid['ratio_direction'] : '',
				'color_label'     => ! empty( $grid['color_label'] ) ? $grid['color_label'] : '',
				'label'           => ! empty( $grid['label'] ) ? $grid['label'] : '',
				'cells'           => array(),
			);
		}

		foreach ( $panels_data['grid_cells'] as $cell ) {
			$layout_data[ $cell['grid'] ]['cells'][] = array(
				'widgets' => array(),
				'style'   => ! empty( $cell['style'] ) ? $cell['style'] : array(),
				'weight'  => (float) $cell['weight'],
			);
		}

		foreach ( $panels_data['widgets'] as $i => $widget ) {
			$widget['panels_info']['widget_index'] = $i;
			$row_index = (int) $widget['panels_info']['grid'];
			$cell_index = (int) $widget['panels_info']['cell'];
			$layout_data[ $row_index ]['cells'][ $cell_index ]['widgets'][] = $widget;
		}

		return $layout_data;
	}

	/**
	 * Outputs the given HTML tag with the given attributes.
	 *
	 * @param string $tag        The HTML element to render.
	 * @param array  $attributes The attributes for the HTML element.
	 */
	private function render_element( $tag, $attributes ) {
		echo '<' . $tag;

		foreach ( $attributes as $name => $value ) {
			if ( $value ) {
				echo ' ' . $name . '="' . esc_attr( $value ) . '" ';
			}
		}
		echo '>';
	}

	/**
	 * Render everything for the given row, including:
	 *  - filters before and after row,
	 *  - row style wrapper,
	 *  - row element wrapper with attributes,
	 *  - child cells
	 *
	 * @param string $post_id     The ID of the post containing this layout.
	 * @param int    $ri          The index of this row.
	 * @param array  $row         The model containing this row's data and child cells.
	 * @param array  $panels_data A copy of panels_data for filters.
	 */
	private function render_row( $post_id, $ri, & $row, & $panels_data ) {
		$row_style_wrapper = $this->start_style_wrapper( 'row', ! empty( $row['style'] ) ? $row['style'] : array(), $post_id . '-' . $ri );

		$row_classes = array( 'panel-grid' );
		$row_classes[] = ! empty( $row_style_wrapper ) ? 'panel-has-style' : 'panel-no-style';
		$row_classes = apply_filters( 'siteorigin_panels_row_classes', $row_classes, $row );

		$row_attributes = array(
			'id'    => 'pg-' . $post_id . '-' . $ri,
			'class' => implode( ' ', $row_classes ),
		);

		if ( siteorigin_panels_setting( 'inline-styles' ) ) {
			$panels_margin_bottom = apply_filters( 'siteorigin_panels_css_row_margin_bottom', siteorigin_panels_setting( 'margin-bottom' ) . 'px', $row, $ri, $panels_data, $post_id );

			if  (
				! empty( $row['style']['bottom_margin'] ) ||
				$ri != count( $panels_data['grids'] ) - 1 ||
				! empty( siteorigin_panels_setting( 'margin-bottom-last-row' ) )
			) {
				$row_attributes['style'] = 'margin-bottom: ' . $panels_margin_bottom;
			}
		}

		$row_attributes = apply_filters( 'siteorigin_panels_row_attributes', $row_attributes, $row );

		// This allows other themes and plugins to add html before the row
		echo apply_filters( 'siteorigin_panels_before_row', '', $row, $row_attributes );

		$this->render_element( 'div', $row_attributes );

		if ( ! empty( $row_style_wrapper ) ) {
			echo $row_style_wrapper;
		}

		if (
			$this->container['css_override'] &&
			isset( $row['style']['row_stretch'] ) &&
			$row['style']['row_stretch'] == 'full'
		) {
			$this->render_element( 'div', array(
				'class' => 'so-panels-full-wrapper',
			) );
		}

		// This allows other themes and plugins to add HTML inside of the row before the row contents.
		echo apply_filters( 'siteorigin_panels_inside_row_before', '', $row );

		if ( method_exists( $this, 'modify_row_cells' ) ) {
			// This gives other renderers a chance to change the cell order
			$row['cells'] = $cells = $this->modify_row_cells( $row['cells'], $row );
		}

		foreach ( $row['cells'] as $ci => & $cell ) {
			$this->render_cell( $post_id, $ri, $ci, $cell, $row['cells'], $panels_data );
		}

		// This allows other themes and plugins to add HTML inside of the row after the row contents.
		echo apply_filters( 'siteorigin_panels_inside_row_after', '', $row );

		if (
			$this->container['css_override'] &&
			isset( $row['style']['row_stretch'] ) &&
			$row['style']['row_stretch'] == 'full'
		) {
			echo '</div>';
		}

		// Close the style wrapper
		if ( ! empty( $row_style_wrapper ) ) {
			echo '</div>';
		}

		echo '</div>';

		// This allows other themes and plugins to add html after the row
		echo apply_filters( 'siteorigin_panels_after_row', '', $row, $row_attributes );
	}

	/**
	 * Render everything for the given cell, including:
	 *  - filters before and after cell,
	 *  - cell element wrapper with attributes,
	 *  - style wrapper,
	 *  - child widgets
	 *
	 * @param string $post_id     The ID of the post containing this layout.
	 * @param int    $ri          The index of this cell's parent row.
	 * @param int    $ci          The index of this cell.
	 * @param array  $cell        The model containing this cell's data and child widgets.
	 * @param array  $cells       The array of cells containing this cell.
	 * @param array  $panels_data A copy of panels_data for filters
	 */
	private function render_cell( $post_id, $ri, $ci, & $cell, $cells, & $panels_data ) {
		$cell_classes = array( 'panel-grid-cell' );

		if ( empty( $cell['widgets'] ) ) {
			$cell_classes[] = 'panel-grid-cell-empty';
		}

		if ( $ci == count( $cells ) - 2 && count( $cells[ $ci + 1 ]['widgets'] ) == 0 ) {
			$cell_classes[] = 'panel-grid-cell-mobile-last';
		}

		// Themes can add their own styles to cells
		$cell_classes = apply_filters( 'siteorigin_panels_cell_classes', $cell_classes, $cell );

		// Legacy filter, use `siteorigin_panels_cell_classes` instead
		$cell_classes = apply_filters( 'siteorigin_panels_row_cell_classes', $cell_classes, $panels_data, $cell );

		$cell_attributes = apply_filters( 'siteorigin_panels_cell_attributes', array(
			'id'    => 'pgc-' . $post_id . '-' . $ri . '-' . $ci,
			'class' => implode( ' ', $cell_classes ),
		), $cell );

		// Legacy filter, use `siteorigin_panels_cell_attributes` instead
		$cell_attributes = apply_filters( 'siteorigin_panels_row_cell_attributes', $cell_attributes, $panels_data, $cell );

		echo apply_filters( 'siteorigin_panels_before_cell', '', $cell, $cell_attributes );

		$this->render_element( 'div', $cell_attributes );

		$grid = $panels_data['grids'][ $ri ];

		if ( empty( $cell['style']['class'] ) && ! empty( $grid['style']['cell_class'] ) ) {
			$cell['style']['class'] = $grid['style']['cell_class'];
		}

		$cell_style = ! empty( $cell['style'] ) ? $cell['style'] : array();
		$cell_style_wrapper = $this->start_style_wrapper( 'cell', $cell_style, $post_id . '-' . $ri . '-' . $ci );

		if ( ! empty( $cell_style_wrapper ) ) {
			echo $cell_style_wrapper;
		}
		// This allows other themes and plugins to add HTML inside of the cell before its contents.
		echo apply_filters( 'siteorigin_panels_inside_cell_before', '', $cell );

		foreach ( $cell['widgets'] as $wi => & $widget ) {
			$is_last = ( $wi == count( $cell['widgets'] ) - 1 );

			if ( apply_filters( 'siteorigin_panels_output_widget', true, $widget, $ri, $ci, $wi, $panels_data, $post_id ) ) {
				$this->render_widget( $post_id, $ri, $ci, $wi, $widget, $is_last );
			}
		}

		// This allows other themes and plugins to add HTML inside of the cell after its contents.
		echo apply_filters( 'siteorigin_panels_inside_cell_after', '', $cell );

		if ( ! empty( $cell_style_wrapper ) ) {
			echo '</div>';
		}
		echo '</div>';

		echo apply_filters( 'siteorigin_panels_after_cell', '', $cell, $cell_attributes );
	}

	/**
	 * Gets the style wrapper for this widget and passes it through to `the_widget` along with other required parameters.
	 *
	 * @param string $post_id The ID of the post containing this layout.
	 * @param int    $ri      The index of this widget's ancestor row.
	 * @param int    $ci      The index of this widget's parent cell.
	 * @param int    $wi      The index of this widget.
	 * @param array  $widget  The model containing this widget's data.
	 * @param bool   $is_last Whether this is the last widget in the parent cell.
	 */
	private function render_widget( $post_id, $ri, $ci, $wi, & $widget, $is_last ) {
		$widget_style_wrapper = $this->start_style_wrapper(
			'widget',
			! empty( $widget['panels_info']['style'] ) ? $widget['panels_info']['style'] : array(),
			$post_id . '-' . $ri . '-' . $ci . '-' . $wi
		);

		$this->the_widget(
			$widget['panels_info'],
			$widget,
			$ri,
			$ci,
			$wi,
			$wi == 0,
			$is_last,
			$post_id,
			$widget_style_wrapper
		);
	}

	public function front_css_url() {
		return siteorigin_panels_url( 'css/front-flex' . SITEORIGIN_PANELS_CSS_SUFFIX . '.css' );
	}
}
