<?php

class SiteOrigin_Panels_Renderer_Legacy extends SiteOrigin_Panels_Renderer {

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Generate the CSS for the page layout.
	 *
	 * @param $post_id
	 * @param $panels_data
	 * @param $layout_data
	 *
	 * @return string
	 */
	public function generate_css( $post_id, $panels_data = false, $layout_data = false) {
		// Exit if we don't have panels data
		if ( empty( $panels_data ) ) {
			$panels_data = get_post_meta( $post_id, 'panels_data', true );
			if( empty( $panels_data ) ) {
				return '';
			}
		}
		if ( empty( $layout_data ) ) {
			$layout_data = $this->get_panels_layout_data( $panels_data );
			$layout_data = apply_filters( 'siteorigin_panels_layout_data', $layout_data, $post_id );
		}

		// Get some of the default settings
		$settings                      = siteorigin_panels_setting();
		$panels_tablet_width           = $settings['tablet-width'];
		$panels_mobile_width           = $settings['mobile-width'];
		$panels_margin_bottom          = $settings['margin-bottom'];
		$panels_margin_bottom_last_row = $settings['margin-bottom-last-row'];

		$css = new SiteOrigin_Panels_Css_Builder();

		$ci = 0;
		foreach ( $layout_data as $ri => $row ) {
			if( empty( $row['cells'] ) ) continue;

			// Let other themes and plugins change the gutter.
			$gutter = apply_filters( 'siteorigin_panels_css_row_gutter', $settings['margin-sides'] . 'px', $row, $ri, $panels_data );
			preg_match( '/([0-9\.,]+)(.*)/', $gutter, $gutter_parts );

			$cell_count = count( $row['cells'] );

			// Add the cell sizing
			foreach( $row['cells'] as $ci => $cell ) {
				$weight = apply_filters( 'siteorigin_panels_css_cell_weight', $cell['weight'], $row, $ri, $cell, $ci - 1, $panels_data, $post_id );

				// Add the width and ensure we have correct formatting for CSS.
				$css->add_cell_css( $post_id, $ri, $ci, '', array(
					'width' => round( $weight * 100, 4 ) . '%',
				) );
			}
			
			$css->add_cell_css( $post_id, $ri, false, '', array(
			
			) );

			if(
				$ri != count( $layout_data ) - 1 ||
				! empty( $row[ 'style' ][ 'bottom_margin' ] ) ||
				! empty( $panels_margin_bottom_last_row )
			) {
				// Filter the bottom margin for this row with the arguments
				$css->add_row_css( $post_id, $ri, '', array(
					'margin-bottom' => apply_filters( 'siteorigin_panels_css_row_margin_bottom', $panels_margin_bottom . 'px', $row, $ri, $panels_data, $post_id )
				) );
			}

			$margin_half = ( floatval( $gutter_parts[1] ) / 2 ) . $gutter_parts[2];
			$css->add_row_css($post_id, $ri, '', array(
				'margin-left' => '-' . $margin_half,
				'margin-right' => '-' . $margin_half,
			) );
			$css->add_cell_css($post_id, $ri, false, '', array(
				'padding-left' => $margin_half,
				'padding-right' => $margin_half,
			) );
		}

		// Add the bottom margins
		$css->add_widget_css( $post_id, false, false, false, '', array(
			'margin-bottom' => apply_filters( 'siteorigin_panels_css_cell_margin_bottom', $panels_margin_bottom . 'px', false, false, $panels_data, $post_id )
		) );
		$css->add_widget_css( $post_id, false, false, false, ':last-child', array(
			'margin-bottom' => apply_filters( 'siteorigin_panels_css_cell_last_margin_bottom', '0px', false, false, $panels_data, $post_id )
		) );

		if ( $settings['responsive'] ) {
			
			$css->add_cell_css($post_id, false, false, '', array(
				'float' => 'none',
				'width' => 'auto'
			), $panels_mobile_width);
			
			$css->add_row_css($post_id, false, '', array(
				'margin-left' => 0,
				'margin-right' => 0,
			), $panels_mobile_width);
			
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
			
			foreach ( $layout_data as $ri => $row ) {
				$css->add_cell_css( $post_id, $ri, false, '', array(
					'margin-bottom' => $panels_margin_bottom . 'px',
				), $panels_mobile_width );
				
				$css->add_cell_css( $post_id, $ri, false, ':last-child', array(
					'margin-bottom' => '0px',
				), $panels_mobile_width );
			}
		}

		foreach ( $panels_data['widgets'] as $widget_id => $widget ) {
			if ( ! empty( $widget['panels_info']['style']['link_color'] ) ) {
				$css->add_widget_css( $post_id, $widget['panels_info']['grid'], $widget['panels_info']['cell'], $widget['panels_info']['cell_index'], ' a', array(
					'color' => $widget['panels_info']['style']['link_color']
				) );
			}
		}

		// Let other plugins and components filter the CSS object.
		$css = apply_filters( 'siteorigin_panels_css_object', $css, $panels_data, $post_id, $layout_data );

		return $css->get_css();
	}
	
	public function front_css_url(){
		return plugin_dir_url( __FILE__ ) . '../css/front' . ( siteorigin_panels_setting( 'legacy-layout' ) ? '-legacy' : '' ) . '.css';
	}
}
