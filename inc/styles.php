<?php

/**
 * Class for handling all the default styling.
 *
 * Class SiteOrigin_Panels_Default_Styles
 */
class SiteOrigin_Panels_Styles {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ), 5 );

		// Adding all the fields
		add_filter( 'siteorigin_panels_row_style_fields', array( $this, 'row_style_fields' ) );
		add_filter( 'siteorigin_panels_cell_style_fields', array( $this, 'cell_style_fields' ) );
		add_filter( 'siteorigin_panels_widget_style_fields', array( $this, 'widget_style_fields' ) );

		// Style wrapper attributes
		add_filter( 'siteorigin_panels_row_style_attributes', array( $this, 'general_style_attributes' ), 10, 2 );
		add_filter( 'siteorigin_panels_row_style_attributes', array( $this, 'row_style_attributes' ), 10, 2 );
		add_filter( 'siteorigin_panels_row_style_attributes', array( $this, 'vantage_row_style_attributes' ), 11, 2 );
		add_filter( 'siteorigin_panels_cell_style_attributes', array( $this, 'general_style_attributes' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_attributes', array( $this, 'general_style_attributes' ), 10, 2 );

		// Style wrapper CSS
		add_filter( 'siteorigin_panels_row_style_css', array( $this, 'general_style_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_cell_style_css', array( $this, 'general_style_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_css', array( $this, 'general_style_css' ), 10, 2 );

		add_filter( 'siteorigin_panels_row_style_mobile_css', array( $this, 'general_style_mobile_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_cell_style_mobile_css', array( $this, 'general_style_mobile_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_mobile_css', array( $this, 'general_style_mobile_css' ), 10, 2 );

		// Main filter to add any custom CSS.
		add_filter( 'siteorigin_panels_css_object', array( $this, 'filter_css_object' ), 10, 4 );

		// Filtering specific attributes
		add_filter( 'siteorigin_panels_css_row_margin_bottom', array( $this, 'filter_row_bottom_margin' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_cell_mobile_margin_bottom', array( $this, 'filter_row_cell_bottom_margin' ), 10, 5 );
		add_filter( 'siteorigin_panels_css_row_mobile_margin_bottom', array( $this, 'filter_row_mobile_bottom_margin' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_row_gutter', array( $this, 'filter_row_gutter' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_widget_css', array( $this, 'filter_widget_style_css' ), 10, 2 );
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	static function register_scripts() {
		wp_register_script(
			'siteorigin-panels-front-styles',
			siteorigin_panels_url( 'js/styling' . SITEORIGIN_PANELS_VERSION_SUFFIX . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
			array( 'jquery' ),
			SITEORIGIN_PANELS_VERSION
		);
		wp_localize_script( 'siteorigin-panels-front-styles', 'panelsStyles', array(
			'fullContainer' => apply_filters( 'siteorigin_panels_full_width_container', siteorigin_panels_setting( 'full-width-container' ) ),
		) );
		wp_register_script(
			'siteorigin-parallax',
			siteorigin_panels_url( 'js/siteorigin-parallax' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
			array( 'jquery' ),
			SITEORIGIN_PANELS_VERSION
		);
		wp_localize_script( 'siteorigin-parallax', 'parallaxStyles', array(
			'parallax-mobile' => ! empty( siteorigin_panels_setting( 'parallax-mobile' ) ) ?: siteorigin_panels_setting( 'parallax-mobile' ),
			'mobile-breakpoint' => siteorigin_panels_setting( 'mobile-width' ) . 'px',
		) );
	}

	/**
	 * These are general styles that apply to all elements
	 *
	 * @param $label
	 *
	 * @return array
	 */
	static function get_general_style_fields( $id, $label ) {
		$fields = array();

		// All the attribute fields

		$fields['id'] = array(
			'name'        => sprintf( __( '%s ID', 'siteorigin-panels' ), $label ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => sprintf( __( 'A custom ID used for this %s.', 'siteorigin-panels' ), strtolower( $label ) ),
			'priority'    => 4,
		);

		$fields['class'] = array(
			'name'        => sprintf( __( '%s Class', 'siteorigin-panels' ), $label ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'A CSS class', 'siteorigin-panels' ),
			'priority'    => 5,
		);

		$fields[ $id . '_css' ] = array(
			'name'        => __( 'CSS Declarations', 'siteorigin-panels' ),
			'type'        => 'code',
			'group'       => 'attributes',
			'description' => __( 'One declaration per line.', 'siteorigin-panels' ),
			'priority'    => 10,
		);

		$fields[ 'mobile_css' ] = array(
			'name'        => __( 'Mobile CSS Declarations', 'siteorigin-panels' ),
			'type'        => 'code',
			'group'       => 'attributes',
			'description' => __( 'CSS declarations applied when in mobile view.', 'siteorigin-panels' ),
			'priority'    => 11,
		);

		// The layout fields

		$fields['padding'] = array(
			'name'        => __( 'Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => sprintf( __( 'Padding around the entire %s.', 'siteorigin-panels' ), strtolower( $label ) ),
			'priority'    => 7,
			'multiple'    => true
		);
		
		// Mobile layout fields

		$fields['mobile_padding'] = array(
			'name'        => __( 'Mobile Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'mobile_layout',
			'description' => __( 'Padding when on mobile devices.', 'siteorigin-panels' ),
			'priority'    => 8,
			'multiple'    => true
		);

		// The general design fields

		$fields['background'] = array(
			'name'        => __( 'Background Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'description' => sprintf( __( 'Background color of the %s.', 'siteorigin-panels' ), strtolower( $label ) ),
			'priority'    => 5,
		);

		$fields['background_image_attachment'] = array(
			'name'        => __( 'Background Image', 'siteorigin-panels' ),
			'type'        => 'image',
			'group'       => 'design',
			'description' => sprintf( __( 'Background image of the %s.', 'siteorigin-panels' ), strtolower( $label ) ),
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
				'contain'           => __( 'Contain', 'siteorigin-panels' ),
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
			'description' => sprintf( __( 'Border color of the %s.', 'siteorigin-panels' ), strtolower( $label ) ),
			'priority'    => 10,
		);

		return $fields;
	}

	/**
	 * All the row styling fields
	 *
	 * @param $fields
	 *
	 * @return array
	 */
	static function row_style_fields( $fields ) {
		// Add the general fields
		$fields = wp_parse_args( $fields, self::get_general_style_fields( 'row', __( 'Row', 'siteorigin-panels' ) ) );

		$fields['cell_class'] = array(
			'name'        => __( 'Cell Class', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'Class added to all cells in this row.', 'siteorigin-panels' ),
			'priority'    => 6,
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
			'description' => sprintf( __( 'Amount of space between cells. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-sides' ) ),
			'priority'    => 6,
		);

		$fields['row_stretch'] = array(
			'name'     => __( 'Row Layout', 'siteorigin-panels' ),
			'type'     => 'select',
			'group'    => 'layout',
			'options'  => array(
				''               => __( 'Standard', 'siteorigin-panels' ),
				'full'           => __( 'Full Width', 'siteorigin-panels' ),
				'full-stretched' => __( 'Full Width Stretched', 'siteorigin-panels' ),
				'full-stretched-padded' => __( 'Full Width Stretched Padded', 'siteorigin-panels' ),
			),
			'priority' => 10,
		);

		$fields['collapse_behaviour'] = array(
			'name'     => __( 'Collapse Behaviour', 'siteorigin-panels' ),
			'type'     => 'select',
			'group'    => 'layout',
			'options'  => array(
				''               => __( 'Standard', 'siteorigin-panels' ),
				'no_collapse'    => __( 'No Collapse', 'siteorigin-panels' ),
			),
			'priority' => 15,
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
			'priority' => 16,
		);

		if ( siteorigin_panels_setting( 'legacy-layout' ) != 'always'  ) {
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
				'priority' => 17,
			);
		}
		
		// Add the mobile layout fields
		
		$fields['mobile_bottom_margin'] = array(
			'name'        => __( 'Mobile Bottom Margin', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'mobile_layout',
			'description' => sprintf( __( 'Space below the row on mobile devices. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-bottom' ) ),
			'priority'    => 5,
		);
		
		$fields['mobile_cell_margin'] = array(
			'name'        => __( 'Mobile Cell Margins', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'mobile_layout',
			'description' => sprintf( __( 'Vertical space between cells in a collapsed mobile row. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-bottom' ) ),
			'priority'    => 5,
		);
		
		return $fields;
	}

	/**
	 * All the cell styling fields
	 *
	 * @param $fields
	 *
	 * @return array
	 */
	static function cell_style_fields( $fields ) {
		// Add the general fields
		$fields = wp_parse_args( $fields, self::get_general_style_fields( 'cell', __( 'Cell', 'siteorigin-panels' ) ) );

		$fields['vertical_alignment'] = array(
			'name'     => __( 'Vertical Alignment', 'siteorigin-panels' ),
			'type'     => 'select',
			'group'    => 'layout',
			'options'  => array(
				'auto'       => __( 'Use row setting', 'siteorigin-panels' ),
				'flex-start' => __( 'Top', 'siteorigin-panels' ),
				'center'     => __( 'Center', 'siteorigin-panels' ),
				'flex-end'   => __( 'Bottom', 'siteorigin-panels' ),
				'stretch'    => __( 'Stretch', 'siteorigin-panels' ),
			),
			'priority' => 16,
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

	/**
	 * @param $fields
	 *
	 * @return array
	 */
	static function widget_style_fields( $fields ) {

		// Add the general fields
		$fields = wp_parse_args( $fields, self::get_general_style_fields( 'widget', __( 'Widget', 'siteorigin-panels' ) ) );
		
		$fields['margin'] = array(
			'name'        => __( 'Margin', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Margins around the widget.', 'siteorigin-panels' ),
			'priority'    => 6,
			'multiple'    => true
		);

		// How lets add the design fields

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

	/**
	 * Style attributes that apply to rows, cells and widgets
	 *
	 * @param $attributes
	 * @param $style
	 *
	 * @return array $attributes
	 */
	static function general_style_attributes( $attributes, $style ){
		if ( ! empty( $style['class'] ) ) {
			if( ! is_array( $style['class'] ) ) {
				$style['class'] = explode( ' ', $style[ 'class' ] );
			}
			$attributes['class'] = array_merge( $attributes['class'], $style['class'] );
		}

		if ( ! empty( $style['background_display'] ) &&
			 ! empty( $style['background_image_attachment'] )
		) {
			
			$url = self::get_attachment_image_src( $style['background_image_attachment'], 'full' );

			if (
				! empty( $url ) &&
				( $style['background_display'] == 'parallax' || $style['background_display'] == 'parallax-original' )
			) {
				wp_enqueue_script( 'siteorigin-parallax' );
				$parallax_args                          = array(
					'backgroundUrl'    => $url[0],
					'backgroundSize'   => array( $url[1], $url[2] ),
					'backgroundSizing' => $style['background_display'] == 'parallax-original' ? 'original' : 'scaled',
					'limitMotion'      => siteorigin_panels_setting( 'parallax-motion' ) ? floatval( siteorigin_panels_setting( 'parallax-motion' ) ) : 'auto',
				);
				$attributes['data-siteorigin-parallax'] = json_encode( $parallax_args );
			}
		}

		if ( ! empty( $style['id'] ) ) {
			$attributes['id'] = sanitize_html_class( $style['id'] );
		}

		return $attributes;
	}

	static function row_style_attributes( $attributes, $style ) {
		if ( ! empty( $style['row_stretch'] ) ) {
			$attributes['class'][]           = 'siteorigin-panels-stretch';
			$attributes['data-stretch-type'] = $style['row_stretch'];
			wp_enqueue_script( 'siteorigin-panels-front-styles' );
		}

		return $attributes;
	}

	static function vantage_row_style_attributes( $attributes, $style ) {
		if ( isset( $style['class'] ) && $style['class'] == 'wide-grey' && ! empty( $attributes['style'] ) ) {
			$attributes['style'] = preg_replace( '/padding-left: 1000px; padding-right: 1000px;/', '', $attributes['style'] );
		}

		return $attributes;
	}

	/**
	 * Get the CSS styles that apply to all rows, cells and widgets
	 *
	 * @param $css
	 * @param $style
	 *
	 * @return mixed
	 */
	static function general_style_css( $css, $style ){

		if ( ! empty( $style['background'] ) ) {
			$css[ 'background-color' ] = $style['background'];
		}

		if ( ! empty( $style['background_display'] ) &&
			 ! ( empty( $style['background_image_attachment'] ) && empty( $style['background_image_attachment_fallback'] ) )
		) {
			$url = self::get_attachment_image_src( $style['background_image_attachment'], 'full' );
			
			if ( empty( $url ) && ! empty( $style['background_image_attachment_fallback'] ) ) {
				$url = $style['background_image_attachment_fallback'];
			}

			if ( ! empty( $url ) ) {
				$css['background-image'] = 'url(' .( is_array( $url ) ? $url[0] : $url ) . ')';

				switch ( $style['background_display'] ) {
					case 'parallax':
					case 'parallax-original':
						$css[ 'background-position' ] = 'center center';
						$css[ 'background-repeat' ] = 'no-repeat';
						break;
					case 'tile':
						$css[ 'background-repeat' ] = 'repeat';
						break;
					case 'cover':
						$css[ 'background-position' ] = 'center center';
						$css[ 'background-size' ] = 'cover';
						break;
					case 'contain':
						$css[ 'background-size' ] = 'contain';
						break;
					case 'center':
						$css[ 'background-position' ] = 'center center';
						$css[ 'background-repeat' ] = 'no-repeat';
						break;
					case 'fixed':
						$css[ 'background-attachment' ] = 'fixed';
						$css[ 'background-position' ] = 'center center';
						$css[ 'background-size' ] = 'cover';
						break;
				}
			}
		}

		if ( ! empty( $style[ 'border_color' ] ) ) {
			$css[ 'border' ] = '1px solid ' . $style['border_color'];
		}

		if ( ! empty( $style[ 'font_color' ] ) ) {
			$css[ 'color' ] = $style['font_color'];
		}

		if( ! empty( $style[ 'padding' ] ) ) {
			$css['padding'] = $style[ 'padding' ];
		}

		// Find which key the CSS is stored in
		foreach( array( 'row_css', 'cell_css', 'widget_css', '' ) as $css_key ) {
			if( empty( $css_key ) || ! empty( $style[ $css_key ] ) ) {
				break;
			}
		}
		if ( ! empty( $css_key ) && ! empty( $style[ $css_key ] ) ) {
			preg_match_all( '/^([A-Za-z0-9\-]+?):(.+?);?$/m', $style[ $css_key ], $matches );

			if ( ! empty( $matches[0] ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i ++ ) {
					$css[ $matches[1][ $i ] ] = $matches[2][ $i ];
				}
			}
		}

		return $css;
	}

	/**
	 * Get the mobile styling for rows, cells and widgets
	 *
	 * @param $css
	 * @param $style
	 *
	 * @return mixed
	 */
	static function general_style_mobile_css( $css, $style ){
		if( ! empty( $style['mobile_padding'] ) ) {
			$css['padding'] = $style[ 'mobile_padding' ];
		}
		
		if ( ! empty( $style['background_display'] ) &&
			 $style['background_display'] == 'fixed'  &&
			 ! ( empty( $style['background_image_attachment'] ) && empty( $style['background_image_attachment_fallback'] ) )
		) {
			$css[ 'background-attachment' ] = 'scroll';
		}

		if ( ! empty( $style[ 'mobile_css' ] ) ) {
			preg_match_all( '/^([A-Za-z0-9\-]+?):(.+?);?$/m', $style[ 'mobile_css' ], $matches );

			if ( ! empty( $matches[0] ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i ++ ) {
					$css[ $matches[1][ $i ] ] = $matches[2][ $i ];
				}
			}
		}

		return $css;
	}

	/**
	 * @param SiteOrigin_Panels_Css_Builder $css
	 * @param $panels_data
	 * @param $post_id
	 *
	 * @return mixed
	 */
	static function filter_css_object( $css, $panels_data, $post_id, $layout ) {
		$mobile_width = siteorigin_panels_setting( 'mobile-width' );
		if( empty( $layout ) ) {
			return $css;
		}

		foreach( $layout as $ri => $row ) {
			if( empty( $row[ 'style' ] ) ) $row[ 'style' ] = array();

			$standard_css = apply_filters( 'siteorigin_panels_row_style_css', array(), $row['style'] );
			$mobile_css = apply_filters( 'siteorigin_panels_row_style_mobile_css', array(), $row['style'] );

			if ( ! empty( $standard_css ) ) {
				$css->add_row_css(
					$post_id,
					$ri,
					'> .panel-row-style',
					$standard_css
				);
			}
			if ( ! empty( $mobile_css ) ) {
				$css->add_row_css(
					$post_id,
					$ri,
					'> .panel-row-style',
					$mobile_css,
					$mobile_width
				);
			}

			// Add in flexbox alignment to the main row element
			if ( siteorigin_panels_setting( 'legacy-layout' ) != 'always' && ! SiteOrigin_Panels::is_legacy_browser() && ! empty( $row['style']['cell_alignment'] ) ) {
				$css->add_row_css(
					$post_id,
					$ri,
					array( '.panel-no-style', '.panel-has-style > .panel-row-style' ),
					array(
						'-webkit-align-items' => $row['style']['cell_alignment'],
						'align-items'         => $row['style']['cell_alignment'],
					)
				);
			}

			// Process the cells if there are any
			if( empty( $row[ 'cells' ] ) ) continue;

			foreach( $row[ 'cells' ] as $ci => $cell ) {
				if( empty( $cell[ 'style' ] ) ) $cell[ 'style' ] = array();

				$standard_css = apply_filters( 'siteorigin_panels_cell_style_css', array(), $cell['style'] );
				$mobile_css = apply_filters( 'siteorigin_panels_cell_style_mobile_css', array(), $cell['style'] );

				if ( ! empty( $standard_css ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						'> .panel-cell-style',
						$standard_css
					);
				}
				if ( ! empty( $mobile_css ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						'> .panel-cell-style',
						$mobile_css,
						$mobile_width
					);
				}

				if ( ! empty( $cell[ 'style' ]['vertical_alignment'] ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						'',
						array(
							'align-self' => $cell[ 'style' ]['vertical_alignment']
						)
					);
				}

				// Process the widgets if there are any
				if( empty( $cell[ 'widgets' ] ) ) continue;

				foreach( $cell['widgets'] as $wi => $widget ) {
					if ( empty( $widget['panels_info'] ) ) continue;
					if ( empty( $widget['panels_info']['style'] ) ) $widget['panels_info']['style'] = array();

					$standard_css = apply_filters( 'siteorigin_panels_widget_style_css', array(), $widget['panels_info']['style'] );
					$mobile_css = apply_filters( 'siteorigin_panels_widget_style_mobile_css', array(), $widget['panels_info']['style'] );

					if( ! empty( $standard_css ) ) {
						$css->add_widget_css(
							$post_id,
							$ri,
							$ci,
							$wi,
							'> .panel-widget-style',
							$standard_css
						);
					}

					if( ! empty( $mobile_css ) ) {
						$css->add_widget_css(
							$post_id,
							$ri,
							$ci,
							$wi,
							'> .panel-widget-style',
							$mobile_css,
							$mobile_width
						);
					}
					
					if ( ! empty( $widget['panels_info']['style']['link_color'] ) ) {
						$css->add_widget_css( $post_id, $ri, $ci, $wi, ' a', array(
							'color' => $widget['panels_info']['style']['link_color']
						) );
					}
				}
			}
		}

		return $css;
	}
	
	/**
	 * Add in custom styles for the row's bottom margin
	 *
	 * @param $margin
	 * @param $grid
	 *
	 * @return mixed
	 */
	static function filter_row_bottom_margin( $margin, $grid ) {
		if ( ! empty( $grid['style']['bottom_margin'] ) ) {
			$margin = $grid['style']['bottom_margin'];
		}

		return $margin;
	}
	
	/**
	 * Add in custom styles for spacing between cells in a row.
	 *
	 * @param $margin
	 * @param $cell
	 *
	 * @return mixed
	 */
	static function filter_row_cell_bottom_margin($margin, $cell, $ci, $row, $ri){
		if ( ! empty( $row['style']['mobile_cell_margin'] ) ) {
			$margin = $row['style']['mobile_cell_margin'];
		}
		
		return $margin;
	}
	
	/**
	 * Add in custom styles for a row's mobile bottom margin
	 *
	 * @param $margin
	 * @param $grid
	 *
	 * @return mixed
	 */
	static function filter_row_mobile_bottom_margin( $margin, $grid ) {
		if ( ! empty( $grid['style']['mobile_bottom_margin'] ) ) {
			$margin = $grid['style']['mobile_bottom_margin'];
		}

		return $margin;
	}

	static function filter_row_gutter( $gutter, $grid ) {
		if ( ! empty( $grid['style']['gutter'] ) ) {
			$gutter = $grid['style']['gutter'];
		}

		return $gutter;
	}
	
	/**
	 * Adds widget specific styles not included in the general style fields.
	 *
	 * @param $widget_css The CSS properties and values
	 * @param $widget_style_data The style settings as obtained from the style fields.
	 *
	 * @return mixed
	 */
	static function filter_widget_style_css( $widget_css, $widget_style_data ) {
		if ( ! empty( $widget_style_data['margin'] ) ) {
			$widget_css['margin'] = $widget_style_data['margin'];
		}
		
		return $widget_css;
	}
	
	public static function get_attachment_image_src( $image, $size = 'full' ){
		if( empty( $image ) ) {
			return false;
		}
		else if( is_numeric( $image ) ) {
			return wp_get_attachment_image_src( $image, $size );
		}
		else if( is_string( $image ) ) {
			preg_match( '/(.*?)\#([0-9]+)x([0-9]+)$/', $image, $matches );
			return ! empty( $matches ) ? $matches : false;
		}
	}

}
