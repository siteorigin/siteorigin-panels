<?php
/**
 * Contains several legacy and shorthand functions
 *
 * @since 3.0
 */

/**
 * @return mixed|void Are we currently viewing the home page
 */
function siteorigin_panels_is_home() {
	return SiteOrigin_Panels::is_home();
}

/**
 * Check if we're currently viewing a page builder page.
 *
 * @param bool $can_edit Also check if the user can edit this page
 *
 * @return bool
 */
function siteorigin_panels_is_panel( $can_edit = false ) {
	return SiteOrigin_Panels::is_panel( $can_edit );
}

function siteorigin_panels_get_home_page_data() {
	return SiteOrigin_Panels::single()->get_home_page_data();
}

/**
 * Render Page Builder content
 *
 * @param bool $post_id
 * @param bool $enqueue_css
 * @param bool $panels_data
 *
 * @return string The HTML content.
 */
function siteorigin_panels_render( $post_id = false, $enqueue_css = true, $panels_data = false ) {
	return SiteOrigin_Panels::renderer()->render( $post_id, $enqueue_css, $panels_data );
}

/**
 * Generate the CSS for the page layout.
 *
 * @return string
 */
function siteorigin_panels_generate_css( $post_id, $panels_data = false ) {
	return SiteOrigin_Panels::renderer()->generate_css( $post_id, $panels_data );
}

/**
 * Legacy function to process raw widgets.
 *
 * @return array
 */
function siteorigin_panels_process_raw_widgets( $widgets, $old_widgets = false, $escape_classes = false ) {
	return SiteOrigin_Panels_Admin::single()->process_raw_widgets( $widgets, $old_widgets, $escape_classes );
}

function siteorigin_panels_the_widget( $widget_info, $instance, $grid, $cell, $panel, $is_first, $is_last, $post_id = false, $style_wrapper = '' ) {
	SiteOrigin_Panels::renderer()->the_widget( $widget_info, $instance, $grid, $cell, $panel, $is_first, $is_last, $post_id, $style_wrapper );
}

/**
 * Get a setting with the given key.
 *
 * @param string $key
 *
 * @return array|bool|mixed|null
 */
function siteorigin_panels_setting( $key = '' ) {
	return SiteOrigin_Panels_Settings::single()->get( $key );
}

function siteorigin_panels_plugin_activation_install_url( $plugin, $plugin_name, $source = false ) {
	return SiteOrigin_Panels_Admin_Widgets_Bundle::install_url( $plugin, $plugin_name, $source );
}

/**
 * A null function for compatibility with aTheme themes.
 *
 * @return bool
 */
function siteorigin_panels_activate() {
	return false;
}

/**
 * Returns the base URL of our widget with `$path` appended.
 *
 * @param string $path Extra path to append to the end of the URL.
 *
 * @return string Base URL of the widget, with $path appended.
 */
function siteorigin_panels_url( $path = '' ) {
	return plugins_url( $path, SITEORIGIN_PANELS_BASE_FILE );
}
