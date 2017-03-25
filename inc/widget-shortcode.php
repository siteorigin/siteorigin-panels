<?php

class SiteOrigin_Panels_Widget_Shortcode {

	static $text_widgets = array(
		'SiteOrigin_Panels_Editor_Widget',
		'SiteOrigin_Panels_Widgets_Layout',
		'WP_Widget_Black_Studio_TinyMCE',
		'WP_Widget_Text',
	);

	static function init() {
		add_shortcode( 'siteorigin_widget', array( 'SiteOrigin_Panels_Widget_Shortcode', 'shortcode' ) );
	}

	static function add_filters() {
		add_filter( 'siteorigin_panels_the_widget_html', 'SiteOrigin_Panels_Widget_Shortcode::widget_html', 10, 4 );
	}

	static function remove_filters(){
		remove_filter( 'siteorigin_panels_the_widget_html', 'SiteOrigin_Panels_Widget_Shortcode::widget_html' );
	}

	static function shortcode( $attr, $content ){
		$attr = shortcode_atts( array(
			'class' => false,
			'id' => '',
		), $attr, 'panels_widget' );

		global $wp_widget_factory;
		if( ! empty( $attr[ 'class' ] ) && isset( $wp_widget_factory->widgets[ $attr[ 'class' ] ] ) ) {
			$the_widget = $wp_widget_factory->widgets[ $attr[ 'class' ] ];

			$meta = json_decode( html_entity_decode( $content ), true );

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

		$widget_data = array(
			'instance' => $instance,
			'args' => $args,
		);

		// This will always be handled by Page Builder or the cached version
		unset( $args['before_widget'] );
		unset( $args['after_widget'] );

		// This allows other plugins to implement their own shortcode. For example, to work when Page Builder isn't active
		$shortcode_name = apply_filters( 'siteorigin_panels_cache_shortcode', 'siteorigin_widget', $widget, $instance, $args );

		$shortcode = '[' . $shortcode_name . ' ';
		$shortcode .= 'class="' . htmlentities( get_class( $widget ) ) . '"]';
		$shortcode .= htmlentities( wp_json_encode( $widget_data ) );
		$shortcode .= '[/' . $shortcode_name . ']';

		return $shortcode;
	}

	/**
	 *
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
}
