<?php

/**
 * Handles registering custom REST endpoints.
 *
 * Class SiteOrigin_Widgets_Rest_Routes
 */

class SiteOrigin_Panels_Rest_Routes {
	
	function __construct() {
		
		global $wp_version;
		if ( version_compare( $wp_version, '4.7', '>=' ) && class_exists( 'WP_REST_Controller' ) ) {
			add_action( 'rest_api_init', array( $this, 'register_rest_routes') );
		}
	}
	
	/**
	 * Singleton
	 *
	 * @return SiteOrigin_Panels_Rest_Routes
	 */
	static function single() {
		static $single;
		
		if( empty($single) ) {
			$single = new self();
		}
		
		return $single;
	}
	
	/**
	 * Register all our REST resources.
	 */
	function register_rest_routes() {
		$resources = array(
			new SiteOrigin_Panels_Layouts_Resource(),
		);
		
		foreach ( $resources as $resource ) {
			/* @var WP_REST_Controller $resource */
			$resource->register_routes();
		}
	}
	
}
