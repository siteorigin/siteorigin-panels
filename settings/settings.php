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

	function __construct(){
		$this->settings = array();
		$this->fields = array();
		$this->settings_saved = false;

		// Admin actions
		add_action( 'admin_enqueue_scripts', array($this, 'admin_scripts') );
		add_action( 'admin_menu', array($this, 'add_settings_page') );
		add_action( 'after_setup_theme', array($this, 'clear_cache'), 100 );

		// Default filters for fields and defaults
		add_filter( 'siteorigin_panels_settings_defaults', array($this, 'settings_defaults') );
		add_filter( 'siteorigin_panels_default_add_widget_class', array($this, 'add_widget_class') );
		add_filter( 'siteorigin_panels_settings_fields', array($this, 'settings_fields') );
	}

	/**
	 * @return SiteOrigin_Panels_Settings
	 */
	static function single(){
		static $single = false;
		if( empty($single) ) {
			$single = new SiteOrigin_Panels_Settings();
		}

		return $single;
	}

	function clear_cache(){
		$this->settings = array();
	}

	/**
	 * Get a settings value
	 *
	 * @param string $key
	 *
	 * @return array|bool|mixed|null|void
	 */
	function get($key = ''){

		if( empty($this->settings) ){

			// Get the settings, attempt to fetch new settings first.
			$current_settings = get_option( 'siteorigin_panels_settings', false );

			if( $current_settings === false ) {
				// We can't find the settings, so try access old settings
				$current_settings = get_option( 'siteorigin_panels_display', array() );
				$post_types = get_option( 'siteorigin_panels_post_types' );
				if( !empty($post_types) ) $current_settings['post-types'] = $post_types;

				// Store the old settings in the new field
				update_option('siteorigin_panels_settings', $current_settings);
			}

			// Get the settings provided by the theme
			$theme_settings = get_theme_support('siteorigin-panels');
			if( !empty($theme_settings) ) $theme_settings = $theme_settings[0];
			else $theme_settings = array();

			$this->settings = wp_parse_args( $theme_settings, apply_filters( 'siteorigin_panels_settings_defaults', array() ) );
			$this->settings = wp_parse_args( $current_settings, $this->settings);

			// Filter these settings
			$this->settings = apply_filters('siteorigin_panels_settings', $this->settings);
		}

		if( !empty( $key ) ) return isset( $this->settings[$key] ) ? $this->settings[$key] : null;
		return $this->settings;
	}

	/**
	 * Set a settings value
	 *
	 * @param $key
	 * @param $value
	 */
	function set($key, $value){
		$current_settings = get_option( 'siteorigin_panels_settings', array() );
		$current_settings[$key] = $value;
		update_option( 'siteorigin_panels_settings', $current_settings );
	}

	/**
	 * Add default settings for the Page Builder settings.
	 *
	 * @param $defaults
	 *
	 * @return mixed
	 */
	function settings_defaults($defaults) {
		$defaults['home-page'] = false;
		$defaults['home-page-default'] = false;
		$defaults['home-template'] = 'home-panels.php';
		$defaults['affiliate-id'] = apply_filters( 'siteorigin_panels_affiliate_id', false );
		$defaults['display-teaser'] = true;
		$defaults['display-learn'] = true;

		// The general fields
		$defaults['post-types'] = array('page', 'post');
		$defaults['live-editor-quick-link'] = true;
		$defaults['parallax-motion'] = '';
		$defaults['sidebars-emulator'] = true;

		// Widgets fields
		$defaults['title-html'] = '<h3 class="widget-title">{{title}}</h3>';
		$defaults['add-widget-class'] = apply_filters( 'siteorigin_panels_default_add_widget_class', true );
		$defaults['bundled-widgets'] = get_option( 'siteorigin_panels_is_using_bundled', false );
		$defaults['recommended-widgets'] = true;

		// The layout fields
		$defaults['responsive'] = true;
		$defaults['tablet-layout'] = false;
		$defaults['tablet-width'] = 1024;
		$defaults['mobile-width'] = 780;
		$defaults['margin-bottom'] = 30;
		$defaults['margin-bottom-last-row'] = false;
		$defaults['margin-sides'] = 30;
		$defaults['full-width-container'] = 'body';

		// Content fields
		$defaults['copy-content'] = true;

		return $defaults;
	}

	/**
	 * Set the option on whether to add widget classes for known themes
	 *
	 * @param $add_class
	 *
	 * @return bool
	 */
	function add_widget_class( $add_class ){

		switch( get_option('stylesheet') ) {
			case 'twentysixteen';
				$add_class = false;
				break;
		}


		return $add_class;
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param $prefix
	 */
	function admin_scripts($prefix){
		if( $prefix != 'settings_page_siteorigin_panels' ) return;
		wp_enqueue_style( 'siteorigin-panels-settings', plugin_dir_url(__FILE__) . 'admin-settings.css', array(), SITEORIGIN_PANELS_VERSION );
		wp_enqueue_script( 'siteorigin-panels-settings', plugin_dir_url(__FILE__) . 'admin-settings' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', array(), SITEORIGIN_PANELS_VERSION );
	}

	/**
	 * Add the Page Builder settings page
	 */
	function add_settings_page(){
		$page = add_options_page( __('SiteOrigin Page Builder', 'siteorigin-panels'), __('Page Builder', 'siteorigin-panels'), 'manage_options', 'siteorigin_panels', array($this, 'display_settings_page') );
		add_action('load-' . $page, array( $this, 'add_help_tab'  ));
		add_action('load-' . $page, array( $this, 'save_settings'  ));
	}

	/**
	 * Display the Page Builder settings page
	 */
	function display_settings_page(){
		$settings_fields = $this->fields = apply_filters('siteorigin_panels_settings_fields', array() );
		include plugin_dir_path(__FILE__) . '/tpl/settings.php';
	}

	/**
	 * Add a settings help tab
	 */
	function add_help_tab(){
		$screen = get_current_screen();
		ob_start();
		include plugin_dir_path(__FILE__) . 'tpl/help.php';
		$content = ob_get_clean();

		$screen->add_help_tab( array(
			'id' => 'panels-help-tab',
			'title' => __('Page Builder Settings', 'siteorigin-panels'),
			'content' => $content
		) );
	}

	/**
	 * Add the default Page Builder settings.
	 *
	 * @param $fields
	 *
	 * @return mixed
	 */
	function settings_fields( $fields ){
		// The post types fields

		$fields['general'] = array(
			'title' => __('General', 'siteorigin-panels'),
			'fields' => array(),
		);

		$fields['general']['fields']['post-types'] = array(
			'type' => 'select_multi',
			'label' => __('Post Types', 'siteorigin-panels'),
			'options' => $this->get_post_types(),
			'description' => __('The post types to use Page Builder on.', 'siteorigin-panels'),
		);

		$fields['general']['fields']['live-editor-quick-link'] = array(
			'type' => 'checkbox',
			'label' => __('Live Editor Quick Link', 'siteorigin-panels'),
			'description' => __('Display a Live Editor button in the admin bar.', 'siteorigin-panels'),
		);

		$fields['general']['fields']['parallax-motion'] = array(
			'type' => 'float',
			'label' => __('Limit Parallax Motion', 'siteorigin-panels'),
			'description' => __('How many pixels of scrolling result in a single pixel of parallax motion. 0 means automatic. Lower values give more noticeable effect.', 'siteorigin-panels'),
		);

		$fields['general']['fields']['sidebars-emulator'] = array(
			'type' => 'checkbox',
			'label' => __('Sidebars Emulator', 'siteorigin-panels'),
			'description' => __('Page Builder will create an emulated sidebar, that contains all widgets in the page.', 'siteorigin-panels'),
		);

		$fields['general']['fields']['display-teaser'] = array(
			'type' => 'checkbox',
			'label' => __('Upgrade Teaser', 'siteorigin-panels'),
			'description' => sprintf(
				__('Display the %sSiteOrigin Premium%s upgrade teaser in the Page Builder toolbar.', 'siteorigin-panels'),
				'<a href="siteorigin.com/downloads/premium/" target="_blank">',
				'</a>'
			)
		);

		$fields['general']['fields']['display-learn'] = array(
			'type' => 'checkbox',
			'label' => __( 'Page Builder Learning', 'siteorigin-panels' ),
			'description' => __( 'Display buttons for Page Builder learning.', 'siteorigin-panels' )
		);

		// The widgets fields

		$fields['widgets'] = array(
			'title' => __('Widgets', 'siteorigin-panels'),
			'fields' => array(),
		);

		$fields['widgets']['fields']['title-html'] = array(
			'type' => 'html',
			'label' => __('Widget Title HTML', 'siteorigin-panels'),
			'description' => __('The HTML used for widget titles. {{title}} is replaced with the widget title.', 'siteorigin-panels'),
		);

		$fields['widgets']['fields']['add-widget-class'] = array(
			'type' => 'checkbox',
			'label' => __('Add Widget Class', 'siteorigin-panels'),
			'description' => __("Add the widget class to Page Builder widgets. Disable this if you're experiencing conflicts.", 'siteorigin-panels'),
		);

		$fields['widgets']['fields']['bundled-widgets'] = array(
			'type' => 'checkbox',
			'label' => __('Legacy Bundled Widgets', 'siteorigin-panels'),
			'description' => __('Load legacy widgets from Page Builder 1.', 'siteorigin-panels'),
		);

		$fields['widgets']['fields']['recommended-widgets'] = array(
			'type' => 'checkbox',
			'label' => __('Recommended Widgets', 'siteorigin-panels'),
			'description' => __('Display recommend widgets in Page Builder add widget dialog.', 'siteorigin-panels'),
		);

		// The layout fields

		$fields['layout'] = array(
			'title' => __('Layout', 'siteorigin-panels'),
			'fields' => array(),
		);

		// The layout fields

		$fields['layout']['fields']['responsive'] = array(
			'type' => 'checkbox',
			'label' => __('Responsive Layout', 'siteorigin-panels'),
			'description' => __('Collapse widgets, rows and columns on mobile devices.', 'siteorigin-panels'),
		);

		$fields['layout']['fields']['tablet-layout'] = array(
			'type' => 'checkbox',
			'label' => __('Use Tablet Layout', 'siteorigin-panels'),
			'description' => __('Collapses columns differently on tablet devices.', 'siteorigin-panels'),
		);

		$fields['layout']['fields']['tablet-width'] = array(
			'type' => 'number',
			'unit' => 'px',
			'label' => __('Tablet Width', 'siteorigin-panels'),
			'description' => __('Device width, in pixels, to collapse into a tablet view.', 'siteorigin-panels'),
		);

		$fields['layout']['fields']['mobile-width'] = array(
			'type' => 'number',
			'unit' => 'px',
			'label' => __('Mobile Width', 'siteorigin-panels'),
			'description' => __('Device width, in pixels, to collapse into a mobile view.', 'siteorigin-panels'),
		);

		$fields['layout']['fields']['margin-bottom'] = array(
			'type' => 'number',
			'unit' => 'px',
			'label' => __('Row Bottom Margin', 'siteorigin-panels'),
			'description' => __('Default margin below rows.', 'siteorigin-panels'),
		);

		$fields['layout']['fields']['margin-bottom-last-row'] = array(
			'type' => 'checkbox',
			'label' => __('Last Row With Margin', 'siteorigin-panels'),
			'description' => __('Allow margin in last row.', 'siteorigin-panels'),
		);

		$fields['layout']['fields']['margin-sides'] = array(
			'type' => 'number',
			'unit' => 'px',
			'label' => __('Row Gutter', 'siteorigin-panels'),
			'description' => __('Default spacing between columns in each row.', 'siteorigin-panels'),
			'keywords' => 'margin',
		);

		$fields['layout']['fields']['full-width-container'] = array(
			'type' => 'text',
			'label' => __('Full Width Container', 'siteorigin-panels'),
			'description' => __('The container used for the full width layout.', 'siteorigin-panels'),
			'keywords' => 'full width, container, stretch',
		);

		// The content fields

		$fields['content'] = array(
			'title' => __('Content', 'siteorigin-panels'),
			'fields' => array(),
		);

		$fields['content']['fields']['copy-content'] = array(
			'type' => 'checkbox',
			'label' => __('Copy Content', 'siteorigin-panels'),
			'description' => __('Copy content from Page Builder to post content.', 'siteorigin-panels'),
		);

		return $fields;
	}

	/**
	 * Display a settings field
	 *
	 * @param $field_id
	 * @param $field
	 */
	function display_field($field_id, $field){
		$value = siteorigin_panels_setting($field_id);

		$field_name = 'panels_setting[' . $field_id . ']';

		switch ($field['type'] ) {
			case 'text':
			case 'float':
				?><input name="<?php echo esc_attr($field_name) ?>" class="panels-setting-<?php echo esc_attr($field['type']) ?>" type="text" value="<?php echo esc_attr($value) ?>" /> <?php
				break;

			case 'number':
				?>
				<input name="<?php echo esc_attr($field_name) ?>" type="number" class="panels-setting-<?php echo esc_attr($field['type']) ?>" value="<?php echo esc_attr($value) ?>" />
				<?php
				if( !empty($field['unit']) ) echo esc_html($field['unit']);
				break;

			case 'html':
				?><textarea name="<?php echo esc_attr($field_name) ?>" class="panels-setting-<?php echo esc_attr($field['type']) ?> widefat" rows="<?php echo !empty($field['rows']) ? intval($field['rows']) : 2 ?>"><?php echo esc_textarea($value) ?></textarea> <?php
				break;

			case 'checkbox':
				?>
				<label class="widefat">
					<input name="<?php echo esc_attr($field_name) ?>" type="checkbox" <?php checked( !empty($value) ) ?> />
					<?php echo !empty($field['checkbox_text']) ? esc_html($field['checkbox_text']) : __('Enabled', 'siteorigin-panels') ?>
				</label>
				<?php
				break;

			case 'select':
				?>
				<select name="<?php echo esc_attr($field_name) ?>">
					<?php foreach( $field['options'] as $option_id => $option ) : ?>
						<option value="<?php echo esc_attr($option_id) ?>" <?php selected($option_id, $value) ?>><?php echo esc_html($option) ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'select_multi':
				foreach( $field['options'] as $option_id => $option ) {
					?>
					<label class="widefat">
						<input name="<?php echo esc_attr($field_name) ?>[<?php echo esc_attr($option_id) ?>]" type="checkbox" <?php checked( in_array($option_id, $value) ) ?> />
						<?php echo esc_html($option) ?>
					</label>
					<?php
				}

				break;
		}
	}

	/**
	 * Save the Page Builder settings.
	 */
	function save_settings(){
		$screen = get_current_screen();
		if( $screen->base != 'settings_page_siteorigin_panels' ) return;

		if( !current_user_can('manage_options') ) return;
		if( empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'panels-settings') ) return;
		if( empty($_POST['panels_setting']) ) return;

		$values = array();
		$post = stripslashes_deep( $_POST['panels_setting'] );
		$settings_fields = $this->fields = apply_filters('siteorigin_panels_settings_fields', array() );

		if( empty($settings_fields) ) return;

		foreach( $settings_fields as $section_id => $section ) {
			if( empty($section['fields']) ) continue;

			foreach( $section['fields'] as $field_id => $field ) {

				switch( $field['type'] ) {
					case 'text' :
						$values[$field_id] = !empty($post[$field_id]) ? sanitize_text_field( $post[$field_id] ) : '';
						break;

					case 'number':
						if( $post[$field_id] != '' ) {
							$values[$field_id] = !empty($post[$field_id]) ? intval( $post[$field_id] ) : 0;
						}
						else {
							$values[$field_id] = '';
						}
						break;

					case 'float':
						if( $post[$field_id] != '' ) {
							$values[$field_id] = !empty($post[$field_id]) ? floatval( $post[$field_id] ) : 0;
						}
						else {
							$values[$field_id] = '';
						}
						break;

					case 'html':
						$values[$field_id] = !empty($post[$field_id]) ? $post[$field_id] : '';
						$values[$field_id] = wp_kses_post( $values[$field_id] );
						$values[$field_id] = force_balance_tags( $values[$field_id] );
						break;

					case 'checkbox':
						$values[$field_id] = !empty( $post[$field_id] );
						break;

					case 'select':
						$values[$field_id] = !empty( $post[$field_id] ) ? $post[$field_id] : '';
						if( !in_array( $values[$field_id], array_keys($field['options']) ) ) {
							unset($values[$field_id]);
						}
						break;

					case 'select_multi':
						$values[$field_id] = array();
						$multi_values = array();
						foreach( $field['options'] as $option_id => $option ) {
							$multi_values[$option_id] = !empty($post[$field_id][$option_id]);
						}
						foreach( $multi_values as $k => $v ) {
							if( $v ) $values[$field_id][] = $k;
						}

						break;
				}

			}
		}

		// Save the values to the database
		update_option( 'siteorigin_panels_settings', $values );
		$this->settings = wp_parse_args( $values, $this->settings );
		$this->settings_saved = true;
	}

	/**
	 * Get a post type array
	 *
	 * @return array
	 */
	function get_post_types(){
		$types = array_merge( array( 'page' => 'page', 'post' => 'post' ), get_post_types( array( '_builtin' => false ) ) );

		// These are post types we know we don't want to show Page Builder on
		unset( $types['ml-slider'] );

		foreach( $types as $type_id => $type ) {
			$type_object = get_post_type_object( $type_id );

			if( !$type_object->show_ui ) {
				unset($types[$type_id]);
				continue;
			}

			$types[$type_id] = $type_object->label;
		}

		return $types;
	}

}

// Create the single instance
SiteOrigin_Panels_Settings::single();


/**
 * Get a setting with the given key.
 *
 * @param string $key
 *
 * @return array|bool|mixed|null
 */
function siteorigin_panels_setting($key = ''){
	return SiteOrigin_Panels_Settings::single()->get($key);
}
