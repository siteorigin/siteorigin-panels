<?php

class SiteOrigin_Panels_Widget_Price_Box extends SiteOrigin_Panels_Widget  {
	function __construct() {
		parent::__construct(
			__('Price Box (PB)', 'siteorigin-panels'),
			array(
				'description' => __('Displays a bullet list of elements', 'siteorigin-panels'),
				'default_style' => 'simple',
			),
			array(),
			array(
				'title' => array(
					'type' => 'text',
					'label' => __('Title', 'siteorigin-panels'),
				),
				'price' => array(
					'type' => 'text',
					'label' => __('Price', 'siteorigin-panels'),
				),
				'per' => array(
					'type' => 'text',
					'label' => __('Per', 'siteorigin-panels'),
				),
				'information' => array(
					'type' => 'text',
					'label' => __('Information Text', 'siteorigin-panels'),
				),
				'features' => array(
					'type' => 'textarea',
					'label' => __('Features Text', 'siteorigin-panels'),
					'description' => __('Start each new point with an asterisk (*)', 'siteorigin-panels'),
				),
				'button_text' => array(
					'type' => 'text',
					'label' => __('Button Text', 'siteorigin-panels'),
				),
				'button_url' => array(
					'type' => 'text',
					'label' => __('Button URL', 'siteorigin-panels'),
				),
				'button_new_window' => array(
					'type' => 'checkbox',
					'label' => __('Open In New Window', 'siteorigin-panels'),
				),
			)
		);

		$this->add_sub_widget('button', __('Button', 'siteorigin-panels'), 'SiteOrigin_Panels_Widget_Button');
		$this->add_sub_widget('list', __('Feature List', 'siteorigin-panels'), 'SiteOrigin_Panels_Widget_List');
	}
}