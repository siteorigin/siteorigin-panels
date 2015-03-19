<?php

class SiteOrigin_Panels_Sidebars_Emulator {

	private $all_posts_widgets;

	function __construct() {
		$this->all_posts_widgets = array();
		add_action( 'widgets_init', array( $this, 'register_widgets' ), 99 );
		add_filter( 'sidebars_widgets', array( $this, 'add_widgets_to_sidebars' ) );
	}

	/**
	 * Get the single instance.
	 *
	 * @return SiteOrigin_Panels_Widgets
	 */
	static function single(){
		static $single = false;
		if( empty($single) ) $single = new SiteOrigin_Panels_Sidebars_Emulator();

		return $single;
	}

	/**
	 * @param string $name The name of the function
	 * @param array $args
	 *
	 * @return mixed
	 */
	function __call( $name, $args ) {

		// Check if this is a filter option call
		preg_match( '/filter_option_widget_(.+)/', $name, $opt_matches );
		if ( !empty( $opt_matches ) && count( $opt_matches ) > 1 ) {
			$opt_name = $opt_matches[1];
			global $wp_widget_factory;
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if( $widget->id_base != $opt_name ) continue;

				$widget_class = get_class( $widget );
				foreach ( $this->all_posts_widgets as $post_widgets ) {
					foreach ( $post_widgets as $widget_instance ) {
						$instance_class = $widget_instance['panels_info']['class'];
						if ( $instance_class == $widget_class ) {
							//The option value uses only the widget id number as keys
							preg_match( '/-([0-9]+$)/', $widget_instance['id'], $num_match );
							$args[0][ $num_match[1] ] = $widget_instance;
						}
					}
				}
			}

			return $args[0];
		}

	}

	/**
	 * Register all the current widgets so we can filter the get_option('widget_...') values to add instances
	 */
	function register_widgets( ) {
		// Get the ID of the current post
		$post_id = url_to_postid( add_query_arg( false, false ) );
		if( empty($post_id) ) {
			// Maybe this is the home page
			if( add_query_arg(false, false) == '/' && get_option('page_on_front') != 0 ) {
				$post_id = get_option( 'page_on_front' );
			}
		}
		if( empty($post_id) ) return;

		global $wp_widget_factory;
		$widget_option_names = array();
		$panels_data = get_post_meta( $post_id, 'panels_data', true );
		if( empty( $panels_data ) || empty( $panels_data['widgets'] ) ) {
			return;
		}
		$widgets = $panels_data['widgets'];
		$this->all_posts_widgets[ $post_id ] = array();
		foreach ( $widgets as $widget_instance ) {
			$id_val = $post_id . strval( 1000 + intval( $widget_instance['panels_info']['id'] ) );
			$widget_class = $widget_instance['panels_info']['class'];
			if ( ! empty( $wp_widget_factory->widgets[ $widget_class ] ) ) {
				$widget = $wp_widget_factory->widgets[ $widget_class ];
				$widget_instance['id'] = $widget->id_base . '-' . $id_val;
				$widget_option_names[] = $widget->option_name;
			}
			$this->all_posts_widgets[ $post_id ][] = $widget_instance;
		}

		$widget_option_names = array_unique( $widget_option_names );
		foreach ( $widget_option_names as $widget_option_name ) {
			add_filter( 'option_' . $widget_option_name, array( $this, 'filter_option_' . $widget_option_name ) );
		}
	}

	/**
	 * Add a sidebar for SiteOrigin Panels widgets so they are correctly detected by is_active_widget
	 *
	 * @param $sidebars_widgets
	 * @return array
	 */
	function add_widgets_to_sidebars( $sidebars_widgets ) {
		if ( empty( $this->all_posts_widgets ) ) return $sidebars_widgets;

		foreach ( array_keys( $this->all_posts_widgets ) as $post_id ) {
			$post_widgets = $this->all_posts_widgets[ $post_id ];
			foreach ( $post_widgets as $widget_instance ) {
				if( empty($widget_instance['id']) ) continue;
				//Sidebars widgets and the global $wp_registered widgets use full widget ids as keys
				$siteorigin_panels_widget_ids[] = $widget_instance['id'];
			}
			if( ! empty( $siteorigin_panels_widget_ids) ) $sidebars_widgets['sidebar-siteorigin_panels-post-' . $post_id] = $siteorigin_panels_widget_ids;
		}

		return $sidebars_widgets;
	}
}

SiteOrigin_Panels_Sidebars_Emulator::single();