<?php

/**
 * Display a loop of posts.
 *
 * Class SiteOrigin_Panels_Widgets_PostLoop
 */
class SiteOrigin_Panels_Widgets_PostLoop extends SiteOrigin_Widget {
	function __construct() {
		parent::__construct(
			'siteorigin-panels-postloop',
			__( 'Post Loop', 'siteorigin-panels' ),
			array(
				'description' => __( 'Displays a post loop.', 'siteorigin-panels' ),
			)
		);
	}
	
	function get_widget_form() {
		return array(
			'title' => array(
				'type' => 'text',
				'label' => __( 'Title', 'siteorigin-panels' ),
			),
			'template' => array(
				'type' => 'select',
				'label' => __( 'Template', 'siteorigin-panels' ),
				'options' => $this->get_loop_templates(),
				'default' => 'loop.php',
			),
			'more' => array(
				'type' => 'checkbox',
				'label' => __( 'More link', 'so-widgets-bundle' ),
				'description' => __( 'If the template supports it, cut posts and display the more link.', 'siteorigin-panels' ),
				'default' => false,
			),
			'posts' => array(
				'type' => 'posts',
				'label' => __( 'Posts query', 'so-widgets-bundle' ),
				'hide' => true
			),
		);
	}
	
	/**
	 * Convert this instance into one that's compatible with the posts field
	 *
	 * @param $instance
	 *
	 * @return mixed
	 */
	function modify_instance( $instance ) {
		if( ! empty( $instance['post_type'] ) ) {
			$value = '';
			
			if( ! empty( $instance['post_type'] ) ) $value .= 'post_type=' . $instance['post_type'];
			if( ! empty( $instance['posts_per_page'] ) ) $value .= '&posts_per_page=' . $instance['posts_per_page'];
			if( ! empty( $instance['order'] ) ) $value .= '&order=' . $instance['order'];
			if( ! empty( $instance['orderby'] ) ) $value .= '&orderby=' . $instance['orderby'];
			if( ! empty( $instance['sticky'] ) ) $value .= '&sticky=' . $instance['sticky'];
			if( ! empty( $instance['additional'] ) ) $value .= '&additional=' . $instance['additional'];
			$instance[ 'posts' ] = $value;
			
			unset( $instance[ 'post_type' ] );
			unset( $instance[ 'posts_per_page' ] );
			unset( $instance[ 'order' ] );
			unset( $instance[ 'orderby' ] );
			unset( $instance[ 'sticky' ] );
			unset( $instance[ 'additional' ] );
		}
		
		return $instance;
	}
	
	/**
	 * @param array $args
	 * @param array $instance
	 */
	function widget( $args, $instance ) {
		SiteOrigin_Panels_Widgets_PostLoopHelper::widget( $args, $instance, $this );
	}
	
	static function is_rendering_loop() {
		return SiteOrigin_Panels_Widgets_PostLoopHelper::$rendering_loop;
	}
	
	/**
	 * Get all the existing files
	 *
	 * @return array
	 */
	function get_loop_templates(){
		foreach( SiteOrigin_Panels_Widgets_PostLoopHelper::get_loop_templates() as $template ) {
			$templates[ $template ] = $template;
		}
		return $templates;
	}
}