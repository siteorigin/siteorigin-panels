<?php


/**
 * A class that handles generating the post content version of Page Builder content.
 *
 * Class SiteOrigin_Panels_Post_Content
 */
class SiteOrigin_Panels_Post_Content_Filters {

	/**
	 * Add filters that include data-* attributes on Page Builder divs
	 */
	public static function add_filters(){
		add_filter( 'siteorigin_panels_row_attributes', 'SiteOrigin_Panels_Post_Content_Filters::row_attributes', 99, 2 );
		add_filter( 'siteorigin_panels_cell_attributes','SiteOrigin_Panels_Post_Content_Filters::cell_attributes', 99, 2 );
		add_filter( 'siteorigin_panels_widget_attributes', 'SiteOrigin_Panels_Post_Content_Filters::widget_attributes', 99, 2 );
		SiteOrigin_Panels_Widget_Shortcode::add_filters();
	}

	public static function remove_filters(){
		remove_filter( 'siteorigin_panels_row_attributes', 'SiteOrigin_Panels_Post_Content_Filters::row_attributes', 99, 2 );
		remove_filter( 'siteorigin_panels_cell_attributes','SiteOrigin_Panels_Post_Content_Filters::cell_attributes', 99, 2 );
		remove_filter( 'siteorigin_panels_widget_attributes', 'SiteOrigin_Panels_Post_Content_Filters::widget_attributes', 99, 2 );
		SiteOrigin_Panels_Widget_Shortcode::remove_filters();
	}

	/**
	 * Add the row data attributes
	 *
	 * @param $attributes
	 * @param $row
	 *
	 * @return mixed
	 */
	public static function row_attributes( $attributes, $row ){
		if( ! empty( $row['style'] ) ) {
			$attributes[ 'data-style' ] = json_encode( $row['style'] );
		}
		if( ! empty( $row['ratio'] ) ) {
			$attributes[ 'data-ratio' ] = (float) $row['ratio'];
		}
		if( ! empty( $row['ratio_direction'] ) ) {
			$attributes[ 'data-ratio-direction' ] = $row['ratio_direction'];
		}
		if( ! empty( $row['color_label'] ) ) {
			$attributes[ 'data-color-label' ] = (int) $row['color_label'];
		}
		if( ! empty( $row['label'] ) ) {
			$attributes[ 'data-label' ] = $row['label'];
		}

		return $attributes;
	}

	/**
	 * @param $attributes
	 * @param $cell
	 *
	 * @return mixed
	 */
	public static function cell_attributes( $attributes, $cell ){
		if( ! empty( $cell['style'] ) ) {
			$attributes[ 'data-style' ] = json_encode( $cell['style'] );
		}

		$attributes[ 'data-weight' ] = $cell['weight'];

		return $attributes;
	}

	/**
	 * @param $attributes
	 * @param $widget
	 *
	 * @return mixed
	 */
	public static function widget_attributes( $attributes, $widget ){
		if( ! empty( $widget['style'] ) ) {
			$attributes[ 'data-style' ] = json_encode( $widget['style'] );
		}
		if( ! empty( $widget['label'] ) ) {
			$attributes[ 'data-label' ] = $widget['label'];
		}

		return $attributes;
	}

}
