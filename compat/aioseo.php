<?php
// AIOSEO compatibility. We need to add their widgets to a sidebar
// to ensure they're useable in Page Builder.
function siteorigin_panels_load_aioseo_widgets( $sidebars = array() ) {
	$sidebars['aio_pb_compat'] = array(
		'aioseo-breadcrumb-widget',
		'aioseo-html-sitemap-widget',
	);

	return $sidebars;
}
add_filter( 'sidebars_widgets', 'siteorigin_panels_load_aioseo_widgets', 10, 1 );
