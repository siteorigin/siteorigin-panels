<?php
/**
 * Compatibility with Pagelayer Templates.
 *
 * Templates are output using the_content filter.
 * To prevent content duplication, we need to selectively
 * deactivate the panels filter based on template usage.
 *
 */
class SiteOrigin_Panels_Compat_Pagelayer {
	public $panelsDisabled = false;

	public function __construct() {
		add_action( 'get_header', array( $this, 'template_detection' ), 1 );
		add_action( 'get_footer', array( $this, 'template_detection' ), 1 );
		add_filter( 'loop_start', array( $this, 'enable_panels_in_content' ) );
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	function enable_panels_in_content( $query ) {
		if ( $this->panelsDisabled && $query->is_main_query() ) {
			remove_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
			$this->panelsDisabled = false;
		}
	}

	public function template_detection() {
		global $pagelayer;
		$context = current_filter() === 'get_header' ? 'header' : 'footer';
		if (
			! $this->panelsDisabled &&
			! empty( $pagelayer->{ "template_$context" } )
		) {
			add_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
			$this->panelsDisabled = true;
		}
	}
}
