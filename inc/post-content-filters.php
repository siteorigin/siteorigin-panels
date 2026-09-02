<?php

/**
 * A class that handles generating the post content version of Page Builder content.
 *
 * Class SiteOrigin_Panels_Post_Content
 */
class SiteOrigin_Panels_Post_Content_Filters {
	/**
	 * Backup of the registered shortcodes while shortcode execution is
	 * suspended for a post content render. Null when not suspended.
	 */
	private static $shortcode_tags_backup = null;

	/**
	 * How many balanced add_filters()/remove_filters() scopes are currently
	 * suspending shortcodes. Only the outermost scope backs up and restores.
	 */
	private static $suspend_depth = 0;

	/**
	 * Add filters that include data-* attributes on Page Builder divs
	 */
	public static function add_filters( $is_block_editor = false ) {
		add_filter( 'siteorigin_panels_row_attributes', 'SiteOrigin_Panels_Post_Content_Filters::row_attributes', 99, 2 );
		add_filter( 'siteorigin_panels_cell_attributes', 'SiteOrigin_Panels_Post_Content_Filters::cell_attributes', 99, 2 );
		add_filter( 'siteorigin_panels_widget_attributes', 'SiteOrigin_Panels_Post_Content_Filters::widget_attributes', 99, 2 );

		if ( ! $is_block_editor ) {
			SiteOrigin_Panels_Widget_Shortcode::add_filters();
			self::suspend_shortcodes();
		}
	}

	public static function remove_filters( $is_block_editor = false ) {
		remove_filter( 'siteorigin_panels_row_attributes', 'SiteOrigin_Panels_Post_Content_Filters::row_attributes', 99, 2 );
		remove_filter( 'siteorigin_panels_cell_attributes', 'SiteOrigin_Panels_Post_Content_Filters::cell_attributes', 99, 2 );
		remove_filter( 'siteorigin_panels_widget_attributes', 'SiteOrigin_Panels_Post_Content_Filters::widget_attributes', 99, 2 );

		if ( ! $is_block_editor ) {
			SiteOrigin_Panels_Widget_Shortcode::remove_filters();
			self::restore_shortcodes();
		}
	}

	/**
	 * Suspend shortcode execution for the duration of a post content render.
	 *
	 * The post content copy is a mirror of the layout, not a snapshot of one
	 * render. If shortcodes are executed while it's generated, their output is
	 * baked into post_content — a Ninja Forms or similar form shortcode, for
	 * example, is stored as its rendered placeholder markup, which then
	 * displays broken (without the plugin's JS/CSS) anywhere the mirror is
	 * shown instead of a live Page Builder render. Baked output also defeats
	 * plugins that scan post_content with has_shortcode() to decide whether
	 * to enqueue their front end assets.
	 *
	 * Emptying $shortcode_tags makes every do_shortcode() call during the
	 * render a no-op, so widgets (the WP Text widget, the SiteOrigin Editor
	 * widget, etc.) keep raw shortcodes intact in the mirror, and they run
	 * normally through the_content when the mirror itself is displayed.
	 */
	private static function suspend_shortcodes() {
		if ( self::$suspend_depth > 0 ) {
			// Suspension is already active. Nested scopes must always pair
			// with their remove_filters() call, so count them without
			// consulting the opt-out filter — a mid-render change in its
			// result must not desync the depth accounting.
			self::$suspend_depth++;

			return;
		}

		if ( ! apply_filters( 'siteorigin_panels_post_content_keep_shortcodes', true ) ) {
			return;
		}

		self::$suspend_depth = 1;
		self::$shortcode_tags_backup = isset( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array();
		$GLOBALS['shortcode_tags'] = array();
	}

	private static function restore_shortcodes() {
		if ( self::$suspend_depth === 0 ) {
			return;
		}

		self::$suspend_depth--;

		if ( self::$suspend_depth > 0 ) {
			// A nested scope closed; the outermost scope restores.
			return;
		}

		// Keep any shortcodes registered during the render, but let the
		// original registrations win.
		$GLOBALS['shortcode_tags'] = array_merge(
			is_array( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array(),
			self::$shortcode_tags_backup
		);
		self::$shortcode_tags_backup = null;
	}

	/**
	 * Add the row data attributes
	 *
	 * @return mixed
	 */
	public static function row_attributes( $attributes, $row ) {
		if ( ! empty( $row['style'] ) ) {
			$attributes[ 'data-style' ] = wp_json_encode( $row['style'] );
		}

		if ( ! empty( $row['ratio'] ) ) {
			$attributes[ 'data-ratio' ] = (float) $row['ratio'];
		}

		if ( ! empty( $row['ratio_direction'] ) ) {
			$attributes[ 'data-ratio-direction' ] = $row['ratio_direction'];
		}

		if ( ! empty( $row['color_label'] ) ) {
			$attributes[ 'data-color-label' ] = (int) $row['color_label'];
		}

		if ( ! empty( $row['label'] ) ) {
			$attributes[ 'data-label' ] = $row['label'];
		}

		return $attributes;
	}

	/**
	 * @return mixed
	 */
	public static function cell_attributes( $attributes, $cell ) {
		if ( ! empty( $cell['style'] ) ) {
			$attributes[ 'data-style' ] = wp_json_encode( $cell['style'] );
		}

		$attributes[ 'data-weight' ] = $cell['weight'];

		return $attributes;
	}

	/**
	 * @return mixed
	 */
	public static function widget_attributes( $attributes, $widget ) {
		if ( ! empty( $widget['style'] ) ) {
			$attributes[ 'data-style' ] = wp_json_encode( $widget['style'] );
		}

		if ( ! empty( $widget['label'] ) ) {
			$attributes[ 'data-label' ] = $widget['label'];
		}

		return $attributes;
	}
}
