<?php

/**
 * Class for handling all the default styling.
 *
 * Class SiteOrigin_Panels_Default_Styles
 */
class SiteOrigin_Panels_Default_Styles {

	static function init() {
		add_action( 'wp_enqueue_scripts', array( 'SiteOrigin_Panels_Default_Styles', 'register_scripts' ), 5 );

		// Adding all the fields
		add_filter( 'siteorigin_panels_row_style_fields', array(
			'SiteOrigin_Panels_Default_Styles',
			'row_style_fields'
		) );

		add_filter( 'siteorigin_panels_cell_style_fields', array(
			'SiteOrigin_Panels_Default_Styles',
			'cell_style_fields'
		) );

		add_filter( 'siteorigin_panels_widget_style_fields', array(
			'SiteOrigin_Panels_Default_Styles',
			'widget_style_fields'
		) );

		// Filter the row style
		add_filter( 'siteorigin_panels_row_style_attributes', array(
			'SiteOrigin_Panels_Default_Styles',
			'row_style_attributes'
		), 10, 2 );
		add_filter( 'siteorigin_panels_cell_style_attributes', array(
			'SiteOrigin_Panels_Default_Styles',
			'cell_style_attributes'
		), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_attributes', array(
			'SiteOrigin_Panels_Default_Styles',
			'widget_style_attributes'
		), 10, 2 );

		// Main filter to add any custom CSS.
		add_filter( 'siteorigin_panels_css_object', array(
			'SiteOrigin_Panels_Default_Styles',
			'filter_css_object'
		), 10, 3 );

		// Filtering specific attributes
		add_filter( 'siteorigin_panels_css_row_margin_bottom', array(
			'SiteOrigin_Panels_Default_Styles',
			'filter_row_bottom_margin'
		), 10, 2 );
		add_filter( 'siteorigin_panels_css_row_gutter', array(
			'SiteOrigin_Panels_Default_Styles',
			'filter_row_gutter'
		), 10, 2 );
	}

	static function register_scripts() {
		wp_register_script( 'siteorigin-panels-front-styles', plugin_dir_url( __FILE__ ) . '../js/styling' . SITEORIGIN_PANELS_VERSION_SUFFIX . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'jquery' ), SITEORIGIN_PANELS_VERSION );
		wp_register_script( 'siteorigin-parallax', plugin_dir_url( __FILE__ ) . '../js/siteorigin-parallax' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'jquery' ), SITEORIGIN_PANELS_VERSION );
		wp_localize_script( 'siteorigin-panels-front-styles', 'panelsStyles', array(
			'fullContainer' => apply_filters( 'siteorigin_panels_full_width_container', siteorigin_panels_setting( 'full-width-container' ) )
		) );
	}

	static function row_style_fields( $fields ) {
		// Add the attribute fields

		$fields['id'] = array(
			'name'        => __( 'Row ID', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'A custom ID used for this row.', 'siteorigin-panels' ),
			'priority'    => 4,
		);

		$fields['class'] = array(
			'name'        => __( 'Row Class', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'A CSS class', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		//Should we remove this? Or move it's existing value to new Cell Styles?
		$fields['cell_class'] = array(
			'name'        => __( 'Cell Class', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'Class added to all cells in this row.', 'siteorigin-panels' ),
			'priority'    => 6,
		);

		$fields['row_css'] = array(
			'name'        => __( 'CSS Styles', 'siteorigin-panels' ),
			'type'        => 'code',
			'group'       => 'attributes',
			'description' => __( 'One style attribute per line.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		// Add the layout fields

		$fields['bottom_margin'] = array(
			'name'        => __( 'Bottom Margin', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => sprintf( __( 'Space below the row. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-bottom' ) ),
			'priority'    => 5,
		);

		$fields['gutter'] = array(
			'name'        => __( 'Gutter', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => sprintf( __( 'Amount of space between columns. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-sides' ) ),
			'priority'    => 6,
		);

		$fields['padding'] = array(
			'name'        => __( 'Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Padding around the entire row.', 'siteorigin-panels' ),
			'priority'    => 7,
			'multiple'    => true
		);

		$fields['mobile_padding'] = array(
			'name'        => __( 'Mobile Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Padding when on mobile devices.', 'siteorigin-panels' ),
			'priority'    => 8,
			'multiple'    => true
		);

		$fields['row_stretch'] = array(
			'name'     => __( 'Row Layout', 'siteorigin-panels' ),
			'type'     => 'select',
			'group'    => 'layout',
			'options'  => array(
				''               => __( 'Standard', 'siteorigin-panels' ),
				'full'           => __( 'Full Width', 'siteorigin-panels' ),
				'full-stretched' => __( 'Full Width Stretched', 'siteorigin-panels' ),
			),
			'priority' => 10,
		);

		$fields['collapse_order'] = array(
			'name'     => __( 'Collapse Order', 'siteorigin-panels' ),
			'type'     => 'select',
			'group'    => 'layout',
			'options'  => array(
				''          => __( 'Default', 'siteorigin-panels' ),
				'left-top'  => __( 'Left on Top', 'siteorigin-panels' ),
				'right-top' => __( 'Right on Top', 'siteorigin-panels' ),
			),
			'priority' => 15,
		);

		$fields['cell_alignment'] = array(
			'name'     => __( 'Cell Vertical Alignment', 'siteorigin-panels' ),
			'type'     => 'select',
			'group'    => 'layout',
			'options'  => array(
				'flex-start' => __( 'Top', 'siteorigin-panels' ),
				'center'     => __( 'Center', 'siteorigin-panels' ),
				'flex-end'   => __( 'Bottom', 'siteorigin-panels' ),
				'stretch'    => __( 'Stretch', 'siteorigin-panels' ),
			),
			'priority' => 16,
		);

		// How lets add the design fields

		$fields['background'] = array(
			'name'        => __( 'Background Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Background color of the row.', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		$fields['background_image_attachment'] = array(
			'name'        => __( 'Background Image', 'siteorigin-panels' ),
			'type'        => 'image',
			'group'       => 'design',
			'description' => __( 'Background image of the row.', 'siteorigin-panels' ),
			'priority'    => 6,
		);

		$fields['background_display'] = array(
			'name'        => __( 'Background Image Display', 'siteorigin-panels' ),
			'type'        => 'select',
			'group'       => 'design',
			'options'     => array(
				'tile'              => __( 'Tiled Image', 'siteorigin-panels' ),
				'cover'             => __( 'Cover', 'siteorigin-panels' ),
				'center'            => __( 'Centered, with original size', 'siteorigin-panels' ),
				'fixed'             => __( 'Fixed', 'siteorigin-panels' ),
				'parallax'          => __( 'Parallax', 'siteorigin-panels' ),
				'parallax-original' => __( 'Parallax (Original Size)', 'siteorigin-panels' ),
			),
			'description' => __( 'How the background image is displayed.', 'siteorigin-panels' ),
			'priority'    => 7,
		);

		$fields['border_color'] = array(
			'name'        => __( 'Border Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Border color of the row.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		return $fields;
	}

	static function cell_style_fields( $fields ) {
		$fields['class'] = array(
			'name'        => __( 'Cell Class', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'A CSS class', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		$fields['cell_css'] = array(
			'name'        => __( 'CSS Styles', 'siteorigin-panels' ),
			'type'        => 'code',
			'group'       => 'attributes',
			'description' => __( 'One style attribute per line.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		$fields['padding'] = array(
			'name'        => __( 'Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Padding around the entire cell.', 'siteorigin-panels' ),
			'priority'    => 7,
			'multiple'    => true
		);

		$fields['mobile_padding'] = array(
			'name'        => __( 'Mobile Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Padding when on mobile devices.', 'siteorigin-panels' ),
			'priority'    => 8,
			'multiple'    => true
		);

		// How lets add the design fields

		$fields['background'] = array(
			'name'        => __( 'Background Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Background color of the cell.', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		$fields['background_image_attachment'] = array(
			'name'        => __( 'Background Image', 'siteorigin-panels' ),
			'type'        => 'image',
			'group'       => 'design',
			'description' => __( 'Background image of the cell.', 'siteorigin-panels' ),
			'priority'    => 6,
		);

		$fields['background_display'] = array(
			'name'        => __( 'Background Image Display', 'siteorigin-panels' ),
			'type'        => 'select',
			'group'       => 'design',
			'options'     => array(
				'tile'              => __( 'Tiled Image', 'siteorigin-panels' ),
				'cover'             => __( 'Cover', 'siteorigin-panels' ),
				'center'            => __( 'Centered, with original size', 'siteorigin-panels' ),
				'fixed'             => __( 'Fixed', 'siteorigin-panels' ),
				'parallax'          => __( 'Parallax', 'siteorigin-panels' ),
				'parallax-original' => __( 'Parallax (Original Size)', 'siteorigin-panels' ),
			),
			'description' => __( 'How the background image is displayed.', 'siteorigin-panels' ),
			'priority'    => 7,
		);

		$fields['border_color'] = array(
			'name'        => __( 'Border Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Border color of the cell.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		$fields['font_color'] = array(
			'name'        => __( 'Font Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Color of text inside this cell.', 'siteorigin-panels' ),
			'priority'    => 15,
		);

		$fields['link_color'] = array(
			'name'        => __( 'Links Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Color of links inside this cell.', 'siteorigin-panels' ),
			'priority'    => 16,
		);

		return $fields;
	}

	static function widget_style_fields( $fields ) {
		$fields['class'] = array(
			'name'        => __( 'Widget Class', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'A CSS class', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		$fields['widget_css'] = array(
			'name'        => __( 'CSS Styles', 'siteorigin-panels' ),
			'type'        => 'code',
			'group'       => 'attributes',
			'description' => __( 'One style attribute per line.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		$fields['padding'] = array(
			'name'        => __( 'Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Padding around the entire widget.', 'siteorigin-panels' ),
			'priority'    => 7,
			'multiple'    => true
		);

		$fields['mobile_padding'] = array(
			'name'        => __( 'Mobile Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Padding when on mobile devices.', 'siteorigin-panels' ),
			'priority'    => 8,
			'multiple'    => true
		);

		// How lets add the design fields

		$fields['background'] = array(
			'name'        => __( 'Background Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Background color of the widget.', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		$fields['background_image_attachment'] = array(
			'name'        => __( 'Background Image', 'siteorigin-panels' ),
			'type'        => 'image',
			'group'       => 'design',
			'description' => __( 'Background image of the widget.', 'siteorigin-panels' ),
			'priority'    => 6,
		);

		$fields['background_display'] = array(
			'name'        => __( 'Background Image Display', 'siteorigin-panels' ),
			'type'        => 'select',
			'group'       => 'design',
			'options'     => array(
				'tile'              => __( 'Tiled Image', 'siteorigin-panels' ),
				'cover'             => __( 'Cover', 'siteorigin-panels' ),
				'center'            => __( 'Centered, with original size', 'siteorigin-panels' ),
				'fixed'             => __( 'Fixed', 'siteorigin-panels' ),
				'parallax'          => __( 'Parallax', 'siteorigin-panels' ),
				'parallax-original' => __( 'Parallax (Original Size)', 'siteorigin-panels' ),
			),
			'description' => __( 'How the background image is displayed.', 'siteorigin-panels' ),
			'priority'    => 7,
		);

		$fields['border_color'] = array(
			'name'        => __( 'Border Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Border color of the widget.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		$fields['font_color'] = array(
			'name'        => __( 'Font Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Color of text inside this widget.', 'siteorigin-panels' ),
			'priority'    => 15,
		);

		$fields['link_color'] = array(
			'name'        => __( 'Links Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => __( 'Color of links inside this widget.', 'siteorigin-panels' ),
			'priority'    => 16,
		);

		return $fields;
	}

	static function row_style_attributes( $attributes, $args ) {
		if ( ! empty( $args['row_stretch'] ) ) {
			$attributes['class'][]           = 'siteorigin-panels-stretch';
			$attributes['data-stretch-type'] = $args['row_stretch'];
			wp_enqueue_script( 'siteorigin-panels-front-styles' );
		}

		if ( ! empty( $args['class'] ) ) {
			$attributes['class'] = array_merge( $attributes['class'], explode( ' ', $args['class'] ) );
		}

		if ( ! empty( $args['row_css'] ) ) {
			preg_match_all( '/^(.+?):(.+?);?$/m', $args['row_css'], $matches );

			if ( ! empty( $matches[0] ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i ++ ) {
					$attributes['style'] .= $matches[1][ $i ] . ':' . $matches[2][ $i ] . ';';
				}
			}
		}

		if ( ! empty( $args['background'] ) ) {
			$attributes['style'] .= 'background-color:' . $args['background'] . ';';
		}

		if ( ! empty( $args['background_display'] ) && ! empty( $args['background_image_attachment'] ) ) {

			if ( $args['background_display'] == 'parallax' || $args['background_display'] == 'parallax-original' ) {
				wp_enqueue_script( 'siteorigin-panels-front-styles' );
			}

			$url = wp_get_attachment_image_src( $args['background_image_attachment'], 'full' );

			if ( ! empty( $url ) ) {

				if ( $args['background_display'] == 'parallax' || $args['background_display'] == 'parallax-original' ) {
					wp_enqueue_script( 'siteorigin-parallax' );
					$parallax_args                          = array(
						'backgroundUrl'    => $url[0],
						'backgroundSize'   => array( $url[1], $url[2] ),
						'backgroundSizing' => $args['background_display'] == 'parallax-original' ? 'original' : 'scaled',
						'limitMotion'      => siteorigin_panels_setting( 'parallax-motion' ) ? floatval( siteorigin_panels_setting( 'parallax-motion' ) ) : 'auto',
					);
					$attributes['data-siteorigin-parallax'] = json_encode( $parallax_args );
					$attributes['style'] .= 'background-image: url(' . $url[0] . '); background-position: center center; background-repeat: no-repeat;';
				} else {
					$attributes['style'] .= 'background-image: url(' . $url[0] . ');';
					switch ( $args['background_display'] ) {
						case 'tile':
							$attributes['style'] .= 'background-repeat: repeat;';
							break;
						case 'cover':
							$attributes['style'] .= 'background-size: cover;';
							break;
						case 'center':
							$attributes['style'] .= 'background-position: center center; background-repeat: no-repeat;';
							break;
						case 'fixed':
							$attributes['style'] .= 'background-attachment: fixed; background-size: cover;';
							break;
					}
				}
			}
		}

		// We need the style wrapper if there is padding or mobile padding
		if ( ! empty( $args['padding'] ) || ! empty( $args['mobile_padding'] ) ) {
			$attributes['class'][] = 'panel-row-style';
		}

		if ( ! empty( $args['border_color'] ) ) {
			$attributes['style'] .= 'border: 1px solid ' . $args['border_color'] . ';';
		}

		if ( ! empty( $args['id'] ) ) {
			$attributes['id'] = sanitize_html_class( $args['id'] );
		}

		return $attributes;
	}

	static function cell_style_attributes( $attributes, $args ) {
		if ( ! empty( $args['class'] ) ) {
			if ( empty( $attributes['class'] ) ) {
				$attributes['class'] = array();
			}
			$attributes['class'] = array_merge( $attributes['class'], explode( ' ', $args['class'] ) );
		}

		if ( ! empty( $args['cell_css'] ) ) {
			preg_match_all( '/^(.+?):(.+?);?$/m', $args['cell_css'], $matches );

			if ( ! empty( $matches[0] ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i ++ ) {
					$attributes['style'] .= $matches[1][ $i ] . ':' . $matches[2][ $i ] . ';';
				}
			}
		}

		if ( ! empty( $args['background'] ) ) {
			$attributes['style'] .= 'background-color:' . $args['background'] . ';';
		}

		if ( ! empty( $args['background_display'] ) && ! empty( $args['background_image_attachment'] ) ) {
			$url = wp_get_attachment_image_src( $args['background_image_attachment'], 'full' );

			if ( $args['background_display'] == 'parallax' || $args['background_display'] == 'parallax-original' ) {
				wp_enqueue_script( 'siteorigin-panels-front-styles' );
			}

			if ( ! empty( $url ) ) {

				if ( $args['background_display'] == 'parallax' || $args['background_display'] == 'parallax-original' ) {
					wp_enqueue_script( 'siteorigin-parallax' );
					$parallax_args                          = array(
						'backgroundUrl'    => $url[0],
						'backgroundSize'   => array( $url[1], $url[2] ),
						'backgroundSizing' => $args['background_display'] == 'parallax-original' ? 'original' : 'scaled',
					);
					$attributes['data-siteorigin-parallax'] = json_encode( $parallax_args );
					$attributes['style'] .= 'background-image: url(' . $url[0] . '); background-position: center center; background-repeat: no-repeat;';
				} else {
					$attributes['style'] .= 'background-image: url(' . $url[0] . ');';

					switch ( $args['background_display'] ) {
						case 'tile':
							$attributes['style'] .= 'background-repeat: repeat;';
							break;
						case 'cover':
							$attributes['style'] .= 'background-size: cover;';
							break;
						case 'center':
							$attributes['style'] .= 'background-position: center center; background-repeat: no-repeat;';
							break;
						case 'fixed':
							$attributes['style'] .= 'background-attachment: fixed; background-size: cover;';
							break;
					}
				}

			}
		}

		// We need the style wrapper if there is padding or mobile padding
		if ( ! empty( $args['padding'] ) || ! empty( $args['mobile_padding'] ) ) {
			$attributes['class'][] = 'panel-cell-style';
		}

		if ( ! empty( $args['border_color'] ) ) {
			$attributes['style'] .= 'border: 1px solid ' . $args['border_color'] . ';';
		}

		if ( ! empty( $args['font_color'] ) ) {
			$attributes['style'] .= 'color: ' . $args['font_color'] . ';';
		}

		return $attributes;
	}

	static function widget_style_attributes( $attributes, $args ) {
		if ( ! empty( $args['class'] ) ) {
			if ( empty( $attributes['class'] ) ) {
				$attributes['class'] = array();
			}
			$attributes['class'] = array_merge( $attributes['class'], explode( ' ', $args['class'] ) );
		}

		if ( ! empty( $args['widget_css'] ) ) {
			preg_match_all( '/^(.+?):(.+?);?$/m', $args['widget_css'], $matches );

			if ( ! empty( $matches[0] ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i ++ ) {
					$attributes['style'] .= $matches[1][ $i ] . ':' . $matches[2][ $i ] . ';';
				}
			}
		}

		if ( ! empty( $args['background'] ) ) {
			$attributes['style'] .= 'background-color:' . $args['background'] . ';';
		}

		if ( ! empty( $args['background_display'] ) && ! empty( $args['background_image_attachment'] ) ) {
			$url = wp_get_attachment_image_src( $args['background_image_attachment'], 'full' );

			if ( $args['background_display'] == 'parallax' || $args['background_display'] == 'parallax-original' ) {
				wp_enqueue_script( 'siteorigin-panels-front-styles' );
			}

			if ( ! empty( $url ) ) {

				if ( $args['background_display'] == 'parallax' || $args['background_display'] == 'parallax-original' ) {
					wp_enqueue_script( 'siteorigin-parallax' );
					$parallax_args                          = array(
						'backgroundUrl'    => $url[0],
						'backgroundSize'   => array( $url[1], $url[2] ),
						'backgroundSizing' => $args['background_display'] == 'parallax-original' ? 'original' : 'scaled',
					);
					$attributes['data-siteorigin-parallax'] = json_encode( $parallax_args );
					$attributes['style'] .= 'background-image: url(' . $url[0] . '); background-position: center center; background-repeat: no-repeat;';
				} else {
					$attributes['style'] .= 'background-image: url(' . $url[0] . ');';

					switch ( $args['background_display'] ) {
						case 'tile':
							$attributes['style'] .= 'background-repeat: repeat;';
							break;
						case 'cover':
							$attributes['style'] .= 'background-size: cover;';
							break;
						case 'center':
							$attributes['style'] .= 'background-position: center center; background-repeat: no-repeat;';
							break;
						case 'fixed':
							$attributes['style'] .= 'background-attachment: fixed; background-size: cover;';
							break;
					}
				}

			}
		}

		// We need the style wrapper if there is padding or mobile padding
		if ( ! empty( $args['padding'] ) || ! empty( $args['mobile_padding'] ) ) {
			$attributes['class'][] = 'panel-widget-style';
		}

		if ( ! empty( $args['border_color'] ) ) {
			$attributes['style'] .= 'border: 1px solid ' . $args['border_color'] . ';';
		}

		if ( ! empty( $args['font_color'] ) ) {
			$attributes['style'] .= 'color: ' . $args['font_color'] . ';';
		}

		return $attributes;
	}

	/**
	 * @param SiteOrigin_Panels_Css_Builder $css
	 * @param $panels_data
	 * @param $post_id
	 *
	 * @return mixed
	 */
	static function filter_css_object( $css, $panels_data, $post_id ) {

		$mobile_width = siteorigin_panels_setting( 'mobile-width' );

		// Add in the widget padding styling
		foreach ( $panels_data['widgets'] as $i => $widget ) {
			if ( empty( $widget['panels_info'] ) ) {
				continue;
			}

			if ( ! empty( $widget['panels_info']['style']['padding'] ) ) {
				$css->add_widget_css(
					$post_id,
					$widget['panels_info']['grid'],
					$widget['panels_info']['cell'],
					$widget['panels_info']['cell_index'],
					'> .panel-widget-style',
					array( 'padding' => $widget['panels_info']['style']['padding'] )
				);
			}
			if ( ! empty( $widget['panels_info']['style']['mobile_padding'] ) ) {
				$css->add_widget_css(
					$post_id,
					$widget['panels_info']['grid'],
					$widget['panels_info']['cell'],
					$widget['panels_info']['cell_index'],
					'> .panel-widget-style',
					array( 'padding' => $widget['panels_info']['style']['mobile_padding'] ),
					$mobile_width
				);
			}
		}

		$ci = 0;
		// Add in the row  styling
		foreach ( $panels_data['grids'] as $i => $row ) {
			if ( empty( $row['style'] ) ) {
				continue;
			}

			if ( ! empty( $row['style']['padding'] ) ) {
				$css->add_row_css(
					$post_id,
					$i,
					'> .panel-row-style',
					array( 'padding' => $row['style']['padding'] )
				);
			}
			if ( ! empty( $row['style']['mobile_padding'] ) ) {
				$css->add_row_css(
					$post_id,
					$i,
					'> .panel-row-style',
					array( 'padding' => $row['style']['mobile_padding'] ),
					$mobile_width
				);
			}

			// Add in flexbox alignment
			if( ! empty( $row['style'][ 'cell_alignment' ] ) ) {
				$css->add_row_css(
					$post_id,
					$i,
					'',
					array(
						'-webkit-align-items' => $row['style'][ 'cell_alignment' ],
						'align-items' => $row['style'][ 'cell_alignment' ],
					)
				);
			}

			// Add in the cell padding styling
			$cell_count = intval( $row['cells'] );

			for ( $i = 0; $i < $cell_count; $i ++ ) {
				$grid_cell = $panels_data['grid_cells'][ $ci ++ ];
				if ( empty( $grid_cell['style'] ) ) {
					continue;
				}
				$style = $grid_cell['style'];
				if ( ! empty( $style['padding'] ) ) {
					$css->add_cell_css(
						$post_id,
						$grid_cell['grid'],
						$i,
						'> .panel-cell-style',
						array( 'padding' => $style['padding'] )
					);
				}
				if ( ! empty( $style['mobile_padding'] ) ) {
					$css->add_cell_css(
						$post_id,
						$grid_cell['grid'],
						$i,
						'> .panel-cell-style',
						array( 'padding' => $style['mobile_padding'] ),
						$mobile_width
					);
				}
			}
		}

		return $css;
	}

	static function filter_row_bottom_margin( $margin, $grid ) {
		if ( ! empty( $grid['style']['bottom_margin'] ) ) {
			$margin = $grid['style']['bottom_margin'];
		}

		return $margin;
	}

	static function filter_row_gutter( $gutter, $grid ) {
		if ( ! empty( $grid['style']['gutter'] ) ) {
			$gutter = $grid['style']['gutter'];
		}

		return $gutter;
	}

}
