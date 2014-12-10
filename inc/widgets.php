<?php

/**
 * Add some default recommended widgets.
 *
 * @param $widgets
 *
 * @return array
 */
function siteorigin_panels_add_recommended_widgets($widgets){

	if( empty( $widgets['WP_Widget_Black_Studio_TinyMCE'] ) ){
		$widgets['WP_Widget_Black_Studio_TinyMCE'] = array(
			'class' => 'WP_Widget_Black_Studio_TinyMCE',
			'title' => __('Visual Editor', 'siteorigin-panels'),
			'description' => __('Arbitrary text or HTML with visual editor', 'siteorigin-panels'),
			'installed' => false,
			'plugin' => array(
				'name' => __('Black Studio TinyMCE', 'siteorigin-panels'),
				'slug' => 'black-studio-tinymce-widget'
			),
			'groups' => array('recommended'),
			'icon' => 'dashicons dashicons-edit',
		);
	}
	else {
		$widgets['WP_Widget_Black_Studio_TinyMCE']['groups'] = array('recommended');
		$widgets['WP_Widget_Black_Studio_TinyMCE']['icon'] = 'dashicons dashicons-edit';
	}

	// Add in all the widgets bundle widgets
	$widgets = wp_parse_args(
		$widgets,
		include plugin_dir_path(__FILE__).'/widgets-bundle.php'
	);

	foreach($widgets as $class => $data) {
		if( strpos( $class, 'SiteOrigin_Panels_Widgets_' ) === 0 || strpos( $class, 'SiteOrigin_Panels_Widget_' ) === 0 ) {
			$widgets[$class]['groups'] = array('panels');
		}
	}

	$widgets['SiteOrigin_Panels_Widgets_Layout']['icon'] = 'dashicons dashicons-analytics';

	$wordpress_widgets = array(
		'WP_Widget_Pages',
		'WP_Widget_Links',
		'WP_Widget_Search',
		'WP_Widget_Archives',
		'WP_Widget_Meta',
		'WP_Widget_Calendar',
		'WP_Widget_Text',
		'WP_Widget_Categories',
		'WP_Widget_Recent_Posts',
		'WP_Widget_Recent_Comments',
		'WP_Widget_RSS',
		'WP_Widget_Tag_Cloud',
		'WP_Nav_Menu_Widget',
	);

	foreach($wordpress_widgets as $wordpress_widget) {
		if( isset( $widgets[$wordpress_widget] ) ) {
			$widgets[$wordpress_widget]['groups'] = array('wordpress');
			$widgets[$wordpress_widget]['icon'] = 'dashicons dashicons-wordpress';
		}
	}

	return $widgets;

}
add_filter('siteorigin_panels_widgets', 'siteorigin_panels_add_recommended_widgets');

/**
 * Add tabs to the widget dialog
 *
 * @param $tabs
 *
 * @return array
 */
function siteorigin_panels_add_widgets_dialog_tabs($tabs){

	$tabs[] = array(
		'title' => __('Widgets Bundle', 'siteorigin-panels'),
		'filter' => array(
			'groups' => array('so-widgets-bundle')
		)
	);

	$tabs[] = array(
		'title' => __('Page Builder Widgets', 'siteorigin-panels'),
		'filter' => array(
			'groups' => array('panels')
		)
	);

	$tabs[] = array(
		'title' => __('WordPress Widgets', 'siteorigin-panels'),
		'filter' => array(
			'groups' => array('wordpress')
		)
	);

	$tabs[] = array(
		'title' => __('Recommended Widgets', 'siteorigin-panels'),
		'filter' => array(
			'groups' => array('recommended')
		)
	);

	return $tabs;
}
add_filter('siteorigin_panels_widget_dialog_tabs', 'siteorigin_panels_add_widgets_dialog_tabs', 20);

/**
 * This will try restore bundled widgets.
 *
 * @param $object
 * @param $widget
 *
 * @return \WP_Widget_Text
 */
function siteorigin_panels_restore_bundled_widget($object, $widget){

	// We can skip this if there's already an object
	if( !empty($object) ) return $object;

	if( strpos($widget, 'SiteOrigin_Panels_Widget_') === 0 || strpos($widget, 'SiteOrigin_Panels_Widgets_') === 0  ) {

		if( !class_exists('SiteOrigin_Panels_Widget') ) {
			// Initialize the bundled widgets
			include plugin_dir_path( SITEORIGIN_PANELS_BASE_FILE ) . '/widgets/widgets.php';

			// Initialize all the widgets
			origin_widgets_init();
			siteorigin_panels_widgets_init();

			// Set this to change the default behaviour to using the bundled widgets, wont override user settings though
			add_option('siteorigin_panels_is_using_bundled', true);
		}

		if( class_exists($widget) ) {
			$object = new $widget();
		}
	}
	elseif(!is_admin() && $widget == 'WP_Widget_Black_Studio_TinyMCE') {
		// If the visual editor is missing, we can replace it with the text widget for now
		$object = new WP_Widget_Text();
	}

	return $object;
}
add_filter('siteorigin_panels_widget_object', 'siteorigin_panels_restore_bundled_widget', 10, 2);