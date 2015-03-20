<?php

/**
 * Go through all the old PB widgets and change them into far better visual editor widgets
 *
 * @param array $panels_data
 *
 * @return array
 */
function siteorigin_panels_legacy_widget_migration($panels_data){

	if( !empty($panels_data['widgets']) && is_array($panels_data['widgets']) ) {

		foreach( $panels_data['widgets'] as &$widget ) {

			switch($widget['panels_info']['class']) {
				case 'SiteOrigin_Panels_Widgets_Gallery':
					$shortcode = '[gallery ';
					if( !empty($widget['ids']) ) $shortcode .= 'ids="' . esc_attr( $widget['ids'] ) . '" ';
					$shortcode = trim($shortcode) . ']';

					$widget = array(
						'title' => '',
						'filter' => '1',
						'type' => 'visual',
						'text' => $shortcode,
						'panels_info' => $widget['panels_info']
					);
					$widget['panels_info']['class'] = 'WP_Widget_Black_Studio_TinyMCE';

					break;

				case 'SiteOrigin_Panels_Widgets_Image':

					if( class_exists('SiteOrigin_Panels_Widgets_Image') ) {
						ob_start();
						the_widget( 'SiteOrigin_Panels_Widgets_Image', $widget, array(
							'before_widget' => '',
							'after_widget' => '',
							'before_title' => '',
							'after_title' => '',
						) );

						$widget = array(
							'title' => '',
							'filter' => '1',
							'type' => 'visual',
							'text' => ob_get_clean(),
							'panels_info' => $widget['panels_info']
						);

						$widget['panels_info']['class'] = 'WP_Widget_Black_Studio_TinyMCE';
					}

					break;
			}

		}

	}

	return $panels_data;
}
add_filter('siteorigin_panels_data', 'siteorigin_panels_legacy_widget_migration');