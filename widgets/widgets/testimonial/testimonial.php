<?php

class SiteOrigin_Panels_Widget_Testimonial extends SiteOrigin_Panels_Widget  {
	function __construct() {
		parent::__construct(
			__('Testimonial (PB)', 'siteorigin-panels'),
			array(
				'description' => __('Displays a bullet list of points', 'siteorigin-panels'),
				'default_style' => 'simple',
			),
			array(),
			array(
				'name' => array(
					'type' => 'text',
					'label' => __('Name', 'siteorigin-panels'),
				),
				'location' => array(
					'type' => 'text',
					'label' => __('Location', 'siteorigin-panels'),
				),
				'image' => array(
					'type' => 'text',
					'label' => __('Image', 'siteorigin-panels'),
				),
				'text' => array(
					'type' => 'textarea',
					'label' => __('Text', 'siteorigin-panels'),
				),
				'url' => array(
					'type' => 'text',
					// TRANSLATORS: Uniform Resource Locator
					'label' => __('URL', 'siteorigin-panels'),
				),
				'new_window' => array(
					'type' => 'checkbox',
					'label' => __('Open In New Window', 'siteorigin-panels'),
				),
			)
		);
	}
}