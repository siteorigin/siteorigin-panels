<?php

/**
 * Class SiteOrigin_Panels_Admin
 *
 * Handles all the admin and database interactions.
 */
class SiteOrigin_Panels_Admin {

	/**
	 * @var bool Store that we're in the save post action, to prevent infinite loops
	 */
	private $in_save_post;

	function __construct() {

		add_action( 'plugin_action_links_siteorigin-panels/siteorigin-panels.php', array(
			$this,
			'plugin_action_links'
		) );

		add_action( 'plugins_loaded', array( $this, 'admin_init_widget_count' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_init', array( $this, 'save_home_page' ) );
		add_action( 'save_post', array( $this, 'save_post' ) );

		add_action( 'after_switch_theme', array( $this, 'update_home_on_theme_change' ) );

		// Enqueuing admin scripts
		add_action( 'admin_print_scripts-post-new.php', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_print_scripts-post.php', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_print_scripts-appearance_page_so_panels_home_page', array(
			$this,
			'enqueue_admin_scripts'
		) );
		add_action( 'admin_print_scripts-widgets.php', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_print_scripts-edit.php', array( $this, 'footer_column_css' ) );

		// Enqueue the admin styles
		add_action( 'admin_print_styles-post-new.php', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-post.php', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-appearance_page_so_panels_home_page', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_print_styles-widgets.php', array( $this, 'enqueue_admin_styles' ) );

		// The help tab
		add_action( 'load-page.php', array( $this, 'add_help_tab' ), 12 );
		add_action( 'load-post-new.php', array( $this, 'add_help_tab' ), 12 );
		add_action( 'load-appearance_page_so_panels_home_page', array( $this, 'add_help_tab' ), 12 );

		add_action( 'customize_controls_print_footer_scripts', array( $this, 'js_templates' ) );

		// Register all the admin actions
		add_action( 'wp_ajax_so_panels_builder_content', array( $this, 'action_builder_content' ) );
		add_action( 'wp_ajax_so_panels_builder_content_json', array( $this, 'action_builder_content_json' ) );
		add_action( 'wp_ajax_so_panels_widget_form', array( $this, 'action_widget_form' ) );
		add_action( 'wp_ajax_so_panels_live_editor_preview', array( $this, 'action_live_editor_preview' ) );
		add_action( 'wp_ajax_so_panels_layout_block_sanitize', array( $this, 'layout_block_sanitize' ) );
		add_action( 'wp_ajax_so_panels_layout_block_preview', array( $this, 'layout_block_preview' ) );

		// Initialize the additional admin classes.
		SiteOrigin_Panels_Admin_Widget_Dialog::single();
		SiteOrigin_Panels_Admin_Widgets_Bundle::single();
		SiteOrigin_Panels_Admin_Layouts::single();

		// Check to make sure we have all the correct markup
		SiteOrigin_Panels_Admin_Dashboard::single();

		$this->in_save_post = false;


		// Enqueue Yoast compatibility
		add_action( 'admin_print_scripts-post-new.php', array( $this, 'enqueue_seo_compat' ), 100 );
		add_action( 'admin_print_scripts-post.php', array( $this, 'enqueue_seo_compat' ), 100 );

		// Block editor specific actions
		if ( function_exists( 'register_block_type' ) ) {
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_filter( 'gutenberg_can_edit_post_type', array( $this, 'show_classic_editor_for_panels' ), 10, 2 );
			add_filter( 'use_block_editor_for_post_type', array( $this, 'show_classic_editor_for_panels' ), 10, 2 );
			add_action( 'admin_print_scripts-edit.php', array( $this, 'add_panels_add_new_button' ) );
			if( siteorigin_panels_setting( 'admin-post-state' ) ) {
				add_filter( 'display_post_states', array( $this, 'add_panels_post_state' ), 10, 2 );
			}
		}
	}

	/**
	 * @return SiteOrigin_Panels_Admin
	 */
	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Do some general admin initialization
	 */
	public function admin_init_widget_count(){
		if( siteorigin_panels_setting( 'admin-widget-count' ) ) {

			// Add the custom columns
			$post_types = siteorigin_panels_setting( 'post-types' );
			if( ! empty( $post_types ) ) {
				foreach( $post_types as $post_type ) {
					add_filter( 'manage_' . $post_type . 's_columns' , array( $this, 'add_custom_column' ) );
					add_action( 'manage_' . $post_type . 's_custom_column' , array( $this, 'display_custom_column' ), 10, 2 );
				}
			}
		}
	}

	/**
	 * Check if this is an admin page.
	 *
	 * @return mixed|void
	 */
	static function is_admin() {
		$screen         = get_current_screen();
		$is_panels_page = ( $screen->base == 'post' && in_array( $screen->id, siteorigin_panels_setting( 'post-types' ) ) ) ||
						  in_array( $screen->base, array( 'appearance_page_so_panels_home_page', 'widgets', 'customize' ) ) ||
						  self::is_block_editor();

		return apply_filters( 'siteorigin_panels_is_admin_page', $is_panels_page );
	}

	/**
	 * Check if the current page is Gutenberg or the Block Ediotr
	 *
	 * @return bool
	 */
	static function is_block_editor() {
		// This is for the Gutenberg plugin.
		$is_gutenberg_page = function_exists( 'is_gutenberg_page' ) && is_gutenberg_page();
		// This is for WP 5 with the integrated block editor.
		$is_block_editor = false;

		if ( function_exists( 'get_current_screen' ) ) {
			$current_screen = get_current_screen();
			if ( $current_screen && method_exists( $current_screen, 'is_block_editor' ) ) {
				$is_block_editor = $current_screen->is_block_editor();
			}
		}

		return $is_gutenberg_page || $is_block_editor;
	}


	/**
	 * Add action links to the plugin list for Page Builder.
	 *
	 * @param $links
	 *
	 * @return array
	 */
	function plugin_action_links( $links ) {
		if( ! is_array( $links ) ) {
			return $links;
		}

		unset( $links['edit'] );
		$links[] = '<a href="http://siteorigin.com/threads/plugin-page-builder/">' . __( 'Support Forum', 'siteorigin-panels' ) . '</a>';

		if( SiteOrigin_Panels::display_premium_teaser() ) {
			$links[] = '<a href="' . esc_url( SiteOrigin_Panels::premium_url() ) . '" style="color: #3db634" target="_blank" rel="noopener noreferrer">' . __('Addons', 'siteorigin-panels') . '</a>';
		}

		return $links;
	}

	/**
	 * Callback to register the Page Builder Metaboxes
	 */
	function add_meta_boxes() {

		foreach ( siteorigin_panels_setting( 'post-types' ) as $type ) {
			add_meta_box(
				'so-panels-panels',
				__( 'Page Builder', 'siteorigin-panels' ),
				array( $this, 'render_meta_boxes' ),
				( string ) $type,
				'advanced',
				'high',
				array(
					// Ideally when we have panels data for a page we would set this to false and it would cause the
					// editor to fall back to classic editor, but that's not the case so we just declare it as a `__back_compat_meta_box`.
					'__back_compat_meta_box' => true,
					'__block_editor_compatible_meta_box' => false,
				)
			);
		}
	}

	/**
	 * Render a panel metabox.
	 *
	 * @param $post
	 */
	function render_meta_boxes( $post ) {
		$panels_data = $this->get_current_admin_panels_data();
		include plugin_dir_path( __FILE__ ) . '../tpl/metabox-panels.php';
	}

	/**
	 * Save the panels data
	 *
	 * @param $post_id
	 *
	 * @action save_post
	 */
	function save_post( $post_id ) {
		// Check that everything is valid with this save.
		if(
			$this->in_save_post ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			empty( $_POST['_sopanels_nonce'] ) ||
			! wp_verify_nonce( $_POST['_sopanels_nonce'], 'save' ) ||
			! current_user_can( 'edit_post', $post_id ) ||
			! isset( $_POST['panels_data'] )
		) {
			return;
		}
		$this->in_save_post = true;
		// Get post from db as it might have been changed and saved by other plugins.
		$post = get_post( $post_id );
		$old_panels_data = get_post_meta( $post_id, 'panels_data', true );
		$panels_data = json_decode( wp_unslash( $_POST['panels_data'] ), true );

		$panels_data['widgets'] = $this->process_raw_widgets(
			$panels_data['widgets'],
			! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
			false
		);

		if ( siteorigin_panels_setting( 'sidebars-emulator' ) ) {
			$sidebars_emulator = SiteOrigin_Panels_Sidebars_Emulator::single();
			$panels_data['widgets'] = $sidebars_emulator->generate_sidebar_widget_ids( $panels_data['widgets'], $post_id );
		}

		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );
		$panels_data = apply_filters( 'siteorigin_panels_data_pre_save', $panels_data, $post, $post_id );

		if ( ! empty( $panels_data['widgets'] ) || ! empty( $panels_data['grids'] ) ) {
			// Use `update_metadata` instead of `update_post_meta` to prevent saving to parent post when it's a revision, e.g. preview.
			update_metadata( 'post', $post_id, 'panels_data', map_deep( $panels_data, array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) ) );

			if( siteorigin_panels_setting( 'copy-content' ) ) {
				// Store a version of the HTML in post_content
				$post_parent_id = wp_is_post_revision( $post_id );
				$layout_id = ( ! empty( $post_parent_id ) ) ? $post_parent_id : $post_id;

				SiteOrigin_Panels_Post_Content_Filters::add_filters();
				$GLOBALS[ 'SITEORIGIN_PANELS_POST_CONTENT_RENDER' ] = true;
				$post_content = SiteOrigin_Panels::renderer()->render( $layout_id, false, $panels_data );
				$post_css = SiteOrigin_Panels::renderer()->generate_css( $layout_id, $panels_data );
				SiteOrigin_Panels_Post_Content_Filters::remove_filters();
				unset( $GLOBALS[ 'SITEORIGIN_PANELS_POST_CONTENT_RENDER' ] );

				// Update the post_content
				$post->post_content = $post_content;
				if( siteorigin_panels_setting( 'copy-styles' ) ) {
					$post->post_content .= "\n\n";
					$post->post_content .= '<style type="text/css" class="panels-style" data-panels-style-for-post="' . intval( $layout_id ) . '">';
					$post->post_content .= '@import url(' . SiteOrigin_Panels::front_css_url() . '); ';
					$post->post_content .= $post_css;
					$post->post_content .= '</style>';
				}
				wp_update_post( $post );
			}

		} else {
			// There are no widgets or rows, so delete the panels data
			delete_post_meta( $post_id, 'panels_data' );
		}

		$this->in_save_post = false;
	}

	/**
	 * Enqueue the panels admin scripts
	 *
	 * @param string $prefix
	 * @param bool $force Should we force the enqueues
	 *
	 * @action admin_print_scripts-post-new.php
	 * @action admin_print_scripts-post.php
	 * @action admin_print_scripts-appearance_page_so_panels_home_page
	 */
	function enqueue_admin_scripts( $prefix = '', $force = false ) {
		$screen = get_current_screen();
		if ( $force || self::is_admin() ) {
			// Media is required for row styles
			wp_enqueue_media();
			wp_enqueue_script(
				'so-panels-admin',
				siteorigin_panels_url( 'js/siteorigin-panels' . SITEORIGIN_PANELS_VERSION_SUFFIX . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
				array(
					'jquery',
					'jquery-ui-resizable',
					'jquery-ui-sortable',
					'jquery-ui-draggable',
					'underscore',
					'backbone',
					'plupload',
					'plupload-all'
				),
				SITEORIGIN_PANELS_VERSION,
				true
			);
			add_action( 'admin_footer', array( $this, 'js_templates' ) );

			$widgets = $this->get_widgets();
			$directory_enabled = get_user_meta( get_current_user_id(), 'so_panels_directory_enabled', true );

			// This is the widget we'll use for default text
			if( ! empty( $widgets[ 'SiteOrigin_Widget_Editor_Widget' ] ) ) $text_widget = 'SiteOrigin_Widget_Editor_Widget';
			else if( ! empty( $widgets[ 'WP_Widget_Text' ] ) ) $text_widget = 'WP_Widget_Text';
			else $text_widget = false;
			$text_widget = apply_filters( 'siteorigin_panels_text_widget_class', $text_widget );

			$user = wp_get_current_user();

			$load_on_attach = siteorigin_panels_setting( 'load-on-attach' ) || isset( $_GET['siteorigin-page-builder'] );
			wp_localize_script( 'so-panels-admin', 'panelsOptions', array(
				'user'                      => ! empty( $user ) ? $user->ID : 0,
				'ajaxurl'                   => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'panels_action', '_panelsnonce' ),
				'widgets'                   => $widgets,
				'text_widget'               => $text_widget,
				'widget_dialog_tabs'        => apply_filters( 'siteorigin_panels_widget_dialog_tabs', array(
					0 => array(
						'title'  => __( 'All Widgets', 'siteorigin-panels' ),
						'filter' => array(
							'installed' => true,
							'groups'    => ''
						)
					)
				) ),
				'row_layouts'               => apply_filters( 'siteorigin_panels_row_layouts', array() ),
				'directory_enabled'         => ! empty( $directory_enabled ),
				'copy_content'              => siteorigin_panels_setting( 'copy-content' ),
				'cache'						=> array(),
				'instant_open'              => siteorigin_panels_setting( 'instant-open-widgets' ),

				// Settings for the contextual menu
				'contextual'                => array(
					// Developers can change which widgets are displayed by default using this filter
					'default_widgets' => apply_filters( 'siteorigin_panels_contextual_default_widgets', array(
						'SiteOrigin_Widget_Editor_Widget',
						'SiteOrigin_Widget_Button_Widget',
						'SiteOrigin_Widget_Image_Widget',
						'SiteOrigin_Panels_Widgets_Layout',
					) )
				),

				// General localization messages
				'loc'                       => array(
					'missing_widget'       => array(
						'title'       => __( 'Missing Widget', 'siteorigin-panels' ),
						'description' => __( "Page Builder doesn't know about this widget.", 'siteorigin-panels' ),
					),
					'time'                 => array(
						// TRANSLATORS: Number of seconds since
						'seconds' => __( '%d seconds', 'siteorigin-panels' ),
						// TRANSLATORS: Number of minutes since
						'minutes' => __( '%d minutes', 'siteorigin-panels' ),
						// TRANSLATORS: Number of hours since
						'hours'   => __( '%d hours', 'siteorigin-panels' ),

						// TRANSLATORS: A single second since
						'second'  => __( '%d second', 'siteorigin-panels' ),
						// TRANSLATORS: A single minute since
						'minute'  => __( '%d minute', 'siteorigin-panels' ),
						// TRANSLATORS: A single hour since
						'hour'    => __( '%d hour', 'siteorigin-panels' ),

						// TRANSLATORS: Time ago - eg. "1 minute before".
						'ago'     => __( '%s before', 'siteorigin-panels' ),
						'now'     => __( 'Now', 'siteorigin-panels' ),
					),
					'history'              => array(
						// History messages
						'current'           => __( 'Current', 'siteorigin-panels' ),
						'revert'            => __( 'Original', 'siteorigin-panels' ),
						'restore'           => __( 'Version restored', 'siteorigin-panels' ),
						'back_to_editor'    => __( 'Converted to editor', 'siteorigin-panels' ),

						// Widgets
						// TRANSLATORS: Message displayed in the history when a widget is deleted
						'widget_deleted'    => __( 'Widget deleted', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget is added
						'widget_added'      => __( 'Widget added', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget is edited
						'widget_edited'     => __( 'Widget edited', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget is duplicated
						'widget_duplicated' => __( 'Widget duplicated', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a widget position is changed
						'widget_moved'      => __( 'Widget moved', 'siteorigin-panels' ),

						// Rows
						// TRANSLATORS: Message displayed in the history when a row is deleted
						'row_deleted'       => __( 'Row deleted', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is added
						'row_added'         => __( 'Row added', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is edited
						'row_edited'        => __( 'Row edited', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row position is changed
						'row_moved'         => __( 'Row moved', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is duplicated
						'row_duplicated'    => __( 'Row duplicated', 'siteorigin-panels' ),
						// TRANSLATORS: Message displayed in the history when a row is pasted
						'row_pasted'        => __( 'Row pasted', 'siteorigin-panels' ),

						// Cells
						'cell_resized'      => __( 'Cell resized', 'siteorigin-panels' ),

						// Prebuilt
						'prebuilt_loaded'   => __( 'Prebuilt layout loaded', 'siteorigin-panels' ),
					),

					// general localization
					'prebuilt_loading'     => __( 'Loading prebuilt layout', 'siteorigin-panels' ),
					'confirm_use_builder'  => __( "Would you like to copy this editor's existing content to Page Builder?", 'siteorigin-panels' ),
					'confirm_stop_builder' => __( "Would you like to clear your Page Builder content and revert to using the standard visual editor?", 'siteorigin-panels' ),
					// TRANSLATORS: This is the title for a widget called "Layout Builder"
					'layout_widget'        => __( 'Layout Builder Widget', 'siteorigin-panels' ),
					// TRANSLATORS: A standard confirmation message
					'dropdown_confirm'     => __( 'Are you sure?', 'siteorigin-panels' ),
					// TRANSLATORS: When a layout file is ready to be inserted. %s is the filename.
					'ready_to_insert'      => __( '%s is ready to insert.', 'siteorigin-panels' ),

					// Everything for the contextual menu
					'contextual'           => array(
						'add_widget_below' => __( 'Add Widget Below', 'siteorigin-panels' ),
						'add_widget_cell'  => __( 'Add Widget to Cell', 'siteorigin-panels' ),
						'search_widgets'   => __( 'Search Widgets', 'siteorigin-panels' ),

						'add_row' => __( 'Add Row', 'siteorigin-panels' ),
						'column'  => __( 'Column', 'siteorigin-panels' ),

						'cell_actions'        => __( 'Cell Actions', 'siteorigin-panels' ),
						'cell_paste_widget'   => __( 'Paste Widget', 'siteorigin-panels' ),

						'widget_actions'   => __( 'Widget Actions', 'siteorigin-panels' ),
						'widget_edit'      => __( 'Edit Widget', 'siteorigin-panels' ),
						'widget_duplicate' => __( 'Duplicate Widget', 'siteorigin-panels' ),
						'widget_delete'    => __( 'Delete Widget', 'siteorigin-panels' ),
						'widget_copy'      => __( 'Copy Widget', 'siteorigin-panels' ),
						'widget_paste'     => __( 'Paste Widget Below', 'siteorigin-panels' ),

						'row_actions'   => __( 'Row Actions', 'siteorigin-panels' ),
						'row_edit'      => __( 'Edit Row', 'siteorigin-panels' ),
						'row_duplicate' => __( 'Duplicate Row', 'siteorigin-panels' ),
						'row_delete'    => __( 'Delete Row', 'siteorigin-panels' ),
						'row_copy'      => __( 'Copy Row', 'siteorigin-panels' ),
						'row_paste'     => __( 'Paste Row', 'siteorigin-panels' ),
					),
					'draft'                => __( 'Draft', 'siteorigin-panels' ),
					'untitled'             => __( 'Untitled', 'siteorigin-panels' ),
					'row' => array(
						'add' => __( 'New Row', 'siteorigin-panels' ),
						'edit' => __( 'Row', 'siteorigin-panels' ),
					),
					'welcomeMessage' => array(
						'addingDisabled' => __( 'Hmmm... Adding layout elements is not enabled. Please check if Page Builder has been configured to allow adding elements.', 'siteorigin-panels' ),
                        'oneEnabled' => __( 'Add a {{%= items[0] %}} to get started.', 'siteorigin-panels' ),
                        'twoEnabled' => __( 'Add a {{%= items[0] %}} or {{%= items[1] %}} to get started.', 'siteorigin-panels' ),
                        'threeEnabled' => __( 'Add a {{%= items[0] %}}, {{%= items[1] %}} or {{%= items[2] %}} to get started.', 'siteorigin-panels' ),
                        'addWidgetButton' => "<a href='#' class='so-tool-button so-widget-add'>" . __( 'Widget', 'siteorigin-panels' ) . "</a>",
                        'addRowButton' => "<a href='#' class='so-tool-button so-row-add'>" . __( 'Row', 'siteorigin-panels' ) . "</a>",
                        'addPrebuiltButton' => "<a href='#' class='so-tool-button so-prebuilt-add'>" . __( 'Prebuilt Layout', 'siteorigin-panels' ) . "</a>",
                        'docsMessage' => sprintf(
                                __( 'Read our %s if you need help.', 'siteorigin-panels' ),
                            "<a href='https://siteorigin.com/page-builder/documentation/' target='_blank' rel='noopener noreferrer'>" . __( 'documentation', 'siteorigin-panels' ) . "</a>"
                        ),
					),
				),
				'plupload'                  => array(
					'max_file_size'       => wp_max_upload_size() . 'b',
					'url'                 => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'panels_action', '_panelsnonce' ),
					'flash_swf_url'       => includes_url( 'js/plupload/plupload.flash.swf' ),
					'silverlight_xap_url' => includes_url( 'js/plupload/plupload.silverlight.xap' ),
					'filter_title'        => __( 'Page Builder layouts', 'siteorigin-panels' ),
					'error_message'       => __( 'Error uploading or importing file.', 'siteorigin-panels' ),
				),
				'wpColorPickerOptions'      => apply_filters( 'siteorigin_panels_wpcolorpicker_options', array() ),
				'prebuiltDefaultScreenshot' => siteorigin_panels_url( 'css/images/prebuilt-default.png' ),
				'loadOnAttach'              => $load_on_attach ,
				'siteoriginWidgetRegex'     => str_replace( '*+', '*', get_shortcode_regex( array( 'siteorigin_widget' ) ) ),
				'forms' 				=> array(
					'loadingFailed' => __( 'Unknown error. Failed to load the form. Please check your internet connection, contact your web site administrator, or try again later.', 'siteorigin-panels' ),
				)
			) );

			$js_widgets = array();
			if ( $screen->base != 'widgets' ) {
				// Render all the widget forms. A lot of widgets use this as a chance to enqueue their scripts
				$original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null; // Make sure widgets don't change the global post.
				global $wp_widget_factory;
				foreach ( $wp_widget_factory->widgets as $widget_obj ) {
					ob_start();
					$return = $widget_obj->form( array() );
					// These are the new widgets in WP 4.8 which are largely JS based. They only enqueue their own
					// scripts on the 'widgets' screen.
					if ( $this->is_core_js_widget( $widget_obj ) && method_exists( $widget_obj, 'enqueue_admin_scripts' ) ) {
						$widget_obj->enqueue_admin_scripts();
					}
					do_action_ref_array( 'in_widget_form', array( &$widget_obj, &$return, array() ) );
					ob_end_clean();

					// Need to render templates for new WP 4.8 widgets when not on the 'widgets' screen or in the customizer.
					if ( $this->is_core_js_widget( $widget_obj ) ) {
						$js_widgets[] = $widget_obj;
					}
				}
				$GLOBALS['post'] = $original_post;
			}

			// This gives panels a chance to enqueue scripts too, without having to check the screen ID.
			if ( $screen->base != 'widgets' && $screen->base != 'customize' ) {
				foreach ( $js_widgets as $js_widget ) {
					$js_widget->render_control_template_scripts();
				}
				do_action( 'siteorigin_panel_enqueue_admin_scripts' );
				do_action( 'sidebar_admin_setup' );
			}
		}
	}

	public function enqueue_seo_compat(){
		if ( self::is_admin() ) {
			if ( defined( 'WPSEO_FILE' ) && wp_script_is( 'yoast-seo-metabox' ) ) {
				wp_enqueue_script(
					'so-panels-seo-compat',
					siteorigin_panels_url( 'js/seo-compat' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
					array('jquery', 'yoast-seo-metabox' ),
					SITEORIGIN_PANELS_VERSION,
					true
				);
			} elseif ( defined( 'RANK_MATH_VERSION' ) && wp_script_is( 'rank-math-analyzer' ) ) {
				wp_enqueue_script(
					'so-panels-seo-compat',
					siteorigin_panels_url( 'js/seo-compat' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
					array('jquery', 'rank-math-analyzer' ),
					SITEORIGIN_PANELS_VERSION,
					true
				);
			}
		}
	}

	/**
	 * Enqueue the admin panel styles
	 *
	 * @param string $prefix
	 * @param bool $force Should we force the enqueue
	 *
	 * @action admin_print_styles-post-new.php
	 * @action admin_print_styles-post.php
	 */
	function enqueue_admin_styles( $prefix = '', $force = false ) {
		if ( $force || self::is_admin() ) {
			wp_enqueue_style(
				'so-panels-admin',
				siteorigin_panels_url( 'css/admin' . SITEORIGIN_PANELS_CSS_SUFFIX . '.css' ),
				array( 'wp-color-picker' ),
				SITEORIGIN_PANELS_VERSION
			);
			do_action( 'siteorigin_panel_enqueue_admin_styles' );
		}
	}

	/**
	 * Add a help tab to pages that include a Page Builder interface.
	 *
	 * @param $prefix
	 */
	function add_help_tab( $prefix ) {
		$screen = get_current_screen();
		if (
			( $screen->base == 'post' && ( in_array( $screen->id, siteorigin_panels_setting( 'post-types' ) ) || $screen->id == '' ) )
			|| ( $screen->id == 'appearance_page_so_panels_home_page' )
		) {
			$screen->add_help_tab( array(
				'id'       => 'panels-help-tab', //unique id for the tab
				'title'    => __( 'Page Builder', 'siteorigin-panels' ), //unique visible title for the tab
				'callback' => array( $this, 'help_tab_content' )
			) );
		}
	}

	/**
	 * Display the content for the help tab.
	 */
	function help_tab_content() {
		include plugin_dir_path( __FILE__ ) . '../tpl/help.php';
	}

	/**
	 * Get the Page Builder data for the current admin page.
	 *
	 * @return array
	 */
	function get_current_admin_panels_data() {
		$screen = get_current_screen();

		// Localize the panels with the panels data
		if ( $screen->base == 'appearance_page_so_panels_home_page' ) {
			$home_page_id = get_option( 'page_on_front' );
			if ( empty( $home_page_id ) ) {
				$home_page_id = get_option( 'siteorigin_panels_home_page_id' );
			}

			$panels_data = ! empty( $home_page_id ) ? get_post_meta( $home_page_id, 'panels_data', true ) : null;

			if ( is_null( $panels_data ) ) {
				// Load the default layout
				$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

				$home_name   = siteorigin_panels_setting( 'home-page-default' ) ? siteorigin_panels_setting( 'home-page-default' ) : 'home';
				$panels_data = ! empty( $layouts[ $home_name ] ) ? $layouts[ $home_name ] : current( $layouts );
			} elseif ( empty( $panels_data ) ) {
				// The current page_on_front isn't using page builder
				return false;
			}

			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, 'home' );
		} else {
			global $post;
			if ( ! empty( $post ) ) {
				$panels_data = get_post_meta( $post->ID, 'panels_data', true );
				$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, $post->ID );
			}
		}

		if ( empty( $panels_data ) ) {
			$panels_data = array();
		}

		return $panels_data;
	}

	/**
	 * Save home page
	 */
	function save_home_page() {
		if ( ! isset( $_POST['_sopanels_home_nonce'] ) || ! wp_verify_nonce( $_POST['_sopanels_home_nonce'], 'save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['panels_data'] ) ) {
			return;
		}

		// Check that the home page ID is set and the home page exists
		$page_id = get_option( 'page_on_front' );
		if ( empty( $page_id ) ) {
			$page_id = get_option( 'siteorigin_panels_home_page_id' );
		}

		$post_content = wp_unslash( $_POST['post_content'] );

		if ( ! $page_id || get_post_meta( $page_id, 'panels_data', true ) == '' ) {
			// Lets create a new page
			$page_id = wp_insert_post( array(
				// TRANSLATORS: This is the default name given to a user's home page
				'post_title'     => __( 'Home Page', 'siteorigin-panels' ),
				'post_status'    => ! empty( $_POST['siteorigin_panels_home_enabled'] ) ? 'publish' : 'draft',
				'post_type'      => 'page',
				'post_content'   => $post_content,
				'comment_status' => 'closed',
			) );
			update_option( 'page_on_front', $page_id );
			update_option( 'siteorigin_panels_home_page_id', $page_id );

			// Action triggered when creating a new home page through the custom home page interface
			do_action( 'siteorigin_panels_create_home_page', $page_id );
		} else {
			// `wp_insert_post` does it's own sanitization, but it seems `wp_update_post` doesn't.
			$post_content = sanitize_post_field( 'post_content', $post_content, $page_id, 'db' );

			// Update the post with changed content to save revision if necessary.
			wp_update_post( array( 'ID' => $page_id, 'post_content' => $post_content ) );
		}

		$page = get_post( $page_id );

		// Save the updated page data
		$old_panels_data        = get_post_meta( $page_id, 'panels_data', true );
		$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
		$panels_data['widgets'] = $this->process_raw_widgets(
			$panels_data['widgets'],
			! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
			false
		);

		if ( siteorigin_panels_setting( 'sidebars-emulator' ) ) {
			$sidebars_emulator = SiteOrigin_Panels_Sidebars_Emulator::single();
			$panels_data['widgets'] = $sidebars_emulator->generate_sidebar_widget_ids( $panels_data['widgets'], $page_id );
		}

		$panels_data            = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );
		$panels_data            = apply_filters( 'siteorigin_panels_data_pre_save', $panels_data, $page, $page_id );

		update_post_meta( $page_id, 'panels_data', map_deep( $panels_data, array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) ) );

		$template      = get_post_meta( $page_id, '_wp_page_template', true );
		$home_template = siteorigin_panels_setting( 'home-template' );
		if ( ( $template == '' || $template == 'default' ) && ! empty( $home_template ) ) {
			// Set the home page template
			update_post_meta( $page_id, '_wp_page_template', $home_template );
		}

		if ( ! empty( $_POST['siteorigin_panels_home_enabled'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );
			update_option( 'siteorigin_panels_home_page_id', $page_id );
			wp_publish_post( $page_id );
		} else {
			// We're disabling this home page
			update_option( 'show_on_front', 'posts' );

			// Change the post status to draft
			$post = get_post( $page_id );
			if ( $post->post_status != 'draft' ) {
				global $wpdb;

				$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post->ID ) );
				clean_post_cache( $post->ID );

				$old_status        = $post->post_status;
				$post->post_status = 'draft';
				wp_transition_post_status( 'draft', $old_status, $post );

				do_action( 'edit_post', $post->ID, $post );
				do_action( "save_post_{$post->post_type}", $post->ID, $post, true );
				do_action( 'save_post', $post->ID, $post, true );
				do_action( 'wp_insert_post', $post->ID, $post, true );
			}
		}
	}

	/**
	 * After the theme is switched, change the template on the home page if the theme supports home page functionality.
	 */
	function update_home_on_theme_change() {
		$page_id = get_option( 'page_on_front' );
		if ( empty( $page_id ) ) {
			$page_id = get_option( 'siteorigin_panels_home_page_id' );
		}

		if ( siteorigin_panels_setting( 'home-page' ) && siteorigin_panels_setting( 'home-template' ) && $page_id && get_post_meta( $page_id, 'panels_data', true ) !== '' ) {
			// Lets update the home page to use the home template that this theme supports
			update_post_meta( $page_id, '_wp_page_template', siteorigin_panels_setting( 'home-template' ) );
		}
	}

	/**
	 * @return array|mixed|void
	 */
	function get_widgets() {
		global $wp_widget_factory;
		$widgets = array();
		foreach ( $wp_widget_factory->widgets as $class => $widget_obj ) {
			$class = preg_match( '/[0-9a-f]{32}/', $class ) ? get_class( $widget_obj ) : $class;
			$widgets[ $class ] = array(
				'class'       => $class,
				'title'       => ! empty( $widget_obj->name ) ? $widget_obj->name : __( 'Untitled Widget', 'siteorigin-panels' ),
				'description' => ! empty( $widget_obj->widget_options['description'] ) ? $widget_obj->widget_options['description'] : '',
				'installed'   => true,
				'groups'      => array(),
			);

			// Get Page Builder specific widget options
			if ( isset( $widget_obj->widget_options['panels_title'] ) ) {
				$widgets[ $class ]['panels_title'] = $widget_obj->widget_options['panels_title'];
			}
			if ( isset( $widget_obj->widget_options['panels_groups'] ) ) {
				$widgets[ $class ]['groups'] = $widget_obj->widget_options['panels_groups'];
			}
			if ( isset( $widget_obj->widget_options['panels_icon'] ) ) {
				$widgets[ $class ]['icon'] = $widget_obj->widget_options['panels_icon'];
			}

		}

		// Other plugins can manipulate the list of widgets. Possibly to add recommended widgets
		$widgets = apply_filters( 'siteorigin_panels_widgets', $widgets );

		// Exclude these temporarily, as they won't work until we have a reliable way to enqueue their admin form scripts.
		$to_exclude = array(
			'Jetpack_Gallery_Widget',
			'WPCOM_Widget_GooglePlus_Badge',
			'Jetpack_Widget_Social_Icons',
			'Jetpack_Twitter_Timeline_Widget'
		);

		foreach ( $to_exclude as $widget_class ) {
			if ( in_array( $widget_class, $widgets ) ) {
				unset( $widgets[ $widget_class ] );
			}
		}

		// Sort the widgets alphabetically
		uasort( $widgets, array( $this, 'widgets_sorter' ) );

		return $widgets;
	}

	/**
	 * Sorts widgets for get_widgets function by title
	 *
	 * @param $a
	 * @param $b
	 *
	 * @return int
	 */
	function widgets_sorter( $a, $b ) {
		if ( empty( $a['title'] ) ) {
			return - 1;
		}
		if ( empty( $b['title'] ) ) {
			return 1;
		}

		return $a['title'] > $b['title'] ? 1 : - 1;
	}

	/**
	 * Process raw widgets that have come from the Page Builder front end.
	 *
	 * @param array $widgets An array of widgets from panels_data.
	 * @param array $old_widgets
	 * @param bool $escape_classes Should the class names be escaped.
	 * @param bool $force
	 *
	 * @return array
	 */
	function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
		if ( empty( $widgets ) || ! is_array( $widgets ) ) {
			return array();
		}

		$old_widgets_by_id = array();
		if( ! empty( $old_widgets ) ) {
			foreach( $old_widgets as $widget ) {
				if( ! empty( $widget[ 'panels_info' ][ 'widget_id' ] ) ) {
					$old_widgets_by_id[ $widget[ 'panels_info' ][ 'widget_id' ] ] = $widget;
					unset( $old_widgets_by_id[ $widget[ 'panels_info' ][ 'widget_id' ] ][ 'panels_info' ] );
				}
			}
		}

		foreach( $widgets as $i => & $widget ) {
			if ( ! is_array( $widget ) ) {
				continue;
			}

			if ( is_array( $widget ) ) {
				$info = (array) ( is_array( $widget['panels_info'] ) ? $widget['panels_info'] : $widget['info'] );
			} else {
				$info = array();
			}
			unset( $widget['info'] );

			$info[ 'class' ] = apply_filters( 'siteorigin_panels_widget_class', $info[ 'class' ] );

			if ( ! empty( $info['raw'] ) || $force ) {
				$the_widget = SiteOrigin_Panels::get_widget_instance( $info['class'] );
				if ( ! empty( $the_widget ) &&
					 method_exists( $the_widget, 'update' ) ) {

					if(
						! empty( $old_widgets_by_id ) &&
						! empty( $widget[ 'panels_info' ][ 'widget_id' ] ) &&
						! empty( $old_widgets_by_id[ $widget[ 'panels_info' ][ 'widget_id' ] ] )
					){
						$old_widget = $old_widgets_by_id[ $widget[ 'panels_info' ][ 'widget_id' ] ];
					}
					else {
						$old_widget = $widget;
					}

					/** @var WP_Widget $the_widget */
					$the_widget = SiteOrigin_Panels::get_widget_instance( $info['class'] );
					$instance   = $the_widget->update( $widget, $old_widget );
					$instance   = apply_filters( 'widget_update_callback', $instance, $widget, $old_widget, $the_widget );

					$widget = $instance;

					unset( $info['raw'] );
				}
			}

			if( $escape_classes ) {
				// Escaping for namespaced widgets
				$info[ 'class' ] = preg_replace( '/\\\\+/', '\\\\\\\\', $info['class'] );
			}

			$widget['panels_info'] = $info;
		}

		return $widgets;
	}

	/**
	 * Add all the footer JS templates.
	 */
	function js_templates() {
		include plugin_dir_path( __FILE__ ) . '../tpl/js-templates.php';
	}

	/**
	 * Render a widget form with all the Page Builder specific fields
	 *
	 * @param string $widget_class The class of the widget
	 * @param array $instance Widget values
	 * @param bool $raw
	 * @param string $widget_number
	 *
	 * @return mixed|string The form
	 */
	function render_form( $widget_class, $instance = array(), $raw = false, $widget_number = '{$id}' ) {

		$the_widget = SiteOrigin_Panels::get_widget_instance( $widget_class );
		// This is a chance for plugins to replace missing widgets
		$the_widget = apply_filters( 'siteorigin_panels_widget_object', $the_widget, $widget_class );

		if ( empty( $the_widget ) || ! is_a( $the_widget, 'WP_Widget' ) ) {
			$widgets = $this->get_widgets();

			if ( ! empty( $widgets[ $widget_class ] ) && ! empty( $widgets[ $widget_class ]['plugin'] ) ) {
				// We know about this widget, show a form about installing it.
				$install_url = siteorigin_panels_plugin_activation_install_url( $widgets[ $widget_class ]['plugin']['slug'], $widgets[ $widget_class ]['plugin']['name'] );
				$form        =
					'<div class="panels-missing-widget-form">' .
					'<p>' .
					preg_replace(
						array(
							'/1\{ *(.*?) *\}/',
							'/2\{ *(.*?) *\}/',
						),
						array(
							'<a href="' . $install_url . '" target="_blank" rel="noopener noreferrer">$1</a>',
							'<strong>$1</strong>'
						),
						sprintf(
							__( 'You need to install 1{%1$s} to use the widget 2{%2$s}.', 'siteorigin-panels' ),
							$widgets[ $widget_class ]['plugin']['name'],
							$widget_class
						)
					) .
					'</p>' .
					'<p>' . __( "Save and reload this page to start using the widget after you've installed it.", 'siteorigin-panels' ) . '</p>' .
					'</div>';
			} else {
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
							'<a href="https://siteorigin.com/thread/" target="_blank" rel="noopener noreferrer">$1</a>'
						),
						sprintf(
							__( 'The widget 1{%1$s} is not available. Please try locate and install the missing plugin. Post on the 2{support forums} if you need help.', 'siteorigin-panels' ),
							esc_html( $widget_class )
						)
					) .
					'</p></div>';
			}

			// Allow other themes and plugins to change the missing widget form
			return apply_filters( 'siteorigin_panels_missing_widget_form', $form, $widget_class, $instance );
		}

		if ( $raw ) {
			$instance = $the_widget->update( $instance, $instance );
		}

		$the_widget->id     = 'temp';
		$the_widget->number = $widget_number;

		ob_start();
		if ( $this->is_core_js_widget( $the_widget ) ) {
			?><div class="widget-content"><?php
		}
		$return = $the_widget->form( $instance );
		do_action_ref_array( 'in_widget_form', array( &$the_widget, &$return, $instance ) );
		if ( $this->is_core_js_widget( $the_widget ) ) {
			?>
			</div>
			<input type="hidden" name="id_base" class="id_base" value="<?php echo esc_attr( $the_widget->id_base ); ?>" />
			<?php
		}
		$form = ob_get_clean();

		// Convert the widget field naming into ones that Page Builder uses
		$exp  = preg_quote( $the_widget->get_field_name( '____' ) );
		$exp  = str_replace( '____', '(.*?)', $exp );
		$form = preg_replace( '/' . $exp . '/', 'widgets[' . preg_replace( '/\$(\d)/', '\\\$$1', $widget_number ) . '][$1]', $form );

		$form = apply_filters( 'siteorigin_panels_widget_form', $form, $widget_class, $instance );

		// Add all the information fields
		return $form;
	}

	/**
	 * Checks whether a widget is considered to be a JS widget. I.e. it needs to have scripts and/or styles enqueued for
	 * it's admin form to work.
	 *
	 * Can remove the whitelist of core widgets when all widgets are following a similar pattern.
	 *
	 * @param $widget The widget to be tested.
	 *
	 * @return bool Whether or not the widget is considered a JS widget.
	 */
	function is_core_js_widget( $widget ) {
		$js_widgets = apply_filters(
			'siteorigin_panels_core_js_widgets',
			array(
				'WP_Widget_Custom_HTML',
				'WP_Widget_Media_Audio',
				'WP_Widget_Media_Gallery',
				'WP_Widget_Media_Image',
				'WP_Widget_Media_Video',
				'WP_Widget_Text',
			)
		);

		$is_js_widget = in_array( get_class( $widget ), $js_widgets ) &&
						// Need to check this for `WP_Widget_Text` which was not a JS widget before 4.8
		                method_exists( $widget, 'render_control_template_scripts' );

		return $is_js_widget;
	}

	///////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//  ADMIN AJAX ACTIONS
	///////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Get builder content based on the submitted panels_data.
	 */
	function action_builder_content() {
		header( 'content-type: text/html' );

		if ( ! wp_verify_nonce( $_GET['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'edit_post', $_POST['post_id'] ) ) {
			wp_die();
		}

		if ( empty( $_POST['post_id'] ) || empty( $_POST['panels_data'] ) ) {
			echo '';
			wp_die();
		}

		// echo the content
		$old_panels_data        = get_post_meta( $_POST['post_id'], 'panels_data', true );
		$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
		$panels_data['widgets'] = $this->process_raw_widgets(
			$panels_data['widgets'],
			! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
			false
		);
		$panels_data            = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		// Create a version of the builder data for post content
		SiteOrigin_Panels_Post_Content_Filters::add_filters();
		$GLOBALS[ 'SITEORIGIN_PANELS_POST_CONTENT_RENDER' ] = true;
		echo SiteOrigin_Panels::renderer()->render( intval( $_POST['post_id'] ), false, $panels_data );
		SiteOrigin_Panels_Post_Content_Filters::remove_filters();
		unset( $GLOBALS[ 'SITEORIGIN_PANELS_POST_CONTENT_RENDER' ] );

		wp_die();
	}

	/**
	 * Get builder content based on the submitted panels_data.
	 */
	function action_builder_content_json() {
		header( 'content-type: application/json' );
		$return = array('post_content' => '', 'preview' => '', 'sanitized_panels_data' => '');

		if ( ! wp_verify_nonce( $_GET['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'edit_post', $_POST['post_id'] ) ) {
			wp_die();
		}

		if ( empty( $_POST['post_id'] ) || empty( $_POST['panels_data'] ) ) {
			echo json_encode($return);
			wp_die();
		}

		// echo the content
		$old_panels_data        = get_post_meta( $_POST['post_id'], 'panels_data', true );
		$panels_data            = json_decode( wp_unslash( $_POST['panels_data'] ), true );
		$panels_data['widgets'] = $this->process_raw_widgets(
			$panels_data['widgets'],
			! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
			false
		);
		$panels_data            = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );
		$return['sanitized_panels_data'] = $panels_data;

		// Create a version of the builder data for post content
		SiteOrigin_Panels_Post_Content_Filters::add_filters();
		$GLOBALS[ 'SITEORIGIN_PANELS_POST_CONTENT_RENDER' ] = true;
		$return['post_content'] = SiteOrigin_Panels::renderer()->render( intval( $_POST['post_id'] ), false, $panels_data );
		SiteOrigin_Panels_Post_Content_Filters::remove_filters();
		unset( $GLOBALS[ 'SITEORIGIN_PANELS_POST_CONTENT_RENDER' ] );

		$GLOBALS[ 'SITEORIGIN_PANELS_PREVIEW_RENDER' ] = true;
		$return['preview'] = SiteOrigin_Panels::renderer()->render( intval( $_POST['post_id'] ), false, $panels_data );
		unset( $GLOBALS[ 'SITEORIGIN_PANELS_PREVIEW_RENDER' ] );

		echo json_encode( $return );

		wp_die();
	}

	/**
	 * Display a widget form with the provided data
	 */
	function action_widget_form() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die(
				__( 'The supplied nonce is invalid.', 'siteorigin-panels' ),
				__( 'Invalid nonce.', 'siteorigin-panels' ),
				403
			);
		}
		if ( empty( $_REQUEST['widget'] ) ) {
			wp_die(
				__( 'Please specify the type of widget form to be rendered.', 'siteorigin-panels' ),
				__( 'Missing widget type.', 'siteorigin-panels' ),
				400
			);
		}

		$request = array_map( 'stripslashes_deep', $_REQUEST );

		$widget_class = $request['widget'];
		$widget_class = apply_filters( 'siteorigin_panels_widget_class', $widget_class );
		$instance = ! empty( $request['instance'] ) ? json_decode( $request['instance'], true ) : array();

		$form = $this->render_form( $widget_class, $instance, $_REQUEST['raw'] == 'true' );
		$form = apply_filters( 'siteorigin_panels_ajax_widget_form', $form, $widget_class, $instance );

		echo $form;
		wp_die();
	}

	/**
	 * Preview in the live editor when there is no public view of the item
	 */
	function action_live_editor_preview() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'live-editor-preview' ) ) {
			wp_die();
		}

		include plugin_dir_path( __FILE__ ) . '../tpl/live-editor-preview.php';

		exit();
	}

	/**
	 * Preview in the block editor.
	 */
	public function layout_block_preview() {

		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'layout-block-preview' ) ) {
			wp_die();
		}

		$panels_data = json_decode( wp_unslash( $_POST['panelsData'] ), true );
		$builder_id = 'gbp' . uniqid();
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true, true );
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );
		$sowb_active = class_exists( 'SiteOrigin_Widgets_Bundle' );
		if ( $sowb_active ) {
			// We need this to get our widgets bundle to add it's styles inline for previews.
			add_filter( 'siteorigin_widgets_is_preview', '__return_true' );
		}
		$rendered_layout = SiteOrigin_Panels::renderer()->render( $builder_id, true, $panels_data, $layout_data, true );

		// Need to explicitly call `siteorigin_widget_print_styles` because Gutenberg previews don't render a full version of the front end,
		// so neither the `wp_head` nor the `wp_footer` actions are called, which usually trigger `siteorigin_widget_print_styles`.
		if ( $sowb_active ) {
			ob_start();
			siteorigin_widget_print_styles();
			$rendered_layout .= ob_get_clean();
		}

		echo $rendered_layout;
		wp_die();
	}

	public function layout_block_sanitize() {

		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'layout-block-sanitize' ) ) {
			wp_die();
		}

		$panels_data = json_decode( wp_unslash( $_POST['panelsData'] ), true );
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true, true );
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		wp_send_json( $panels_data );
	}

	/**
	 * Add a column that indicates if a column is powered by Page Builder
	 *
	 * @param $columns
	 *
	 * @return array
	 */
	function add_custom_column( $columns ){
		$index = array_search( 'comments', array_keys( $columns ) );

		if( empty( $index ) ) {
			$columns = array_merge(
				$columns,
				array( 'panels' => __( 'Page Builder', 'siteorigin-panels' ) )
			);
		}
		else {
			$columns = array_slice( $columns, 0, $index, true ) +
					   array( 'panels' => __( 'Page Builder', 'siteorigin-panels' ) ) +
					   array_slice( $columns, $index, count( $columns ) - 1, true );
		}

		return $columns;
	}

	function display_custom_column( $column, $post_id ){
		if( $column != 'panels' ) return;

		$panels_data = get_post_meta( $post_id, 'panels_data', true );
		if( ! empty( $panels_data['widgets'] ) ) {
			$widgets_count = count( $panels_data['widgets'] );
			printf( _n( '%s Widget', '%s Widgets', $widgets_count, 'siteorigin-panels' ), $widgets_count );
		}
		else {
			echo '—';
		}
	}

	public function footer_column_css(){
		if( siteorigin_panels_setting( 'admin-widget-count' ) ) {
			$screen = get_current_screen();
			$post_types = siteorigin_panels_setting( 'post-types' );

			if(
				$screen->base == 'edit' &&
				is_array( $post_types ) &&
				in_array( $screen->post_type, $post_types )
			){
				?><style type="text/css">.column-panels{ width: 10% }</style><?php
			}
		}
	}

	/**
	 * Add double slashes to strings
	 *
	 * @param $value
	 *
	 * @return string
	 */
	public static function double_slash_string( $value ){
		return is_string( $value ) ? addcslashes( $value, '\\' ) : $value;
	}

	public function get_layout_directories(){

	}

	/**
	 * Display links for various SiteOrigin addons
	 */
	public static function display_footer_premium_link(){
		$links = array(
			array(
				'text' => __('Get a lightbox addon for SiteOrigin widgets', 'siteorigin-panels'),
				'url' => SiteOrigin_Panels::premium_url('plugin/lightbox')
			),
			array(
				'text' => __('Get the row, cell and widget animations addon', 'siteorigin-panels'),
				'url' => SiteOrigin_Panels::premium_url('plugin/lightbox')
			),
			array(
				'text' => __('Get premium email support for SiteOrigin Page Builder', 'siteorigin-panels'),
				'url' => SiteOrigin_Panels::premium_url()
			),
		);
		$link = $links[array_rand($links)];

		?>
        <a href="<?php echo esc_url( $link['url'] ) ?>" target="_blank" rel='noopener noreferrer'>
			<?php echo esc_html( $link['text'] ) ?>.
        </a>
		<?php
	}

	public function admin_notices() {
		global $typenow, $pagenow;
		$is_new = $pagenow == 'post-new.php';
		$post_types = siteorigin_panels_setting( 'post-types' );
		$is_panels_type = in_array( $typenow, $post_types );
		$use_classic = siteorigin_panels_setting( 'use-classic' );
		$show_classic_admin_notice = $is_new && $is_panels_type && $use_classic;
		$show_classic_admin_notice = apply_filters( 'so_panels_show_classic_admin_notice', $show_classic_admin_notice );
		if ( $show_classic_admin_notice ) {
			$settings_url = self_admin_url( 'options-general.php?page=siteorigin_panels' );
			$notice = sprintf(
				__( 'This post type is set to use the Classic Editor by default for new posts. If you’d like to change this to the block editor, please go to <a href="%s" class="components-notice__action is-link">Page Builder Settings</a> and uncheck <strong>Use Classic Editor for new posts</strong>' ),
				$settings_url
			);
			?>
			<div id="siteorigin-panels-use-classic-notice" class="notice notice-info"><p id="use-classic-notice"><?php echo $notice ?></p></div>
			<?php
		}
	}

	/**
	 * Show Classic Editor for existing PB posts.
	 *
	 * @param $use_block_editor
	 * @param $post_type
	 *
	 * @return bool
	 */
	public function show_classic_editor_for_panels( $use_block_editor, $post_type ) {

		// For new pages.
		if ( isset( $_GET['block-editor'] ) ) {
			return $use_block_editor;
		} else if ( isset( $_GET['siteorigin-page-builder'] ) ) {
			return false;
		}

		$post_types = siteorigin_panels_setting( 'post-types' );
        global $pagenow;
		// If the `$post_type` is set to be used by Page Builder for new posts.
		$is_new_panels_type = $pagenow == 'post-new.php' && in_array( $post_type, $post_types );
		$use_classic = siteorigin_panels_setting( 'use-classic' );
		// For existing posts.
		global $post;
		if ( ! empty( $post ) ) {
			// If the post has blocks just allow `$use_block_editor` to decide.
			if ( ! has_blocks( $post ) ) {
				$panels_data = get_post_meta( $post->ID, 'panels_data', true );
				if ( ! empty( $panels_data ) || ( $use_classic && $is_new_panels_type ) ) {
					$use_block_editor = false;
				}
			}
		} else if ( $is_new_panels_type ) {
			$use_block_editor = false;
		}

		return $use_block_editor;
	}

	/**
	 * This was copied from Gutenberg and slightly modified as a quick way to allow users to create new Page Builder pages
	 * in the classic editor without requiring the classic editor plugin be installed.
	 */
	function add_panels_add_new_button() {
		global $typenow;

		if ( 'wp_block' === $typenow ) {
			?>
			<style type="text/css">
				.page-title-action {
					display: none;
				}
			</style>
			<?php
		}

		if ( ! $this->show_add_new_dropdown_for_type( $typenow ) ) {
			return;
		}

		?>
		<style type="text/css">
			.split-page-title-action {
				display: inline-block;
			}

			.split-page-title-action a,
			.split-page-title-action a:active,
			.split-page-title-action .expander:after {
				padding: 6px 10px;
				position: relative;
				top: -3px;
				text-decoration: none;
				border: 1px solid #ccc;
				border-radius: 2px 0px 0px 2px;
				background: #f7f7f7;
				text-shadow: none;
				font-weight: 600;
				font-size: 13px;
				line-height: normal; /* IE8-IE11 need this for buttons */
				color: #0073aa; /* some of these controls are button elements and don't inherit from links */
				cursor: pointer;
				outline: 0;
			}

			.split-page-title-action a:hover,
			.split-page-title-action .expander:hover:after {
				border-color: #008EC2;
				background: #00a0d2;
				color: #fff;
			}

			.split-page-title-action a:focus,
			.split-page-title-action .expander:focus:after {
				border-color: #5b9dd9;
				box-shadow: 0 0 2px rgba( 30, 140, 190, 0.8 );
			}

			.split-page-title-action .expander:after {
				content: "\f140";
				font: 400 20px/.5 dashicons;
				speak: none;
				top: 0px;
				position: relative;
				vertical-align: top;
				text-decoration: none !important;
				padding: 4px 5px 4px 4px;
				border-radius: 0px 2px 2px 0px;
				<?php echo  is_rtl() ? 'right: -1px;' : 'left: -1px;' ?>
			}

			.split-page-title-action .dropdown {
				display: none;
			}

			.split-page-title-action .dropdown.visible {
				display: block;
				position: absolute;
				margin-top: 3px;
				z-index: 1;
			}

			.split-page-title-action .dropdown.visible a {
				display: block;
				top: 0;
				margin: -1px 0;
				<?php echo is_rtl() ? 'padding-left: 9px;' : 'padding-right: 9px;' ?>
			}

			.split-page-title-action .expander {
				outline: none;
				float: right;
				margin-top: 1px;
			}

		</style>
		<script type="text/javascript">
			document.addEventListener( 'DOMContentLoaded', function() {
				var buttons = document.getElementsByClassName( 'page-title-action' ),
					button = buttons.item( 0 );

				if ( ! button ) {
					return;
				}

				var url = button.href;
				var urlHasParams = ( -1 !== url.indexOf( '?' ) );
				var panelsUrl = url + ( urlHasParams ? '&' : '?' ) + 'siteorigin-page-builder';
				var blockEditorUrl = url + ( urlHasParams ? '&' : '?' ) + 'block-editor';

				var newbutton = '<span id="split-page-title-action" class="split-page-title-action">';
				newbutton += '<a href="' + url + '">' + button.innerText + '</a>';
				newbutton += '<span class="expander" tabindex="0" role="button" aria-haspopup="true" aria-label="<?php echo esc_attr( __( 'Toggle editor selection menu', 'siteorigin-panels' ) ); ?>"></span>';
				newbutton += '<span class="dropdown"><a href="' + panelsUrl + '"><?php echo esc_html( __( 'SiteOrigin Page Builder', 'siteorigin-panels' ) ); ?></a>';
				newbutton += '<a href="' + blockEditorUrl + '"><?php echo esc_html( __( 'Block Editor', 'siteorigin-panels' ) ); ?></a></span></span><span class="page-title-action" style="display:none;"></span>';

				button.insertAdjacentHTML( 'afterend', newbutton );
				button.parentNode.removeChild( button );

				var expander = document.getElementById( 'split-page-title-action' ).getElementsByClassName( 'expander' ).item( 0 );
				var dropdown = expander.parentNode.querySelector( '.dropdown' );
				function toggleDropdown() {
					dropdown.classList.toggle( 'visible' );
				}
				expander.addEventListener( 'click', function( e ) {
					e.preventDefault();
					toggleDropdown();
				} );
				expander.addEventListener( 'keydown', function( e ) {
					if ( 13 === e.which || 32 === e.which ) {
						e.preventDefault();
						toggleDropdown();
					}
				} );
			} );
		</script>
		<?php
	}

	private function show_add_new_dropdown_for_type( $post_type ) {

		$show = in_array( $post_type, siteorigin_panels_setting( 'post-types' ) );

		// WooCommerce product type doesn't support block editor...
		$show = $show && ! ( class_exists( 'WooCommerce' ) && $post_type == 'product' );

		if ( class_exists( 'SiteOrigin_Premium_Plugin_Cpt_Builder' ) ) {
			$show = $show && $post_type != SiteOrigin_Premium_Plugin_Cpt_Builder::POST_TYPE;
			$cpt_builder = SiteOrigin_Premium_Plugin_Cpt_Builder::single();
			$so_custom_types = $cpt_builder->get_post_types();
			$show = $show && ! isset( $so_custom_types[ $post_type ] );
		}

		return apply_filters( 'so_panels_show_add_new_dropdown_for_type', $show, $post_type );
	}

	public function add_panels_post_state( $post_states, $post ) {
		$panels_data = get_post_meta( $post->ID, 'panels_data', true );

		if ( ! empty( $panels_data ) ) {
			$post_states[] = __( 'SiteOrigin Page Builder', 'siteorigin-panels' );
		}

		return $post_states;
	}
}
