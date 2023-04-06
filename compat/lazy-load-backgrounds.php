<?php
/**
 * Apply background and Lazy Load attributes/classes to rows, cells and widgets.
 *
 * @return array $attributes
 */
function siteorigin_apply_lazy_load_attributes( $attributes, $style ) {
	if (
		! empty( $style['background_display'] ) &&
		! empty( $style['background_image_attachment'] ) &&
		$style['background_display'] != 'parallax' &&
		$style['background_display'] != 'parallax-original'
	) {
		$url = SiteOrigin_Panels_Styles::get_attachment_image_src( $style['background_image_attachment'], 'full' );

		if ( ! empty( $url ) ) {
			$attributes['class'][] = 'lazy';
			$attributes['data-bg'] = $url[0];

			// WP Rocket uses a different lazy load class.
			if ( defined( 'ROCKET_LL_VERSION' ) || function_exists( 'get_rocket_option' ) ) {
				$attributes['class'][] = 'rocket-lazyload';
			}

			// Other lazy loads can sometimes use an inline background image.
			if ( apply_filters( 'siteorigin_lazy_load_inline_background', false ) ) {
				$attributes['style'] = 'background-image: url(' . $url[0] . ')';
			}
		}
	}

	return $attributes;
}
add_filter( 'siteorigin_panels_row_style_attributes', 'siteorigin_apply_lazy_load_attributes', 10, 2 );
add_filter( 'siteorigin_panels_cell_style_attributes', 'siteorigin_apply_lazy_load_attributes', 10, 2 );
add_filter( 'siteorigin_panels_widget_style_attributes', 'siteorigin_apply_lazy_load_attributes', 10, 2 );

/**
 * Prevent background image from being added using CSS.
 *
 * @return mixed
 */
function siteorigin_prevent_background_css( $css, $style ) {
	if (
		! empty( $css['background-image'] ) &&
		$style['background_display'] != 'parallax' &&
		$style['background_display'] != 'parallax-original'
	) {
		unset( $css['background-image'] );
	}

	return $css;
}
add_filter( 'siteorigin_panels_row_style_css', 'siteorigin_prevent_background_css', 10, 2 );
add_filter( 'siteorigin_panels_cell_style_css', 'siteorigin_prevent_background_css', 10, 2 );
add_filter( 'siteorigin_panels_widget_style_css', 'siteorigin_prevent_background_css', 10, 2 );
