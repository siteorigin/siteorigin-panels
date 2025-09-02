<?php
/**
 * Compatibility class SiteOrigin Page Builder.
 */
class SiteOrigin_Panels_Compatibility {
	private $compat_path;

	public function __construct() {
		$this->compat_path = plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/';
		add_action( 'admin_init', array( $this, 'admin_init' ), 10, 0 );
		add_action( 'init', array( $this, 'init' ), 100, 0 );
		add_action( 'widgets_init', array( $this, 'widgets_init' ), 1, 0 );
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function admin_init() {
		// SEO analysis compatibility.
		if ( defined( 'WPSEO_FILE' ) || defined( 'RANK_MATH_VERSION' ) ) {
			require_once $this->compat_path . 'seo.php';
		}

		// Compatibility with ACF.
		if (
			class_exists( 'ACF' ) &&
			version_compare( get_option( 'acf_version' ), '5.7.10', '>=' )
		) {
			SiteOrigin_Panels_Compat_ACF_Widgets::single();
		}

		// Compatibility with Livemesh SiteOrigin Widgets.
		if ( defined( 'LSOW_VERSION' ) ) {
			require_once $this->compat_path . 'livemesh.php';
		}
	}

	public function init() {
		// Compatibility with Widget Options.
		if ( class_exists( 'WP_Widget_Options' ) ) {
			require_once $this->compat_path . 'widget-options.php';
		}

		// Compatibility with Yoast plugins.
		if (
			defined( 'WPSEO_FILE' ) ||
			function_exists( 'yoast_wpseo_video_seo_init' )
		) {
			require_once $this->compat_path . 'yoast.php';
		}

		// Compatibility with Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			require_once $this->compat_path . 'rank-math.php';
		}

		// Compatibility with AMP plugin.
		if ( is_admin() && function_exists( 'amp_bootstrap_plugin' ) ) {
			require_once $this->compat_path . 'amp.php';
		}

		// Compatibility with Gravity Forms.
		if ( class_exists( 'GFCommon' ) ) {
			require_once $this->compat_path . 'gravity-forms.php';
		}

		// Compatibility with Yikes Custom Product Tabs.
		if ( class_exists( 'YIKES_Custom_Product_Tabs' ) ) {
			require_once $this->compat_path . 'yikes.php';
		}

		// Compatibility with WP Rocket and WP Rocket LazyLoad.
		$load_lazy_load_compat = false;
		// LazyLoad by WP Rocket.
		if ( defined( 'ROCKET_LL_VERSION' ) ) {
			$lazy_load_settings = get_option( 'rocket_lazyload_options' );
			$load_lazy_load_compat = ! empty( $lazy_load_settings ) && ! empty( $lazy_load_settings['images'] );
		// WP Rocket.
		} elseif ( function_exists( 'get_rocket_option' ) && ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
			$load_lazy_load_compat = get_rocket_option( 'lazyload' ) && apply_filters( 'do_rocket_lazyload', true );
		}

		if ( apply_filters( 'siteorigin_lazyload_compat', $load_lazy_load_compat ) ) {
			require_once $this->compat_path . 'lazy-load-backgrounds.php';
		}

		// Compatibility with Jetpack.
		if ( class_exists( 'Jetpack' ) ) {
			require_once $this->compat_path . 'jetpack.php';
		}

		// Compatibility with Polylang.
		if ( class_exists( 'Polylang' ) ) {
			SiteOrigin_Panels_Compat_Polylang::single();
		}

		// Compatibility with SeoPress.
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			require_once $this->compat_path . 'seopress.php';
		}

		// Compatibility with WP Event Manager.
		if ( class_exists( 'WP_Event_Manager' ) ) {
			add_filter( 'display_event_description', array( SiteOrigin_Panels::single(), 'generate_post_content' ), 11 );
		}

		// Compatibility with Vantage.
		if ( get_template() == 'vantage' ) {
			require_once $this->compat_path . 'vantage.php';
		}

		// Compatibility with Pagelayer.
		if ( defined( 'PAGELAYER_VERSION' ) ) {
			SiteOrigin_Panels_Compat_Pagelayer::single();
		}

		// Compatibility with Popup Maker.
		if ( class_exists( 'PUM_Site' )) {
			require_once $this->compat_path . 'popup-maker.php';
		}

		// Compatibility with Events Manager.
		if ( defined( 'EM_VERSION' ) ) {
			require_once $this->compat_path . 'events-manager.php';
		}
	}

	public function widgets_init() {
		// Compatibility for All in One SEO.
		if ( function_exists( 'aioseo' ) ) {
			require_once $this->compat_path . 'aioseo.php';
		}
	}
}
