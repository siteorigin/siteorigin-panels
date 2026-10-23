<?php
/**
 * Plugin Name: Panels E2E CSS Container
 * Description: Test-only must-use plugin. Declares a theme content container for the CSS container breaker while ?panels_e2e_css_container=1 is present. Never shipped (the release build excludes tests/).
 */
if ( isset( $_GET['panels_e2e_css_container'] ) ) {
	add_filter( 'siteorigin_panels_theme_container_selector', function () {
		return '.e2e-wrap > .e2e-container';
	} );
	add_filter( 'siteorigin_panels_theme_container_width', function () {
		return '600px';
	} );
}
