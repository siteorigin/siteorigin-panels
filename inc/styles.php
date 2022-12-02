<?php
/**
 * Class for handling all the default styling.
 *
 * Class SiteOrigin_Panels_Default_Styles
 */
class SiteOrigin_Panels_Styles {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ), 5 );

		// Adding all the fields.
		add_filter( 'siteorigin_panels_row_style_fields', array( $this, 'row_style_fields' ) );
		add_filter( 'siteorigin_panels_cell_style_fields', array( $this, 'cell_style_fields' ) );
		add_filter( 'siteorigin_panels_widget_style_fields', array( $this, 'widget_style_fields' ) );

		// Style wrapper attributes.
		add_filter( 'siteorigin_panels_row_style_attributes', array( $this, 'general_style_attributes' ), 10, 2 );
		add_filter( 'siteorigin_panels_row_style_attributes', array( $this, 'row_style_attributes' ), 10, 2 );
		add_filter( 'siteorigin_panels_row_style_attributes', array( $this, 'vantage_row_style_attributes' ), 11, 2 );
		add_filter( 'siteorigin_panels_cell_style_attributes', array( $this, 'general_style_attributes' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_attributes', array( $this, 'general_style_attributes' ), 10, 2 );

		// Style wrapper CSS.
		add_filter( 'siteorigin_panels_row_style_css', array( $this, 'general_style_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_cell_style_css', array( $this, 'general_style_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_css', array( $this, 'general_style_css' ), 10, 2 );

		add_filter( 'siteorigin_panels_row_style_tablet_css', array( $this, 'general_style_tablet_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_cell_style_tablet_css', array( $this, 'general_style_tablet_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_tablet_css', array( $this, 'general_style_tablet_css' ), 10, 2 );

		add_filter( 'siteorigin_panels_row_style_mobile_css', array( $this, 'general_style_mobile_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_cell_style_mobile_css', array( $this, 'general_style_mobile_css' ), 10, 2 );
		add_filter( 'siteorigin_panels_widget_style_mobile_css', array( $this, 'general_style_mobile_css' ), 10, 2 );

		// Main filter to add any custom CSS.
		add_filter( 'siteorigin_panels_css_object', array( $this, 'filter_css_object' ), 10, 4 );

		// Filtering specific attributes.
		add_filter( 'siteorigin_panels_css_row_margin_bottom', array( $this, 'filter_row_bottom_margin' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_row_mobile_margin_bottom', array( $this, 'filter_row_mobile_bottom_margin' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_cell_mobile_margin_bottom', array( $this, 'filter_row_cell_bottom_margin' ), 10, 5 );
		add_filter( 'siteorigin_panels_css_widget_mobile_margin', array( $this, 'filter_widget_mobile_margin' ), 10, 5 );

		add_filter( 'siteorigin_panels_css_row_gutter', array( $this, 'filter_row_gutter' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_widget_css', array( $this, 'filter_widget_style_css' ), 10, 2 );

		// New Parallax.
		if ( siteorigin_panels_setting( 'parallax-type' ) == 'modern' ) {
			add_filter( 'siteorigin_panels_inside_row_before', array( $this, 'add_parallax' ), 10, 2 );
			add_filter( 'siteorigin_panels_inside_cell_before', array( $this, 'add_parallax' ), 10, 2 );
			add_filter( 'siteorigin_panels_inside_widget_before', array( $this, 'add_parallax' ), 10, 2 );
		}
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	static function register_scripts() {
		wp_register_script(
			'siteorigin-panels-front-styles',
			siteorigin_panels_url( 'js/styling' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
			array( 'jquery' ),
			SITEORIGIN_PANELS_VERSION
		);

		$container_settings = SiteOrigin_Panels::container_settings();
		wp_localize_script( 'siteorigin-panels-front-styles', 'panelsStyles', array(
			'fullContainer' => apply_filters( 'siteorigin_panels_full_width_container', siteorigin_panels_setting( 'full-width-container' ) ),
			'stretchRows' => ! $container_settings['css_override'],
		) );

		if ( siteorigin_panels_setting( 'parallax-type' ) == 'modern' ) {
			wp_register_script(
				'simpleParallax',
				siteorigin_panels_url( 'js/lib/simpleparallax' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
				array( 'siteorigin-panels-front-styles' ),
				'5.5.1'
			);

			wp_localize_script( 'simpleParallax', 'parallaxStyles', array(
				'mobile-breakpoint' => siteorigin_panels_setting( 'mobile-width' ) . 'px',
				'disable-parallax-mobile' => ! empty( siteorigin_panels_setting( 'parallax-mobile' ) ),
				'delay' => ! empty( siteorigin_panels_setting( 'parallax-delay' ) ) ? siteorigin_panels_setting( 'parallax-delay' ) : 0.4,
				'scale' => ! empty( siteorigin_panels_setting( 'parallax-scale' ) ) ? siteorigin_panels_setting( 'parallax-scale' ) : 1.1,
			) );
		} else {
			wp_register_script(
				'siteorigin-parallax',
				siteorigin_panels_url( 'js/siteorigin-legacy-parallax' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
				array( 'jquery' ),
				SITEORIGIN_PANELS_VERSION
			);
			wp_localize_script( 'siteorigin-parallax', 'parallaxStyles', array(
				'parallax-mobile' => ! empty( siteorigin_panels_setting( 'parallax-mobile' ) ) ?: siteorigin_panels_setting( 'parallax-mobile' ),
				'mobile-breakpoint' => siteorigin_panels_setting( 'mobile-width' ) . 'px',
			) );
		}
	}

	/**
	 * These are general styles that apply to all elements.
	 *
	 * @param $label
	 *
	 * @return array
	 */
	static function get_general_style_fields( $id, $label ) {
		$fields = array();

		// All the attribute fields.
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
			'description' => sprintf(__( 'A custom class used for this %s.', 'siteorigin-panels' ), strtolower( $label ) ),
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

		// The layout fields.
		$fields['padding'] = array(
			'name'        => __( 'Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => sprintf( __( 'Padding around the entire %s.', 'siteorigin-panels' ), strtolower( $label ) ),
			'priority'    => 7,
			'multiple'    => true
		);

		// Tablet layout fields.
		if ( siteorigin_panels_setting( 'tablet-layout' ) ) {
			$fields['tablet_padding'] = array(
				'name'        => __( 'Tablet Padding', 'siteorigin-panels' ),
				'type'        => 'measurement',
				'group'       => 'tablet_layout',
				'description' => __( 'Padding when on tablet devices.', 'siteorigin-panels' ),
				'priority'    => 8,
				'multiple'    => true
			);
		}

		// Mobile layout fields.
		if ( $label == 'Widget' ) {
			$fields['mobile_margin'] = array(
				'name'        => __( 'Mobile Margin', 'siteorigin-panels' ),
				'type'        => 'measurement',
				'group'       => 'mobile_layout',
				'description' => __( 'Margins around the widget when on mobile devices.', 'siteorigin-panels' ),
				'priority'    => 8,
				'multiple'    => true
			);
		}

		$fields['mobile_padding'] = array(
			'name'        => __( 'Mobile Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'mobile_layout',
			'description' => __( 'Padding when on mobile devices.', 'siteorigin-panels' ),
			'priority'    => 9,
			'multiple'    => true
		);

		// The general design fields.

		$fields['background'] = array(
			'name'        => __( 'Background Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 5,
		);

		$fields['background_image_attachment'] = array(
			'name'        => __( 'Background Image', 'siteorigin-panels' ),
			'type'        => 'image',
			'group'       => 'design',
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
			),
			'priority'    => 7,
		);

		$fields['background_image_size'] = array(
			'name'        => __( 'Background Image Size', 'siteorigin-panels' ),
			'type'        => 'image_size',
			'group'       => 'design',
			'priority'    => 8,
		);

		$fields['border_color'] = array(
			'name'        => __( 'Border Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 10,
		);

		$fields['border_thickness'] = array(
			'name'        => __( 'Border Thickness', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'design',
			'default'     => '1px',
			'priority'    => 11,
		);

		$fields['border_radius'] = array(
			'name'        => __( 'Border Radius', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'design',
			'priority'    => 12,
			'multiple'    => true
		);

		$fields['box_shadow'] = array(
			'name'        => __( 'Box Shadow', 'siteorigin-panels' ),
			'type'        => 'toggle',
			'group'       => 'design',
			'priority'    => 20,
			'fields' => array(
				'color' => array(
					'name'        => __( 'Color', 'siteorigin-panels' ),
					'type'        => 'color',
					'priority'    => 10,
					'default'     => '#000000',
				),
				'opacity' => array(
					'name'        => __( 'Opacity', 'siteorigin-panels' ),
					'type'        => 'number',
					'priority'    => 20,
					'default'     => 15,
					'description' => __( 'Enter a value between 0 and 100.', 'siteorigin-panels' ),
				),	
				'inset' => array(
					'name'        => __( 'Inset', 'siteorigin-panels' ),
					'type'        => 'checkbox',
					'priority'    => 30,
					'default'     => false,
					'description' => sprintf( __( 'Inset box shadows appear inside the %s.', 'siteorigin-panels' ), strtolower( $label ) ),
				),				
				'offset_horizontal' => array(
					'name'        => __( 'Horizontal Offset', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 40,
					'default'     => 0,
				),
				'offset_vertical' => array(
					'name'        => __( 'Vertical Offset', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 50,
					'default'     => '5px',
				),
				'blur' => array(
					'name'        => __( 'Blur', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 60,
					'default'     => '15px',
				),
				'spread' => array(
					'name'        => __( 'Spread', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 70,
				),
			),
		);

		$fields['box_shadow_hover'] = array(
			'name'        => __( 'Box Shadow Hover', 'siteorigin-panels' ),
			'type'        => 'toggle',
			'group'       => 'design',
			'priority'    => 25,
			'fields' => array(
				'color' => array(
					'name'        => __( 'Color', 'siteorigin-panels' ),
					'type'        => 'color',
					'priority'    => 10,
					'default'     => '#000000',
				),
				'opacity' => array(
					'name'        => __( 'Opacity', 'siteorigin-panels' ),
					'type'        => 'number',
					'priority'    => 20,
					'default'     => 30,
					'description' => __( 'Enter a value between 0 and 100.', 'siteorigin-panels' ),
				),
				'inset' => array(
					'name'        => __( 'Inset', 'siteorigin-panels' ),
					'type'        => 'checkbox',
					'priority'    => 30,
					'default'     => false,
					'description' => sprintf( __( 'Inset box shadows appear inside the %s.', 'siteorigin-panels' ), strtolower( $label ) ),
				),				
				'offset_horizontal' => array(
					'name'        => __( 'Horizontal Offset', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 30,
					'default'     => 0,
				),
				'offset_vertical' => array(
					'name'        => __( 'Vertical Offset', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 40,
					'default'     => '5px',
				),
				'blur' => array(
					'name'        => __( 'Blur', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 50,
					'default'     => '15px',
				),
				'spread' => array(
					'name'        => __( 'Spread', 'siteorigin-panels' ),
					'type'        => 'measurement',
					'priority'    => 60,
				),
			),
		);

		return $fields;
	}

	/**
	 * All the row styling fields.
	 *
	 * @param $fields
	 *
	 * @return array
	 */
	static function row_style_fields( $fields ) {
		// Add the general fields.
		$fields = wp_parse_args( $fields, self::get_general_style_fields( 'row', __( 'Row', 'siteorigin-panels' ) ) );

		$fields['cell_class'] = array(
			'name'        => __( 'Cell Class', 'siteorigin-panels' ),
			'type'        => 'text',
			'group'       => 'attributes',
			'description' => __( 'Class added to all cells in this row.', 'siteorigin-panels' ),
			'priority'    => 6,
		);

		// Add the layout fields.
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
		
		if ( siteorigin_panels_setting( 'tablet-layout' ) ) {
			// Add the tablet layout fields.
			$fields['tablet_bottom_margin'] = array(
				'name'        => __( 'Tablet Bottom Margin', 'siteorigin-panels' ),
				'type'        => 'measurement',
				'group'       => 'tablet_layout',
				'description' => sprintf( __( 'Space below the row on tablet devices. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-bottom' ) ),
				'priority'    => 5,
			);
		}

		// Add the mobile layout fields.
		$fields['mobile_bottom_margin'] = array(
			'name'        => __( 'Mobile Bottom Margin', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'mobile_layout',
			'description' => sprintf( __( 'Space below the row on mobile devices. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-bottom' ) ),
			'priority'    => 5,
		);
		
		$fields['mobile_cell_margin'] = array(
			'name'        => __( 'Mobile Cell Bottom Margin', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'mobile_layout',
			'description' => sprintf( __( 'Vertical space between cells in a collapsed mobile row. Default is %spx.', 'siteorigin-panels' ), ! empty( siteorigin_panels_setting( 'mobile-cell-margin' ) ) ? siteorigin_panels_setting( 'mobile-cell-margin' ) : siteorigin_panels_setting( 'margin-bottom' ) ),
			'priority'    => 5,
		);
		
		return $fields;
	}

	/**
	 * All the cell styling fields.
	 *
	 * @param $fields
	 *
	 * @return array
	 */
	static function cell_style_fields( $fields ) {
		// Add the general fields.
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
			'priority'    => 15,
		);

		$fields['link_color'] = array(
			'name'        => __( 'Link Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 16,
		);

		$fields['link_color_hover'] = array(
			'name'        => __( 'Link Hover Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 17,
		);

		return $fields;
	}

	/**
	 * @param $fields
	 *
	 * @return array
	 */
	static function widget_style_fields( $fields ) {

		// Add the general fields.
		$fields = wp_parse_args( $fields, self::get_general_style_fields( 'widget', __( 'Widget', 'siteorigin-panels' ) ) );
		
		$fields['margin'] = array(
			'name'        => __( 'Margin', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
			'description' => __( 'Margins around the widget.', 'siteorigin-panels' ),
			'priority'    => 6,
			'multiple'    => true
		);

		// How lets add the design fields.

		$fields['font_color'] = array(
			'name'        => __( 'Font Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 15,
		);

		$fields['link_color'] = array(
			'name'        => __( 'Link Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 16,
		);

		$fields['link_color_hover'] = array(
			'name'        => __( 'Link Hover Color', 'siteorigin-panels' ),
			'type'        => 'color',
			'group'       => 'design',
			'priority'    => 17,
		);

		return $fields;
	}

	static function is_background_parallax( $type ) {
		return $type == 'parallax' || $type == 'parallax-original';
	}

	/**
	 * Style attributes that apply to rows, cells and widgets.
	 *
	 * @param $attributes
	 * @param $style
	 *
	 * @return array $attributes
	 */
	static function general_style_attributes( $attributes, $style ) {
		if ( ! empty( $style['class'] ) ) {
			if ( ! is_array( $style['class'] ) ) {
				$style['class'] = explode( ' ', $style[ 'class' ] );
			}
			$attributes['class'] = array_merge( $attributes['class'], $style['class'] );
		}

		if (
			! empty( $style['background_display'] ) &&
			self::is_background_parallax( $style['background_display'] ) &&
			(
				! empty( $style['background_image_attachment'] ) ||
				! empty( $style['background_image_attachment_fallback'] )
			)
		) {
			if ( siteorigin_panels_setting( 'parallax-type' ) == 'legacy' ) {
				$url = self::get_attachment_image_src( $style['background_image_attachment'], ! empty( $style['background_image_size'] ) ? $style['background_image_size'] : 'full' );
				if ( ! empty( $url ) ) {
					wp_enqueue_script( 'siteorigin-parallax' );
					$parallax_args = array(
						'backgroundUrl'    => $url[0],
						'backgroundSize'   => array( $url[1], $url[2] ),
						'backgroundSizing' => 'scaled',
						'limitMotion'      => siteorigin_panels_setting( 'parallax-motion' ) ? floatval( siteorigin_panels_setting( 'parallax-motion' ) ) : 'auto',
					);
					$attributes['data-siteorigin-parallax'] = json_encode( $parallax_args );
				}
			} else {
				$attributes['class'][] = 'so-parallax';
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

	function add_parallax( $output, $context ) {
		if (
			! empty( $context['style']['background_display'] ) &&
			self::is_background_parallax( $context['style']['background_display'] )
		) {
			$parallax = false;
			if ( ! empty( $context['style']['background_image_attachment'] ) ) {
				// Jetpack Image Accelerator (Photon) can result in the parallax being incorrectly sized so we need to exclude it.
				$photon_exclude = class_exists( 'Jetpack_Photon' ) && Jetpack::is_module_active( 'photon' );
				if ( $photon_exclude ) {
					add_filter( 'photon_validate_image_url', 'siteorigin_panels_photon_exclude_parallax_srcset', 10, 3 );
					// Prevent Photon from overriding the image URL later.
					add_filter( 'jetpack_photon_skip_image',  'siteorigin_panels_photon_exclude_parallax', 10, 3 );
				}

				$image_html = wp_get_attachment_image(
					$context['style']['background_image_attachment'],
					! empty( $context['style']['background_image_size'] ) ? $context['style']['background_image_size'] : 'full',
					false,
					array(
						'data-siteorigin-parallax' => 'true',
						'loading' => 'eager',
					)
				);

				if ( $photon_exclude ) {
					// Restore photon.
					remove_filter( 'photon_validate_image_url', 'siteorigin_panels_photon_exclude_parallax_downsize', 10 );
				}

				if ( ! empty( $image_html ) ) {
					$parallax = true;
					$output .= $image_html;
				}
			}

			if ( ! $parallax && ! empty( $context['style']['background_image_attachment_fallback'] ) ) {
				$parallax = true;
				$output .= '<img src=' . esc_url( $context['style']['background_image_attachment_fallback'] ) . ' data-siteorigin-parallax="true">';
			}

			if ( $parallax ) {
				wp_enqueue_script( 'simpleParallax' );
			}
		}

		return $output;
	}

	static function generate_box_shadow_css( $prefix, $style ) {
		if ( ! class_exists( 'SiteOrigin_Color_Object' ) ) require plugin_dir_path( __FILE__ ) . '../widgets/lib/color.php';

		$box_shadow_inset = ! empty( $style[ $prefix . '_inset' ] ) ? 'inset' : '';
		$box_shadow_offset_horizontal = ! empty( $style[ $prefix . '_offset_horizontal' ] ) ? $style[ $prefix . '_offset_horizontal' ] : 0;
		$box_shadow_offset_vertical = ! empty( $style[ $prefix . '_offset_vertical' ] ) ? $style[ $prefix . '_offset_vertical' ] : '5px';
		$box_shadow_blur = ! empty( $style[ $prefix . '_blur' ] ) ? $style[ $prefix . '_blur' ] : '15px';
		$box_shadow_spread = ! empty( $style[ $prefix . '_spread' ] ) ? $style[ $prefix . '_spread' ] : '';

		if ( ! empty( $style[ $prefix . '_color' ] ) ) {
			$box_shadow_color = new SiteOrigin_Color_Object( $style[ $prefix . '_color' ] );
			$box_shadow_color = $box_shadow_color->__get( 'rgb' );
			$box_shadow_color = "$box_shadow_color[0], $box_shadow_color[1], $box_shadow_color[2]";
		} else {
			$box_shadow_color = '0, 0, 0';
		}
		$box_shadow_default = $prefix == 'box_shadow' ? 0.15 : 0.30;
		$box_shadow_opacity = isset( $style[ $prefix . '_opacity' ] ) && is_numeric( $style[ $prefix . '_opacity' ] ) ? min( 100, $style[ $prefix . '_opacity' ] ) / 100 : $box_shadow_default;

		return array(
			'box-shadow' => "$box_shadow_inset $box_shadow_offset_horizontal $box_shadow_offset_vertical $box_shadow_blur $box_shadow_spread rgba($box_shadow_color, $box_shadow_opacity )"
		);
	}

	/**
	 * Get the CSS styles that apply to all rows, cells and widgets.
	 *
	 * @param $css
	 * @param $style
	 *
	 * @return mixed
	 */
	static function general_style_css( $css, $style ) {

		if ( ! empty( $style['background'] ) ) {
			$css[ 'background-color' ] = $style['background'];
		}

		if (
			(
				! empty( $style['background_image_attachment'] ) ||
				! empty( $style['background_image_attachment_fallback'] )
			) &&
			! empty( $style['background_display'] ) &&
			(
				! self::is_background_parallax( $style['background_display'] ) ||
				siteorigin_panels_setting( 'parallax-type' ) == 'legacy'
			)
		) {

			if ( ! empty( $style['background_image_attachment'] ) ) {
				$url = self::get_attachment_image_src( $style['background_image_attachment'], ! empty( $style['background_image_size'] ) ? $style['background_image_size'] : 'full' );
			}
			
			if ( empty( $url ) && ! empty( $style['background_image_attachment_fallback'] ) ) {
				$url = $style['background_image_attachment_fallback'];
			}

			if ( ! empty( $url ) ) {
				$css['background-image'] = 'url(' .( is_array( $url ) ? $url[0] : $url ) . ')';

				switch ( $style['background_display'] ) {
					case 'parallax':
					case 'parallax-original':
						// Only used by Legacy Parallax.
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
			$css[ 'border' ] = ( ! empty( $style['border_thickness'] ) ? $style['border_thickness'] : '1px' ) . ' solid ' . $style['border_color'];
		}

		if ( ! empty( $style[ 'font_color' ] ) ) {
			$css[ 'color' ] = $style['font_color'];
		}

		if ( ! empty( $style[ 'padding' ] ) ) {
			$css['padding'] = $style[ 'padding' ];
		}

		// Find which key the CSS is stored in.
		foreach ( array( 'row_css', 'cell_css', 'widget_css', '' ) as $css_key ) {
			if ( empty( $css_key ) || ! empty( $style[ $css_key ] ) ) {
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

		if ( isset( $style['border_radius'] ) ) {
			$css['border-radius'] = $style['border_radius'];
		}

		if ( ! empty( $style['box_shadow'] ) ) {
			$css['box-shadow'] = self::generate_box_shadow_css( 'box_shadow', $style )['box-shadow'];
		}

		if ( ! empty( $style['box_shadow_hover'] ) && empty( $css['transition'] ) ) {
			$css['transition'] = '300ms ease-in-out box-shadow';
		}

		return $css;
	}

	/**
	 * Get the tablet styling for rows, cells and widgets.
	 *
	 * @param $css
	 * @param $style
	 *
	 * @return mixed
	 */
	static function general_style_tablet_css( $css, $style ) {
		if ( ! empty( $style['tablet_padding'] ) ) {
			$css['padding'] = $style[ 'tablet_padding' ];
		}

		if (
			! empty( $style['background_display'] ) &&
			 $style['background_display'] == 'fixed'  &&
			 ! ( empty( $style['background_image_attachment'] ) && empty( $style['background_image_attachment_fallback'] ) )
		) {
			$css[ 'background-attachment' ] = 'scroll';
		}

		return $css;
	}

	/**
	 * Get the mobile styling for rows, cells and widgets.
	 *
	 * @param $css
	 * @param $style
	 *
	 * @return mixed
	 */
	static function general_style_mobile_css( $css, $style ) {
		if ( ! empty( $style['mobile_padding'] ) ) {
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
		$tablet_width = siteorigin_panels_setting( 'tablet-width' );
		$tablet_layout = siteorigin_panels_setting( 'tablet-layout' );
		$mobile_width = siteorigin_panels_setting( 'mobile-width' );
		if ( empty( $layout ) ) {
			return $css;
		}

		foreach ( $layout as $ri => $row ) {
			if ( empty( $row[ 'style' ] ) ) $row[ 'style' ] = array();

			$standard_css = apply_filters( 'siteorigin_panels_row_style_css', array(), $row['style'] );
			$tablet_css = $tablet_layout ? apply_filters( 'siteorigin_panels_row_style_tablet_css', array(), $row['style'] ) : '';
			$mobile_css = apply_filters( 'siteorigin_panels_row_style_mobile_css', array(), $row['style'] );

			if ( ! empty( $standard_css ) ) {
				$css->add_row_css(
					$post_id,
					$ri,
					'> .panel-row-style',
					$standard_css
				);
			}

			if ( ! empty( $tablet_css ) ) {
				$css->add_row_css(
					$post_id,
					$ri,
					'> .panel-row-style',
					$tablet_css,
					"$tablet_width:$mobile_width"
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

			if ( ! empty( $row['style']['box_shadow_hover'] ) ) {
				$css->add_row_css(
					$post_id,
					$ri,
					'> .panel-row-style:hover',
					self::generate_box_shadow_css(
						'box_shadow_hover',
						$row['style']
					)
				);
			}

			// Add in flexbox alignment to the main row element.
			if ( siteorigin_panels_setting( 'legacy-layout' ) != 'always' && ! SiteOrigin_Panels::is_legacy_browser() && ! empty( $row['style']['cell_alignment'] ) ) {

				$selector = array();
				$container_settings = SiteOrigin_Panels::container_settings();
				// What selector we use is dependent on their row setup.
				if ( // Is CSS Container Breaker is enabled, and is the row full width?
					$container_settings['css_override'] &&
					isset( $row['style']['row_stretch'] ) &&
					$row['style']['row_stretch'] == 'full'
				) {
					$selector[] = '.panel-has-style > .panel-row-style > .so-panels-full-wrapper';
				} else {
					$selector[] = '.panel-has-style > .panel-row-style';
					$selector[] = '.panel-no-style';
				}

				$css->add_row_css(
					$post_id,
					$ri,
					$selector,
					array(
						'-webkit-align-items' => $row['style']['cell_alignment'],
						'align-items'         => $row['style']['cell_alignment'],
					)
				);
			}

			// Process the cells if there are any.
			if ( empty( $row[ 'cells' ] ) ) continue;

			foreach ( $row[ 'cells' ] as $ci => $cell ) {
				if ( empty( $cell[ 'style' ] ) ) $cell[ 'style' ] = array();

				$standard_css = apply_filters( 'siteorigin_panels_cell_style_css', array(), $cell['style'] );
				$tablet_css = $tablet_layout ? apply_filters( 'siteorigin_panels_cell_style_tablet_css', array(), $cell['style'] ) : '';
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
				if ( ! empty( $tablet_css ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						'> .panel-cell-style',
						$tablet_css,
						"$tablet_width:$mobile_width"
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

				if ( ! empty( $cell['style']['box_shadow_hover'] ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						'> .panel-cell-style:hover',
						self::generate_box_shadow_css(
							'box_shadow_hover',
							$cell['style']
						)
					);
				}

				if ( ! empty( $cell['style']['link_color'] ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						' a',
						array(
							'color' => $cell['style']['link_color']
						)
					);
				}

				if ( ! empty( $cell['style']['link_color_hover'] ) ) {
					$css->add_cell_css(
						$post_id,
						$ri,
						$ci,
						' a:hover', array(
							'color' => $cell['style']['link_color_hover']
						)
					);
				}

				// Process the widgets if there are any.
				if ( empty( $cell[ 'widgets' ] ) ) continue;

				foreach ( $cell['widgets'] as $wi => $widget ) {
					if ( empty( $widget['panels_info'] ) ) continue;
					if ( empty( $widget['panels_info']['style'] ) ) $widget['panels_info']['style'] = array();

					$standard_css = apply_filters( 'siteorigin_panels_widget_style_css', array(), $widget['panels_info']['style'] );
					$tablet_css = $tablet_layout ? apply_filters( 'siteorigin_panels_widget_style_tablet_css', array(), $widget['panels_info']['style'] ) : '';
					$mobile_css = apply_filters( 'siteorigin_panels_widget_style_mobile_css', array(), $widget['panels_info']['style'] );

					if ( ! empty( $standard_css ) ) {
						$css->add_widget_css(
							$post_id,
							$ri,
							$ci,
							$wi,
							'> .panel-widget-style',
							$standard_css
						);
					}

					if ( ! empty( $tablet_css ) ) {
						$css->add_widget_css(
							$post_id,
							$ri,
							$ci,
							$wi,
							'> .panel-widget-style',
							$tablet_css,
							"$tablet_width:$mobile_width"
						);
					}
					if ( ! empty( $mobile_css ) ) {
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

					if ( ! empty( $widget['panels_info']['style']['link_color_hover'] ) ) {
						$css->add_widget_css( $post_id, $ri, $ci, $wi, ' a:hover', array(
							'color' => $widget['panels_info']['style']['link_color_hover']
						) );
					}

					if ( ! empty( $widget['panels_info']['style']['box_shadow_hover'] ) ) {
						$css->add_widget_css(
							$post_id,
							$ri,
							$ci,
							$wi,
							'> .panel-widget-style:hover',
							self::generate_box_shadow_css(
								'box_shadow_hover',
								$widget['panels_info']['style']
							)
						);
					}
				}
			}
		}

		return $css;
	}
	
	/**
	 * Add in custom styles for the row's bottom margin.
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
	static function filter_row_cell_bottom_margin($margin, $cell, $ci, $row, $ri) {
		if ( ! empty( $row['style']['mobile_cell_margin'] ) ) {
			$margin = $row['style']['mobile_cell_margin'];
		}
		
		return $margin;
	}


	static function filter_widget_mobile_margin( $margin, $widget, $wi, $panels_data, $post_id ) {
		if ( ! empty( $widget['style']['mobile_margin'] ) ) {
			$margin = $widget['style']['mobile_margin'];
		}

		return $margin;
	}
	
	/**
	 * Add in custom styles for a row's mobile bottom margin.
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
	 * @param $widget_css The CSS properties and values.
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
	
	public static function get_attachment_image_src( $image, $size = 'full' ) {
		if ( empty( $image ) ) {
			return false;
		}
		elseif ( is_numeric( $image ) ) {
			return wp_get_attachment_image_src( $image, $size );
		}
		elseif ( is_string( $image ) ) {
			preg_match( '/(.*?)\#([0-9]+)x([0-9]+)$/', $image, $matches );
			return ! empty( $matches ) ? $matches : false;
		}
	}

}
