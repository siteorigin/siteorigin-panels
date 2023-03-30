<?php
function siteorigin_rank_math_sitemap( $content, $post_id ) {
	$panels_data = get_post_meta( $post_id, 'panels_data', true );
	if ( ! empty( $panels_data ) ) {
		$GLOBALS[ 'SITEORIGIN_PANELS_PREVIEW_RENDER' ] = true;
		$content = SiteOrigin_Panels::renderer()->render( (int) $post_id, false, $panels_data );
		if ( function_exists( 'wp_targeted_link_rel' ) && is_array( $return ) ) {
			$content = wp_targeted_link_rel( $return );
		}
		unset( $GLOBALS[ 'SITEORIGIN_PANELS_PREVIEW_RENDER' ] );
	}

	return $content;
}
add_filter( 'rank_math/sitemap/content_before_parse_html_images', 'siteorigin_rank_math_sitemap', 10, 2 );
