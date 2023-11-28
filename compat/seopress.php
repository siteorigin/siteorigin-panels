<?php
function siteorigin_panels_seopress_compat( $content ) {
	$id = empty( $_GET['post'] ) ? $_GET['post_id'] : $_GET['post'];
	if ( ! empty( $id ) ) {
		$page_builder_data = get_post_meta( $id, 'panels_data', true );
		if ( ! empty( $page_builder_data ) ) {
			$content = SiteOrigin_Panels_Admin::single()->generate_panels_preview( $id, $page_builder_data );

			// To help with consistent results, we strip out certain elements.
			if ( class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) ) {
				$whitelist = [
					'p', 'a', 'img', 'caption', 'br',
					'blockquote', 'cite', 'em', 'strong', 'i', 'b', 'q',
					'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
					'ul', 'ol', 'li', 'table', 'tr', 'th', 'td'
				];
				$dom = new DOMDocument();
				$dom->loadHTML( $content );
			
				$xpath = new DOMXPath( $dom );
				$elements = $xpath->query( '//iframe | //script | //style | //link' );
				foreach ( $elements as $element ) {
					$element->parentNode->removeChild( $element );
				}
				$dom->removeChild( $dom->doctype );
			
				// Remove elements that are not in the whitelist.
				$elements = $xpath->query( '//*' );
				foreach ( $elements as $element ) {
					if ( ! in_array( $element->nodeName, $whitelist ) ) {
						$content = $dom->createDocumentFragment();
						while ( $element->childNodes->length > 0 ) {
							$content->appendChild( $element->childNodes->item( 0 ) );
						}
						$element->parentNode->replaceChild( $content, $element );
					}
				}
			
				$content = $dom->saveHTML();
			}
		}
	}

	return $content;
}
add_action( 'seopress_dom_analysis_get_post_content', 'siteorigin_panels_seopress_compat', 15, 2 );