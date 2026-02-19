<?php
/**
 * Compatibility with Ditty.
 */

/**
 * Prevent Ditty widgets from rendering during Page Builder admin post content generation.
 *
 * This avoids Ditty admin render scripts from affecting the Page Builder admin UI.
 *
 * @param string    $widget_html Widget output.
 * @param WP_Widget $the_widget  Widget object.
 * @param array     $args        Widget args.
 *
 * @return string
 */
function siteorigin_panels_ditty_admin_widget_render_compat( $widget_html, $the_widget, $args ) {
	$is_builder_render_context = ! empty( $GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] ) ||
		! empty( $GLOBALS['SITEORIGIN_PANELS_PREVIEW_RENDER'] );

	if (
		! is_admin() ||
		! $is_builder_render_context ||
		! is_a( $the_widget, 'WP_Widget' )
	) {
		return $widget_html;
	}

	$ditty_widget_bases = apply_filters(
		'siteorigin_panels_ditty_widget_bases',
		array(
			'ditty-widget',
			'mtphr-dnt-widget',
		)
	);

	if ( ! in_array( $the_widget->id_base, $ditty_widget_bases, true ) ) {
		return $widget_html;
	}

	if ( empty( $GLOBALS['SITEORIGIN_PANELS_DITTY_COMPAT'] ) ) {
		$GLOBALS['SITEORIGIN_PANELS_DITTY_COMPAT'] = array( 'hits' => array() );
	}
	$GLOBALS['SITEORIGIN_PANELS_DITTY_COMPAT']['hits'][] = array(
		'id_base'   => $the_widget->id_base,
		'widget_id' => ! empty( $args['widget_id'] ) ? $args['widget_id'] : '',
	);

	// Return an HTML comment so the renderer short-circuits the widget output path.
	return '<!-- Ditty widget output suppressed in Page Builder admin render context. -->';
}
add_filter( 'siteorigin_panels_the_widget_html', 'siteorigin_panels_ditty_admin_widget_render_compat', 10, 3 );

/**
 * Check if current admin screen is a SiteOrigin builder screen outside Ditty admin.
 *
 * @param string $hook_suffix Admin hook suffix.
 *
 * @return bool
 */
function siteorigin_panels_ditty_is_siteorigin_builder_screen( $hook_suffix ) {
	if (
		! is_admin() ||
		! class_exists( 'SiteOrigin_Panels_Admin' ) ||
		! SiteOrigin_Panels_Admin::is_admin() ||
		! function_exists( 'get_current_screen' )
	) {
		return false;
	}

	$screen = get_current_screen();
	if ( empty( $screen ) ) {
		return false;
	}

	$screen_id = ! empty( $screen->id ) ? (string) $screen->id : '';
	$post_type = ! empty( $screen->post_type ) ? (string) $screen->post_type : '';

	// Do not interfere with Ditty's own admin screens.
	if (
		false !== strpos( $screen_id, 'ditty' ) ||
		false !== strpos( $post_type, 'ditty' ) ||
		false !== strpos( (string) $hook_suffix, 'ditty' )
	) {
		return false;
	}

	return true;
}

/**
 * Dequeue Ditty assets on SiteOrigin builder admin screens to avoid UI conflicts.
 *
 * @param string $hook_suffix Admin hook suffix.
 *
 * @return void
 */
function siteorigin_panels_ditty_dequeue_builder_screen_assets( $hook_suffix ) {
	if ( ! siteorigin_panels_ditty_is_siteorigin_builder_screen( $hook_suffix ) ) {
		return;
	}

	$style_handles = apply_filters(
		'siteorigin_panels_ditty_admin_style_handles',
		array(
			'ditty-displays',
			'ditty-admin',
			'ditty-admin-old',
			'ditty-settings',
			'ditty-editor',
			'ditty-editor-init',
			'ditty-news-ticker',
			'ditty-news-ticker-font',
			'ditty-fontawesome',
			'ditty-display-cache',
		)
	);

	$script_handles = apply_filters(
		'siteorigin_panels_ditty_admin_script_handles',
		array(
			'ditty',
			'ditty-display-cache',
			'ditty-slider',
			'ditty-helpers',
			'ditty-admin',
			'ditty-settings',
			'ditty-editor-init',
			'ditty-editor',
			'ditty-display-editor',
			'ditty-layout-editor',
			'ditty-fields',
			'ditty-news-ticker',
		)
	);

	$removed = array(
		'styles'  => array(),
		'scripts' => array(),
	);

	foreach ( $style_handles as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			$removed['styles'][] = $handle;
		}
		wp_dequeue_style( $handle );
	}

	foreach ( $script_handles as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			$removed['scripts'][] = $handle;
		}
		wp_dequeue_script( $handle );
	}

	$GLOBALS['SITEORIGIN_PANELS_DITTY_COMPAT']['removed'] = $removed;
}
add_action( 'admin_enqueue_scripts', 'siteorigin_panels_ditty_dequeue_builder_screen_assets', 1000 );
