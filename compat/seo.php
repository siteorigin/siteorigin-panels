<?php
function siteorigin_enqueue_seo_compat() {
	$enqueue = false;
	$deps = array( 'jquery' );

	if ( // Yoast.
		defined( 'WPSEO_FILE' ) &&
		(
			// => 18
			wp_script_is( 'yoast-seo-post-edit' ) ||
			wp_script_is( 'yoast-seo-post-edit-classic' ) ||
			// => 14.6 <= 17.9.
			wp_script_is( 'yoast-seo-admin-global-script' ) ||
			// <= 14.5.
			wp_script_is( 'yoast-seo-metabox' )
		)
	) {
		$enqueue = true;
	} elseif ( // Rank Math.
		defined( 'RANK_MATH_VERSION' ) &&
		wp_script_is( 'rank-math-analyzer' )
	) {
		$enqueue = true;
		$deps[] = 'rank-math-analyzer';
	}

	if ( $enqueue ) {
		wp_enqueue_script(
			'so-panels-seo-compat',
			esc_url( siteorigin_panels_url( 'js/seo-compat' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ) ),
			$deps,
			SITEORIGIN_PANELS_VERSION,
			true
		);
	}
}
add_action( 'siteorigin_panel_enqueue_admin_styles', 'siteorigin_enqueue_seo_compat', 100 );
