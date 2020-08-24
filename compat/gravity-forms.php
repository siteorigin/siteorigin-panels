<?php
/**
 * Disable Gravity Disable Print Form Scirpts.
 *
 * @param $widget
 *
 * @return $widget
 */
function siteorigin_gravity_forms_disable_print_scripts( $widget ) {
	if ( $widget->id_base == 'gform_widget' ) {
		add_filter( 'gform_disable_print_form_scripts', '__return_true' );
	}

	return $widget;
}
add_filter( 'siteorigin_panels_widget_object', 'siteorigin_gravity_forms_disable_print_scripts' );
