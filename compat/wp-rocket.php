<?php

/**
 * Remove WP Rocket's widget purge from the current request.
 *
 * Page Builder runs widget_update_callback while it prepares widgets for previews and for the
 * builder, and WP Rocket's callback on that filter clears the whole site. Other callbacks on the
 * filter still run, because their changes reach the preview and the Layout Block's stored data.
 *
 * @return void
 */
function siteorigin_panels_wp_rocket_remove_widget_purge() {
	remove_filter( 'widget_update_callback', 'rocket_widget_update_callback' );
}

/**
 * Skip WP Rocket's widget purge on Page Builder requests that don't save a layout.
 *
 * @return void
 */
function siteorigin_panels_wp_rocket_skip_preview_purge() {
	if ( wp_doing_ajax() ) {
		$actions = array(
			'so_panels_builder_content',
			'so_panels_builder_content_json',
			'so_panels_get_layout',
			'so_panels_import_layout',
			'so_panels_layout_block_preview',
			'so_panels_live_editor_preview',
		);
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';

		if ( is_string( $action ) && in_array( $action, $actions, true ) ) {
			siteorigin_panels_wp_rocket_remove_widget_purge();
		}
	} elseif ( SiteOrigin_Panels::is_live_editor() && ! empty( $_POST['live_editor_panels_data'] ) ) {
		// template_redirect only fires for a front-end page, so admin and REST saves keep their purge.
		add_action( 'template_redirect', 'siteorigin_panels_wp_rocket_remove_widget_purge' );
	}
}
siteorigin_panels_wp_rocket_skip_preview_purge();
