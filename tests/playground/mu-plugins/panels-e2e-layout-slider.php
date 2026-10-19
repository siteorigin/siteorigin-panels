<?php
/** Observe the render-time instance before Widgets Bundle computes LESS variables. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'siteorigin_widgets_widget_style_hash', function ( $hash, $widget ) {
	if ( isset( $_GET['panels_e2e_slider_instance'] ) && get_class( $widget ) === 'SiteOrigin_Widget_LayoutSlider_Widget' ) {
		$property = new ReflectionProperty( 'SiteOrigin_Widget', 'current_instance' );
		$property->setAccessible( true );
		// Classic themes may collect styles and render the same widget more than once.
		$instance = $property->getValue( $widget );
		$GLOBALS['panels_e2e_slider_instances'][ md5( serialize( $instance ) ) ] = $instance;
	}
	return $hash;
}, 10, 2 );

add_action( 'wp_footer', function () {
	if ( isset( $GLOBALS['panels_e2e_slider_instances'] ) ) {
		echo '<script type="application/json" id="panels-e2e-slider-instances">' . wp_json_encode( array_values( $GLOBALS['panels_e2e_slider_instances'] ), JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	}
} );
