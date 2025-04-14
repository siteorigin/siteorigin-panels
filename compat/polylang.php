<?php
/**
 * Ensure that SiteOrigin Panels data is included in Polylang's post meta copy,
 * and sync.
 */
function siteorigin_polylang_include_panels_data( $keys, $sync ) {
	if ( $sync ) {
		$keys[] = 'panels_data';
	}

	return $keys;
}
add_filter( 'pll_copy_post_metas', 'siteorigin_polylang_include_panels_data', 10, 2 );
add_filter( 'pll_sync_post_fields', 'siteorigin_polylang_include_panels_data', 10, 2 );
