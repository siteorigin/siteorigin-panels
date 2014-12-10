<?php

class SiteOrigin_Panels_Widget_Button extends SiteOrigin_Panels_Widget  {
	function __construct() {
		parent::__construct(
			__('Button (PB)', 'siteorigin-panels'),
			array(
				'description' => __('A simple button', 'siteorigin-panels'),
				'default_style' => 'simple',
			),
			array(),
			array(
				'text' => array(
					'type' => 'text',
					'label' => __('Text', 'siteorigin-panels'),
				),
				'url' => array(
					'type' => 'text',
					'label' => __('Destination URL', 'siteorigin-panels'),
				),
				'new_window' => array(
					'type' => 'checkbox',
					'label' => __('Open In New Window', 'siteorigin-panels'),
				),
				'align' => array(
					'type' => 'select',
					'label' => __('Button Alignment', 'siteorigin-panels'),
					'options' => array(
						'left' => __('Left', 'siteorigin-panels'),
						'right' => __('Right', 'siteorigin-panels'),
						'center' => __('Center', 'siteorigin-panels'),
						'justify' => __('Justify', 'siteorigin-panels'),
					)
				),
			)
		);
	}

	function widget_classes($classes, $instance) {
		$classes[] = 'align-'.(empty($instance['align']) ? 'none' : $instance['align']);
		return $classes;
	}
}