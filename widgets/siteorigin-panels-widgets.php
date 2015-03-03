<?php

class SiteOrigin_Panels_Widgets {

	private $all_posts_widgets;

	function __construct() {
		$this->all_posts_widgets = array();

		add_action( 'widgets_init', array( $this, 'register_widgets' ) , 20 );
		add_filter( 'sidebars_widgets', array( $this, 'add_widgets_to_sidebars' ) );
	}

	function __call( $name, $args ) {
		preg_match( '/call_widget_(.+)/', $name, $opt_matches );
		if ( !empty( $opt_matches ) && count( $opt_matches ) > 1 ) {
			$opt_name = $opt_matches[1];
			global $wp_widget_factory;
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if ( $widget->id_base == $opt_name ) {
					$widget_class = get_class( $widget );
					foreach ( $this->all_posts_widgets as $post_widgets ) {
						foreach ( $post_widgets as $widget_instance ) {
							$instance_class = $widget_instance['panels_info']['class'];
							if ( $instance_class == $widget_class ) {
								$args[0][ $widget_instance['id'] ] = $widget_instance;
							}
						}
					}
				}
			}

			return $args[0];
		}
	}

	function register_widgets() {
		$pb_posts = new WP_Query( array(
			'meta_key' => 'panels_data',
			'post_type' => array( 'post', 'page' ),
			'post_status' => 'publish',
			'nopaging' => true,
		) );
		global $wp_widget_factory;
		$widget_option_names = array();
		foreach ( $pb_posts->posts as $pb_post ) {
			$panels_data = get_post_meta( $pb_post->ID, 'panels_data', true );
			if( empty( $panels_data ) || empty( $panels_data['widgets'] ) ) {
				continue;
			}
			$widgets = $panels_data['widgets'];
			$this->all_posts_widgets[ $pb_post->ID ] = array();
			foreach ( $widgets as $widget_instance ) {
				$id_val = $pb_post->ID . strval( 1000 + intval( $widget_instance['panels_info']['id'] ) );
				$widget_instance['id'] = $id_val;
				$widget_class = $widget_instance['panels_info']['class'];
				if ( ! empty( $wp_widget_factory->widgets[ $widget_class ] ) ) {
					$widget = $wp_widget_factory->widgets[ $widget_class ];
					$widget_option_names[] = $widget->option_name;
				}
				$this->all_posts_widgets[$pb_post->ID][] = $widget_instance;
			}
		}
		$widget_option_names = array_unique( $widget_option_names );
		foreach ( $widget_option_names as $widget_option_name ) {
			add_filter( 'option_' . $widget_option_name, array( $this, 'call_' . $widget_option_name ) );
		}
	}

	/**
	 * Add a sidebar for SiteOrigin Panels widgets so they are correctly detected by is_active_widget
	 */
	function add_widgets_to_sidebars( $sidebars_widgets ) {
		global $wp_query;
		if ( ! empty( $this->all_posts_widgets ) ) {
			$siteorigin_panels_widget_ids = array();
//			foreach ( $this->all_posts_widgets as $post_id => $post_widgets ) {
			foreach ( $wp_query->posts as $post ) {
				if ( ! empty( $this->all_posts_widgets[ $post->ID ] ) ) {
					$post_widgets = $this->all_posts_widgets[ $post->ID ];
					foreach ( $post_widgets as $widget_instance ) {
						$siteorigin_panels_widget_ids[] = $widget_instance['id'];
					}
					if( ! empty( $siteorigin_panels_widget_ids) ) $sidebars_widgets['sidebar-siteorigin_panels-post-' . $post->ID] = $siteorigin_panels_widget_ids;
				}
			}
		}

		return $sidebars_widgets;
	}
}
global $siteorigin_panels_widgets;
$siteorigin_panels_widgets = new SiteOrigin_Panels_Widgets();