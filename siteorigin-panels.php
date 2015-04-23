<?php
/*
Plugin Name: Page Builder by SiteOrigin
Plugin URI: http://siteorigin.com/page-builder/
Description: A drag and drop, responsive page builder that simplifies building your website.
Version: 2.1.1
Author: SiteOrigin
Author URI: http://siteorigin.com
License: GPL3
License URI: http://www.gnu.org/licenses/gpl.html
Donate link: http://siteorigin.com/page-builder/#donate
*/

define('SITEORIGIN_PANELS_VERSION', '2.1.1');
define('SITEORIGIN_PANELS_JS_SUFFIX', '');
define('SITEORIGIN_PANELS_BASE_FILE', __FILE__);

// All the basic settings
require_once plugin_dir_path(__FILE__) . 'settings/settings.php';

// Include all the basic widgets
require_once plugin_dir_path(__FILE__) . 'widgets/basic.php';
require_once plugin_dir_path(__FILE__) . 'widgets/migration.php';

require_once plugin_dir_path(__FILE__) . 'inc/css.php';
require_once plugin_dir_path(__FILE__) . 'inc/revisions.php';
require_once plugin_dir_path(__FILE__) . 'inc/styles.php';
require_once plugin_dir_path(__FILE__) . 'inc/default-styles.php';
require_once plugin_dir_path(__FILE__) . 'inc/widgets.php';
require_once plugin_dir_path(__FILE__) . 'inc/plugin-activation.php';
require_once plugin_dir_path(__FILE__) . 'inc/admin-actions.php';
require_once plugin_dir_path(__FILE__) . 'inc/sidebars-emulator.php';

if( defined('SITEORIGIN_PANELS_DEV') && SITEORIGIN_PANELS_DEV ) include plugin_dir_path(__FILE__).'inc/debug.php';

/**
 * Hook for activation of Page Builder.
 */
function siteorigin_panels_activate(){
	add_option('siteorigin_panels_initial_version', SITEORIGIN_PANELS_VERSION, '', 'no');
}
register_activation_hook(__FILE__, 'siteorigin_panels_activate');

/**
 * Initialize the Page Builder.
 */
function siteorigin_panels_init(){
	$bundled = siteorigin_panels_setting('bundled-widgets');
	if( !$bundled ) return;

	if( !defined('SITEORIGIN_PANELS_LEGACY_WIDGETS_ACTIVE') && ( !is_admin() || basename($_SERVER["SCRIPT_FILENAME"]) != 'plugins.php') ) {
		// Include the bundled widgets if the Legacy Widgets plugin isn't active.
		include plugin_dir_path(__FILE__).'widgets/widgets.php';
	}
}
add_action('plugins_loaded', 'siteorigin_panels_init');

/**
 * Initialize the language files
 */
function siteorigin_panels_init_lang(){
	load_plugin_textdomain('siteorigin-panels', false, dirname( plugin_basename( __FILE__ ) ). '/lang/');
}
add_action('plugins_loaded', 'siteorigin_panels_init_lang');

/**
 * Add the admin menu entries
 */
function siteorigin_panels_admin_menu(){
	if( !siteorigin_panels_setting( 'home-page' ) ) return;

	add_theme_page(
		__( 'Custom Home Page Builder', 'siteorigin-panels' ),
		__( 'Home Page', 'siteorigin-panels' ),
		'edit_theme_options',
		'so_panels_home_page',
		'siteorigin_panels_render_admin_home_page'
	);
}
add_action('admin_menu', 'siteorigin_panels_admin_menu');

/**
 * Render the page used to build the custom home page.
 */
function siteorigin_panels_render_admin_home_page(){
	// We need a global post for some features in Page Builder (eg history)
	global $post;

	$home_page_id = get_option( 'page_on_front' );
	if( empty($home_page_id) ) $home_page_id = get_option( 'siteorigin_panels_home_page_id' );

	$home_page = get_post( $home_page_id );
	if( !empty($home_page) && get_post_meta( $home_page->ID, 'panels_data', true ) != '' ) {
		$post = $home_page;
	}

	$panels_data = siteorigin_panels_get_current_admin_panels_data();
	include plugin_dir_path(__FILE__).'tpl/admin-home-page.php';
}

/**
 * Callback to register the Page Builder Metaboxes
 */
function siteorigin_panels_metaboxes() {
	foreach( siteorigin_panels_setting( 'post-types' ) as $type ){
		add_meta_box(
			'so-panels-panels',
			__( 'Page Builder', 'siteorigin-panels' ),
			'siteorigin_panels_metabox_render',
			$type,
			'advanced',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'siteorigin_panels_metaboxes' );

/**
 * Save home page
 */
function siteorigin_panels_save_home_page(){
	$nonce = filter_input( INPUT_POST, '_sopanels_home_nonce', FILTER_SANITIZE_STRING );
	if( !wp_verify_nonce($nonce, 'save') ) return;

	if( !current_user_can('edit_theme_options') ) return;

	// Check that the home page ID is set and the home page exists
	$page_id = get_option( 'page_on_front' );
	if( empty($page_id) ) $page_id = get_option( 'siteorigin_panels_home_page_id' );

	$request = filter_input_array( INPUT_POST, array(
		'panels_data' => FILTER_DEFAULT,
		'siteorigin_panels_home_enabled' => FILTER_VALIDATE_BOOLEAN
	) );

	if ( !$page_id || get_post_meta( $page_id, 'panels_data', true ) == '' ) {
		// Lets create a new page
		$page_id = wp_insert_post( array(
			// TRANSLATORS: This is the default name given to a user's home page
			'post_title' => __( 'Home Page', 'siteorigin-panels' ),
			'post_status' => $request['siteorigin_panels_home_enabled'] ? 'publish' : 'draft',
			'post_type' => 'page',
			'comment_status' => 'closed',
		) );
		update_option( 'page_on_front', $page_id );
		update_option( 'siteorigin_panels_home_page_id', $page_id );
	}

	// Save the updated page data
	$panels_data = json_decode( $request['panels_data'], true);
	$panels_data['widgets'] = siteorigin_panels_process_raw_widgets($panels_data['widgets']);
	$panels_data = siteorigin_panels_styles_sanitize_all( $panels_data );

	update_post_meta( $page_id, 'panels_data', $panels_data );
	update_post_meta( $page_id, '_wp_page_template', siteorigin_panels_setting( 'home-template' ) );

	if( $request['siteorigin_panels_home_enabled'] ) {
		update_option('show_on_front', 'page');
		update_option('page_on_front', $page_id);
		update_option('siteorigin_panels_home_page_id', $page_id);
		wp_publish_post($page_id);
	}
	else {
		// We're disabling this home page
		update_option( 'show_on_front', 'posts' );

		// Change the post status to draft
		$post = get_post($page_id);
		if($post->post_status != 'draft') {
			global $wpdb;

			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post->ID ) );
			clean_post_cache( $post->ID );

			$old_status = $post->post_status;
			$post->post_status = 'draft';
			wp_transition_post_status( 'draft', $old_status, $post );

			do_action( 'edit_post', $post->ID, $post );
			do_action( "save_post_{$post->post_type}", $post->ID, $post, true );
			do_action( 'save_post', $post->ID, $post, true );
			do_action( 'wp_insert_post', $post->ID, $post, true );
		}

	}
}
add_action('admin_init', 'siteorigin_panels_save_home_page');

/**
 * After the theme is switched, change the template on the home page if the theme supports home page functionality.
 */
function siteorigin_panels_update_home_on_theme_change(){
	$page_id = get_option( 'page_on_front' );
	if( empty($page_id) ) $page_id = get_option( 'siteorigin_panels_home_page_id' );

	if( siteorigin_panels_setting( 'home-page' ) && siteorigin_panels_setting( 'home-template' ) && $page_id && get_post_meta( $page_id, 'panels_data', true ) !== '' ) {
		// Lets update the home page to use the home template that this theme supports
		update_post_meta( $page_id, '_wp_page_template', siteorigin_panels_setting( 'home-template' ) );
	}
}
add_action('after_switch_theme', 'siteorigin_panels_update_home_on_theme_change');

/**
 * @return mixed|void Are we currently viewing the home page
 */
function siteorigin_panels_is_home(){
	$home = ( is_front_page() && is_page() && get_option('show_on_front') == 'page' && get_option('page_on_front') == get_the_ID() && get_post_meta( get_the_ID(), 'panels_data' ) );
	return apply_filters('siteorigin_panels_is_home', $home);
}

/**
 * Check if we're currently viewing a page builder page.
 *
 * @param bool $can_edit Also check if the user can edit this page
 * @return bool
 */
function siteorigin_panels_is_panel($can_edit = false){
	// Check if this is a panel
	$is_panel =  ( siteorigin_panels_is_home() || ( is_singular() && get_post_meta(get_the_ID(), 'panels_data', false) != '' ) );
	return $is_panel && (!$can_edit || ( (is_singular() && current_user_can('edit_post', get_the_ID())) || ( siteorigin_panels_is_home() && current_user_can('edit_theme_options') ) ));
}

/**
 * Render a panel metabox.
 *
 * @param $post
 */
function siteorigin_panels_metabox_render( $post ) {
	$panels_data = siteorigin_panels_get_current_admin_panels_data();
	include plugin_dir_path(__FILE__) . 'tpl/metabox-panels.php';
}

/**
 * Enqueue the panels admin scripts
 *
 * @action admin_print_scripts-post-new.php
 * @action admin_print_scripts-post.php
 * @action admin_print_scripts-appearance_page_so_panels_home_page
 */
function siteorigin_panels_admin_enqueue_scripts($prefix) {
	$screen = get_current_screen();

	if ( ( $screen->base == 'post' && in_array( $screen->id, siteorigin_panels_setting('post-types') ) ) || $screen->base == 'appearance_page_so_panels_home_page' || $screen->base == 'widgets' || $screen->base == 'customize' ) {

		wp_enqueue_script( 'so-panels-admin', plugin_dir_url(__FILE__) . 'js/siteorigin-panels' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'jquery', 'jquery-ui-resizable', 'jquery-ui-sortable', 'jquery-ui-draggable', 'underscore', 'backbone', 'plupload', 'plupload-all' ), SITEORIGIN_PANELS_VERSION, true );
		wp_enqueue_script( 'so-panels-admin-styles', plugin_dir_url(__FILE__) . 'js/siteorigin-panels-styles' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'jquery', 'underscore', 'backbone', 'wp-color-picker' ), SITEORIGIN_PANELS_VERSION, true );

		if( $screen->base != 'widgets' && $screen->base != 'customize' ) {
			// We don't use the history browser and live editor in the widgets interface
			wp_enqueue_script( 'so-panels-admin-history', plugin_dir_url(__FILE__) . 'js/siteorigin-panels-history' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'so-panels-admin', 'jquery', 'underscore', 'backbone' ), SITEORIGIN_PANELS_VERSION, true );
			wp_enqueue_script( 'so-panels-admin-live-editor', plugin_dir_url(__FILE__) . 'js/siteorigin-panels-live-editor' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array( 'so-panels-admin', 'jquery', 'underscore', 'backbone' ), SITEORIGIN_PANELS_VERSION, true );
		}

		add_action( 'admin_footer', 'siteorigin_panels_js_templates' );

		$widgets = siteorigin_panels_get_widgets();

		wp_localize_script( 'so-panels-admin', 'soPanelsOptions', array(
			'ajaxurl' => wp_nonce_url( admin_url('admin-ajax.php?action=so_panels_import_layout'), 'panels_action', '_panelsnonce' ),
			'widgets' => $widgets,
			'widget_dialog_tabs' => apply_filters( 'siteorigin_panels_widget_dialog_tabs', array(
				0 => array(
					'title' => __('All Widgets', 'siteorigin-panels'),
					'filter' => array(
						'installed' => true,
						'groups' => ''
					)
				)
			) ),
			'row_layouts' => apply_filters( 'siteorigin_panels_row_layouts', array() ),
			// General localization messages
			'loc' => array(
				'missing_widget' => array(
					'title' => __('Missing Widget', 'siteorigin-panels'),
					'description' => __("Page Builder doesn't know about this widget.", 'siteorigin-panels'),
				),
				'time' => array(
					// TRANSLATORS: Number of seconds since
					'seconds' => __('%d seconds', 'siteorigin-panels'),
					// TRANSLATORS: Number of minutes since
					'minutes' => __('%d minutes', 'siteorigin-panels'),
					// TRANSLATORS: Number of hours since
					'hours' => __('%d hours', 'siteorigin-panels'),

					// TRANSLATORS: A single second since
					'second' => __('%d second', 'siteorigin-panels'),
					// TRANSLATORS: A single minute since
					'minute' => __('%d minute', 'siteorigin-panels'),
					// TRANSLATORS: A single hour since
					'hour' => __('%d hour', 'siteorigin-panels'),

					// TRANSLATORS: Time ago - eg. "1 minute before".
					'ago' => __('%s before', 'siteorigin-panels'),
					'now' => __('Now', 'siteorigin-panels'),
				),
				'history' => array(
					// History messages
					'current' => __('Current', 'siteorigin-panels'),
					'revert' => __('Original', 'siteorigin-panels'),
					'restore' => __('Version restored', 'siteorigin-panels'),
					'back_to_editor' => __('Converted to editor', 'siteorigin-panels'),

					// Widgets
					// TRANSLATORS: Message displayed in the history when a widget is deleted
					'widget_deleted' => __('Widget deleted', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a widget is added
					'widget_added' => __('Widget added', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a widget is edited
					'widget_edited' => __('Widget edited', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a widget is duplicated
					'widget_duplicated' => __('Widget duplicated', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a widget position is changed
					'widget_moved' => __('Widget moved', 'siteorigin-panels'),

					// Rows
					// TRANSLATORS: Message displayed in the history when a row is deleted
					'row_deleted' => __('Row deleted', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a row is added
					'row_added' => __('Row added', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a row is edited
					'row_edited' => __('Row edited', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a row position is changed
					'row_moved' => __('Row moved', 'siteorigin-panels'),
					// TRANSLATORS: Message displayed in the history when a row is duplicated
					'row_duplicated' => __('Row duplicated', 'siteorigin-panels'),

					// Cells
					'cell_resized' => __('Cell resized', 'siteorigin-panels'),

					// Prebuilt
					'prebuilt_loaded' => __('Prebuilt layout loaded', 'siteorigin-panels'),
				),

				// general localization
				'prebuilt_confirm' => __('Are you sure you want to overwrite your current content? This can be undone in the builder history.', 'siteorigin-panels'),
				'prebuilt_loading' => __('Loading prebuilt layout', 'siteorigin-panels'),
				'confirm_use_builder' => __("Would you like to copy this editor's existing content to Page Builder?", 'siteorigin-panels'),
				'confirm_stop_builder' => __("Would you like to clear your Page Builder content and revert to using the standard visual editor?", 'siteorigin-panels'),
				// TRANSLATORS: This is the title for a widget called "Layout Builder"
				'layout_widget' => __('Layout Builder Widget', 'siteorigin-panels'),
				// TRANSLATORS: A standard confirmation message
				'dropdown_confirm' => __('Are you sure?', 'siteorigin-panels'),
			),
			'plupload' => array(
				'max_file_size' => wp_max_upload_size().'b',
				'url'  => wp_nonce_url( admin_url('admin-ajax.php'), 'panels_action', '_panelsnonce' ),
				'flash_swf_url' => includes_url('js/plupload/plupload.flash.swf'),
				'silverlight_xap_url' => includes_url('js/plupload/plupload.silverlight.xap'),
				'filter_title' => __('Page Builder layouts', 'siteorigin-panels'),
				'error_message' => __('Error uploading or importing file.', 'siteorigin-panels'),
			)
		));

		if( $screen->base != 'widgets' ) {
			// Render all the widget forms. A lot of widgets use this as a chance to enqueue their scripts
			$original_post = isset($GLOBALS['post']) ? $GLOBALS['post'] : null; // Make sure widgets don't change the global post.
			foreach($GLOBALS['wp_widget_factory']->widgets as $class => $widget_obj){
				ob_start();
				$widget_obj->form( array() );
				ob_clean();
			}
			$GLOBALS['post'] = $original_post;
		}

		// This gives panels a chance to enqueue scripts too, without having to check the screen ID.
		if( $screen->base != 'widgets' && $screen->base != 'customize' ) {
			do_action( 'siteorigin_panel_enqueue_admin_scripts' );
			do_action( 'sidebar_admin_setup' );
		}
	}
}
add_action( 'admin_print_scripts-post-new.php', 'siteorigin_panels_admin_enqueue_scripts' );
add_action( 'admin_print_scripts-post.php', 'siteorigin_panels_admin_enqueue_scripts' );
add_action( 'admin_print_scripts-appearance_page_so_panels_home_page', 'siteorigin_panels_admin_enqueue_scripts' );
add_action( 'admin_print_scripts-widgets.php', 'siteorigin_panels_admin_enqueue_scripts' );

/**
 * Print templates when in customizer
 */
function siteorigin_panels_customize_controls_print_footer_scripts() {
	siteorigin_panels_js_templates();
}
add_action( 'customize_controls_print_footer_scripts', 'siteorigin_panels_customize_controls_print_footer_scripts' );

/**
 * Get an array of all the available widgets.
 *
 * @return array
 */
function siteorigin_panels_get_widgets(){
	global $wp_widget_factory;
	$widgets = array();
	foreach($wp_widget_factory->widgets as $class => $widget_obj) {
		$widgets[$class] = array(
			'class' => $class,
			'title' => !empty($widget_obj->name) ? $widget_obj->name : __('Untitled Widget', 'siteorigin-panels'),
			'description' => !empty($widget_obj->widget_options['description']) ? $widget_obj->widget_options['description'] : '',
			'installed' => true,
			'groups' => array(),
		);

		// Get Page Builder specific widget options
		if( isset($widget_obj->widget_options['panels_title']) ) {
			$widgets[$class]['panels_title'] = $widget_obj->widget_options['panels_title'];
		}
		if( isset($widget_obj->widget_options['panels_groups']) ) {
			$widgets[$class]['groups'] = $widget_obj->widget_options['panels_groups'];
		}
		if( isset($widget_obj->widget_options['panels_icon']) ) {
			$widgets[$class]['icon'] = $widget_obj->widget_options['panels_icon'];
		}

	}

	// Other plugins can manipulate the list of widgets. Possibly to add recommended widgets
	$widgets = apply_filters('siteorigin_panels_widgets', $widgets);

	// Sort the widgets alphabetically
	uasort($widgets, 'siteorigin_panels_widgets_sorter');

	return $widgets;
}

/**
 * @param $a
 * @param $b
 */
function siteorigin_panels_widgets_sorter($a, $b){
	return $a['title'] > $b['title'] ? 1 : -1;
}

/**
 * Display the templates for JS in the footer
 */
function siteorigin_panels_js_templates(){
	include plugin_dir_path(__FILE__).'tpl/js-templates.php';
}

/**
 * Enqueue the admin panel styles
 *
 * @action admin_print_styles-post-new.php
 * @action admin_print_styles-post.php
 */
function siteorigin_panels_admin_enqueue_styles() {
	$screen = get_current_screen();
	if ( in_array( $screen->id, siteorigin_panels_setting('post-types') ) || $screen->base == 'appearance_page_so_panels_home_page' || $screen->base == 'widgets' || $screen->base == 'customize') {
		wp_enqueue_style( 'so-panels-admin', plugin_dir_url(__FILE__) . 'css/admin.css', array( 'wp-color-picker' ), SITEORIGIN_PANELS_VERSION );
		do_action( 'siteorigin_panel_enqueue_admin_styles' );
	}
}
add_action( 'admin_print_styles-post-new.php', 'siteorigin_panels_admin_enqueue_styles' );
add_action( 'admin_print_styles-post.php', 'siteorigin_panels_admin_enqueue_styles' );
add_action( 'admin_print_styles-appearance_page_so_panels_home_page', 'siteorigin_panels_admin_enqueue_styles' );
add_action( 'admin_print_styles-widgets.php', 'siteorigin_panels_admin_enqueue_styles' );

/**
 * Add a help tab to pages with panels.
 */
function siteorigin_panels_add_help_tab($prefix) {
	$screen = get_current_screen();
	if(
		( $screen->base == 'post' && ( in_array( $screen->id, siteorigin_panels_setting( 'post-types' ) ) || $screen->id == '') )
		|| ($screen->id == 'appearance_page_so_panels_home_page')
	) {
		$screen->add_help_tab( array(
			'id' => 'panels-help-tab', //unique id for the tab
			'title' => __( 'Page Builder', 'siteorigin-panels' ), //unique visible title for the tab
			'callback' => 'siteorigin_panels_add_help_tab_content'
		) );
	}
}
add_action('load-page.php', 'siteorigin_panels_add_help_tab', 12);
add_action('load-post-new.php', 'siteorigin_panels_add_help_tab', 12);
add_action('load-appearance_page_so_panels_home_page', 'siteorigin_panels_add_help_tab', 12);

/**
 * Display the content for the help tab.
 */
function siteorigin_panels_add_help_tab_content(){
	include plugin_dir_path(__FILE__) . 'tpl/help.php';
}

/**
 * Save the panels data
 *
 * @param $post_id
 * @param $post
 *
 * @action save_post
 */
function siteorigin_panels_save_post( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	$nonce = filter_input( INPUT_POST, '_sopanels_nonce', FILTER_SANITIZE_STRING );
	if ( !wp_verify_nonce( $nonce, 'save' ) ) return;
	if ( !current_user_can( 'edit_post', $post_id ) ) return;

	$request = filter_input_array( INPUT_POST, array(
		'panels_data' => FILTER_DEFAULT
	) );

	if ( !wp_is_post_revision($post_id) ) {
		$panels_data = json_decode( $request['panels_data'], true);
		$panels_data['widgets'] = siteorigin_panels_process_raw_widgets($panels_data['widgets']);
		$panels_data = siteorigin_panels_styles_sanitize_all( $panels_data );

		if( !empty( $panels_data['widgets'] ) ) {
			update_post_meta( $post_id, 'panels_data', $panels_data );
		}
		else {
			// There are no widgets, so delete the panels data.
			delete_post_meta( $post_id, 'panels_data' );
		}
	}
	else {
		// When previewing, we don't need to wp_unslash the panels_data post variable.
		$panels_data = json_decode( $request['panels_data'], true);
		$panels_data['widgets'] = siteorigin_panels_process_raw_widgets($panels_data['widgets']);
		$panels_data = siteorigin_panels_styles_sanitize_all( $panels_data );

		// Because of issue #20299, we are going to save the preview into a different variable so we don't overwrite the actual data.
		// https://core.trac.wordpress.org/panels_data/20299
		if( !empty( $panels_data['widgets'] ) ) {
			update_post_meta( $post_id, '_panels_data_preview', $panels_data );
		}
	}
}
add_action( 'save_post', 'siteorigin_panels_save_post', 10, 2 );

/**
 * @param $value
 * @param $post_id
 * @param $meta_key
 *
 * @return mixed
 */
function siteorigin_panels_view_post_preview($value, $post_id, $meta_key){
	if( $meta_key == 'panels_data' && is_preview() && current_user_can( 'edit_post', $post_id ) ) {
		$panels_preview = get_post_meta($post_id, '_panels_data_preview');
		return !empty($panels_preview) ? $panels_preview : $value;
	}

	return $value;
}
add_filter('get_post_metadata', 'siteorigin_panels_view_post_preview', 10, 3);

/**
 * Process raw widgets that have come from the Page Builder front end.
 *
 * @param $widgets
 */
function siteorigin_panels_process_raw_widgets($widgets) {
	for($i = 0; $i < count($widgets); $i++) {

		$info = isset($widgets[$i]['panels_info']) ? $widgets[$i]['panels_info'] : $widgets[$i]['info'];
		unset($widgets[$i]['info']);

		if( !empty($info['raw']) ) {
			if ( class_exists( $info['class'] ) && method_exists( $info['class'], 'update' ) ) {
				$the_widget = new $info['class'];
				$widgets[$i] = $the_widget->update( $widgets[$i], $widgets[$i] );
				unset($info['raw']);
			}
		}

		$info['class'] = addslashes( $info['class'] );
		$widgets[$i]['panels_info'] = $info;

	}

	return $widgets;
}

/**
 * Get the home page panels layout data.
 *
 * @return mixed|void
 */
function siteorigin_panels_get_home_page_data(){
	$page_id = get_option( 'page_on_front' );
	if( empty($page_id) ) $page_id = get_option( 'siteorigin_panels_home_page_id' );
	if( empty($page_id) ) return false;

	$panels_data = get_post_meta( $page_id, 'panels_data', true );
	if( is_null( $panels_data ) ){
		// Load the default layout
		$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
		$panels_data = !empty($layouts['default_home']) ? $layouts['default_home'] : current($layouts);
	}

	return $panels_data;
}

/**
 * Get the Page Builder data for the current admin page.
 *
 * @return array
 */
function siteorigin_panels_get_current_admin_panels_data( ){
	$screen = get_current_screen();

	// Localize the panels with the panels data
	if($screen->base == 'appearance_page_so_panels_home_page'){
		$home_page_id = get_option( 'page_on_front' );
		if( empty($home_page_id) ) $home_page_id = get_option( 'siteorigin_panels_home_page_id' );

		$panels_data = !empty($home_page_id) ? get_post_meta( $home_page_id, 'panels_data', true ) : null;

		if( is_null( $panels_data ) ){
			// Load the default layout
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

			$home_name = siteorigin_panels_setting('home-page-default') ? siteorigin_panels_setting('home-page-default') : 'home';
			$panels_data = !empty($layouts[$home_name]) ? $layouts[$home_name] : current($layouts);
		}
		elseif( empty( $panels_data ) ) {
			// The current page_on_front isn't using page builder
			return false;
		}

		$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, 'home');
	}
	else{
		global $post;
		$panels_data = get_post_meta( $post->ID, 'panels_data', true );
		$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post->ID );
	}

	if ( empty( $panels_data ) ) $panels_data = array();

	return $panels_data;
}

/**
 * Generate the CSS for the page layout.
 *
 * @param $post_id
 * @param $panels_data
 * @return string
 */
function siteorigin_panels_generate_css($post_id, $panels_data = false){
	// Exit if we don't have panels data
	if( empty($panels_data) ) $panels_data = get_post_meta( $post_id, 'panels_data', true );
	if ( empty( $panels_data ) || empty( $panels_data['grids'] ) ) return;

	// Get some of the default settings
	$settings = siteorigin_panels_setting();
	$panels_mobile_width = $settings['mobile-width'];
	$panels_margin_bottom = $settings['margin-bottom'];

	$css = new SiteOrigin_Panels_Css_Builder();

	$ci = 0;
	foreach ( $panels_data['grids'] as $gi => $grid ) {

		$cell_count = intval( $grid['cells'] );

		// Add the cell sizing
		for ( $i = 0; $i < $cell_count; $i++ ) {
			$cell = $panels_data['grid_cells'][$ci++];

			if ( $cell_count > 1 ) {
				$width = round( $cell['weight'] * 100, 3 ) . '%';
				$width = apply_filters('siteorigin_panels_css_cell_width', $width, $grid, $gi, $cell, $ci - 1, $panels_data, $post_id);

				// Add the width and ensure we have correct formatting for CSS.
				$css->add_cell_css($post_id, $gi, $i, '', array(
					'width' => str_replace(',', '.', $width)
				));
			}
		}

		// Add the bottom margin to any grids that aren't the last
		if($gi != count($panels_data['grids'])-1){
			// Filter the bottom margin for this row with the arguments
			$css->add_row_css($post_id, $gi, '', array(
				'margin-bottom' => apply_filters('siteorigin_panels_css_row_margin_bottom', $panels_margin_bottom.'px', $grid, $gi, $panels_data, $post_id)
			));
		}

		if ( $cell_count > 1 ) {
			$css->add_cell_css($post_id, $gi, false, '', array(
				// Float right for RTL
				'float' => !is_rtl() ? 'left' : 'right'
			));
		}

		if ( $settings['responsive'] ) {
			// Mobile Responsive
			$css->add_cell_css($post_id, $gi, false, '', array(
				'float' => 'none',
				'width' => 'auto'
			), $panels_mobile_width);

			for ( $i = 0; $i < $cell_count; $i++ ) {
				if ( $i != $cell_count - 1 ) {
					$css->add_cell_css($post_id, $gi, $i, '', array(
						'margin-bottom' => $panels_margin_bottom . 'px',
					), $panels_mobile_width);
				}
			}
		}
	}

	if( $settings['responsive'] ) {
		// Add CSS to prevent overflow on mobile resolution.
		$css->add_row_css($post_id, false, '', array(
			'margin-left' => 0,
			'margin-right' => 0,
		), $panels_mobile_width);

		$css->add_cell_css($post_id, false, false, '', array(
			'padding' => 0,
		), $panels_mobile_width);
	}

	// Add the bottom margins
	$css->add_cell_css($post_id, false, false, '.so-panel', array(
		'margin-bottom' => $panels_margin_bottom.'px'
	));
	$css->add_cell_css($post_id, false, false, '.so-panel:last-child', array(
		'margin-bottom' => 0
	));

	// Let other plugins customize various aspects of the rows (grids)
	foreach ( $panels_data['grids'] as $gi => $grid ) {
		// Rows with only one cell don't need gutters
		if($grid['cells'] <= 1) continue;

		// Let other themes and plugins change the gutter.
		$gutter = apply_filters('siteorigin_panels_css_row_gutter', $settings['margin-sides'].'px', $grid, $gi, $panels_data);

		if( !empty($gutter) ) {
			// We actually need to find half the gutter.
			preg_match('/([0-9\.,]+)(.*)/', $gutter, $match);
			if( !empty( $match[1] ) ) {
				$margin_half = (floatval($match[1])/2) . $match[2];
				$css->add_row_css($post_id, $gi, '', array(
					'margin-left' => '-' . $margin_half,
					'margin-right' => '-' . $margin_half,
				) );
				$css->add_cell_css($post_id, $gi, false, '', array(
					'padding-left' => $margin_half,
					'padding-right' => $margin_half,
				) );

			}
		}
	}

	// Let other plugins and components filter the CSS object.
	$css = apply_filters('siteorigin_panels_css_object', $css, $panels_data, $post_id);
	return $css->get_css();
}


/**
 * Filter the content of the panel, adding all the widgets.
 *
 * @param $content
 * @return string
 *
 * @filter the_content
 */
function siteorigin_panels_filter_content( $content ) {
	global $post;

	static $render_cache = array();

	if ( empty( $post ) ) return $content;
	if ( !apply_filters( 'siteorigin_panels_filter_content_enabled', true ) ) return $content;
	if ( in_array( $post->post_type, siteorigin_panels_setting('post-types') ) ) {
		
		if( empty($render_cache[$post->ID]) ) {
			$render_cache[$post->ID] = siteorigin_panels_render( $post->ID );
		}
		if( !empty($render_cache[$post->ID]) ) {
			$content = $render_cache[$post->ID];
		}
	}

	return $content;
}
add_filter( 'the_content', 'siteorigin_panels_filter_content' );


/**
 * Render the panels
 *
 * @param int|string|bool $post_id The Post ID or 'home'.
 * @param bool $enqueue_css Should we also enqueue the layout CSS.
 * @param array|bool $panels_data Existing panels data. By default load from settings or post meta.
 * @return string
 */
function siteorigin_panels_render( $post_id = false, $enqueue_css = true, $panels_data = false ) {
	if( empty($post_id) ) $post_id = get_the_ID();

	global $siteorigin_panels_current_post;
	$old_current_post = $siteorigin_panels_current_post;
	$siteorigin_panels_current_post = $post_id;

	// Try get the cached panel from in memory cache.
	global $siteorigin_panels_cache;
	if(!empty($siteorigin_panels_cache) && !empty($siteorigin_panels_cache[$post_id]))
		return $siteorigin_panels_cache[$post_id];

	if( empty($panels_data) ) {
		if( strpos($post_id, 'prebuilt:') === 0) {
			list($null, $prebuilt_id) = explode(':', $post_id, 2);
			$layouts = apply_filters('siteorigin_panels_prebuilt_layouts', array());
			$panels_data = !empty($layouts[$prebuilt_id]) ? $layouts[$prebuilt_id] : array();
		}
		else if($post_id == 'home'){
			$page_id = get_option( 'page_on_front' );
			if( empty($page_id) ) $page_id = get_option( 'siteorigin_panels_home_page_id' );

			$panels_data = !empty($page_id) ? get_post_meta( $page_id, 'panels_data', true ) : null;

			if( is_null($panels_data) ){
				// Load the default layout
				$layouts = apply_filters('siteorigin_panels_prebuilt_layouts', array());
				$prebuilt_id = siteorigin_panels_setting('home-page-default') ? siteorigin_panels_setting('home-page-default') : 'home';

				$panels_data = !empty($layouts[$prebuilt_id]) ? $layouts[$prebuilt_id] : current($layouts);
			}
		}
		else{
			if ( post_password_required($post_id) ) return false;
			$panels_data = get_post_meta( $post_id, 'panels_data', true );
		}
	}

	$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post_id );
	if( empty( $panels_data ) || empty( $panels_data['grids'] ) ) return '';

	// Filter the widgets to add indexes
	if ( !empty( $panels_data['widgets'] ) ) {
		$last_gi = 0;
		$last_ci = 0;
		$last_wi = 0;
		foreach ( $panels_data['widgets'] as $wid => &$widget_info ) {

			if ( $widget_info['panels_info']['grid'] != $last_gi ) {
				$last_gi = $widget_info['panels_info']['grid'];
				$last_ci = 0;
				$last_wi = 0;
			}
			elseif ( $widget_info['panels_info']['cell'] != $last_ci ) {
				$last_ci = $widget_info['panels_info']['cell'];
				$last_wi = 0;
			}
			$widget_info['panels_info']['cell_index'] = $last_wi++;
		}
	}

	if( is_rtl() ) $panels_data = siteorigin_panels_make_rtl( $panels_data );

	// Create the skeleton of the grids
	$grids = array();
	if( !empty( $panels_data['grids'] ) && !empty( $panels_data['grids'] ) ) {
		foreach ( $panels_data['grids'] as $gi => $grid ) {
			$gi = intval( $gi );
			$grids[$gi] = array();
			for ( $i = 0; $i < $grid['cells']; $i++ ) {
				$grids[$gi][$i] = array();
			}
		}
	}

	// We need this to migrate from the old $panels_data that put widget meta into the "info" key instead of "panels_info"
	if( !empty( $panels_data['widgets'] ) && is_array($panels_data['widgets']) ) {
		foreach ( $panels_data['widgets'] as $i => $widget ) {
			if( empty( $panels_data['widgets'][$i]['panels_info'] ) ) {
				$panels_data['widgets'][$i]['panels_info'] = $panels_data['widgets'][$i]['info'];
				unset($panels_data['widgets'][$i]['info']);
			}
		}
	}

	if( !empty( $panels_data['widgets'] ) && is_array($panels_data['widgets']) ){
		foreach ( $panels_data['widgets'] as $widget ) {
			// Put the widgets in the grids
			$grids[ intval( $widget['panels_info']['grid']) ][ intval( $widget['panels_info']['cell'] ) ][] = $widget;
		}
	}

	ob_start();

	// Add the panel layout wrapper
	$panel_layout_classes = apply_filters( 'siteorigin_panels_layout_classes', array(), $post_id, $panels_data );
	$panel_layout_attributes = apply_filters( 'siteorigin_panels_layout_attributes', array(
		'class' => implode( ' ', $panel_layout_classes ),
		'id' => 'pl-' . $post_id
	),  $post_id, $panels_data );
	echo '<div';
	foreach ( $panel_layout_attributes as $name => $value ) {
		if ($value) {
			echo ' ' . $name . '="' . esc_attr($value) . '"';
		}
	}
	echo '>';

	global $siteorigin_panels_inline_css;
	if( empty($siteorigin_panels_inline_css) ) $siteorigin_panels_inline_css = array();

	if( $enqueue_css && !isset($siteorigin_panels_inline_css[$post_id]) ) {
		wp_enqueue_style('siteorigin-panels-front');
		$siteorigin_panels_inline_css[$post_id] = siteorigin_panels_generate_css($post_id, $panels_data);
	}

	echo apply_filters( 'siteorigin_panels_before_content', '', $panels_data, $post_id );

	foreach ( $grids as $gi => $cells ) {

		$grid_classes = apply_filters( 'siteorigin_panels_row_classes', array('panel-grid'), $panels_data['grids'][$gi] );
		$grid_attributes = apply_filters( 'siteorigin_panels_row_attributes', array(
			'class' => implode( ' ', $grid_classes ),
			'id' => 'pg-' . $post_id . '-' . $gi
		), $panels_data['grids'][$gi] );

		// This allows other themes and plugins to add html before the row
		echo apply_filters( 'siteorigin_panels_before_row', '', $panels_data['grids'][$gi], $grid_attributes );

		echo '<div ';
		foreach ( $grid_attributes as $name => $value ) {
			echo $name.'="'.esc_attr($value).'" ';
		}
		echo '>';

		$style_attributes = array();
		if( !empty( $panels_data['grids'][$gi]['style']['class'] ) ) {
			$style_attributes['class'] = array('panel-row-style-'.$panels_data['grids'][$gi]['style']['class']);
		}

		// Themes can add their own attributes to the style wrapper
		$row_style_wrapper = siteorigin_panels_start_style_wrapper( 'row', $style_attributes, !empty($panels_data['grids'][$gi]['style']) ? $panels_data['grids'][$gi]['style'] : array() );
		if( !empty($row_style_wrapper) ) echo $row_style_wrapper;

		foreach ( $cells as $ci => $widgets ) {
			// Themes can add their own styles to cells
			$cell_classes = apply_filters( 'siteorigin_panels_row_cell_classes', array('panel-grid-cell'), $panels_data );
			$cell_attributes = apply_filters( 'siteorigin_panels_row_cell_attributes', array(
				'class' => implode( ' ', $cell_classes ),
				'id' => 'pgc-' . $post_id . '-' . $gi  . '-' . $ci
			), $panels_data );

			echo '<div ';
			foreach ( $cell_attributes as $name => $value ) {
				echo $name.'="'.esc_attr($value).'" ';
			}
			echo '>';

			$cell_style_wrapper = siteorigin_panels_start_style_wrapper( 'cell', array(), !empty($panels_data['grids'][$gi]['style']) ? $panels_data['grids'][$gi]['style'] : array() );
			if( !empty($cell_style_wrapper) ) echo $cell_style_wrapper;

			foreach ( $widgets as $pi => $widget_info ) {
				// TODO this wrapper should go in the before/after widget arguments
				$widget_style_wrapper = siteorigin_panels_start_style_wrapper( 'widget', array(), !empty( $widget_info['panels_info']['style'] ) ? $widget_info['panels_info']['style'] : array() );
				siteorigin_panels_the_widget( $widget_info['panels_info'], $widget_info, $gi, $ci, $pi, $pi == 0, $pi == count( $widgets ) - 1, $post_id, $widget_style_wrapper );
			}
			if ( empty( $widgets ) ) echo '&nbsp;';

			if( !empty($cell_style_wrapper) ) echo '</div>';
			echo '</div>';
		}

		echo '</div>';

		// Close the
		if( !empty($row_style_wrapper) ) echo '</div>';

		// This allows other themes and plugins to add html after the row
		echo apply_filters( 'siteorigin_panels_after_row', '', $panels_data['grids'][$gi], $grid_attributes );
	}

	echo apply_filters( 'siteorigin_panels_after_content', '', $panels_data, $post_id );

	echo '</div>';

	$html = ob_get_clean();

	// Reset the current post
	$siteorigin_panels_current_post = $old_current_post;

	return apply_filters( 'siteorigin_panels_render', $html, $post_id, !empty($post) ? $post : null );
}

/**
 * Echo the style wrapper and return if there was a wrapper
 *
 * @param $name
 * @param $style_attributes
 * @param array $style_args
 *
 * @return bool Is there a style wrapper
 */
function siteorigin_panels_start_style_wrapper($name, $style_attributes, $style_args = array()){

	$style_wrapper = '';

	if( empty($style_attributes['class']) ) $style_attributes['class'] = array();
	if( empty($style_attributes['style']) ) $style_attributes['style'] = '';

	$style_attributes = apply_filters('siteorigin_panels_' . $name . '_style_attributes', $style_attributes, $style_args );

	if( empty($style_attributes['class']) ) unset($style_attributes['class']);
	if( empty($style_attributes['style']) ) unset($style_attributes['style']);

	if( !empty($style_attributes) ) {
		if(empty($style_attributes['class'])) $style_attributes['class'] = array();
		$style_attributes['class'][] = 'panel-' . $name . '-style';
		$style_attributes['class'] = array_unique( $style_attributes['class'] );

		// Filter and sanitize the classes
		$style_attributes['class'] = apply_filters('siteorigin_panels_' . $name . '_style_classes', $style_attributes['class'], $style_attributes, $style_args);
		$style_attributes['class'] = array_map('sanitize_html_class', $style_attributes['class']);

		$style_wrapper = '<div ';
		foreach ( $style_attributes as $name => $value ) {
			if( is_array($value) ) {
				$style_wrapper .= $name.'="'.esc_attr( implode( " ", array_unique( $value ) ) ).'" ';
			}
			else {
				$style_wrapper .= $name.'="'.esc_attr($value).'" ';
			}
		}
		$style_wrapper .= '>';

		return $style_wrapper;
	}

	return $style_wrapper;
}

/**
 * Print inline CSS in the header and footer.
 */
function siteorigin_panels_print_inline_css(){
	global $siteorigin_panels_inline_css;
	if(!empty($siteorigin_panels_inline_css)) {
		$the_css = '';
		foreach( $siteorigin_panels_inline_css as $post_id => $css ) {
			if( empty($css) ) continue;

			$the_css .= '/* Layout ' . esc_attr($post_id) . ' */ ';
			$the_css .= $css;
			$siteorigin_panels_inline_css[$post_id] = '';
		}

		if( !empty($the_css) ) {
			?><style type="text/css" media="all" id="siteorigin-panels-grids-<?php echo esc_attr( current_filter() ) ?>"><?php echo $the_css ?></style><?php
		}
	}
}
add_action('wp_head', 'siteorigin_panels_print_inline_css', 12);
add_action('wp_footer', 'siteorigin_panels_print_inline_css');

/**
 * Render the widget.
 *
 * @param array $widget_info The widget info.
 * @param array $instance The widget instance
 * @param int $grid The grid number.
 * @param int $cell The cell number.
 * @param int $panel the panel number.
 * @param bool $is_first Is this the first widget in the cell.
 * @param bool $is_last Is this the last widget in the cell.
 * @param bool $post_id
 * @param string $style_wrapper The start of the style wrapper
 */
function siteorigin_panels_the_widget( $widget_info, $instance, $grid, $cell, $panel, $is_first, $is_last, $post_id = false, $style_wrapper = '' ) {

	global $wp_widget_factory;

	// Set widget class to $widget
	$widget = $widget_info['class'];

	// Load the widget from the widget factory and give themes and plugins a chance to provide their own
	$the_widget = !empty($wp_widget_factory->widgets[$widget]) ? $wp_widget_factory->widgets[$widget] : false;
	$the_widget = apply_filters( 'siteorigin_panels_widget_object', $the_widget, $widget, $instance );

	if( empty($post_id) ) $post_id = get_the_ID();

	$classes = array( 'so-panel', 'widget' );   
	if ( !empty( $the_widget ) && !empty( $the_widget->id_base ) ) $classes[] = 'widget_' . $the_widget->id_base;
	if ( $is_first ) $classes[] = 'panel-first-child';
	if ( $is_last ) $classes[] = 'panel-last-child';
	$id = 'panel-' . $post_id . '-' . $grid . '-' . $cell . '-' . $panel;

	// Filter and sanitize the classes
	$classes = apply_filters('siteorigin_panels_widget_classes', $classes, $widget, $instance, $widget_info);
	$classes = array_map('sanitize_html_class', $classes);

	$title_html = siteorigin_panels_setting( 'title-html' );
	if( strpos($title_html, '{{title}}') !== false ) {
		list( $before_title, $after_title ) = explode( '{{title}}', $title_html, 2 );
	}
	else {
		$before_title = '<h3 class="widget-title">';
		$after_title = '</h3>';
	}

	$args = array(
		'before_widget' => '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="' . $id . '">',
		'after_widget' => '</div>',
		'before_title' => $before_title,
		'after_title' => $after_title,
		'widget_id' => 'widget-' . $grid . '-' . $cell . '-' . $panel
	);

	// Let other themes and plugins change the arguments that go to the widget class.
	$args = apply_filters('siteorigin_panels_widget_args', $args);

	// If there is a style wrapper, add it.
	if( !empty($style_wrapper) ) {
		$args['before_widget'] = $args['before_widget'] . $style_wrapper;
		$args['after_widget'] = '</div>' . $args['after_widget'];
	}

	if ( !empty($the_widget) && is_a($the_widget, 'WP_Widget')  ) {
		$the_widget->widget($args , $instance );
	}
	else {
		// This gives themes a chance to display some sort of placeholder for missing widgets
		echo apply_filters('siteorigin_panels_missing_widget', $args['before_widget'] . $args['after_widget'], $widget, $args , $instance);
	}
}

/**
 * Add the Edit Home Page item to the admin bar.
 *
 * @param WP_Admin_Bar $admin_bar
 * @return WP_Admin_Bar
 */
function siteorigin_panels_admin_bar_menu($admin_bar){
	// Ignore this unless the theme is using the home page feature.
	if( !siteorigin_panels_setting('home-page') ) return $admin_bar;
	if( !current_user_can('edit_theme_options') ) return $admin_bar;

	if( is_home() || is_front_page() ) {
		if( ( is_page() && get_post_meta( get_the_ID(), 'panels_data', true ) !== '' ) || !is_page() ) {
			$admin_bar->add_node( array(
				'id' => 'edit-home-page',
				'title' => __('Edit Home Page', 'siteorigin-panels'),
				'href' => admin_url('themes.php?page=so_panels_home_page')
			) );

			if( is_page() ) {
				// Remove the standard edit button
				$admin_bar->remove_node('edit');
			}
		}
	}

	return $admin_bar;
}
add_action('admin_bar_menu', 'siteorigin_panels_admin_bar_menu', 100);

/**
 * Is this a preview.
 *
 * @return bool
 */
function siteorigin_panels_is_preview(){
	global $siteorigin_panels_is_preview;
	return (bool) $siteorigin_panels_is_preview;
}

/**
 * Add all the necessary body classes.
 *
 * @param $classes
 * @return array
 */
function siteorigin_panels_body_class($classes){
	if( siteorigin_panels_is_panel() ) $classes[] = 'siteorigin-panels';
	if( siteorigin_panels_is_home() ) $classes[] = 'siteorigin-panels-home';

	return $classes;
}
add_filter('body_class', 'siteorigin_panels_body_class');

/**
 * Enqueue the required styles
 */
function siteorigin_panels_enqueue_styles(){
	// Register the style to support possible lazy loading
	wp_register_style('siteorigin-panels-front', plugin_dir_url(__FILE__) . 'css/front.css', array(), SITEORIGIN_PANELS_VERSION );

	if( is_singular() && get_post_meta( get_the_ID(), true ) != '' ) {
		wp_enqueue_style('siteorigin-panels-front');

		// Enqueue the general layout CSS
		global $siteorigin_panels_inline_css;
		if( empty($siteorigin_panels_inline_css) ) $siteorigin_panels_inline_css = array();
		$siteorigin_panels_inline_css[ get_the_ID() ] = siteorigin_panels_generate_css( get_the_ID() );
	}
}
add_action('wp_enqueue_scripts', 'siteorigin_panels_enqueue_styles', 1);

/**
 * Render a widget form with all the Page Builder specific fields
 *
 * @param string $widget The class of the widget
 * @param array $instance Widget values
 * @param bool $raw
 * @return mixed|string The form
 */
function siteorigin_panels_render_form($widget, $instance = array(), $raw = false){
	global $wp_widget_factory;

	// This is a chance for plugins to replace missing widgets
	$the_widget = !empty($wp_widget_factory->widgets[$widget]) ? $wp_widget_factory->widgets[$widget] : false;
	$the_widget = apply_filters( 'siteorigin_panels_widget_object', $the_widget, $widget );

	if ( empty($the_widget) || !is_a( $the_widget, 'WP_Widget' ) ) {
		$widgets = siteorigin_panels_get_widgets();

		if( !empty($widgets[$widget]) && !empty( $widgets[$widget]['plugin'] ) ) {
			// We know about this widget, show a form about installing it.
			$install_url = siteorigin_panels_plugin_activation_install_url($widgets[$widget]['plugin']['slug'], $widgets[$widget]['plugin']['name']);
			$form =
				'<div class="panels-missing-widget-form">' .
				'<p>' .
				preg_replace(
					array(
						'/1\{ *(.*?) *\}/',
						'/2\{ *(.*?) *\}/',
					),
					array(
						'<a href="'.$install_url.'" target="_blank">$1</a>',
						'<strong>$1</strong>'
					),
					sprintf(
						__('You need to install 1{%1$s} to use the widget 2{%2$s}.', 'siteorigin-panels') ,
						$widgets[$widget]['plugin']['name'],
						$widget
					)
				).
				'</p>' .
				'<p>' . __("Save and reload this page to start using the widget after you've installed it.", 'siteorigin-panels') . '</p>' .
				'</div>';
		}
		else {
			// This widget is missing, so show a missing widgets form.
			$form =
				'<div class="panels-missing-widget-form"><p>' .
				preg_replace(
					array(
						'/1\{ *(.*?) *\}/',
						'/2\{ *(.*?) *\}/',
					),
					array(
						'<strong>$1</strong>',
						'<a href="https://siteorigin.com/thread/" target="_blank">$1</a>'
					),
					sprintf(
						__('The widget 1{%1$s} is not available. Please try locate and install the missing plugin. Post on the 2{support forums} if you need help.', 'siteorigin-panels'),
						esc_html($widget)
					)
				).
				'</p></div>';
		}

		// Allow other themes and plugins to change the missing widget form
		return apply_filters('siteorigin_panels_missing_widget_form', $form, $widget, $instance);
	}

	if( $raw ) $instance = $the_widget->update($instance, $instance);

	$the_widget->id = 'temp';
	$the_widget->number = '{$id}';

	ob_start();
	$the_widget->form($instance);
	$form = ob_get_clean();

	// Convert the widget field naming into ones that Page Builder uses
	$exp = preg_quote( $the_widget->get_field_name('____') );
	$exp = str_replace('____', '(.*?)', $exp);
	$form = preg_replace( '/'.$exp.'/', 'widgets[{$id}][$1]', $form );

	$form = apply_filters('siteorigin_panels_widget_form', $form, $widget, $instance);

	// Add all the information fields
	return $form;
}

/**
 * This takes existing Page Builder data and makes it RTL by reversing the content
 */
function siteorigin_panels_make_rtl($panels_data){

	// To start, we need a cell count for every row
	foreach($panels_data['widgets'] as &$widget) {
		// This reverses the cells of the widgets
		$count = $panels_data['grids'][ $widget['panels_info']['grid'] ]['cells'];
		$widget['panels_info']['cell'] = abs( $widget['panels_info']['cell'] - $count + 1 );
	}

	// Now we need to swap around the grid cells because we're going to use float right instead.
	$grid_cells = array();
	foreach( $panels_data['grid_cells'] as $cell) {
		if( empty( $grid_cells[ $cell['grid'] ] ) ) $grid_cells[ $cell['grid'] ] = array();
		array_unshift( $grid_cells[ $cell['grid'] ], $cell );
	}
	$new_grid_cells = array();
	foreach( $grid_cells as $i => $cells ) {
		foreach($cells as $cell) {
			$new_grid_cells[] = $cell;
		}
	}
	$panels_data['grid_cells'] = $new_grid_cells;

	return $panels_data;
}

/**
 * Add action links to the plugin list for Page Builder.
 *
 * @param $links
 * @return array
 */
function siteorigin_panels_plugin_action_links($links) {
	$links[] = '<a href="http://siteorigin.com/threads/plugin-page-builder/">' . __('Support Forum', 'siteorigin-panels') . '</a>';
	$links[] = '<a href="http://siteorigin.com/page-builder/#newsletter">' . __('Newsletter', 'siteorigin-panels') . '</a>';
	return $links;
}
add_action('plugin_action_links_' . plugin_basename(__FILE__), 'siteorigin_panels_plugin_action_links');

/**
 * Process panels data to make sure everything is properly formatted
 *
 * @param array $panels_data
 *
 * @return array
 */
function siteorigin_panels_process_panels_data( $panels_data ){

	// Process all widgets to make sure that panels_info is properly represented
	if( !empty($panels_data['widgets']) && is_array($panels_data['widgets']) ) {

		foreach( $panels_data['widgets'] as &$widget ) {

			if( empty($widget['panels_info']) && !empty($widget['info']) ) {
				$widget['panels_info'] = $widget['info'];
				unset( $widget['info'] );
			}

		}

	}

	return $panels_data;
}
add_filter( 'siteorigin_panels_data', 'siteorigin_panels_process_panels_data', 5 );

// Include the live editor file if we're in live editor mode.
if( !empty($_GET['siteorigin_panels_live_editor']) ) require_once plugin_dir_path(__FILE__) . 'inc/live-editor.php';
