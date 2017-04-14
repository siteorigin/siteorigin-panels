<?php

class SiteOrigin_Panels_Admin_Live_Editor {
	
	function __construct() {
		add_action( 'wp_ajax_so_panels_le_partial_layout', array( $this, 'action_partial_layout' ) );
		add_action( 'wp_ajax_so_panels_le_partial_widget', array( $this, 'action_partial_widget' ) );
	}
	
	/**
	 * @return SiteOrigin_Panels_Admin
	 */
	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}
	
	/**
	 * Render just the layout.
	 */
	public function action_partial_layout(  ){
	
	}
	
	/**
	 * Render a single layout
	 */
	public function action_partial_widget(  ){
	
	}
}