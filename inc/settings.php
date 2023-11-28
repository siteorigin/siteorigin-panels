<?php

/**
 * Class to handle Page Builder settings.
 *
 * Class SiteOrigin_Panels_Settings
 */
class SiteOrigin_Panels_Settings {
	private $settings;
	private $fields;
	private $settings_saved;

	public function __construct() {
		$this->settings = array();
		$this->fields = array();
		$this->settings_saved = false;

		// Admin actions
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'after_setup_theme', array( $this, 'clear_cache' ), 100 );

		// Default filters for fields and defaults
		add_filter( 'siteorigin_panels_settings_defaults', array( $this, 'settings_defaults' ) );
		add_filter( 'siteorigin_panels_default_add_widget_class', array( $this, 'add_widget_class' ) );
		add_filter( 'siteorigin_panels_settings_fields', array( $this, 'settings_fields' ) );
	}

	/**
	 * @return SiteOrigin_Panels_Settings
	 */
	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function clear_cache() {
		$this->settings = array();
	}

	/**
	 * Get a settings value.
	 *
	 * @param string $key
	 *
	 * @return array|bool|mixed|void|null
	 */
	public function get( $key = '' ) {
		if ( empty( $this->settings ) ) {
			// Get the settings, attempt to fetch new settings first.
			$current_settings = get_option( 'siteorigin_panels_settings', false );

			if ( $current_settings === false ) {
				// We can't find the settings, so try access old settings.
				$current_settings = get_option( 'siteorigin_panels_display', array() );
				$post_types = get_option( 'siteorigin_panels_post_types' );

				if ( ! empty( $post_types ) ) {
					$current_settings['post-types'] = $post_types;
				}

				// Store the old settings in the new field.
				update_option( 'siteorigin_panels_settings', $current_settings );
			}

			// Get the settings provided by the theme.
			$theme_settings = get_theme_support( 'siteorigin-panels' );

			if ( ! empty( $theme_settings ) ) {
				$theme_settings = $theme_settings[0];
			} else {
				$theme_settings = array();
			}

			$this->settings = wp_parse_args( $theme_settings, apply_filters( 'siteorigin_panels_settings_defaults', array() ) );
			$this->settings = wp_parse_args( $current_settings, $this->settings );

			// Filter these settings
			$this->settings = apply_filters( 'siteorigin_panels_settings', $this->settings );
		}

		if ( ! empty( $key ) ) {
			return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null;
		}

		return $this->settings;
	}

	/**
	 * Set a settings value
	 */
	public function set( $key, $value ) {
		$current_settings = get_option( 'siteorigin_panels_settings', array() );
		$current_settings[ $key ] = $value;
		update_option( 'siteorigin_panels_settings', $current_settings );
	}

	/**
	 * Add default settings for the Page Builder settings.
	 *
	 * @return mixed
	 */
	public function settings_defaults( $defaults ) {
		$defaults['home-page'] = false;
		$defaults['home-page-default'] = false;
		$defaults['home-template']     = 'home-panels.php';
		$defaults['affiliate-id']      = apply_filters( 'siteorigin_panels_affiliate_id', false );
		$defaults['display-teaser']    = true;
		$defaults['display-learn']     = true;
		$defaults['load-on-attach']    = false;
		$defaults['use-classic']       = true;

		/**
		 * Certain settings have different defaults depending on if this is a new
		 * install, or not.
		 *
		 * This is done here rather than using `siteorigin_panels_version_changed` as
		 * that hook is triggered after the settings are loaded.
		 */
		$so_settings = get_option( 'siteorigin_panels_settings' );

		if ( empty( $so_settings ) ) {
			// New install.
			$parallax_type = 'modern';
			$live_editor_close_after = true;
			$mobile_cell_margin = 30;
		} else {
			$live_editor_close_after = false;
			// Parallax Type.
			if ( isset( $so_settings['parallax-type'] ) ) {
				// If parallax-type already exists, use the existing value to prevent a potential override.
				$parallax_type = $so_settings['parallax-type'];
			} elseif ( isset( $so_settings['parallax-delay'] ) ) {
				// User is upgrading.
				$parallax_type = 'legacy';
			} else {
				// If all else fails, fallback to modern.
				$parallax_type = 'modern';
			}
			$mobile_cell_margin = isset( $so_settings['margin-bottom'] ) ? $so_settings['margin-bottom'] : 30;
		}

		// General fields.
		$defaults['post-types']                         = array( 'page', 'post' );
		$defaults['live-editor-quick-link']             = true;
		$defaults['live-editor-quick-link-close-after'] = $live_editor_close_after;
		$defaults['admin-post-state']                   = true;
		$defaults['admin-widget-count']                 = false;
		$defaults['parallax-type']                      = $parallax_type;
		$defaults['parallax-mobile']                    = false;
		$defaults['parallax-motion']                    = ''; // legacy parallax
		$defaults['parallax-delay']                     = 0.4;
		$defaults['parallax-scale']                     = 1.2;
		$defaults['sidebars-emulator']                  = true;
		$defaults['layout-block-default-mode']          = 'preview';
		$defaults['layout-block-quick-add']             = true;

		// Widgets fields.
		$defaults['title-html']           = '<h3 class="widget-title">{{title}}</h3>';
		$defaults['add-widget-class']     = apply_filters( 'siteorigin_panels_default_add_widget_class', true );
		$defaults['bundled-widgets']      = get_option( 'siteorigin_panels_is_using_bundled', false );
		$defaults['recommended-widgets']  = true;
		$defaults['instant-open-widgets'] = true;

		// Layout fields.
		$defaults['responsive']                 = true;
		$defaults['tablet-layout']               = false;
		$defaults['legacy-layout']               = 'auto';
		$defaults['tablet-width']                = 1024;
		$defaults['mobile-width']                = 780;
		$defaults['margin-bottom']               = 30;
		$defaults['row-mobile-margin-bottom']    = '';
		$defaults['mobile-cell-margin']          = $mobile_cell_margin;
		$defaults['widget-mobile-margin-bottom'] = '';
		$defaults['margin-bottom-last-row']      = false;
		$defaults['margin-sides']                = 30;
		$defaults['full-width-container']        = 'body';
		$defaults['output-css-header']           = 'auto';
		$defaults['inline-styles']               = false;

		// Content fields.
		$defaults['copy-content'] = true;
		$defaults['copy-styles'] = false;

		return $defaults;
	}

	/**
	 * Set the option on whether to add widget classes for known themes.
	 *
	 * @return bool
	 */
	public function add_widget_class( $add_class ) {
		switch ( get_option( 'stylesheet' ) ) {
			case 'twentysixteen':
				$add_class = false;
				break;
		}

		return $add_class;
	}

	/**
	 * Enqueue admin scripts
	 */
	public function admin_scripts( $prefix ) {
		if ( $prefix != 'settings_page_siteorigin_panels' ) {
			return;
		}
		wp_enqueue_style(
			'siteorigin-panels-settings',
			siteorigin_panels_url( 'settings/admin-settings.css' ),
			array(),
			SITEORIGIN_PANELS_VERSION
		);
		wp_enqueue_script(
			'siteorigin-panels-settings',
			siteorigin_panels_url( 'settings/admin-settings' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
			array( 'fitvids' ),
			SITEORIGIN_PANELS_VERSION
		);
	}

	/**
	 * Add the Page Builder settings page
	 */
	public function add_settings_page() {
		$page = add_options_page( __( 'SiteOrigin Page Builder', 'siteorigin-panels' ), __( 'Page Builder', 'siteorigin-panels' ), 'manage_options', 'siteorigin_panels', array(
			$this,
			'display_settings_page',
		) );
		add_action( 'load-' . $page, array( $this, 'add_help_tab' ) );
		add_action( 'load-' . $page, array( $this, 'save_settings' ) );
	}

	/**
	 * Display the Page Builder settings page.
	 */
	public function display_settings_page() {
		$settings_fields = $this->fields = apply_filters( 'siteorigin_panels_settings_fields', array() );
		include plugin_dir_path( __FILE__ ) . '../settings/tpl/settings.php';
	}

	/**
	 * Add a settings help tab.
	 */
	public function add_help_tab() {
		$screen = get_current_screen();
		ob_start();
		include plugin_dir_path( __FILE__ ) . '../settings/tpl/help.php';
		$content = ob_get_clean();

		$screen->add_help_tab( array(
			'id'      => 'panels-help-tab',
			'title'   => __( 'Page Builder Settings', 'siteorigin-panels' ),
			'content' => $content,
		) );
	}

	/**
	 * Add the default Page Builder settings.
	 *
	 * @return mixed
	 */
	public function settings_fields( $fields ) {
		// General settings.

		$fields['general'] = array(
			'title'  => __( 'General', 'siteorigin-panels' ),
			'fields' => array(),
		);

		$fields['general']['fields']['post-types'] = array(
			'type'        => 'select_multi',
			'label'       => __( 'Post Types', 'siteorigin-panels' ),
			'options'     => $this->get_post_types(),
			'description' => __( 'The post types on which to use Page Builder.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['use-classic'] = array(
			'type' => 'checkbox',
			'label' => __( 'Use Classic Editor for New Posts', 'siteorigin-panels' ),
			'description' => __( 'New posts of the above Post Types will be created using the Classic Editor.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['live-editor-quick-link'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Live Editor Toolbar Link', 'siteorigin-panels' ),
			'description' => __( 'Display a Live Editor link in the toolbar when viewing site.', 'siteorigin-panels' ),
		);
		$fields['general']['fields']['live-editor-quick-link-close-after'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Live Editor Toolbar Link: Close After Editing', 'siteorigin-panels' ),
			'description' => __( 'When accessing the Live Editor via the toolbar link, return to the site after saving.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['admin-post-state'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Display Post State', 'siteorigin-panels' ),
			'description' => sprintf(
				__( 'Display a %sSiteOrigin Page Builder%s post state in the admin lists of posts/pages to indicate Page Builder is active.', 'siteorigin-panels' ),
				'<strong>',
				'</strong>'
			),
		);

		$fields['general']['fields']['admin-widget-count'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Display Widget Count', 'siteorigin-panels' ),
			'description' => __( "Display a widget count in the admin lists of posts/pages where you're using Page Builder.", 'siteorigin-panels' ),
		);

		$fields['general']['fields']['parallax-type'] = array(
			'type'        => 'select',
			'label'       => __( 'Parallax Type', 'siteorigin-panels' ),
			'options'     => array(
				'modern' => __( 'Modern', 'siteorigin-panels' ),
				'legacy' => __( 'Legacy', 'siteorigin-panels' ),
			),
			'description' => __( 'Modern is recommended as it can use smaller images and offers better performance.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['parallax-mobile'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Disable Parallax On Mobile', 'siteorigin-panels' ),
			'description' => __( 'Disable row/widget background parallax when the browser is smaller than the mobile width.', 'siteorigin-panels' ),
		);

		// Legacy Parallax.
		$fields['general']['fields']['parallax-motion'] = array(
			'type'        => 'float',
			'label'       => __( 'Limit Parallax Motion', 'siteorigin-panels' ),
			'description' => __( 'How many pixels of scrolling results in a single pixel of parallax motion. 0 means automatic. Lower values give a more noticeable effect.', 'siteorigin-panels' ),
		);

		// Modern Parallax.
		$fields['general']['fields']['parallax-delay'] = array(
			'type'        => 'float',
			'label'       => __( 'Parallax Delay', 'siteorigin-panels' ),
			'description' => __( 'The delay before the parallax effect finishes after the user stops scrolling.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['parallax-scale'] = array(
			'type'        => 'float',
			'label'       => __( 'Parallax Scale', 'siteorigin-panels' ),
			'description' => __( 'How much the image is scaled. The higher the scale is set, the more visible the parallax effect will be. Increasing the scale will result in a loss of image quality.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['sidebars-emulator'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Sidebars Emulator', 'siteorigin-panels' ),
			'description' => __( 'Page Builder will create an emulated sidebar, that contains all widgets in the page.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['display-teaser'] = array(
			'type' => 'checkbox',
			'label' => __( 'Upgrade Teaser', 'siteorigin-panels' ),
			'description' => sprintf(
				__( 'Display the %sSiteOrigin Premium%s upgrade teaser in the Page Builder toolbar.', 'siteorigin-panels' ),
				'<a href="https://siteorigin.com/downloads/premium/" target="_blank" rel="noopener noreferrer">',
				'</a>'
			),
		);

		$fields['general']['fields']['load-on-attach'] = array(
			'type' => 'checkbox',
			'label' => __( 'Default to Page Builder Interface', 'siteorigin-panels' ),
			'description' => sprintf(
				__( 'New Classic Editor posts/pages that you create will start with the Page Builder loaded. The %s"Use Classic Editor for New Posts"%s setting must be enabled.', 'siteorigin-panels' ),
				'<strong>',
				'</strong>'
			),
		);

		$fields['general']['fields']['layout-block-default-mode'] = array(
			'label' => __( 'Layout Block Default Mode', 'siteorigin-panels' ),
			'type'        => 'select',
			'options'     => array(
				'edit' => __( 'Edit', 'siteorigin-panels' ),
				'preview' => __( 'Preview', 'siteorigin-panels' ),
			),
			'description' => __( 'Whether to display SiteOrigin Layout Blocks in edit mode or preview mode in the Block Editor.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['layout-block-quick-add'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Block Editor Layout Block Quick Add Button', 'siteorigin-panels' ),
			'description' => __( 'Display the Add SiteOrigin Layout Block quick add button in the Block Editor.', 'siteorigin-panels' ),
		);

		$fields['general']['fields']['installer'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Installer', 'siteorigin-panels' ),
			'description' => __( 'Display the SiteOrigin Installer admin menu item.', 'siteorigin-panels' ),
		);

		// Widgets settings.

		$fields['widgets'] = array(
			'title'  => __( 'Widgets', 'siteorigin-panels' ),
			'fields' => array(),
		);

		$fields['widgets']['fields']['title-html'] = array(
			'type'        => 'html',
			'label'       => __( 'Widget Title HTML', 'siteorigin-panels' ),
			'description' => __( 'The HTML used for widget titles. {{title}} is replaced with the widget title.', 'siteorigin-panels' ),
		);

		$fields['widgets']['fields']['add-widget-class'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Add Widget Class', 'siteorigin-panels' ),
			'description' => __( 'Add the widget class to Page Builder widgets. Disable if theme widget styles are negatively impacting widgets in Page Builder.', 'siteorigin-panels' ),
		);

		$fields['widgets']['fields']['bundled-widgets'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Legacy Bundled Widgets', 'siteorigin-panels' ),
			'description' => __( 'Load legacy widgets from Page Builder 1.', 'siteorigin-panels' ),
		);

		$fields['widgets']['fields']['recommended-widgets'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Recommended Widgets', 'siteorigin-panels' ),
			'description' => __( 'Display recommend widgets in the Page Builder Add Widget dialog.', 'siteorigin-panels' ),
		);

		$fields['widgets']['fields']['instant-open-widgets'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Instant Open Widgets', 'siteorigin-panels' ),
			'description' => __( 'Open a widget form as soon as it\'s added to a page.', 'siteorigin-panels' ),
		);

		// Layout settings.

		$fields['layout'] = array(
			'title'  => __( 'Layout', 'siteorigin-panels' ),
			'fields' => array(),
		);

		$fields['layout']['fields']['responsive'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Responsive Layout', 'siteorigin-panels' ),
			'description' => __( 'Collapse widgets, rows, and columns on mobile devices.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['tablet-layout'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Use Tablet Layout', 'siteorigin-panels' ),
			'description' => __( 'Collapses columns differently on tablet devices.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['legacy-layout'] = array(
			'type'        => 'select',
			'options'     => array(
				'auto' => __( 'Detect older browsers', 'siteorigin-panels' ),
				'never' => __( 'Never', 'siteorigin-panels' ),
				'always' => __( 'Always', 'siteorigin-panels' ),
			),
			'label'       => __( 'Use Legacy Layout Engine', 'siteorigin-panels' ),
			'description' => __( 'For compatibility, the Legacy Layout Engine switches from Flexbox to float when older browsers are detected.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['tablet-width'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Tablet Width', 'siteorigin-panels' ),
			'description' => __( 'Device width, in pixels, to collapse into a tablet view.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['mobile-width'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Mobile Width', 'siteorigin-panels' ),
			'description' => __( 'Device width, in pixels, to collapse into a mobile view.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['margin-bottom'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Row/Widget Bottom Margin', 'siteorigin-panels' ),
			'description' => __( 'Default margin below rows and widgets.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['row-mobile-margin-bottom'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Mobile Row Bottom Margin', 'siteorigin-panels' ),
			'description' => __( 'The default margin below rows on mobile.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['margin-bottom-last-row'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Last Row With Margin', 'siteorigin-panels' ),
			'description' => __( 'Allow margin below the last row.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['mobile-cell-margin'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Mobile Column Bottom Margin', 'siteorigin-panels' ),
			'description' => __( 'The default vertical space between columns in a collapsed mobile row.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['widget-mobile-margin-bottom'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Mobile Widget Bottom Margin', 'siteorigin-panels' ),
			'description' => __( 'The default widget bottom margin on mobile.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['margin-sides'] = array(
			'type'        => 'number',
			'unit'        => 'px',
			'label'       => __( 'Row Gutter', 'siteorigin-panels' ),
			'description' => __( 'Default spacing between columns in each row.', 'siteorigin-panels' ),
			'keywords'    => 'margin',
		);

		$fields['layout']['fields']['full-width-container'] = array(
			'type'        => 'text',
			'label'       => __( 'Full Width Container', 'siteorigin-panels' ),
			'description' => __( 'The container used for the full width layout.', 'siteorigin-panels' ),
			'keywords'    => 'full width, container, stretch',
		);

		$fields['layout']['fields']['output-css-header'] = array(
			'type'        => 'select',
			'options'     => array(
				'auto'   => __( 'Automatic', 'siteorigin-panels' ),
				'header' => __( 'Header', 'siteorigin-panels' ),
				'footer' => __( 'Footer', 'siteorigin-panels' ),
			),
			'label'       => __( 'Page Builder Layout CSS Output Location', 'siteorigin-panels' ),
			'description' => __( 'This setting is only applicable in the Classic Editor.', 'siteorigin-panels' ),
		);

		$fields['layout']['fields']['inline-styles'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Inline Styles', 'siteorigin-panels' ),
			'description' => __( 'Output margin, border, and padding styles inline to reduce potential Cumulative Layout Shift.', 'siteorigin-panels' ),
		);

		// Content settings.

		$fields['content'] = array(
			'title'  => __( 'Content', 'siteorigin-panels' ),
			'fields' => array(),
		);

		$fields['content']['fields']['copy-content'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Copy Content', 'siteorigin-panels' ),
			'description' => __( 'Copy content from Page Builder to post content.', 'siteorigin-panels' ),
		);

		$fields['content']['fields']['copy-styles'] = array(
			'type'        => 'checkbox',
			'label'       => __( 'Copy Styles', 'siteorigin-panels' ),
			'description' => __( 'Include styles into your Post Content. This keeps page layouts, even when Page Builder is deactivated.', 'siteorigin-panels' ),
		);

		return $fields;
	}

	/**
	 * Display a settings field.
	 */
	public function display_field( $field_id, $field ) {
		$value = $field_id != 'installer' ? siteorigin_panels_setting( $field_id ) : (bool) get_option( 'siteorigin_installer', true );

		$field_name = 'panels_setting[' . $field_id . ']';

		switch ( $field['type'] ) {
			case 'text':
			case 'float':
				?><input name="<?php echo esc_attr( $field_name ); ?>"
					class="panels-setting-<?php echo esc_attr( $field['type'] ); ?>" type="text"
					value="<?php echo esc_attr( $value ); ?>" /> <?php
				break;

			case 'password':
				?><input name="<?php echo esc_attr( $field_name ); ?>"
					class="panels-setting-<?php echo esc_attr( $field['type'] ); ?>" type="password"
					value="<?php echo esc_attr( $value ); ?>" /> <?php
				break;

			case 'number':
				?>
				<input name="<?php echo esc_attr( $field_name ); ?>" type="number"
					class="panels-setting-<?php echo esc_attr( $field['type'] ); ?>"
					value="<?php echo esc_attr( $value ); ?>"/>
				<?php
				if ( ! empty( $field['unit'] ) ) {
					echo esc_html( $field['unit'] );
				}
				break;

			case 'html':
				?><textarea name="<?php echo esc_attr( $field_name ); ?>"
					class="panels-setting-<?php echo esc_attr( $field['type'] ); ?> widefat"
					rows="<?php echo ! empty( $field['rows'] ) ? (int) $field['rows'] : 2; ?>"><?php echo esc_textarea( $value ); ?></textarea> <?php
				break;

			case 'checkbox':
				?>
				<label class="widefat">
					<input name="<?php echo esc_attr( $field_name ); ?>"
						type="checkbox" <?php checked( ! empty( $value ) ); ?> />
					<?php echo ! empty( $field['checkbox_text'] ) ? esc_html( $field['checkbox_text'] ) : __( 'Enabled', 'siteorigin-panels' ); ?>
				</label>
				<?php
				break;

			case 'select':
				?>
				<select name="<?php echo esc_attr( $field_name ); ?>">
					<?php foreach ( $field['options'] as $option_id => $option ) { ?>
						<option
							value="<?php echo esc_attr( $option_id ); ?>" <?php selected( $option_id, $value ); ?>><?php echo esc_html( $option ); ?></option>
					<?php } ?>
				</select>
				<?php
				break;

			case 'select_multi':
				foreach ( $field['options'] as $option_id => $option ) {
					?>
					<label class="widefat">
						<input name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $option_id ); ?>]"
							type="checkbox" <?php checked( in_array( $option_id, $value ) ); ?> />
						<?php echo esc_html( $option ); ?>
					</label>
					<?php
				}

				break;
		}
	}

	/**
	 * Save the Page Builder settings.
	 */
	public function save_settings() {
		$screen = get_current_screen();

		if ( $screen->base != 'settings_page_siteorigin_panels' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'panels-settings' ) ) {
			return;
		}

		if ( empty( $_POST['panels_setting'] ) ) {
			return;
		}

		$values = array();
		$post = stripslashes_deep( $_POST['panels_setting'] );
		$settings_fields = $this->fields = apply_filters( 'siteorigin_panels_settings_fields', array() );

		if ( empty( $settings_fields ) ) {
			return;
		}

		foreach ( $settings_fields as $section_id => $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}

			foreach ( $section['fields'] as $field_id => $field ) {
				// Sanitize the fields
				switch ( $field['type'] ) {
					case 'text':
						$values[ $field_id ] = ! empty( $post[ $field_id ] ) ? sanitize_text_field( $post[ $field_id ] ) : '';
						break;

					case 'number':
						if ( $post[ $field_id ] != '' ) {
							$values[ $field_id ] = ! empty( $post[ $field_id ] ) ? (int) $post[ $field_id ] : 0;
						} else {
							$values[ $field_id ] = '';
						}
						break;

					case 'float':
						if ( $post[ $field_id ] != '' ) {
							$values[ $field_id ] = ! empty( $post[ $field_id ] ) ? (float) $post[ $field_id ] : 0;
						} else {
							$values[ $field_id ] = '';
						}
						break;

					case 'html':
						$values[ $field_id ] = ! empty( $post[ $field_id ] ) ? $post[ $field_id ] : '';
						$values[ $field_id ] = wp_kses_post( $values[ $field_id ] );
						$values[ $field_id ] = force_balance_tags( $values[ $field_id ] );
						break;

					case 'checkbox':
						$values[ $field_id ] = ! empty( $post[ $field_id ] );
						break;

					case 'select':
						$values[ $field_id ] = ! empty( $post[ $field_id ] ) ? $post[ $field_id ] : '';

						if ( ! in_array( $values[ $field_id ], array_keys( $field['options'] ) ) ) {
							unset( $values[ $field_id ] );
						}
						break;

					case 'select_multi':
						$values[ $field_id ] = array();
						$multi_values = array();

						foreach ( $field['options'] as $option_id => $option ) {
							$multi_values[ $option_id ] = ! empty( $post[ $field_id ][ $option_id ] );
						}

						foreach ( $multi_values as $k => $v ) {
							if ( $v ) {
								$values[ $field_id ][] = $k;
							}
						}

						break;
				}
			}
		}

		// Don't let mobile width go below 320.
		$values[ 'mobile-width' ] = max( $values[ 'mobile-width' ], 320 );

		if ( isset( $values['installer'] ) ) {
			update_option( 'siteorigin_installer', (string) rest_sanitize_boolean( $values['installer'] ) );
			unset( $values['installer'] );
		}

		// Save the values to the database.
		update_option( 'siteorigin_panels_settings', $values );
		do_action( 'siteorigin_panels_save_settings', $values );
		$this->settings = wp_parse_args( $values, $this->settings );
		$this->settings_saved = true;
	}

	/**
	 * Get a post type array.
	 *
	 * @return array
	 */
	public function get_post_types() {
		$post_types = get_post_types( array( '_builtin' => false ) );

		$types = array(
			'page' => 'page',
			'post' => 'post',
		);

		// Don't use `array_merge` here as it will break things if a post type has a numeric slug.
		foreach ( $post_types as $key => $value ) {
			$types[ $key ] = $value;
		}

		// These are post types we know we don't want to show Page Builder on.
		unset( $types['ml-slider'] );
		unset( $types['shop_coupon'] );
		unset( $types['shop_order'] );

		foreach ( $types as $type_id => $type ) {
			$type_object = get_post_type_object( $type_id );

			if ( ! $type_object->show_ui ) {
				unset( $types[ $type_id ] );
				continue;
			}

			$types[ $type_id ] = $type_object->label;
		}

		return apply_filters( 'siteorigin_panels_settings_enabled_post_types', $types );
	}
}
