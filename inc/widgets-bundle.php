<?php

// An array with all the SiteOrigin Widget Bundle widgets
return array(
	'SiteOrigin_Widget_Editor_Widget' => array(
		'class' => 'SiteOrigin_Widget_Editor_Widget',
		'title' => __('SiteOrigin Editor', 'siteorigin-panels'),
		'description' => __('A rich text editor', 'siteorigin-panels'),
		'installed' => false,
		'plugin' => array(
			'name' => __('SiteOrigin Widgets Bundle', 'siteorigin-panels'),
			'slug' => 'so-widgets-bundle'
		),
		'groups' => array('so-widgets-bundle'),
	),

	'SiteOrigin_Widget_Button_Widget' => array(
		'class' => 'SiteOrigin_Widget_Button_Widget',
		'title' => __('SiteOrigin Button', 'siteorigin-panels'),
		'description' => __('A simple button', 'siteorigin-panels'),
		'installed' => false,
		'plugin' => array(
			'name' => __('SiteOrigin Widgets Bundle', 'siteorigin-panels'),
			'slug' => 'so-widgets-bundle'
		),
		'groups' => array('so-widgets-bundle'),
	),

	'SiteOrigin_Widget_Image_Widget' => array(
		'class' => 'SiteOrigin_Widget_Image_Widget',
		'title' => __('SiteOrigin Image', 'siteorigin-panels'),
		'description' => __('Choose images from your media library.', 'siteorigin-panels'),
		'installed' => false,
		'plugin' => array(
			'name' => __('SiteOrigin Widgets Bundle', 'siteorigin-panels'),
			'slug' => 'so-widgets-bundle'
		),
		'groups' => array('so-widgets-bundle'),
	),

	'SiteOrigin_Widget_Slider_Widget' => array(
		'class' => 'SiteOrigin_Widget_Slider_Widget',
		'title' => __('SiteOrigin Slider', 'siteorigin-panels'),
		'description' => __('A basic slider widget.', 'siteorigin-panels'),
		'installed' => false,
		'plugin' => array(
			'name' => __('SiteOrigin Widgets Bundle', 'siteorigin-panels'),
			'slug' => 'so-widgets-bundle'
		),
		'groups' => array('so-widgets-bundle'),
	),

	'SiteOrigin_Widget_Features_Widget' => array(
		'class' => 'SiteOrigin_Widget_Features_Widget',
		'title' => __('SiteOrigin Features', 'siteorigin-panels'),
		'description' => __('Display site features as a collection of icons.', 'siteorigin-panels'),
		'installed' => false,
		'plugin' => array(
			'name' => __('SiteOrigin Widgets Bundle', 'siteorigin-panels'),
			'slug' => 'so-widgets-bundle'
		),
		'groups' => array('so-widgets-bundle'),
	),

	'SiteOrigin_Widget_PostCarousel_Widget' => array(
		'class' => 'SiteOrigin_Widget_PostCarousel_Widget',
		'title' => __('SiteOrigin Post Carousel', 'siteorigin-panels'),
		'description' => __('Display your posts as a carousel.', 'siteorigin-panels'),
		'installed' => false,
		'plugin' => array(
			'name' => __('SiteOrigin Widgets Bundle', 'siteorigin-panels'),
			'slug' => 'so-widgets-bundle'
		),
		'groups' => array('so-widgets-bundle'),
	),
);