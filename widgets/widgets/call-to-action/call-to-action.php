<?php

class SiteOrigin_Panels_Widget_Call_To_Action extends SiteOrigin_Panels_Widget {
	function __construct() {
		parent::__construct(
			__( 'Call To Action (PB)', 'siteorigin-panels' ),
			array(
				'description' => __( 'A Call to Action block', 'siteorigin-panels' ),
				'default_style' => 'simple',
			),
			array(),
			array(
				'title' => array(
					'type' => 'text',
					'label' => __( 'Title', 'siteorigin-panels' ),
				),
				'subtitle' => array(
					'type' => 'text',
					'label' => __( 'Sub Title', 'siteorigin-panels' ),
				),
				'button_text' => array(
					'type' => 'text',
					'label' => __( 'Button Text', 'siteorigin-panels' ),
				),
				'button_url' => array(
					'type' => 'text',
					'label' => __( 'Button URL', 'siteorigin-panels' ),
				),
				'button_new_window' => array(
					'type' => 'checkbox',
					'label' => __( 'Open In New Window', 'siteorigin-panels' ),
				),
			)
		);

		// We need the button style.
		$this->add_sub_widget( 'button', __( 'Button', 'siteorigin-panels' ), 'SiteOrigin_Panels_Widget_Button') ;
	}
}