<?php

/**
 * The live editor class. Only loaded when in live editor mode.
 *
 * Class SiteOrigin_Panels_Live_Editor
 */
class SiteOrigin_Panels_Live_Editor {

	function __construct() {
		add_action( 'template_redirect', array( $this, 'xss_headers' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );

		// Don't display the admin bar when in live editor mode
		add_filter( 'show_admin_bar', '__return_false' );
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	public function xss_headers(){
		global $post;
		if(
			! empty( $_POST['live_editor_panels_data'] ) &&
			! empty( $post->ID ) &&
			current_user_can( 'edit_post', $post->ID )
		) {
			// Disable XSS protection when in the Live Editor
			header( 'X-XSS-Protection: 0' );
		}
	}

	/**
	 * Load the frontend scripts for the live editor
	 */
	function frontend_scripts() {
		wp_enqueue_script(
			'live-editor-front',
			siteorigin_panels_url( 'js/live-editor/live-editor-front' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
			array( 'jquery' ),
			SITEORIGIN_PANELS_VERSION
		);

		wp_enqueue_script(
			'live-editor-scrollto',
			siteorigin_panels_url( 'js/live-editor/jquery.scrollTo' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
			array( 'jquery' ),
			SITEORIGIN_PANELS_VERSION
		);

		wp_enqueue_style(
			'live-editor-front',
			siteorigin_panels_url( 'css/live-editor-front' . SITEORIGIN_PANELS_CSS_SUFFIX . '.css' ),
			array(),
			SITEORIGIN_PANELS_VERSION
		);
	}
}
