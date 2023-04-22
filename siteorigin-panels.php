<?php
/*
Plugin Name: Page Builder by SiteOrigin
Plugin URI: https://siteorigin.com/page-builder/
Description: A drag and drop, responsive page builder that simplifies building your website.
Version: dev
Author: SiteOrigin
Author URI: https://siteorigin.com
License: GPL3
License URI: http://www.gnu.org/licenses/gpl.html
Donate link: http://siteorigin.com/page-builder/#donate
*/

define( 'SITEORIGIN_PANELS_VERSION', 'dev' );

if ( ! defined( 'SITEORIGIN_PANELS_JS_SUFFIX' ) ) {
	define( 'SITEORIGIN_PANELS_JS_SUFFIX', '' );
}
define( 'SITEORIGIN_PANELS_CSS_SUFFIX', '' );

require_once plugin_dir_path( __FILE__ ) . 'inc/functions.php';

class SiteOrigin_Panels {
	public function __construct() {
		register_activation_hook( __FILE__, array( 'SiteOrigin_Panels', 'activate' ) );

		// Register the autoloader.
		spl_autoload_register( array( $this, 'autoloader' ) );

		add_action( 'plugins_loaded', array( $this, 'version_check' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'init_compat' ), 100 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );

		add_action( 'widgets_init', array( $this, 'widgets_init' ) );

		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'siteorigin_panels_data', array( $this, 'process_panels_data' ), 5 );
		add_filter( 'siteorigin_panels_widget_class', array( $this, 'fix_namespace_escaping' ), 5 );

		add_action( 'activated_plugin', array( $this, 'activation_flag_redirect' ) );
		add_action( 'admin_init', array( $this, 'activation_do_redirect' ) );

		if (
			is_admin() ||
			( wp_doing_ajax() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'inline-save' )
		) {
			SiteOrigin_Panels_Admin::single();
		}

		if ( is_admin() ) {
			// Setup all the admin classes.
			SiteOrigin_Panels_Settings::single();
			SiteOrigin_Panels_Revisions::single();
		}

		// Include the live editor file if we're in live editor mode.
		if ( self::is_live_editor() ) {
			SiteOrigin_Panels_Live_Editor::single();
		}

		SiteOrigin_Panels::renderer();
		SiteOrigin_Panels_Styles_Admin::single();

		if ( siteorigin_panels_setting( 'bundled-widgets' ) && ! function_exists( 'origin_widgets_init' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'widgets/widgets.php';
		}

		SiteOrigin_Panels_Widget_Shortcode::init();

		// We need to generate fresh post content.
		add_filter( 'the_content', array( $this, 'generate_post_content' ) );
		add_filter( 'woocommerce_format_content', array( $this, 'generate_woocommerce_content' ) );
		add_filter( 'wp_enqueue_scripts', array( $this, 'generate_post_css' ) );

		// Remove the default excerpt function.
		add_filter( 'get_the_excerpt', array( $this, 'generate_post_excerpt' ), 9 );

		// Content cache has been removed. SiteOrigin_Panels_Cache_Renderer just deletes any existing caches.
		SiteOrigin_Panels_Cache_Renderer::single();

		if ( function_exists( 'register_block_type' ) ) {
			SiteOrigin_Panels_Compat_Layout_Block::single();
		}

		define( 'SITEORIGIN_PANELS_BASE_FILE', __FILE__ );
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Get an instance of the renderer
	 *
	 * @return SiteOrigin_Panels_Renderer
	 */
	public static function renderer() {
		static $renderer;

		if ( empty( $renderer ) ) {
			switch( siteorigin_panels_setting( 'legacy-layout' ) ) {
				case 'always':
					$renderer = SiteOrigin_Panels_Renderer_Legacy::single();
					break;

				case 'never':
					$renderer = SiteOrigin_Panels_Renderer::single();
					break;

				default:
					$renderer = self::is_legacy_browser() ?
						SiteOrigin_Panels_Renderer_Legacy::single() :
						SiteOrigin_Panels_Renderer::single();
					break;
			}
		}

		return $renderer;
	}

	public static function is_legacy_browser() {
		$agent = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

		if ( empty( $agent ) ) {
			return false;
		}

		return
			// IE lte 11
			( preg_match( '/Trident\/(?P<v>\d+)/i', $agent, $B ) && $B['v'] <= 7 ) ||
			// Chrome lte 25
			( preg_match( '/Chrome\/(?P<v>\d+)/i', $agent, $B ) && $B['v'] <= 25 ) ||
			// Firefox lte 21
			( preg_match( '/Firefox\/(?P<v>\d+)/i', $agent, $B ) && $B['v'] <= 21 ) ||
			// Safari lte 7
			( preg_match( '/Version\/(?P<v>\d+).*?Safari\/\d+/i', $agent, $B ) && $B['v'] <= 6 );
	}

	/**
	 * Autoload Page Builder specific classses.
	 */
	public static function autoloader( $class ) {
		$filename = false;

		if ( strpos( $class, 'SiteOrigin_Panels_Widgets_' ) === 0 ) {
			$filename = str_replace( 'SiteOrigin_Panels_Widgets_', '', $class );
			$filename = str_replace( '_', '-', $filename );
			$filename = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $filename ) );
			$filename = plugin_dir_path( __FILE__ ) . 'inc/widgets/' . $filename . '.php';
		} elseif ( strpos( $class, 'SiteOrigin_Panels_Compat_' ) === 0 ) {
			$filename = str_replace( array( 'SiteOrigin_Panels_Compat_', '_' ), array( '', '-' ), $class );
			$filename = plugin_dir_path( __FILE__ ) . 'compat/' . strtolower( $filename ) . '.php';
		} elseif ( strpos( $class, 'SiteOrigin_Panels_' ) === 0 ) {
			$filename = str_replace( array( 'SiteOrigin_Panels_', '_' ), array( '', '-' ), $class );
			$filename = plugin_dir_path( __FILE__ ) . 'inc/' . strtolower( $filename ) . '.php';
		}

		if ( ! empty( $filename ) && file_exists( $filename ) ) {
			include $filename;
		}
	}

	public static function activate() {
		add_option( 'siteorigin_panels_initial_version', SITEORIGIN_PANELS_VERSION, '', 'no' );
	}

	/**
	 * Initialize SiteOrigin Page Builder
	 *
	 * @action plugins_loaded
	 */
	public function init() {
		if (
			! is_admin() &&
			siteorigin_panels_setting( 'sidebars-emulator' ) &&
			( ! get_option( 'permalink_structure' ) || get_option( 'rewrite_rules' ) )
		) {
			// Initialize the sidebars emulator.
			SiteOrigin_Panels_Sidebars_Emulator::single();
		}

		// Initialize the language.
		load_plugin_textdomain( 'siteorigin-panels', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

		// Initialize all the extra classes.
		SiteOrigin_Panels_Home::single();

		// Check if we need to initialize the admin class.
		if ( is_admin() ) {
			SiteOrigin_Panels_Admin::single();
		}
	}

	/**
	 * Loads Page Builder compatibility to allow other plugins/themes
	 */
	public function init_compat() {
		// Compatibility with Widget Options plugin.
		if ( class_exists( 'WP_Widget_Options' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/widget-options.php';
		}

		// Compatibility with Yoast plugins.
		if (
			defined( 'WPSEO_FILE' ) ||
			function_exists( 'yoast_wpseo_video_seo_init' )
		) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/yoast.php';
		}

		// Compatibility with Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/rank-math.php';
		}

		// Compatibility with AMP plugin.
		if ( is_admin() && function_exists( 'amp_bootstrap_plugin' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/amp.php';
		}

		// Compatibility with Gravity Forms.
		if ( class_exists( 'GFCommon' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/gravity-forms.php';
		}

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
			require_once plugin_dir_path( __FILE__ ) . 'compat/lazy-load-backgrounds.php';
		}

		if ( class_exists( 'Jetpack' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/jetpack.php';
		}

		if ( class_exists( 'Polylang' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'compat/polylang.php';
		}
	}

	/**
	 * @return mixed|void Are we currently viewing the home page.
	 */
	public static function is_home() {
		$home = ( is_front_page() && is_page() && get_option( 'show_on_front' ) == 'page' && get_option( 'page_on_front' ) == get_the_ID() && get_post_meta( get_the_ID(), 'panels_data' ) );

		return apply_filters( 'siteorigin_panels_is_home', $home );
	}

	/**
	 * Check if we're currently viewing a page builder page.
	 *
	 * @param bool $can_edit Also check if the user can edit this page
	 *
	 * @return bool
	 */
	public static function is_panel( $can_edit = false ) {
		// Check if this is a panel
		$is_panel = ( siteorigin_panels_is_home() || ( is_singular() && get_post_meta( get_the_ID(), 'panels_data', false ) ) );

		return $is_panel && ( ! $can_edit || ( ( is_singular() && current_user_can( 'edit_post', get_the_ID() ) ) || ( siteorigin_panels_is_home() && current_user_can( 'edit_theme_options' ) ) ) );
	}

	/**
	 * Check if we're in the Live Editor in the frontend.
	 *
	 * @return bool
	 */
	public static function is_live_editor() {
		return ! empty( $_GET['siteorigin_panels_live_editor'] );
	}

	public static function preview_url() {
		global $post, $wp_post_types;

		if (
			empty( $post ) ||
			empty( $wp_post_types ) ||
			empty( $wp_post_types[ $post->post_type ] ) ||
			! $wp_post_types[ $post->post_type ]->public
		) {
			$preview_url = add_query_arg(
				'siteorigin_panels_live_editor',
				'true',
				admin_url( 'admin-ajax.php?action=so_panels_live_editor_preview' )
			);
		} else {
			$preview_url = add_query_arg( 'siteorigin_panels_live_editor', 'true', set_url_scheme( get_permalink() ) );
		}
		$preview_url = wp_nonce_url( $preview_url, 'live-editor-preview', '_panelsnonce' );

		return $preview_url;
	}

	public static function container_settings() {
		$container = array(
			'selector' => apply_filters( 'siteorigin_panels_theme_container_selector', '' ),
			'width' => apply_filters( 'siteorigin_panels_theme_container_width', '' ),
			'full_width' => false,
		);
		$container['css_override'] = ! empty( $container['selector'] ) && ! empty( $container['width'] );

		return $container;
	}

	/**
	 * Get the Page Builder data for the home page.
	 *
	 * @return bool|mixed
	 */
	public function get_home_page_data() {
		$page_id = get_option( 'page_on_front' );

		if ( empty( $page_id ) ) {
			$page_id = get_option( 'siteorigin_panels_home_page_id' );
		}

		if ( empty( $page_id ) ) {
			return false;
		}

		$panels_data = get_post_meta( $page_id, 'panels_data', true );

		if ( is_null( $panels_data ) ) {
			// Load the default layout
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
			$panels_data = ! empty( $layouts['default_home'] ) ? $layouts['default_home'] : current( $layouts );
		}

		return $panels_data;
	}

	/**
	 * Generate post content for WooCommerce shop page if it's using a PB layout.
	 *
	 * @return string
	 *
	 * @filter woocommerce_format_content
	 */
	public function generate_woocommerce_content( $content ) {
		if ( class_exists( 'WooCommerce' ) && is_shop() ) {
			return $this->generate_post_content( $content );
		}

		return $content;
	}

	/**
	 * Generate post content for the current post.
	 *
	 * @return string
	 *
	 * @filter the_content
	 */
	public function generate_post_content( $content ) {
		global $post, $preview;

		if ( empty( $post ) && ! in_the_loop() ) {
			return $content;
		}

		if ( ! apply_filters( 'siteorigin_panels_filter_content_enabled', true ) ) {
			return $content;
		}

		$post_id = $this->get_post_id();

		// Check if this post has panels_data.
		if ( get_post_meta( $post_id, 'panels_data', true ) ) {
			$panel_content = SiteOrigin_Panels::renderer()->render(
				$post_id,
				// Add CSS if this is not the main single post, this is handled by add_single_css.
				$preview || $post_id !== get_queried_object_id()
			);

			if ( ! empty( $panel_content ) ) {
				$content = $panel_content;

				if ( ! is_singular() ) {
					// This is an archive page, so try strip out anything after the more text.

					if ( preg_match( '/<!--more(.*?)?-->/', $content, $matches ) ) {
						$content = explode( $matches[0], $content, 2 );
						$content = $content[0];
						$content = force_balance_tags( $content );

						if ( ! empty( $matches[1] ) ) {
							$more_link_text = strip_tags( wp_kses_no_null( trim( $matches[1] ) ) );
						} else {
							$more_link_text = __( 'Read More', 'siteorigin-panels' );
						}

						$more_link = apply_filters( 'the_content_more_link', ' <a href="' . get_permalink() . "#more-{$post->ID}\" class=\"more-link\">$more_link_text</a>", $more_link_text );
						$content .= '<p>' . $more_link . '</p>';
					}
				}
			}
		}

		return $content;
	}

	/**
	 * Generate an excerpt for the current post, if possible.
	 *
	 * @return mixed|string
	 */
	public function generate_post_excerpt( $text ) {
		global $post;

		if ( ( empty( $post ) && ! in_the_loop() ) || $text !== '' ) {
			return $text;
		}

		$post_id = $this->get_post_id();
		$panels_data = get_post_meta( $post_id, 'panels_data', true );

		// If no panels_data is detected, check if the post has blocks.
		if ( empty( $panels_data ) ) {
			if ( function_exists( 'has_blocks' ) && has_blocks( get_the_content() ) ) {
				$parsed_content = parse_blocks( get_the_content() );
				// Check if the first block is an SO Layout Block, and extract panels_data if it is.
				if (
					$parsed_content[0]['blockName'] == 'siteorigin-panels/layout-block' &&
					isset( $parsed_content[0]['attrs'] ) &&
					! empty( $parsed_content[0]['attrs']['panelsData'] )
				) {
					$panels_data = $parsed_content[0]['attrs']['panelsData'];
				}
			}
		}

		if ( $panels_data && ! empty( $panels_data['widgets'] ) ) {
			$raw_excerpt = '';
			$excerpt_length = apply_filters( 'excerpt_length', 55 );

			foreach ( $panels_data['widgets'] as $widget ) {
				$panels_info = $widget['panels_info'];

				if ( $panels_info['grid'] > 1 ) {
					// Limiting search for a text type widget to the first two PB rows to avoid having excerpt content
					// that's very far down in a post.
					break;
				}

				if ( $panels_info['class'] == 'SiteOrigin_Widget_Editor_Widget' || $panels_info['class'] == 'WP_Widget_Text' || $panels_info['class'] == 'WP_Widget_Black_Studio_TinyMCE' ) {
					$raw_excerpt .= ' ' . $widget['text'];
					// This is all effectively default behavior for excerpts, copied from the `wp_trim_excerpt` function.
					// We're just applying it to text type widgets content in the first two rows.
					$text = strip_shortcodes( $raw_excerpt );
					$text = str_replace( ']]>', ']]&gt;', $text );

					if ( $this->get_localized_word_count( $text ) >= $excerpt_length ) {
						break;
					}

					// Check for more quicktag.
					if ( strpos( $text, '<!--more' ) !== false ) {
						// Only return everything prior to more quicktag.
						$raw_excerpt = explode( '<!--more', $text )[0];
						$excerpt_length = $this->get_localized_word_count( $raw_excerpt );
						break;
					}
				}
			}

			$text = strip_shortcodes( $raw_excerpt );
			$text = str_replace( ']]>', ']]&gt;', $text );

			$excerpt_more = apply_filters( 'excerpt_more', ' ' . '[&hellip;]' );
			$text = wp_trim_words( $text, $excerpt_length, $excerpt_more );
		}

		return $text;
	}

	private function get_localized_word_count( $text ) {
		// From the core `wp_trim_words` function to get localized word count.
		$text = wp_strip_all_tags( $text );

		if ( strpos( _x( 'words', 'Word count type. Do not translate!' ), 'characters' ) === 0 && preg_match( '/^utf\-?8$/i', get_option( 'blog_charset' ) ) ) {
			$text = trim( preg_replace( "/[\n\r\t ]+/", ' ', $text ), ' ' );
			preg_match_all( '/./u', $text, $words_array );
			$words_array = $words_array[0];
		} else {
			$words_array = preg_split( "/[\n\r\t ]+/", $text, -1, PREG_SPLIT_NO_EMPTY );
		}

		return count( $words_array );
	}

	/**
	 * Generate CSS for the current post
	 */
	public function generate_post_css() {
		$post_id = $this->get_post_id();

		if ( is_singular() && get_post_meta( $post_id, 'panels_data', true ) ) {
			$renderer = SiteOrigin_Panels::renderer();
			$renderer->add_inline_css( $post_id, $renderer->generate_css( $post_id ) );
		}
	}

	/**
	 * Get the post id for the current post.
	 */
	public function get_post_id() {
		$post_id = get_the_ID();

		if ( class_exists( 'WooCommerce' ) && is_shop() ) {
			$post_id = wc_get_page_id( 'shop' );
		}
		global $preview;
		// If we're viewing a preview make sure we load and render the autosave post's meta.
		if ( $preview ) {
			$preview_post = wp_get_post_autosave( $post_id, get_current_user_id() );

			if ( ! empty( $preview_post ) ) {
				$post_id = $preview_post->ID;
			}
		}

		return $post_id;
	}

	/**
	 * Add all the necessary body classes.
	 *
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( self::is_panel() ) {
			$classes[] = 'siteorigin-panels';
			$classes[] = 'siteorigin-panels-before-js';

			add_action( 'wp_footer', array( $this, 'strip_before_js' ), 99 );
		}

		if ( self::is_home() ) {
			$classes[] = 'siteorigin-panels-home';
		}

		if ( self::is_live_editor() ) {
			$classes[] = 'siteorigin-panels-live-editor';
		}

		$this->container = SiteOrigin_Panels::container_settings();

		if ( ! empty( $this->container ) && $this->container['css_override'] ) {
			$classes[] = 'siteorigin-panels-css-container';
		}

		return $classes;
	}

	/**
	 * Add the Edit Home Page item to the admin bar.
	 *
	 * @param WP_Admin_Bar $admin_bar
	 *
	 * @return WP_Admin_Bar
	 */
	public function admin_bar_menu( $admin_bar ) {
		// Add the edit home page link
		if (
			siteorigin_panels_setting( 'home-page' ) &&
			current_user_can( 'edit_theme_options' ) &&
			( is_home() || is_front_page() )
		) {
			if ( ( is_page() && get_post_meta( get_the_ID(), 'panels_data', true ) !== '' ) || ! is_page() ) {
				$admin_bar->add_node( array(
					'id'    => 'edit-home-page',
					'title' => __( 'Edit Home Page', 'siteorigin-panels' ),
					'href'  => admin_url( 'themes.php?page=so_panels_home_page' ),
				) );

				if ( is_page() ) {
					// Remove the standard edit button
					$admin_bar->remove_node( 'edit' );
				}
			}
		}

		// Add a Live Edit link if this is a Page Builder page that the user can edit.
		if (
			siteorigin_panels_setting( 'live-editor-quick-link' ) &&
			is_singular() &&
			current_user_can( 'edit_post', get_the_ID() ) &&
			get_post_meta( get_the_ID(), 'panels_data', true )
		) {
			$admin_bar->add_node( array(
				'id'    => 'so_live_editor',
				'title' => __( 'Live Editor', 'siteorigin-panels' ),
				'href'  => add_query_arg( 'so_live_editor', 1, get_edit_post_link( get_the_ID() ) ),
				'meta'  => array(
					'class' => 'live-edit-page',
				),
			) );

			add_action( 'wp_enqueue_scripts', array( $this, 'live_edit_link_style' ) );
		}

		return $admin_bar;
	}

	public function widgets_init() {
		register_widget( 'SiteOrigin_Panels_Widgets_PostContent' );
		register_widget( 'SiteOrigin_Panels_Widgets_PostLoop' );
		register_widget( 'SiteOrigin_Panels_Widgets_Layout' );
	}

	public function live_edit_link_style() {
		if ( is_singular() && current_user_can( 'edit_post', get_the_ID() ) && get_post_meta( get_the_ID(), 'panels_data', true ) ) {
			// Add the style for the eye icon before the Live Editor link.
			$css = '#wpadminbar #wp-admin-bar-so_live_editor > .ab-item:before {
			    content: "\f177";
			    top: 2px;
			}';
			wp_add_inline_style( 'siteorigin-panels-front', $css );
		}
	}

	/**
	 * Process panels data to make sure everything is properly formatted.
	 *
	 * @param array $panels_data
	 *
	 * @return array
	 */
	public function process_panels_data( $panels_data ) {
		// Process all widgets to make sure that panels_info is properly represented.
		if ( ! empty( $panels_data['widgets'] ) && is_array( $panels_data['widgets'] ) ) {
			$last_gi = 0;
			$last_ci = 0;
			$last_wi = 0;

			foreach ( $panels_data['widgets'] as &$widget ) {
				// Transfer legacy content
				if ( empty( $widget['panels_info'] ) && ! empty( $widget['info'] ) ) {
					$widget['panels_info'] = $widget['info'];
					unset( $widget['info'] );
				}

				// Filter the widgets to add indexes.
				if ( $widget['panels_info']['grid'] != $last_gi ) {
					$last_gi = $widget['panels_info']['grid'];
					$last_ci = $widget['panels_info']['cell'];
					$last_wi = 0;
				} elseif ( $widget['panels_info']['cell'] != $last_ci ) {
					$last_ci = $widget['panels_info']['cell'];
					$last_wi = 0;
				}
				$widget['panels_info']['cell_index'] = $last_wi ++;
			}

			foreach ( $panels_data['grids'] as &$grid ) {
				if ( ! empty( $grid['style'] ) && is_string( $grid['style'] ) ) {
					$grid['style'] = array();
				}
			}
		}

		return $panels_data;
	}

	/**
	 * Fix class names that have been incorrectly escaped.
	 *
	 * @return mixed
	 */
	public function fix_namespace_escaping( $class ) {
		return preg_replace( '/\\\\+/', '\\', $class );
	}

	public static function front_css_url() {
		return self::renderer()->front_css_url();
	}

	/**
	 * Trigger a siteorigin_panels_version_changed action if the version has changed.
	 */
	public function version_check() {
		$active_version = get_option( 'siteorigin_panels_active_version', false );

		if ( empty( $active_version ) || $active_version !== SITEORIGIN_PANELS_VERSION ) {
			do_action( 'siteorigin_panels_version_changed' );
			update_option( 'siteorigin_panels_active_version', SITEORIGIN_PANELS_VERSION );
		}
	}

	/**
	 * Script that removes the siteorigin-panels-before-js class from the body.
	 */
	public function strip_before_js() {
		?><script<?php echo current_theme_supports( 'html5', 'script' ) ? '' : ' type="text/javascript"'; ?>>document.body.className = document.body.className.replace("siteorigin-panels-before-js","");</script><?php
	}

	/**
	 * Should we display premium addon messages.
	 *
	 * @return bool
	 */
	public static function display_premium_teaser() {
		return siteorigin_panels_setting( 'display-teaser' ) &&
			   apply_filters( 'siteorigin_premium_upgrade_teaser', true ) &&
			   ! defined( 'SITEORIGIN_PREMIUM_VERSION' );
	}

	/**
	 * Get the premium upgrade URL.
	 *
	 * @return string
	 */
	public static function premium_url( $featured_addon = false ) {
		$ref = apply_filters( 'siteorigin_premium_affiliate_id', '' );
		$url = 'https://siteorigin.com/downloads/premium/?featured_plugin=siteorigin-panels';

		if ( ! empty( $featured_addon ) ) {
			$url = add_query_arg( 'featured_addon', urlencode( $featured_addon ), $url );
		}

		if ( ! empty( $ref ) ) {
			$url = add_query_arg( 'ref', urlencode( $ref ), $url );
		}

		return $url;
	}

	/**
	 * Get the registered widget instance by it's class name or the hash generated when it was registered.
	 *
	 * @return array
	 */
	public static function get_widget_instance( $class_or_hash ) {
		global $wp_widget_factory;

		if ( isset( $wp_widget_factory->widgets[ $class_or_hash ] ) ) {
			return $wp_widget_factory->widgets[ $class_or_hash ];
		} else {
			foreach ( $wp_widget_factory->widgets as $widget_instance ) {
				if ( $widget_instance instanceof $class_or_hash ) {
					return $widget_instance;
				}
			}
		}

		return null;
	}

	/**
	 * Flag redirect to welcome page after activation.
	 */
	public function activation_flag_redirect( $plugin ) {
		if ( $plugin == plugin_basename( __FILE__ ) ) {
			set_transient( 'siteorigin_panels_activation_welcome', true, 30 );
		}
	}

	/**
	 * Redirect to a welcome page after activation.
	 */
	public function activation_do_redirect() {
		if ( get_transient( 'siteorigin_panels_activation_welcome' ) ) {
			delete_transient( 'siteorigin_panels_activation_welcome' );

			// Postpone redirect in certain situations
			if ( ! wp_doing_ajax() && ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
				delete_transient( 'siteorigin_panels_activation_welcome' );
				wp_safe_redirect( admin_url( 'options-general.php?page=siteorigin_panels#welcome' ) );
				exit();
			}
		}
	}
}

SiteOrigin_Panels::single();
