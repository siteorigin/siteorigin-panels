<?php
/**
 * If Yoast OpenGraph is enabled, we'll need disable PB when it gets the excerpt 
 * to avoid conflicts with other plugins.
 *
 */
function siteorigin_yoast_opengraph_panels_disable( $content ) {
	global $wp_current_filter;
	if ( count( $wp_current_filter ) > 2 && $wp_current_filter[1] == 'wpseo_head' ) {
		// Temporarily disable Page Builder for this instance of the_content.
		add_filter( 'siteorigin_panels_filter_content_enabled', '__return_false' );
	} else {
		add_filter( 'siteorigin_panels_filter_content_enabled', '__return_true' );
	}
	return $content;
}

// If Yoast OpenGraph is enabled, disable Page Builder as needed.
if (
	class_exists( 'WPSEO_Options' ) &&
	method_exists( 'WPSEO_Options', 'get' ) &&
	WPSEO_Options::get( 'opengraph' )
) {
	add_filter( 'the_content', 'siteorigin_yoast_opengraph_panels_disable', 1 );
}

/**
 * Returns a list of all images added using Page Builder.
 *
 * @param $images an array of all detected images used in the current post.
 * @param $post_id the current post id.
 *
 * @return array
 */
function siteorigin_yoast_sitemap_images_compat( $images, $post_id ) {
	if (
		get_post_meta( $post_id, 'panels_data', true ) &&
		extension_loaded( 'xml' ) &&
		class_exists( 'DOMDocument' )
	) {
		$content = SiteOrigin_Panels::renderer()->render(
			$post_id,
			false
		);

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $content );
		libxml_clear_errors();

		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );

			if ( ! empty( $src ) && $src == esc_url( $src ) ) {
				$images[] = array(
					'src'   => $src,
				);
			}
		}
	}

	return $images;
}
add_filter( 'wpseo_sitemap_urlimages', 'siteorigin_yoast_sitemap_images_compat', 10, 2 );
