<?php

class SiteOrigin_Panels_Widget_List extends SiteOrigin_Panels_Widget {
	public function __construct() {
		parent::__construct(
			__( 'List (PB)', 'siteorigin-panels' ),
			array(
				'description' => __( 'Displays a bullet list of elements', 'siteorigin-panels' ),
				'default_style' => 'simple',
			),
			array(),
			array(
				'title' => array(
					'type' => 'text',
					'label' => __( 'Title', 'siteorigin-panels' ),
				),
				'text' => array(
					'type' => 'textarea',
					'label' => __( 'Text', 'siteorigin-panels' ),
					'description' => __( 'Start each new point with an asterisk (*)', 'siteorigin-panels' ),
				),
			)
		);
	}

	public static function create_list( $text ) {
		// Add the list items
		$text = preg_replace( "/\*+(.*)?/i", '<ul><li>$1</li></ul>', $text );
		$text = preg_replace( "/(\<\/ul\>\n(.*)\<ul\>*)+/", '', $text );
		$text = wpautop( $text );

		// Return sanitized version of the list
		return wp_kses_post( $text );
	}
}
