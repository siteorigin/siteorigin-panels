<?php

/**
 * Get a setting with the given key.
 *
 * @param string $key
 *
 * @return array|bool|mixed|null
 */
function siteorigin_panels_setting($key = ''){

	static $settings = false;
	if( !has_action('after_setup_theme') ) {
		$settings = false;
	}

	if( empty($settings) ){
		//
		$old_settings = get_option( 'siteorigin_panels_display', array() );
		if( !empty($old_settings) ) {
			// Get the current settings
			$current_settings = get_option('siteorigin_panels_display', array());
			$post_types = get_option('siteorigin_panels_post_types' );
			if( !empty($post_types) ) $current_settings['post-types'] = $post_types;

			update_option( 'siteorigin_panels_settings', $current_settings );
			delete_option( 'siteorigin_panels_display' );
			delete_option( 'siteorigin_panels_post_types' );
		}
		else {
			$current_settings = get_option( 'siteorigin_panels_settings', array() );
		}

		// Get the settings provided by the theme
		$theme_settings = get_theme_support('siteorigin-panels');
		if( !empty($theme_settings) ) $theme_settings = $theme_settings[0];
		else $theme_settings = array();

		$settings = wp_parse_args( $theme_settings, apply_filters( 'siteorigin_panels_settings_defaults', array() ) );
		$settings = wp_parse_args( $current_settings, $settings);

		// Filter these settings
		$settings = apply_filters('siteorigin_panels_settings', $settings);
	}

	if( !empty( $key ) ) return isset( $settings[$key] ) ? $settings[$key] : null;
	return $settings;
}

/**
 * Filter the default settings
 *
 * @param $defaults
 */
function siteorigin_panels_settings_defaults($defaults){
	$defaults['home-page'] = false;
	$defaults['home-page-default'] = false;
	$defaults['home-template'] = 'home-panels.php';
	$defaults['affiliate-id'] = apply_filters( 'siteorigin_panels_affiliate_id', false );

	// Widgets fields
	$defaults['title-html'] = '<h3 class="widget-title">{{title}}</h3>';
	$defaults['bundled-widgets'] = get_option( 'siteorigin_panels_is_using_bundled', false );
	$defaults['recommended-widgets'] = true;

	// Post types
	$defaults['post-types'] = array('page', 'post');

	// The layout fields
	$defaults['responsive'] = true;
	$defaults['mobile-width'] = 780;
	$defaults['margin-bottom'] = 30;
	$defaults['margin-sides'] = 30;

	// Content fields
	$defaults['copy-content'] = true;
	$defaults['powered-by'] = false;

	return $defaults;
}
add_filter('siteorigin_panels_settings_defaults', 'siteorigin_panels_settings_defaults');

/**
 * @param $prefix
 */
function siteorigin_panels_settings_enqueue_scripts($prefix){
	if( $prefix != 'settings_page_siteorigin_panels' ) return;
	wp_enqueue_style( 'siteorigin-panels-settings', plugin_dir_url(__FILE__) . '/admin-settings.css', array(), SITEORIGIN_PANELS_VERSION );
	wp_enqueue_script( 'siteorigin-panels-settings', plugin_dir_url(__FILE__) . '/admin-settings.js', array(), SITEORIGIN_PANELS_VERSION );
}
add_action('admin_enqueue_scripts', 'siteorigin_panels_settings_enqueue_scripts');

/**
 * Add the settings page help tab.
 */
function siteorigin_panels_add_settings_help_tab(){
	$screen = get_current_screen();
	ob_start();
	include plugin_dir_path(__FILE__) . 'tpl/help.php';
	$content = ob_get_clean();

	$screen->add_help_tab( array(
		'id' => 'panels-help-tab',
		'title' => __('Page Builder Settings', 'siteorigin-panels'),
		'content' => $content
	) );
};

/**
 * Add the options page
 */
function siteorigin_panels_options_admin_menu() {
	$page = add_options_page( __('SiteOrigin Page Builder', 'siteorigin-panels'), __('SiteOrigin Page Builder', 'siteorigin-panels'), 'manage_options', 'siteorigin_panels', 'siteorigin_panels_options_page' );
	add_action('load-' . $page, 'siteorigin_panels_add_settings_help_tab');
}
add_action( 'admin_menu', 'siteorigin_panels_options_admin_menu' );

/**
 * Display the admin page.
 */
function siteorigin_panels_options_page(){
	$settings_fields = apply_filters('siteorigin_panels_settings_fields', array() );
	include plugin_dir_path(__FILE__) . '/tpl/settings.php';
}

/**
 * Add any settings fields we need.
 *
 * @param $fields
 */
function siteorigin_panels_settings_fields($fields){

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

	$fields['widgets']['fields']['bundled-widgets'] = array(
		'type' => 'checkbox',
		'label' => __('Bundled Widgets', 'siteorigin-panels'),
		'description' => __('Load legacy bundled widgets from Page Builder 1.', 'siteorigin-panels'),
	);

	$fields['widgets']['fields']['recommended-widgets'] = array(
		'type' => 'checkbox',
		'label' => __('Recommended Widgets', 'siteorigin-panels'),
		'description' => __('Recommend widgets in Page Builder.', 'siteorigin-panels'),
	);

	// The post types fields

	$fields['post-types'] = array(
		'title' => __('Post Types', 'siteorigin-panels'),
		'fields' => array(),
	);

	$fields['post-types']['fields']['post-types'] = array(
		'type' => 'post_types',
		'label' => __('Post Types', 'siteorigin-panels'),
		'description' => __('The post types to use Page Builder on.', 'siteorigin-panels'),
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

	$fields['layout']['fields']['mobile-width'] = array(
		'type' => 'number',
		'unit' => 'px',
		'label' => __('Mobile Width', 'siteorigin-panels'),
		'description' => __('Device width, in pixels, to collapse into a mobile view .', 'siteorigin-panels'),
	);

	$fields['layout']['fields']['margin-bottom'] = array(
		'type' => 'number',
		'unit' => 'px',
		'label' => __('Row Bottom Margin', 'siteorigin-panels'),
		'description' => __('Default margin below rows.', 'siteorigin-panels'),
	);

	$fields['layout']['fields']['margin-sides'] = array(
		'type' => 'number',
		'unit' => 'px',
		'label' => __('Row Gutter', 'siteorigin-panels'),
		'description' => __('Default spacing between columns in each row.', 'siteorigin-panels'),
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

	$fields['content']['fields']['powered-by'] = array(
		'type' => 'checkbox',
		'label' => __('Powered By Link', 'siteorigin-panels'),
		'description' => __('Show your support for Page Builder by including an optional powered by link.', 'siteorigin-panels'),
	);

	return $fields;
}
add_filter('siteorigin_panels_settings_fields', 'siteorigin_panels_settings_fields');

/**
 * Display a single settings field
 */
function siteorigin_panels_settings_display_field( $field_id, $field ){
	$value = siteorigin_panels_setting($field_id);

	$field_name = 'panels_setting[' . $field_id . ']';

	switch ($field['type'] ) {
		case 'text':
			?><input name="<?php echo esc_attr($field_name) ?>" class="panels-setting-<?php echo esc_attr($field['type']) ?> widefat" type="text" value="<?php echo esc_attr($value) ?>" /> <?php
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

		case 'post_types':
			?>RENDER THE POST TYPES SELECTOR<?php
			break;
	}
}

/**
 * Sanitize and save settings from $_POST
 */
function siteorigin_panels_settings_save_settings(){

}