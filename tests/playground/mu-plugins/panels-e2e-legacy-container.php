<?php
/**
 * Exercise JavaScript stretching independently of the active test theme's
 * optional CSS container integration. This file only runs in test Playground.
 */
if ( isset( $_GET['panels_e2e_legacy_container'] ) ) {
	add_filter( 'siteorigin_panels_theme_container_selector', '__return_empty_string' );
	add_filter( 'siteorigin_panels_theme_container_width', '__return_empty_string' );
}
