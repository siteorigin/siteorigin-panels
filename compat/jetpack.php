<?php
/**
 * Prevent Photon from filtering srcset.
 * This is done using a method to prevent conflicting with other usage of this filter.
 *
 * @param $valid
 * @param $url
 * @param $parsed_url
 *
 * @return false
 */
function siteorigin_panels_photon_exclude_parallax_srcset( $valid, $url, $parsed_url ) {
	return false;
}

/**
 * Prevent Photon from overriding parallax images when it calculates srcset and filters the_content.
 *
 * @param $skip Whether to exclude the iamge from Photon.
 * @param $src The URL of the current image
 * @param $tag This parameter is unrelaible as it can contain the image tag, or an array containing image values.
 *
 * @return bool
 */
function siteorigin_panels_photon_exclude_parallax( $skip, $src, $tag ) {
	if ( ! is_array( $tag ) && strpos( $tag, 'data-siteorigin-parallax' ) !== false ) {
		$skip = true;
	}
	return $skip;
}
