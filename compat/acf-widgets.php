<?php
class SiteOrigin_Panels_Compat_ACF_Widgets {
	public function __construct() {
		add_action( 'admin_print_scripts-post-new.php', array( $this, 'enqueue_assets' ), 100 );
		add_action( 'admin_print_scripts-post.php', array( $this, 'enqueue_assets' ), 100 );
	}

	public static function single() {
		static $single;
		
		return empty( $single ) ? $single = new self() : $single;
	}

	public function enqueue_assets() {
		if ( SiteOrigin_Panels_Admin::is_admin() ) {
			wp_enqueue_script(
				'so-panels-acf-widgets-compat',
				siteorigin_panels_url( 'compat/js/acf-widgets' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
				array(
					'jquery',
					'so-panels-admin',
				),
				SITEORIGIN_PANELS_VERSION,
				true
			);
		}
	}
}
