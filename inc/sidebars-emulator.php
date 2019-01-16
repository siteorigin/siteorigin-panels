<?php

class SiteOrigin_Panels_Sidebars_Emulator {

	private $all_posts_widgets;

	function __construct() {
		$this->all_posts_widgets = array();
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
		add_filter( 'sidebars_widgets', array( $this, 'add_widgets_to_sidebars' ) );
	}

	/**
	 * Get the single instance.
	 *
	 * @return SiteOrigin_Panels_Sidebars_Emulator
	 */
	static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
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
		if ( ! empty( $opt_matches ) && count( $opt_matches ) > 1 ) {
			$opt_name = $opt_matches[1];
			global $wp_widget_factory;
			foreach ( $wp_widget_factory->widgets as $widget ) {
				$widget_class = get_class( $widget );
				if ( $widget->id_base != $opt_name ) {
					continue;
				}

				foreach ( $this->all_posts_widgets as $post_widgets ) {
					foreach ( $post_widgets as $widget_instance ) {
						if ( empty( $widget_instance['panels_info']['class'] ) ) {
							continue;
						}

						$instance_class = $widget_instance['panels_info']['class'];
						$sidebar_id = $this->get_sidebar_id( $widget_instance );
						if ( $instance_class == $widget_class && ! empty( $sidebar_id ) ) {
							//The option value uses only the widget id number as keys
							preg_match( '/-([0-9]+$)/', $sidebar_id, $num_match );
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
	function register_widgets() {
		// Get the ID of the current post
		$post_id = url_to_postid( add_query_arg( false, false ) );
		if ( empty( $post_id ) ) {
			// Maybe this is the home page
			$current_url_path = parse_url( add_query_arg( false, false ), PHP_URL_PATH );
			$home_url_path    = parse_url( trailingslashit( home_url() ), PHP_URL_PATH );

			if ( $current_url_path === $home_url_path && get_option( 'page_on_front' ) != 0 ) {
				$post_id = absint( get_option( 'page_on_front' ) );
			}
		}
		if ( empty( $post_id ) ) {
			return;
		}

		$panels_data = get_post_meta( $post_id, 'panels_data', true );
		$widget_option_names = $this->get_widget_option_names( $post_id, $panels_data );
		$widget_option_names = array_unique( $widget_option_names );

		foreach ( $widget_option_names as $widget_option_name ) {
			add_filter( 'option_' . $widget_option_name, array( $this, 'filter_option_' . $widget_option_name ) );
		}
	}

	/**
	 * Recursively get all widget option names from $panels_data and store widget instances in $this->all_posts_widgets.
	 *
	 * @param int|string $post_id
	 * @param array $panels_data
	 *
	 * @return array A list of widget option names from the post and its Layout Builder widgets.
	 */
	private function get_widget_option_names( $post_id, $panels_data ) {
		if( empty( $panels_data ) || empty( $panels_data[ 'widgets' ] ) ) {
			return array();
		}

		if( empty( $this->all_posts_widgets[ $post_id ] ) ) {
			$this->all_posts_widgets[ $post_id ] = array();
		}

		$widget_option_names = array();
		$widgets = $panels_data['widgets'];
		foreach ( $widgets as $i => $widget_instance ) {
			if ( empty( $widget_instance['panels_info']['class'] ) ) {
				continue;
			}

			if( $widget_instance['panels_info']['class'] === 'SiteOrigin_Panels_Widgets_Layout' ) {
				// Add the widget option names from the layout widget
				$widget_option_names = array_merge( $widget_option_names, $this->get_widget_option_names( $post_id, $widget_instance[ 'panels_data' ] ) );
			}
			$sidebar_id = $this->get_sidebar_id( $widget_instance );
			if ( ! empty( $sidebar_id ) ) {
				$widget_option_names[] = $widget_instance['option_name'];
			}
			$this->all_posts_widgets[ $post_id ][] = $widget_instance;
		}

		return $widget_option_names;
	}
	
	/**
	 * This should be called when a post is saved to set ids required for `is_active_widget` checks. It's necessary to
	 * do this separately for widgets that call `is_active_widget` in their constructors, e.g. some of Jetpack's widgets
	 * like Twitter Timeline, Milestone etc.
	 *
	 * @param $widgets array The widgets in the layout from $panels_data for which to generate ids.
	 * @param $post_id int The post id which is used to derive ids.
	 * @param int $start This keeps track of recursive depth.
	 *
	 * @return array The widgets array containing updated widgets.
	 */
	public function generate_sidebar_widget_ids( $widgets, $post_id, $start = 1 ) {
		foreach ( $widgets as $i => &$widget_instance ) {
			$id_val = $post_id . strval( ( 10000 * $start ) + intval( $i ) );
			$widget_class = $widget_instance['panels_info']['class'];
			
			
			if( $widget_instance['panels_info']['class'] === 'SiteOrigin_Panels_Widgets_Layout' ) {
				if ( ! empty( $widget_instance['panels_data']['widgets'] ) ) {
					// Recursively set widget ids in layout widgets.
					$widget_instance[ 'panels_data' ]['widgets'] = $this->generate_sidebar_widget_ids( $widget_instance[ 'panels_data' ]['widgets'], $post_id, ++$start );
				}
			}
			
			/** @var WP_Widget $widget */
			$widget = SiteOrigin_Panels::get_widget_instance( $widget_class );
			if ( ! empty( $widget ) ) {
				$widget_instance['so_sidebar_emulator_id'] = $widget->id_base . '-' . $id_val;
				$widget_instance['option_name'] = $widget->option_name;
			}
		}
		
		return $widgets;
	}

	/**
	 * Add a sidebar for SiteOrigin Panels widgets so they are correctly detected by is_active_widget
	 *
	 * @param $sidebars_widgets
	 *
	 * @return array
	 */
	function add_widgets_to_sidebars( $sidebars_widgets ) {
		if ( empty( $this->all_posts_widgets ) ) {
			return $sidebars_widgets;
		}

		foreach ( array_keys( $this->all_posts_widgets ) as $post_id ) {
			$post_widgets = $this->all_posts_widgets[ $post_id ];
			foreach ( $post_widgets as $widget_instance ) {
				$sidebar_id = $this->get_sidebar_id( $widget_instance );
				if ( empty( $sidebar_id ) ) {
					continue;
				}
				//Sidebars widgets and the global $wp_registered widgets use full widget ids as keys
				$siteorigin_panels_widget_ids[] = $sidebar_id;
			}
			if ( ! empty( $siteorigin_panels_widget_ids ) ) {
				$sidebars_widgets[ 'sidebar-siteorigin_panels-post-' . $post_id ] = $siteorigin_panels_widget_ids;
			}
		}

		return $sidebars_widgets;
	}
	
	/**
	 * The 'id' key was changed to 'so_sidebar_emulator_id' to try avoid conflicts with widgets that already had
	 * an 'id' key.
	 *
	 * @param $instance
	 *
	 * @return null
	 */
	private function get_sidebar_id( $instance ) {
		$sidebar_id = null;
		if ( ! empty( $instance['id'] ) ) {
			$sidebar_id = $instance['id'];
		} else if ( ! empty( $instance['so_sidebar_emulator_id'] ) ) {
			$sidebar_id = $instance['so_sidebar_emulator_id'];
		}
		
		return $sidebar_id;
	}
}

SiteOrigin_Panels_Sidebars_Emulator::single();
