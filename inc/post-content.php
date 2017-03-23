<?php


/**
 * A class that handles generating the post content version of Page Builder content.
 *
 * Class SiteOrigin_Panels_Post_Content
 */
class SiteOrigin_Panels_Post_Content {

	public function __construct() {

	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
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
	 * Setup all the filters to add data attributes to the wrappers
	 */
	public function setup_filters(){
		add_filter( 'siteorigin_panels_row_attributes', array( $this, 'row_attributes' ) );
		add_filter( 'siteorigin_panels_cell_attributes', array( $this, 'cell_attributes' ) );
		add_filter( 'siteorigin_panels_widget_attributes', array( $this, 'widget_attributes' ) );
	}
}
