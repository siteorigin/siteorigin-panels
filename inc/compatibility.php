<?php
/**
 * Compatibility class SiteOrigin Page Builder.
 */
class SiteOrigin_Panels_Compatibility {
	public function __construct() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 100 );
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function admin_init() {
		// SEO analysis compatibility.
		if ( defined( 'WPSEO_FILE' ) || defined( 'RANK_MATH_VERSION' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/seo.php';
		}

		// Compatibility with ACF.
		if (
			class_exists( 'ACF' ) &&
			version_compare( get_option( 'acf_version' ), '5.7.10', '>=' )
		) {
			SiteOrigin_Panels_Compat_ACF_Widgets::single();
		}
	}

	public function init() {
		// Compatibility with Widget Options.
		if ( class_exists( 'WP_Widget_Options' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/widget-options.php';
		}

		// Compatibility with Yoast plugins.
		if (
			defined( 'WPSEO_FILE' ) ||
			function_exists( 'yoast_wpseo_video_seo_init' )
		) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/yoast.php';
		}

		// Compatibility with Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/rank-math.php';
		}

		// Compatibility with AMP plugin.
		if ( is_admin() && function_exists( 'amp_bootstrap_plugin' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/amp.php';
		}

		// Compatibility with Gravity Forms.
		if ( class_exists( 'GFCommon' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/gravity-forms.php';
		}

		// Compatibility with Yikes Custom Product Tabs.
		if ( class_exists( 'YIKES_Custom_Product_Tabs' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/yikes.php';
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
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/lazy-load-backgrounds.php';
		}

		// Compatibility with Jetpack.
		if ( class_exists( 'Jetpack' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/jetpack.php';
		}

		// Compatibility with Polylang.
		if ( class_exists( 'Polylang' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/polylang.php';
		}

		// Compatibility with SeoPress.
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/seopress.php';
		}

		// Compatibility with WP Event Manager.
		if ( class_exists( 'WP_Event_Manager' ) ) {
			add_filter( 'display_event_description', array( SiteOrigin_Panels::single(), 'generate_post_content' ), 11 );
		}

		// Compatibility with Vantage.
		if ( get_template() == 'vantage' ) {
			require_once plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . 'compat/vantage.php';
		}

		// Compatibility with Pagelayer.
		if ( defined( 'PAGELAYER_VERSION' ) ) {
			SiteOrigin_Panels_Compat_Pagelayer::single();
		}
	}
}
