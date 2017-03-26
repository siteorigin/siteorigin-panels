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
define( 'SITEORIGIN_PANELS_VERSION_SUFFIX', '' );

require_once plugin_dir_path( __FILE__ ) . 'inc/functions.php';
require_once plugin_dir_path( __FILE__ ) . 'widgets/basic.php';

class SiteOrigin_Panels {

	function __construct() {
		register_activation_hook( __FILE__, array( 'SiteOrigin_Panels', 'activate' ) );

		// Register the autoloader
		spl_autoload_register( array( $this, 'autoloader' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );

		// This is the main filter
		add_filter( 'wp_enqueue_scripts', array( $this, 'add_single_css' ) );
		add_filter( 'the_content', array( $this, 'filter_content' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		add_filter( 'siteorigin_panels_data', array( $this, 'process_panels_data' ), 5 );

		if ( is_admin() ) {
			SiteOrigin_Panels_Settings::single();
			SiteOrigin_Panels_Revisions::single();
			SiteOrigin_Panels_Admin::single();
		}

		// Include the live editor file if we're in live editor mode.
		if ( self::is_live_editor() ) {
			SiteOrigin_Panels_Live_Editor::single();
		}

		SiteOrigin_Panels_Renderer::single();
		SiteOrigin_Panels_Styles_Admin::single();

		if( siteorigin_panels_setting( 'bundled-widgets' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'widgets/widgets.php';
		}
	}


	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Autoload Page Builder specific classses.
	 *
	 * @param $class
	 */
	public static function autoloader( $class ) {
		if ( strpos( $class, 'SiteOrigin_Panels_' ) === 0 ) {
			$filename = strtolower( str_replace( array( 'SiteOrigin_Panels_', '_' ), array( '', '-' ), $class ) );
			$filename = plugin_dir_path( __FILE__ ) . 'inc/' . strtolower( $filename ) . '.php';

			if ( file_exists( $filename ) ) {
				include $filename;
			}
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
			// Initialize the sidebars emulator
			SiteOrigin_Panels_Sidebars_Emulator::single();
		}

		// Initialize the language
		load_plugin_textdomain( 'siteorigin-panels', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

		// Initialize all the extra classes
		SiteOrigin_Panels_Home::single();

		// Check if we need to initialize the admin class.
		if ( is_admin() ) {
			SiteOrigin_Panels_Admin::single();
		}
	}

	/**
	 * @return mixed|void Are we currently viewing the home page
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
	static function is_live_editor(){
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
			$preview_url = wp_nonce_url( $preview_url, 'live-editor-preview', '_panelsnonce' );
		} else {
			$preview_url = add_query_arg( 'siteorigin_panels_live_editor', 'true', set_url_scheme( get_permalink() ) );
		}

		return $preview_url;
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
			$layouts     = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
			$panels_data = ! empty( $layouts['default_home'] ) ? $layouts['default_home'] : current( $layouts );
		}

		return $panels_data;
	}

	/**
	 * Filter the content of the panel, adding all the widgets.
	 *
	 * @param $content
	 *
	 * @return string
	 *
	 * @filter the_content
	 */
	public function filter_content( $content ) {
		global $post;

		if ( empty( $post ) && ! in_the_loop() ) {
			return $content;
		}
		if ( ! apply_filters( 'siteorigin_panels_filter_content_enabled', true ) ) {
			return $content;
		}

		// Check if this post has panels_data
		if ( get_post_meta( $post->ID, 'panels_data', true ) ) {
			$panel_content = SiteOrigin_Panels_Renderer::single()->render(
				get_the_ID(),
				! is_singular() || get_the_ID() !== get_queried_object_id()
			);

			if ( ! empty( $panel_content ) ) {
				$content = $panel_content;

				if ( ! is_singular() ) {
					// This is an archive page, so try strip out anything after the more text

					if ( preg_match( '/<!--more(.*?)?-->/', $content, $matches ) ) {
						$content = explode( $matches[0], $content, 2 );
						$content = $content[0];
						$content = force_balance_tags( $content );
						if ( ! empty( $matches[1] ) && ! empty( $more_link_text ) ) {
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

	public function add_single_css(){
		if( is_singular() && get_post_meta( get_the_ID(), 'panels_data', true ) ) {
			$renderer = SiteOrigin_Panels_Renderer::single();
			$renderer->add_inline_css( get_the_ID(), $renderer->generate_css( get_the_ID() ) );
		}
	}

	/**
	 * Add all the necessary body classes.
	 *
	 * @param $classes
	 *
	 * @return array
	 */
	function body_class( $classes ) {
		if( self::is_panel() ) $classes[] = 'siteorigin-panels';
		if( self::is_home() ) $classes[] = 'siteorigin-panels-home';
		if( self::is_live_editor() ) $classes[] = 'siteorigin-panels-live-editor';

		return $classes;
	}

	/**
	 * Add the Edit Home Page item to the admin bar.
	 *
	 * @param WP_Admin_Bar $admin_bar
	 *
	 * @return WP_Admin_Bar
	 */
	function admin_bar_menu( $admin_bar ) {
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
					'href'  => admin_url( 'themes.php?page=so_panels_home_page' )
				) );

				if ( is_page() ) {
					// Remove the standard edit button
					$admin_bar->remove_node( 'edit' );
				}
			}
		}

		// Add a Live Edit link if this is a Page Builder page that the user can edit
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
					'class' => 'live-edit-page'
				)
			) );

			add_action( 'wp_enqueue_scripts', array( $this, 'live_edit_link_style' ) );
		}

		return $admin_bar;
	}

	function live_edit_link_style() {
		if ( is_singular() && current_user_can( 'edit_post', get_the_ID() ) && get_post_meta( get_the_ID(), 'panels_data', true ) ) {
			// Add the style for the eye icon before the Live Editor link
			$css = '#wpadminbar #wp-admin-bar-so_live_editor > .ab-item:before {
			    content: "\f177";
			    top: 2px;
			}';
			wp_add_inline_style( 'siteorigin-panels-front', $css );
		}
	}

	/**
	 * Process panels data to make sure everything is properly formatted
	 *
	 * @param array $panels_data
	 *
	 * @return array
	 */
	function process_panels_data( $panels_data ) {

		// Process all widgets to make sure that panels_info is properly represented
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

				// Filter the widgets to add indexes
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

	public static function front_css_url(){
		return plugin_dir_url( __FILE__ ) . 'css/front.css';
	}
}

SiteOrigin_Panels::single();
