<?php

class SiteOrigin_Panels_Cache_Renderer {

	function __construct() {
		// Clear cache when the Page Builder version changes
		add_action( 'siteorigin_panels_version_changed', array( $this, 'clear_cache' ), 10, 0 );

		// When we activate/deactivate a plugin or switch themes that might change rendering
		add_action( 'activated_plugin', array( $this, 'clear_cache' ), 10, 0 );
		add_action( 'deactivated_plugin', array( $this, 'clear_cache' ), 10, 0 );
		add_action( 'switch_theme', array( $this, 'clear_cache' ), 10, 0 );

		// When settings are saved, this is also a good way to force a cache refresh
		add_action( 'siteorigin_panels_save_settings', array( $this, 'clear_cache' ), 10, 0 );

		// When a single post is saved
		add_action( 'save_post', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * @return SiteOrigin_Panels_Cache_Renderer
	 */
	static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Clear post meta cache.
	 *
	 * Keep this around for a bit in attempt to delete any existing caches.
	 */
	public function clear_cache(){
		delete_post_meta_by_key( 'siteorigin_panels_cache' );
	}
}
