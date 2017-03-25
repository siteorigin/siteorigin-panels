<?php


/**
 * A class that handles generating the post content version of Page Builder content.
 *
 * Class SiteOrigin_Panels_Post_Content
 */
class SiteOrigin_Panels_Post_Content_Filters {

	public function __construct() {

	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Add filters that include data-* attributes on Page Builder divs
	 */
	public function setup_filters(){
		add_filter( 'siteorigin_panels_row_attributes', array( $this, 'row_attributes' ), 99, 2 );
		add_filter( 'siteorigin_panels_cell_attributes', array( $this, 'cell_attributes' ), 99, 2 );
		add_filter( 'siteorigin_panels_widget_attributes', array( $this, 'widget_attributes' ), 99, 2 );
	}

	/**
	 * Clear filters so we get predictable HTML
	 */
	public function clear_filters(){
		remove_all_filters( 'siteorigin_panels_data' );

		remove_all_filters( 'siteorigin_panels_layout_classes' );
		remove_all_filters( 'siteorigin_panels_layout_attributes' );

		remove_all_filters( 'siteorigin_panels_before_content' );
		remove_all_filters( 'siteorigin_panels_after_content' );

		remove_all_filters( 'siteorigin_panels_render' );

		// Remove all row wrapper filters
		remove_all_filters( 'siteorigin_panels_row_classes' );
		remove_all_filters( 'siteorigin_panels_row_attributes' );
		remove_all_filters( 'siteorigin_panels_before_row' );
		remove_all_filters( 'siteorigin_panels_after_row' );

		// Remove all the cell wrapper filters
		remove_all_filters( 'siteorigin_panels_cell_classes' );
		remove_all_filters( 'siteorigin_panels_cell_attributes' );
		remove_all_filters( 'siteorigin_panels_before_cell' );
		remove_all_filters( 'siteorigin_panels_after_cell' );

		// Remove all the widget wrapper filters
		remove_all_filters( 'siteorigin_panels_widget_classes' );
		remove_all_filters( 'siteorigin_panels_widget_attributes' );
		remove_all_filters( 'siteorigin_panels_before_widget' );
		remove_all_filters( 'siteorigin_panels_after_widget' );
		remove_all_filters( 'siteorigin_panels_widget_args' );
	}

	/**
	 * Add the row data attributes
	 *
	 * @param $attributes
	 * @param $row
	 *
	 * @return mixed
	 */
	public function row_attributes( $attributes, $row ){
		if( empty( $GLOBALS[ 'SITEORIGIN_PANELS_DATABASE_RENDER' ] ) ) return $attributes;

		if( ! empty( $row['style'] ) ) {
			$attributes[ 'data-style' ] = json_encode( $row['style'] );
		}
		if( ! empty( $row['color_label'] ) ) {
			$attributes[ 'data-color-label' ] = intval( $row['color_label'] );
		}

		return $attributes;
	}

	/**
	 * @param $attributes
	 * @param $cell
	 *
	 * @return mixed
	 */
	public function cell_attributes( $attributes, $cell ){
		if( empty( $GLOBALS[ 'SITEORIGIN_PANELS_DATABASE_RENDER' ] ) ) return $attributes;

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
	public function widget_attributes( $attributes, $widget ){
		if( empty( $GLOBALS[ 'SITEORIGIN_PANELS_DATABASE_RENDER' ] ) ) return $attributes;

		if( ! empty( $widget['style'] ) ) {
			$attributes[ 'data-style' ] = json_encode( $widget['style'] );
		}

		return $attributes;
	}

}
