<?php
/**
 * SiteOrigin Page Builder compatibility with Popup Maker.
 *
 * Popup Maker uses a custom `the_content` filter `pum_popup_content`.
 * This compatibility function ensures that the Page Builder content is
 * displayed correctly within Popup Maker popups.
 *
 * @param string $content The original content of the popup.
 * @param int $popup_id The ID of the popup.
 *
 * @return string The modified content, or the original content.
 */
function siteorigin_popup_maker( $content, $popup_id ) {

	if ( empty( $popup_id ) || ! is_numeric( $popup_id ) ) {
		return $content;
	}

	$panels_data = get_post_meta( (int) $popup_id, 'panels_data', true );
	if ( empty( $panels_data ) ) {
		return $content;
	}

	$panel_content = SiteOrigin_Panels::renderer()->render(
		$popup_id,
		true,
		$panels_data
	);

	return $panel_content ? $panel_content : $content;
}
add_filter( 'pum_popup_content', 'siteorigin_popup_maker', 10, 2 );
