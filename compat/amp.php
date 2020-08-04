<?php
/**
 * Add AMP Text widget as a Core JS Widget.
 *
 * @param $panels_data
 *
 * @return mixed
 */
function siteorigin_panels_add_amp_text( $widgets ) {
	$widgets[] = 'AMP_Widget_Text';

	return $widgets;
}
add_filter( 'siteorigin_panels_core_js_widgets', 'siteorigin_panels_add_amp_text' );
