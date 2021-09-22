<?php
/**
 * When Polylang duplicates a post, copy over panels_data if it exists.
 *
 */
function siteorigin_polylang_include_panels_data( $keys, $sync ) {
	if ( ! $sync ) {
		$keys[] = 'panels_data';
	}
	return $keys;
}
add_filter( 'pll_copy_post_metas', 'siteorigin_polylang_include_panels_data', 10, 2 );
