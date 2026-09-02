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
	 * open. Only the outermost scope decides whether shortcodes are suspended,
	 * backs the registry up, and restores it; nested scopes inherit that
	 * decision without re-consulting the opt-out filter.
	 */
	private static $suspend_depth = 0;

	/**
	 * Whether the outermost open scope actually suspended shortcodes. False
	 * while the opt-out filter declined suspension for this render.
	 */
	private static $suspend_active = false;

	/**
	 * Add filters that include data-* attributes on Page Builder divs
	 */
	public static function add_filters( $is_block_editor = false ) {
		// Suspension runs first: it applies a public filter whose callbacks
		// can throw, and the callers' try/finally cleanup only starts after
		// add_filters() returns - so nothing may be registered before the one
		// call that can fail.
		if ( ! $is_block_editor ) {
			self::suspend_shortcodes();
		}

		add_filter( 'siteorigin_panels_row_attributes', 'SiteOrigin_Panels_Post_Content_Filters::row_attributes', 99, 2 );
		add_filter( 'siteorigin_panels_cell_attributes', 'SiteOrigin_Panels_Post_Content_Filters::cell_attributes', 99, 2 );
		add_filter( 'siteorigin_panels_widget_attributes', 'SiteOrigin_Panels_Post_Content_Filters::widget_attributes', 99, 2 );

		if ( ! $is_block_editor ) {
			SiteOrigin_Panels_Widget_Shortcode::add_filters();
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
	 * normally through the_content when the mirror itself is displayed. A
	 * shortcode registered while the render is running would repopulate the
	 * registry and become executable, so the pre_do_shortcode_tag filter
	 * short-circuits those too, returning the original shortcode text.
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

		// Consult the opt-out filter before touching any state: a throwing
		// filter callback must leave nothing to clean up, because the caller's
		// try/finally only starts after add_filters() returns.
		$suspend = (bool) apply_filters( 'siteorigin_panels_post_content_keep_shortcodes', true );

		// The scope opens whether or not suspension engages, so a nested
		// render inherits this decision instead of re-consulting the filter -
		// an opted-out outer render must not gain suspension from a nested one.
		self::$suspend_depth = 1;
		self::$suspend_active = $suspend;

		if ( ! $suspend ) {
			return;
		}

		self::$shortcode_tags_backup = isset( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array();
		$GLOBALS['shortcode_tags'] = array();
		add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'keep_shortcode_intact' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Short-circuits any shortcode that reaches do_shortcode_tag() while the
	 * registry is suspended, returning the original shortcode text unchanged.
	 * Only a shortcode registered during the render can get this far.
	 *
	 * @param false|string $return The short-circuit value.
	 * @param string       $tag    The shortcode tag.
	 * @param array|string $attr   The shortcode attributes.
	 * @param array        $m      The regex match for the shortcode.
	 *
	 * @return string The original, unexecuted shortcode text.
	 */
	public static function keep_shortcode_intact( $return, $tag, $attr, $m ) {
		return $m[0];
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

		if ( ! self::$suspend_active ) {
			// The outermost scope closed, but this render opted out of
			// suspension - there is nothing to restore.
			return;
		}

		self::$suspend_active = false;

		remove_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'keep_shortcode_intact' ), PHP_INT_MAX );

		// Keep any shortcodes registered during the render, but let the
		// original registrations win. Array union rather than array_merge:
		// digit-only shortcode tags are valid and PHP stores them as integer
		// keys, which array_merge would silently renumber from zero.
		$GLOBALS['shortcode_tags'] = self::$shortcode_tags_backup + (
			is_array( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array()
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
