<?php

class SiteOrigin_Panels_Cache {

	private $cache_render;
	private $post_id;
	private $css;
	private $html;

	function __construct() {
		$this->cache_render = false;

		// Some situations to clear the cache
		add_action( 'siteorigin_panels_version_changed', array( $this, 'clear_cache' ) );
		add_action( 'activated_plugin', array( $this, 'clear_cache' ) );
		add_action( 'switch_theme', array( $this, 'clear_cache' ) );
	}

	/**
	 * @return SiteOrigin_Panels_Cache
	 */
	static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Generate the HTML and CSS for a given post.
	 *
	 * @param $post_id
	 * @param $panels_data
	 * @param bool $save
	 */
 	public function generate_cache( $post_id, $panels_data, $save = false ){
 		if( empty( $panels_data ) ) {
		    $panels_data = get_post_meta( $post_id, 'panels_data', true );
		    if( empty( $panels_data ) ) return;
	    }

	    $this->start_cache_render( $post_id );

	    // Generate the HTML for the post
	    $panels_html = SiteOrigin_Panels_Renderer::single()->render( $post_id, false, $panels_data, $layout_data );
	    $this->add_html( $panels_html );

	    // Create a single line version of the CSS
	    $panels_css = SiteOrigin_Panels_Renderer::single()->generate_css( $post_id, $panels_data, $layout_data );
	    $this->add_css( $panels_css );

	    $this->end_cache_render();

	    if( $save ) {
	    	$this->save( $post_id );
	    }
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
		$this->post_id = $post_id;

		$this->css = '';
		$this->html = '';

		do_action( 'siteorigin_panels_start_cache_render', $post_id );
	}

	/**
	 * Let the caching system know that we're no longer in a cache render.
	 */
	private function end_cache_render(  ){
		$GLOBALS[ 'SITEORIGIN_PANELS_CACHE_RENDER' ] = false;
		$this->cache_render = false;
		do_action( 'siteorigin_panels_end_cache_render', $this->post_id );
	}

	/**
	 * Check if the current render being performed is for the cache.
	 *
	 * @return bool
	 */
	public function is_cache_render( ){
		return $this->cache_render;
	}

	public function add_css( $css ){
		if( ! $this->is_cache_render() ) {
			throw new Exception( 'A cache render must be started before adding CSS' );
		}
		$this->css .= trim( preg_replace( '/\s+/', ' ', $css ) ) . ' ';
	}

	public function add_html( $html ){
		if( ! $this->is_cache_render() ) {
			throw new Exception( 'A cache render must be started before adding HTML' );
		}
		$this->html .= trim( $html ) . ' ';
	}

	public function get_css( ){
		return $this->css;
	}

	public function get_html( ){
		return $this->css;
	}

	/**
	 * Save the generated cache data.
	 */
	public function save( ){
		add_post_meta( $this->post_id, 'siteorigin_panels_cache', array(
			'version' => SITEORIGIN_PANELS_VERSION,
			'html' => SiteOrigin_Panels_Admin::double_slash_string( $this->html ),
			'css' => SiteOrigin_Panels_Admin::double_slash_string( $this->css ),
		) );
	}

	/**
	 * Get the current value of the post meta
	 *
	 * @param $post_id
	 * @param bool $generate
	 *
	 * @return bool|mixed The current value of the HTML and css cache.
	 */
	public function get( $post_id, $generate = true ){
		$meta = get_post_meta( $post_id, 'siteorigin_panels_cache', true );
		if( ! empty( $meta[ 'version' ] ) && $meta[ 'version' ] == SITEORIGIN_PANELS_VERSION ) {
			unset( $meta[ 'version' ] );
			return $meta;
		}
		else if( $generate ) {
			$this->generate_cache( $post_id );
		}
		else {
			return false;
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
}
