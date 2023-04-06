<?php

/**
 * Handler for the LESS function lumlighten and lum darken
 *
 * @param string $type
 *
 * @return array|bool
 */
function origin_widgets_less_lum_change( $args, $type = 'darken' ) {
	if ( !class_exists( 'SiteOrigin_Color_Object' ) ) {
		include plugin_dir_path( __FILE__ ) . '../lib/color.php';
	}

	if ( $args[0] != 'list' ) {
		return false;
	}
	@ list( $a1_type, $a1_value, $a1_unit ) = $args[2][0];
	@ list( $a2_type, $a2_value, $a2_unit ) = $args[2][1];

	if ( $a1_type != 'raw_color' ) {
		return false;
	}

	if ( $a2_type != 'number' ) {
		return false;
	}

	$color = new SiteOrigin_Color_Object( $a1_value );

	if ( $type == 'lighten' ) {
		$color->lum += $a2_value / 100;
	} else {
		$color->lum -= $a2_value / 100;
	}

	return array( 'raw_color', $color->hex );
}

function origin_widgets_less_lumlighten( $args ) {
	return origin_widgets_less_lum_change( $args, 'lighten' );
}

function origin_widgets_less_lumdarken( $args ) {
	return origin_widgets_less_lum_change( $args, 'darken' );
}

/**
 * Less handler function for texture function
 *
 * @return string
 */
function origin_widgets_less_texture( $texture ) {
	if ( $texture[0] != 'list' ) {
		return '';
	}

	$return = '';

	foreach ( $texture[2] as $arg ) {
		if ( $arg[0] == 'keyword' ) {
			$t = $arg[1];

			if ( $t == 'none' ) {
				continue;
			}

			foreach ( SiteOrigin_Panels_Widget::get_image_folders() as $folder => $folder_url ) {
				if ( file_exists( $folder . '/textures/' . $t . '.png' ) ) {
					$return .= 'url(' . esc_url( $folder_url . '/textures/' . $t . '.png' ) . ') repeat ';
					break;
				}
			}
		} elseif ( $arg[0] == 'raw_color' ) {
			$return .= $arg[1] . ' ';
		}
	}

	return trim( $return );
}

/**
 * Less handler function for widgetimage function
 *
 * @return string
 */
function origin_widgets_less_widgetimage( $url ) {
	$the_url = '';

	foreach ( $url[2] as $p ) {
		if ( is_string( $p ) ) {
			$the_url .= $p;
		} elseif ( is_array( $p ) ) {
			$the_url .= $p[1];
		}
	}

	// Search for the appropriate image
	$return_url = '';

	foreach ( SiteOrigin_Panels_Widget::get_image_folders() as $folder => $folder_url ) {
		if ( file_exists( $folder . '/' . $the_url ) ) {
			$return_url = $folder_url . '/' . $the_url;
		}
	}

	if ( is_ssl() ) {
		$return_url = str_replace( 'http://', 'https://', $return_url );
	}

	return $return_url;
}
