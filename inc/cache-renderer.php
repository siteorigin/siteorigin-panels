<?php

class SiteOrigin_Panels_Cache_Renderer {

	private $cache_render;
	private $cache;

	function __construct() {
		$this->cache_render = false;

		$this->cache = array(
			'html' => array(),
			'css' => array(),
		);

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
	 * Tell the caching object that we're starting a cache
	 *
	 * @param $post_id
	 */
	private function start_cache_render( $post_id ){
		$this->clear_cache( $post_id );

		$GLOBALS[ 'SITEORIGIN_PANELS_CACHE_RENDER' ] = true;
		$this->cache_render = true;

		$this->cache[ 'html' ][ $post_id ] = '';
		$this->cache[ 'css' ][ $post_id ] = '';

		do_action( 'siteorigin_panels_start_cache_render', $post_id );
	}

	/**
	 * Let the caching system know that we're no longer in a cache render.
	 */
	private function end_cache_render( $post_id ){
		unset( $GLOBALS[ 'SITEORIGIN_PANELS_CACHE_RENDER' ] );
		$this->cache_render = false;
		do_action( 'siteorigin_panels_end_cache_render', $post_id );
	}

	/**
	 * Save the generated cache data.
	 */
	public function save( $post_id ){
		update_post_meta( $post_id, 'siteorigin_panels_cache', array(
			'version' => SITEORIGIN_PANELS_VERSION,
			'html' => SiteOrigin_Panels_Admin::double_slash_string( $this->cache[ 'html' ][ $post_id ] ),
			'css' => SiteOrigin_Panels_Admin::double_slash_string( $this->cache[ 'css' ][ $post_id ] ),
		) );
	}

	/**
	 * Check if the current render being performed is for the cache.
	 *
	 * @return bool
	 */
	public function is_cache_render( ){
		return $this->cache_render;
	}

	public function add( $type, $content, $post_id ){
		if( ! $this->is_cache_render() ) {
			throw new Exception( 'A cache render must be started before adding HTML' );
		}
		$this->cache[ $type ][ $post_id ] .= trim( $content ) . ' ';
	}

	public function get( $type, $post_id ){
		if( ! empty( $this->cache[ $type ][ $post_id ] ) ) {
			return $this->cache[ $type ][ $post_id ];
		}
		else {
			// Try get this from the meta
			$cache_meta = get_post_meta( $post_id, 'siteorigin_panels_cache', true );
			if(
				! empty( $cache_meta ) &&
				! empty( $cache_meta[ $type ] ) &&
				$cache_meta[ 'version' ] == SITEORIGIN_PANELS_VERSION
			) {
				return $cache_meta[ $type ];
			}

			$this->refresh_cache( $post_id );
			return $this->cache[ $type ][ $post_id ];
		}
	}

	/**
	 * Clear post meta cache.
	 *
	 * @param bool|int $post_id The ID of the post to clear or false for all
	 */
	public function clear_cache( $post_id = false ){
		global $wpdb;
		if( empty( $post_id ) ) {
			$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = 'siteorigin_panels_cache'" );
		}
		else {
			delete_post_meta( $post_id, 'siteorigin_panels_cache' );
		}
	}

	private function refresh_cache( $post_id, $save = true ) {
		$this->start_cache_render( $post_id );

		if( empty( $this->cache[ 'html' ][ $post_id ] ) ) {
			// Generate the HTML for the post
			$panels_html = SiteOrigin_Panels::renderer()->render( $post_id, false );
			$this->add( 'html', $panels_html, $post_id );
		}

		if( empty( $this->cache[ 'css' ][ $post_id ] ) ) {
			// Create a single line version of the CSS
			$panels_css = SiteOrigin_Panels::renderer()->generate_css( $post_id );
			$this->add( 'css', $panels_css, $post_id );
		}

		$this->end_cache_render( $post_id );

		if( $save ) {
			$this->save( $post_id );
		}

		return array(
			'html' => $this->cache[ 'html' ][ $post_id ],
			'css' => $this->cache[ 'css' ][ $post_id ],
		);
	}
}
