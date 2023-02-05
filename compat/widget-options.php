<?php

/**
 * Filter panels_data so it's compatible with Widget Options plugin.
 *
 * @return mixed
 */
function siteorigin_panels_widget_options_compat_panels_data( $panels_data ) {
	if ( ! empty( $panels_data['widgets'] ) && is_array( $panels_data['widgets'] ) ) {
		foreach ( $panels_data['widgets'] as & $widget ) {
			if ( ! empty( $widget['extended_widget_opts'] ) ) {
				$widget['extended_widget_opts'] = siteorigin_panels_widget_options_compat_filter( $widget['extended_widget_opts'] );
			}
		}
	}

	return $panels_data;
}
add_filter( 'siteorigin_panels_data', 'siteorigin_panels_widget_options_compat_panels_data' );

/**
 * Filter that removes any empty strings so they pass an ! isset() test.
 *
 * @return array
 */
function siteorigin_panels_widget_options_compat_filter( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => & $v ) {
			if ( is_array( $v ) ) {
				$v = siteorigin_panels_widget_options_compat_filter( $v );
			} elseif ( is_string( $v ) && empty( $v ) ) {
				unset( $value[$k] );
			}
		}
	}

	return $value;
}
