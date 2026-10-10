<?php
/**
 * WordPress map_deep() for the Layout Block suite, which does not load
 * WordPress. Same recursion as core: arrays and object properties are walked,
 * and the callback runs on every other value.
 */

if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $property_name => $property_value ) {
				$value->$property_name = map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}

		return $value;
	}
}
