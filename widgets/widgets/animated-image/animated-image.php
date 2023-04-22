<?php

class SiteOrigin_Panels_Widget_Animated_Image extends SiteOrigin_Panels_Widget {
	public function __construct() {
		parent::__construct(
			__( 'Animated Image (PB)', 'siteorigin-panels' ),
			array(
				'description' => __( 'An image that animates in when it enters the screen.', 'siteorigin-panels' ),
				'default_style' => 'simple',
			),
			array(),
			array(
				'image' => array(
					'type' => 'text',
					'label' => __( 'Image URL', 'siteorigin-panels' ),
				),
				'animation' => array(
					'type' => 'select',
					'label' => __( 'Animation', 'siteorigin-panels' ),
					'options' => array(
						'fade' => __( 'Fade In', 'siteorigin-panels' ),
						'slide-up' => __( 'Slide Up', 'siteorigin-panels' ),
						'slide-down' => __( 'Slide Down', 'siteorigin-panels' ),
						'slide-left' => __( 'Slide Left', 'siteorigin-panels' ),
						'slide-right' => __( 'Slide Right', 'siteorigin-panels' ),
					),
				),
			)
		);
	}

	public function enqueue_scripts() {
		static $enqueued = false;

		if ( !$enqueued ) {
			wp_enqueue_script( 'siteorigin-widgets-' . $this->origin_id . '-onscreen', plugin_dir_url( __FILE__ ) . 'js/onscreen.js', array( 'jquery' ), SITEORIGIN_PANELS_VERSION );
			wp_enqueue_script( 'siteorigin-widgets-' . $this->origin_id, plugin_dir_url( __FILE__ ) . 'js/main.js', array( 'jquery' ), SITEORIGIN_PANELS_VERSION );
			$enqueued = true;
		}
	}
}
