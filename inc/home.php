<?php

class SiteOrigin_Panels_Home {

	function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Add items to the admin menu
	 *
	 * @action admin_menu
	 */
	public function admin_menu() {
		if ( ! siteorigin_panels_setting( 'home-page' ) ) {
			return;
		}

		add_theme_page(
			__( 'Custom Home Page Builder', 'siteorigin-panels' ),
			__( 'Home Page', 'siteorigin-panels' ),
			'edit_theme_options',
			'so_panels_home_page',
			array( $this, 'render_home' )
		);
	}

	/**
	 * Render the home page interface.
	 */
	public function render_home() {
		// We need a global post for some features in Page Builder (eg history)
		global $post;

		$home_page_id = get_option( 'page_on_front' );
		if ( empty( $home_page_id ) ) {
			$home_page_id = get_option( 'siteorigin_panels_home_page_id' );
		}

		$home_page = get_post( $home_page_id );
		if ( ! empty( $home_page ) && get_post_meta( $home_page->ID, 'panels_data', true ) != '' ) {
			$post = $home_page;
		}

		$panels_data = SiteOrigin_Panels_Admin::single()->get_current_admin_panels_data();
		include plugin_dir_path( __FILE__ ) . '../tpl/admin-home-page.php';
	}

}
