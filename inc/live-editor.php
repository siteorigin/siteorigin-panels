<?php

/**
 * The live editor class. Only loaded when in live editor mode.
 *
 * Class SiteOrigin_Panels_Live_Editor
 */
class SiteOrigin_Panels_Live_Editor {

	function __construct() {
		add_action( 'template_redirect', array( $this, 'xss_headers' ) );
		add_action( 'get_post_metadata', array( $this, 'post_metadata' ), 10, 3 );
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

		if ( ! isset( $_GET['_panelsnonce'] ) || ! wp_verify_nonce( $_GET['_panelsnonce'], 'live-editor-preview' ) ) {
			// If this class has been loaded, we know we're in the Live Editor.
			// In the case that data or the nonce isn't valid, wp_die as a security precaution.
			// This will happen on template_redirect.
			wp_die();
		}

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
	 * Edit the page builder data when we're viewing the live editor version. This is necessary to ensure updated styles
	 * are rendered in the preview.
	 *
	 * @param $value
	 * @param $post_id
	 * @param $meta_key
	 *
	 * @return array
	 */
	function post_metadata( $value, $post_id, $meta_key ) {
		if (
			$meta_key == 'panels_data' &&
			current_user_can( 'edit_post', $post_id ) &&
			! empty( $_POST['live_editor_panels_data'] ) &&
			$_POST['live_editor_post_ID'] == $post_id
		) {
			$value = array( json_decode( wp_unslash( $_POST['live_editor_panels_data'] ), true ) );
		}
		return $value;
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
