<?php

class SiteOrigin_Panels_Widget_Shortcode {

	static $text_widgets = array(
		'SiteOrigin_Widget_Editor_Widget',
		'SiteOrigin_Panels_Widgets_Layout',
		'WP_Widget_Black_Studio_TinyMCE',
		'WP_Widget_Text',
	);

	static function init() {
		add_shortcode( 'siteorigin_widget', 'SiteOrigin_Panels_Widget_Shortcode::shortcode' );

		// Integration with the cache rendering system
		add_action( 'siteorigin_panels_start_cache_render', 'SiteOrigin_Panels_Widget_Shortcode::add_filters' );
		add_action( 'siteorigin_panels_end_cache_render', 'SiteOrigin_Panels_Widget_Shortcode::remove_filters' );
	}

	static function add_filters() {
		add_filter( 'siteorigin_panels_the_widget_html', 'SiteOrigin_Panels_Widget_Shortcode::widget_html', 10, 4 );
	}

	static function remove_filters(){
		remove_filter( 'siteorigin_panels_the_widget_html', 'SiteOrigin_Panels_Widget_Shortcode::widget_html' );
	}

	/**
	 * This shortcode just displays a widget based on the given arguments
	 *
	 * @param $attr
	 * @param $content
	 *
	 * @return string
	 */
	static function shortcode( $attr, $content ){
		$attr = shortcode_atts( array(
			'class' => false,
			'id' => '',
		), $attr, 'panels_widget' );

		$attr[ 'class' ] = html_entity_decode( $attr[ 'class' ] );

		global $wp_widget_factory;
		if( ! empty( $attr[ 'class' ] ) && isset( $wp_widget_factory->widgets[ $attr[ 'class' ] ] ) ) {
			$the_widget = $wp_widget_factory->widgets[ $attr[ 'class' ] ];

			$meta = json_decode( self::unescape_json( $content ), true );

			$widget_args = ! empty( $meta[ 'args' ] ) ? $meta[ 'args' ] : array();
			$widget_instance = ! empty( $meta[ 'instance' ] ) ? $meta[ 'instance' ] : array();

			$widget_args = wp_parse_args( array(
				'before_widget' => '',
				'after_widget' => '',
				'before_title' => '<h3 class="widget-title">',
				'after_title' => '</h3>',
			), $widget_args );

			ob_start();
			$the_widget->widget( $widget_args, $widget_instance );
			return ob_get_clean();
		}
	}

	/**
	 * Get the shortcode for a specific widget
	 *
	 * @param $widget
	 * @param $args
	 * @param $instance
	 *
	 * @return string
	 */
	static function get_shortcode( $widget, $args, $instance ){
		unset( $instance[ 'panels_info' ] );

		// This will always be handled by Page Builder or the cached version

		// This allows other plugins to implement their own shortcode. For example, to work when Page Builder isn't active
		$shortcode_name = apply_filters( 'siteorigin_panels_cache_shortcode', 'siteorigin_widget', $widget, $instance, $args );

		$shortcode = '[' . $shortcode_name . ' ';
		$shortcode .= 'class="' . htmlentities( get_class( $widget ) ) . '"]';
		$shortcode .= self::escape_json( wp_json_encode( $instance ) ) ;
		$shortcode .= '[/' . $shortcode_name . ']';

		return $shortcode;
	}

	/**
	 * A filter to replace widgets with
	 */
	static function widget_html( $html, $widget, $args, $instance ){
		if(
			// Don't try create HTML if there already is some
			! empty( $html ) ||
			// Skip for known text based widgets
			in_array( get_class( $widget ), self::$text_widgets )
		) {
			return $html;
		}

		return self::get_shortcode( $widget, $args, $instance );
	}

	static function escape_json( $string ){
		return $string;
	}

	static function unescape_json( $string ){
		return $string;
	}
}
