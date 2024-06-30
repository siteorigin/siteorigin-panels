<?php
function siteorigin_panels_vantage_row_style_attributes( $attributes, $style ) {
	if ( empty( $style['class'] ) ) {
		return $attributes;
	}

	if ( isset( $style['class'] ) && $style['class'] == 'wide-grey' && ! empty( $attributes['style'] ) ) {
		$attributes['style'] = preg_replace( '/padding-left: 1000px; padding-right: 1000px;/', '', $attributes['style'] );
	}

	return $attributes;
}
add_filter( 'siteorigin_panels_row_style_attributes', 'siteorigin_panels_vantage_row_style_attributes', 10, 2 );

// Ensure all full width stretched rows have padding.
// This will prevent a situation where the content is squished.
function siteorigin_panels_vantage_full_width_stretch( $data, $post_id ) {
	if (
		! is_array( $data ) ||
		empty( $data['grids'] )
	) {
		return $data;
	}

	foreach( $data['grids'] as $grid_id => $grid ) {
		if (
			! empty( $grid['style']['row_stretch'] ) &&
			(
				$grid['style']['row_stretch'] == 'full-width-stretch' ||
				// In the off chance the row data hasn't been migrated yet.
				$grid['style']['row_stretch'] == 'full-stretched' ||
				$grid['style']['row_stretch'] == 'full-stretched-padded'
			) &&
			empty( $grid['style']['padding'] )
		) {
			$data['grids'][ $grid_id ]['style']['padding'] = '0px 0px 0px 0px';
		}
	}

	return $data;
}
if ( ! is_admin() ) {
	add_filter( 'siteorigin_panels_data', 'siteorigin_panels_vantage_full_width_stretch', 9, 2 );
}
