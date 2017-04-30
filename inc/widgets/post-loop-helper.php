<?php

/**
 * Contains
 *
 * Class SiteOrigin_Panels_Widgets_PostLoopHelper
 */
class SiteOrigin_Panels_Widgets_PostLoopHelper {
	static $rendering_loop;
	
	/**
	 * @param array $args
	 * @param array $instance
	 * @param WP_Widget $widget The calling widget.
	 */
	static function widget( $args, $instance, $widget ) {
		if( empty( $instance['template'] ) ) return;
		if( is_admin() ) return;
		
		static $depth = 0;
		$depth++;
		if( $depth > 1 ) {
			// Because of infinite loops, don't render this post loop if its inside another
			$depth--;
			echo $args['before_widget'].$args['after_widget'];
			return;
		}
		
		$query_args = $instance;
		//If Widgets Bundle post selector is available and a posts query has been saved using it.
		if ( function_exists( 'siteorigin_widget_post_selector_process_query' ) && ! empty( $instance['posts'] ) ) {
			$query_args = siteorigin_widget_post_selector_process_query($instance['posts']);
			$query_args['additional'] = empty($instance['additional']) ? array() : $instance['additional'];
		}
		else {
			if ( ! empty( $instance['posts'] ) ) {
				$query_args = wp_parse_args( $instance['posts'], $query_args );
			}
			
			if( ! empty( $query_args['sticky'] ) ) {
				switch( $query_args['sticky'] ){
					case 'ignore' :
						$query_args['ignore_sticky_posts'] = 1;
						break;
					case 'only' :
						$query_args['post__in'] = get_option( 'sticky_posts' );
						break;
					case 'exclude' :
						$query_args['post__not_in'] = get_option( 'sticky_posts' );
						break;
				}
			}
			unset($query_args['template']);
			unset($query_args['title']);
			unset($query_args['sticky']);
			if (empty($query_args['additional'])) {
				$query_args['additional'] = array();
			}
		}
		$query_args = wp_parse_args($query_args['additional'], $query_args);
		unset($query_args['additional']);
		
		global $wp_rewrite;
		
		if( $wp_rewrite->using_permalinks() ) {
			
			if( get_query_var('paged') ) {
				// When the widget appears on a sub page.
				$query_args['paged'] = get_query_var('paged');
			}
			elseif( strpos( $_SERVER['REQUEST_URI'], '/page/' ) !== false ) {
				// When the widget appears on the home page.
				preg_match('/\/page\/([0-9]+)\//', $_SERVER['REQUEST_URI'], $matches);
				if(!empty($matches[1])) $query_args['paged'] = intval($matches[1]);
				else $query_args['paged'] = 1;
			}
			else $query_args['paged'] = 1;
		}
		else {
			// Get current page number when we're not using permalinks
			$query_args['paged'] = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
		}
		
		// Exclude the current post to prevent possible infinite loop
		
		global $siteorigin_panels_current_post;
		
		if( !empty($siteorigin_panels_current_post) ){
			if( !empty( $query_args['post__not_in'] ) ){
				if( !is_array( $query_args['post__not_in'] ) ){
					$query_args['post__not_in'] = explode( ',', $query_args['post__not_in'] );
					$query_args['post__not_in'] = array_map( 'intval', $query_args['post__not_in'] );
				}
				$query_args['post__not_in'][] = $siteorigin_panels_current_post;
			}
			else {
				$query_args['post__not_in'] = array( $siteorigin_panels_current_post );
			}
		}
		
		if( !empty($query_args['post__in']) && !is_array($query_args['post__in']) ) {
			$query_args['post__in'] = explode(',', $query_args['post__in']);
			$query_args['post__in'] = array_map('intval', $query_args['post__in']);
		}
		
		// Create the query
		query_posts( apply_filters( 'siteorigin_panels_postloop_query_args', $query_args ) );
		echo $args['before_widget'];
		
		// Filter the title
		$instance['title'] = apply_filters('widget_title', $instance['title'], $instance, $widget->id_base);
		if ( !empty( $instance['title'] ) ) {
			echo $args['before_title'] . $instance['title'] . $args['after_title'];
		}
		
		global $more; $old_more = $more; $more = empty($instance['more']);
		self::$rendering_loop = true;
		if(strpos('/'.$instance['template'], '/content') !== false) {
			while( have_posts() ) {
				the_post();
				locate_template($instance['template'], true, false);
			}
		}
		else {
			locate_template($instance['template'], true, false);
		}
		self::$rendering_loop = false;
		
		echo $args['after_widget'];
		
		// Reset everything
		wp_reset_query();
		$depth--;
	}
	
	/**
	 * Get all the existing files
	 *
	 * @return array
	 */
	static function get_loop_templates(){
		$templates = array();
		
		$template_files = array(
			'loop*.php',
			'*/loop*.php',
			'content*.php',
			'*/content*.php',
		);
		
		$template_dirs = array( get_template_directory(), get_stylesheet_directory() );
		$template_dirs = array_unique( $template_dirs );
		foreach( $template_dirs  as $dir ){
			foreach( $template_files as $template_file ) {
				foreach( (array) glob($dir.'/'.$template_file) as $file ) {
					if( file_exists( $file ) ) $templates[] = str_replace($dir.'/', '', $file);
				}
			}
		}
		
		$templates = array_unique( $templates );
		$templates = apply_filters('siteorigin_panels_postloop_templates', $templates);
		sort( $templates );
		
		return $templates;
	}
}