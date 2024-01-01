<?php

// Ensure all full width stretched rows have padding.
// This will prevent a situation where the content is squished.
function siteorigin_panels_vantage_full_width_stretch( $data, $post_id ) {
	foreach( $data['grids'] as $grid_id => $grid ) {
		if (
			! empty( $grid['style']['row_stretch'] ) &&
			$grid['style']['row_stretch'] == 'full-width-stretch' &&
			empty( $grid['style']['padding'] )
		) {
			$data['grids'][ $grid_id ]['style']['padding'] = '0';
		}
	}

	return $data;
}
add_filter( 'siteorigin_panels_data', 'siteorigin_panels_vantage_full_width_stretch', 9, 2 );