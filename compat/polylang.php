<?php
/**
 * Ensure that SiteOrigin Panels data is included in Polylang's post meta copy.
 */
function siteorigin_polylang_include_panels_data( $keys, $sync ) {
	$keys[] = 'panels_data';

	return $keys;
}
add_filter( 'pll_copy_post_metas', 'siteorigin_polylang_include_panels_data', 10, 2 );
