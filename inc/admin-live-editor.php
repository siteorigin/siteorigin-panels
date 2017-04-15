<?php

class SiteOrigin_Panels_Admin_Live_Editor {
	
	function __construct() {
		add_action( 'wp_ajax_so_panels_live_partial_layout', array( $this, 'action_partial_layout' ) );
		add_action( 'wp_ajax_so_panels_live_partial_widget', array( $this, 'action_partial_widget' ) );
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
		$renderer = SiteOrigin_Panels_Renderer::single();
		
		$widget_data = json_decode( stripslashes( $_REQUEST[ 'widget' ] ), true );
		$panels_info = $widget_data[ 'panels_info' ];
		$post_id = intval( $_REQUEST[ 'post_id' ] );
		
		$renderer->render_widget(
			$post_id,
			$panels_info[ 'grid' ],
			$panels_info[ 'cell' ],
			$panels_info[ 'id' ],
			$widget_data,
			false
		);
		
		exit();
	}
}