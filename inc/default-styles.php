<?php

/**
 * Register the custom styles scripts
 */
function siteorigin_panels_default_styles_register_scripts(){
	wp_register_script( 'siteorigin-panels-front-styles', plugin_dir_url(SITEORIGIN_PANELS_BASE_FILE) . 'js/styling' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array('jquery'), SITEORIGIN_PANELS_VERSION );
	wp_localize_script( 'siteorigin-panels-front-styles', 'panelsStyles', array(
		'fullContainer' => apply_filters( 'siteorigin_panels_full_width_container', siteorigin_panels_setting('full-width-container') )
	) );

	// Check if we need to enqueue the front styles
	if( is_singular() && get_post_meta( get_the_ID(), 'panels_data', true ) != '' ) {
		$panels_data = get_post_meta( get_the_ID(), 'panels_data', true );

		if( !empty($panels_data['grids']) ) {

			foreach( $panels_data['grids'] as $grid ) {
				if( empty($grid['style']['row_stretch']) ) continue;
				wp_enqueue_script( 'siteorigin-panels-front-styles' );
				break;
			}
		}
	}
}
add_action('wp_enqueue_scripts', 'siteorigin_panels_default_styles_register_scripts', 5);

/**
 * Class for handling all the default styling.
 *
 * Class SiteOrigin_Panels_Default_Styling
 */
class SiteOrigin_Panels_Default_Styling {

	static function init() {
		// Adding all the fields
		add_filter('siteorigin_panels_row_style_fields', array('SiteOrigin_Panels_Default_Styling', 'row_style_fields' ) );
		add_filter('siteorigin_panels_widget_style_fields', array('SiteOrigin_Panels_Default_Styling', 'widget_style_fields' ) );

		// Filter the row style
		add_filter('siteorigin_panels_row_style_attributes', array('SiteOrigin_Panels_Default_Styling', 'row_style_attributes' ), 10, 2);
		add_filter('siteorigin_panels_cell_style_attributes', array('SiteOrigin_Panels_Default_Styling', 'cell_style_attributes' ), 10, 2);
		add_filter('siteorigin_panels_widget_style_attributes', array('SiteOrigin_Panels_Default_Styling', 'widget_style_attributes' ), 10, 2);

		// Main filter to add any custom CSS.
		add_filter('siteorigin_panels_css_object', array('SiteOrigin_Panels_Default_Styling', 'filter_css_object' ), 10, 3);

		// Filtering specific attributes
		add_filter('siteorigin_panels_css_row_margin_bottom', array('SiteOrigin_Panels_Default_Styling', 'filter_row_bottom_margin' ), 10, 2);
		add_filter('siteorigin_panels_css_row_gutter', array('SiteOrigin_Panels_Default_Styling', 'filter_row_gutter' ), 10, 2);
	}

	static function row_style_fields($fields) {
		// Add the attribute fields

		$fields['class'] = array(
			'name' => __('Row Class', 'siteorigin-panels'),
			'type' => 'text',
			'group' => 'attributes',
			'description' => __('A CSS class', 'siteorigin-panels'),
			'priority' => 5,
		);

		$fields['cell_class'] = array(
			'name' => __('Cell Class', 'siteorigin-panels'),
			'type' => 'text',
			'group' => 'attributes',
			'description' => __('Class added to all cells in this row.', 'siteorigin-panels'),
			'priority' => 6,
		);

		$fields['row_css'] = array(
			'name' => __('CSS Styles', 'siteorigin-panels'),
			'type' => 'code',
			'group' => 'attributes',
			'description' => __('CSS Styles, given as one per row.', 'siteorigin-panels'),
			'priority' => 10,
		);

		// Add the layout fields

		$fields['bottom_margin'] = array(
			'name' => __('Bottom Margin', 'siteorigin-panels'),
			'type' => 'measurement',
			'group' => 'layout',
			'description' => __('Space below the row.', 'siteorigin-panels'),
			'priority' => 5,
		);

		$fields['gutter'] = array(
			'name' => __('Gutter', 'siteorigin-panels'),
			'type' => 'measurement',
			'group' => 'layout',
			'description' => __('Amount of space between columns.', 'siteorigin-panels'),
			'priority' => 6,
		);

		$fields['padding'] = array(
			'name' => __('Padding', 'siteorigin-panels'),
			'type' => 'measurement',
			'group' => 'layout',
			'description' => __('Padding around the entire row.', 'siteorigin-panels'),
			'priority' => 7,
			'multiple' => true
		);

		$fields['row_stretch'] = array(
			'name' => __('Row Layout', 'siteorigin-panels'),
			'type' => 'select',
			'group' => 'layout',
			'options' => array(
				'' => __('Standard', 'siteorigin-panels'),
				'full' => __('Full Width', 'siteorigin-panels'),
				'full-stretched' => __('Full Width Stretched', 'siteorigin-panels'),
			),
			'priority' => 10,
		);

		// How lets add the design fields

		$fields['background'] = array(
			'name' => __('Background Color', 'siteorigin-panels'),
			'type' => 'color',
			'group' => 'design',
			'description' => __('Background color of the row.', 'siteorigin-panels'),
			'priority' => 5,
		);

		$fields['background_image_attachment'] = array(
			'name' => __('Background Image', 'siteorigin-panels'),
			'type' => 'image',
			'group' => 'design',
			'description' => __('Background image of the row.', 'siteorigin-panels'),
			'priority' => 6,
		);

		$fields['background_display'] = array(
			'name' => __('Background Image Display', 'siteorigin-panels'),
			'type' => 'select',
			'group' => 'design',
			'options' => array(
				'tile' => __('Tiled Image', 'siteorigin-panels'),
				'cover' => __('Cover', 'siteorigin-panels'),
				'center' => __('Centered, with original size', 'siteorigin-panels'),
			),
			'description' => __('How the background image is displayed.', 'siteorigin-panels'),
			'priority' => 7,
		);

		$fields['border_color'] = array(
			'name' => __('Border Color', 'siteorigin-panels'),
			'type' => 'color',
			'group' => 'design',
			'description' => __('Border color of the row.', 'siteorigin-panels'),
			'priority' => 10,
		);

		return $fields;
	}

	static function widget_style_fields($fields) {
		$fields['class'] = array(
			'name' => __('Widget Class', 'siteorigin-panels'),
			'type' => 'text',
			'group' => 'attributes',
			'description' => __('A CSS class', 'siteorigin-panels'),
			'priority' => 5,
		);

		$fields['widget_css'] = array(
			'name' => __('CSS Styles', 'siteorigin-panels'),
			'type' => 'code',
			'group' => 'attributes',
			'description' => __('CSS Styles, given as one per row.', 'siteorigin-panels'),
			'priority' => 10,
		);

		$fields['padding'] = array(
			'name' => __('Padding', 'siteorigin-panels'),
			'type' => 'measurement',
			'group' => 'layout',
			'description' => __('Padding around the entire widget.', 'siteorigin-panels'),
			'priority' => 7,
			'multiple' => true
		);

		// How lets add the design fields

		$fields['background'] = array(
			'name' => __('Background Color', 'siteorigin-panels'),
			'type' => 'color',
			'group' => 'design',
			'description' => __('Background color of the widget.', 'siteorigin-panels'),
			'priority' => 5,
		);

		$fields['background_image_attachment'] = array(
			'name' => __('Background Image', 'siteorigin-panels'),
			'type' => 'image',
			'group' => 'design',
			'description' => __('Background image of the widget.', 'siteorigin-panels'),
			'priority' => 6,
		);

		$fields['background_display'] = array(
			'name' => __('Background Image Display', 'siteorigin-panels'),
			'type' => 'select',
			'group' => 'design',
			'options' => array(
				'tile' => __('Tiled Image', 'siteorigin-panels'),
				'cover' => __('Cover', 'siteorigin-panels'),
				'center' => __('Centered, with original size', 'siteorigin-panels'),
			),
			'description' => __('How the background image is displayed.', 'siteorigin-panels'),
			'priority' => 7,
		);

		$fields['border_color'] = array(
			'name' => __('Border Color', 'siteorigin-panels'),
			'type' => 'color',
			'group' => 'design',
			'description' => __('Border color of the widget.', 'siteorigin-panels'),
			'priority' => 10,
		);

		$fields['font_color'] = array(
			'name' => __('Font Color', 'siteorigin-panels'),
			'type' => 'color',
			'group' => 'design',
			'description' => __('Color of text inside this widget.', 'siteorigin-panels'),
			'priority' => 15,
		);

		return $fields;
	}

	static function row_style_attributes( $attributes, $args ) {
		if( !empty( $args['row_stretch'] ) ) {
			$attributes['class'][] = 'siteorigin-panels-stretch';
			$attributes['data-stretch-type'] = $args['row_stretch'];
			wp_enqueue_script('siteorigin-panels-front-styles');
		}

		if( !empty( $args['class'] ) ) {
			$attributes['class'] = array_merge( $attributes['class'], explode(' ', $args['class']) );
		}

		if( !empty($args['row_css']) ){
			preg_match_all('/^(.+?):(.+?);?$/m', $args['row_css'], $matches);

			if(!empty($matches[0])){
				for($i = 0; $i < count($matches[0]); $i++) {
					$attributes['style'] .= $matches[1][$i] . ':' . $matches[2][$i] . ';';
				}
			}
		}

		if( !empty( $args['padding'] ) ) {
			$attributes['style'] .= 'padding: ' . esc_attr($args['padding']) . ';';
		}

		if( !empty( $args['background'] ) ) {
			$attributes['style'] .= 'background-color:' . $args['background']. ';';
		}

		if( !empty( $args['background_image_attachment'] ) ) {
			$url = wp_get_attachment_image_src( $args['background_image_attachment'], 'full' );

			if( !empty($url) ) {
				$attributes['style'] .= 'background-image: url(' . $url[0] . ');';
			}

			switch( $args['background_display'] ) {
				case 'tile':
					$attributes['style'] .= 'background-repeat: repeat;';
					break;
				case 'cover':
					$attributes['style'] .= 'background-size: cover;';
					break;
				case 'center':
					$attributes['style'] .= 'background-position: center center; background-repeat: no-repeat;';
					break;
			}
		}

		if( !empty( $args['border_color'] ) ) {
			$attributes['style'] .= 'border: 1px solid ' . $args['border_color']. ';';
		}

		return $attributes;
	}

	static function cell_style_attributes( $attributes, $row_args ) {
		if( !empty( $row_args['cell_class'] ) ) {
			if( empty($attributes['class']) ) $attributes['class'] = array();
			$attributes['class'] = array_merge( $attributes['class'], explode(' ', $row_args['cell_class']) );
		}

		return $attributes;
	}

	static function widget_style_attributes( $attributes, $args ) {
		if( !empty( $args['class'] ) ) {
			if( empty($attributes['class']) ) $attributes['class'] = array();
			$attributes['class'] = array_merge( $attributes['class'], explode(' ', $args['class']) );
		}

		if( !empty($args['widget_css']) ){
			preg_match_all('/^(.+?):(.+?);?$/m', $args['widget_css'], $matches);

			if(!empty($matches[0])){
				for($i = 0; $i < count($matches[0]); $i++) {
					$attributes['style'] .= $matches[1][$i] . ':' . $matches[2][$i] . ';';
				}
			}
		}

		if( !empty( $args['padding'] ) ) {
			$attributes['style'] .= 'padding: ' . esc_attr($args['padding']) . ';';
		}

		if( !empty( $args['background'] ) ) {
			$attributes['style'] .= 'background-color:' . $args['background']. ';';
		}

		if( !empty( $args['background_image_attachment'] ) ) {
			$url = wp_get_attachment_image_src( $args['background_image_attachment'], 'full' );

			if( !empty($url) ) {
				$attributes['style'] .= 'background-image: url(' . $url[0] . ');';
			}

			switch( $args['background_display'] ) {
				case 'tile':
					$attributes['style'] .= 'background-repeat: repeat;';
					break;
				case 'cover':
					$attributes['style'] .= 'background-size: cover;';
					break;
				case 'center':
					$attributes['style'] .= 'background-position: center center; background-repeat: no-repeat;';
					break;
			}
		}

		if( !empty( $args['border_color'] ) ) {
			$attributes['style'] .= 'border: 1px solid ' . $args['border_color']. ';';
		}

		if( !empty( $args['font_color'] ) ) {
			$attributes['style'] .= 'color: ' . $args['font_color']. ';';
		}

		return $attributes;
	}

	static function filter_css_object( $css, $panels_data, $post_id ) {
		return $css;
	}

	static function filter_row_bottom_margin( $margin, $grid ){
		if( !empty($grid['style']['bottom_margin']) ) {
			$margin = $grid['style']['bottom_margin'];
		}
		return $margin;
	}

	static function filter_row_gutter( $gutter, $grid ) {
		if( !empty($grid['style']['gutter']) ) {
			$gutter = $grid['style']['gutter'];
		}

		return $gutter;
	}

}

SiteOrigin_Panels_Default_Styling::init();
