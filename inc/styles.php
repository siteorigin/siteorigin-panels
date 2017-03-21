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
		add_filter( 'siteorigin_panels_css_object', array( $this, 'filter_css_object' ), 10, 3 );

		// Filtering specific attributes
		add_filter( 'siteorigin_panels_css_row_margin_bottom', array( $this, 'filter_row_bottom_margin' ), 10, 2 );
		add_filter( 'siteorigin_panels_css_row_gutter', array( $this, 'filter_row_gutter' ), 10, 2 );
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	static function register_scripts() {
		wp_register_script( 'siteorigin-panels-front-styles', plugin_dir_url( __FILE__ ) . '../js/styling' . SITEORIGIN_PANELS_VERSION_SUFFIX . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'jquery' ), SITEORIGIN_PANELS_VERSION );
		wp_register_script( 'siteorigin-parallax', plugin_dir_url( __FILE__ ) . '../js/siteorigin-parallax' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'jquery' ), SITEORIGIN_PANELS_VERSION );
		wp_localize_script( 'siteorigin-panels-front-styles', 'panelsStyles', array(
			'fullContainer' => apply_filters( 'siteorigin_panels_full_width_container', siteorigin_panels_setting( 'full-width-container' ) )
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
			'name'        => __( 'CSS Styles', 'siteorigin-panels' ),
			'type'        => 'code',
			'group'       => 'attributes',
			'description' => __( 'One style attribute per line.', 'siteorigin-panels' ),
			'priority'    => 10,
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

		$fields['mobile_padding'] = array(
			'name'        => __( 'Mobile Padding', 'siteorigin-panels' ),
			'type'        => 'measurement',
			'group'       => 'layout',
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

		//Should we remove this? Or move it's existing value to new Cell Styles?
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
			'description' => sprintf( __( 'Amount of space between columns. Default is %spx.', 'siteorigin-panels' ), siteorigin_panels_setting( 'margin-sides' ) ),
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
			),
			'priority' => 10,
		);

		$fields['mobile_collapse'] = array(
			'name'     => __( 'Collapse On Mobile', 'siteorigin-panels' ),
			'type'     => 'checkbox',
			'group'    => 'layout',
			'default'  => true,
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
			$attributes['class'] = array_merge( $attributes['class'], explode( ' ', $style['class'] ) );
		}

		if ( ! empty( $style['background_display'] ) && ! empty( $style['background_image_attachment'] ) ) {

			if ( $style['background_display'] == 'parallax' || $style['background_display'] == 'parallax-original' ) {
				wp_enqueue_script( 'siteorigin-panels-front-styles' );
			}

			$url = wp_get_attachment_image_src( $style['background_image_attachment'], 'full' );

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

		// Find which key the CSS is stored in
		foreach( array( 'row_css', 'cell_css', 'widget_css', '' ) as $css_key ) {
			if( empty( $css_key ) || ! empty( $style[ $css_key ] ) ) {
				break;
			}
		}

		if ( ! empty( $css_key ) && ! empty( $style[ $css_key ] ) ) {
			preg_match_all( '/^(.+?):(.+?);?$/m', $style[ $css_key ], $matches );

			if ( ! empty( $matches[0] ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i ++ ) {
					$css[ $matches[1][ $i ] ] = $matches[2][ $i ];
				}
			}
		}

		if ( ! empty( $style['background'] ) ) {
			$css[ 'background-color' ] = $style['background'];
		}

		if ( ! empty( $style['background_display'] ) && ! empty( $style['background_image_attachment'] ) ) {

			$url = wp_get_attachment_image_src( $style['background_image_attachment'], 'full' );

			if ( ! empty( $url ) ) {
				$css[ 'background-image' ] = 'url(' . $url[0] . ')';

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
						$css[ 'background-size' ] = 'cover';
						break;
					case 'center':
						$css[ 'background-position' ] = 'center center';
						$css[ 'background-repeat' ] = 'no-repeat';
						break;
					case 'fixed':
						$css[ 'background-attachment' ] = 'fixed';
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

		return $css;
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

		// Add in the row  styling
		foreach ( $panels_data['grids'] as $i => $row ) {
			if ( empty( $row['style'] ) ) {
				continue;
			}

			$standard_css = apply_filters( 'siteorigin_panels_row_style_css', array(), $row['style'] );
			$mobile_css = apply_filters( 'siteorigin_panels_row_style_mobile_css', array(), $row['style'] );

			if ( ! empty( $standard_css ) ) {
				$css->add_row_css(
					$post_id,
					$i,
					'> .panel-row-style',
					$standard_css
				);
			}
			if ( ! empty( $mobile_css ) ) {
				$css->add_row_css(
					$post_id,
					$i,
					'> .panel-row-style',
					$mobile_css,
					$mobile_width
				);
			}

			// Add in flexbox alignment to the main row element
			if ( ! empty( $row['style']['cell_alignment'] ) ) {
				$css->add_row_css(
					$post_id,
					$i,
					'',
					array(
						'-webkit-align-items' => $row['style']['cell_alignment'],
						'align-items'         => $row['style']['cell_alignment'],
					)
				);
			}
		}

		foreach ( $panels_data['grid_cells'] as $i => $cell ) {
			if ( empty( $cell['style'] ) ) {
				continue;
			}

			$standard_css = apply_filters( 'siteorigin_panels_cell_style_css', array(), $cell['style'] );
			$mobile_css = apply_filters( 'siteorigin_panels_cell_style_mobile_css', array(), $cell['style'] );

			if ( ! empty( $standard_css ) ) {
				$css->add_cell_css(
					$post_id,
					$cell['grid'],
					$cell['index'],
					'> .panel-cell-style',
					$standard_css
				);
			}
			if ( ! empty( $mobile_css ) ) {
				$css->add_cell_css(
					$post_id,
					$cell['grid'],
					$cell['index'],
					'> .panel-cell-style',
					$mobile_css,
					$mobile_width
				);
			}
			if ( ! empty( $style['vertical_alignment'] ) ) {
				$css->add_cell_css(
					$post_id,
					$cell['grid'],
					$cell['index'],
					'',
					array(
						'align-self' => $style['vertical_alignment']
					)
				);
			}
		}

		// Add in the widget padding styling
		foreach ( $panels_data['widgets'] as $i => $widget ) {
			if ( empty( $widget['panels_info'] ) ) {
				continue;
			}

			$standard_css = apply_filters( 'siteorigin_panels_widget_style_css', array(), $widget['panels_info']['style'] );
			$mobile_css = apply_filters( 'siteorigin_panels_widget_style_mobile_css', array(), $widget['panels_info']['style'] );

			if( ! empty( $standard_css ) ) {
				$css->add_widget_css(
					$post_id,
					$widget['panels_info']['grid'],
					$widget['panels_info']['cell'],
					$widget['panels_info']['cell_index'],
					'> .panel-widget-style',
					$standard_css
				);
			}

			if( ! empty( $mobile_css ) ) {
				$css->add_widget_css(
					$post_id,
					$widget['panels_info']['grid'],
					$widget['panels_info']['cell'],
					$widget['panels_info']['cell_index'],
					'> .panel-widget-style',
					$mobile_css,
					$mobile_width
				);
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
