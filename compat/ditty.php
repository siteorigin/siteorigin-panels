<?php
/**
 * Compatibility with Ditty.
 */
class SiteOrigin_Panels_Compat_Ditty {
	/**
	 * Internal compatibility state for debugging.
	 *
	 * @var array
	 */
	private $compat_state = array(
		'hits'    => array(),
		'removed' => array(
			'styles'  => array(),
			'scripts' => array(),
		),
	);

	public function __construct() {
		add_filter( 'siteorigin_panels_the_widget_html', array( $this, 'admin_widget_render_compat' ), 10, 3 );
		add_filter( 'siteorigin_panels_admin_conflict_style_handles', array( $this, 'admin_style_handles' ) );
		add_filter( 'siteorigin_panels_admin_conflict_script_handles', array( $this, 'admin_script_handles' ) );
		add_filter( 'siteorigin_panels_admin_conflict_excluded_screen_matchers', array( $this, 'excluded_screen_matchers' ) );
	}

	/**
	 * Prevent Ditty widgets from rendering during Page Builder admin post content generation.
	 *
	 * This avoids Ditty admin render scripts from affecting the Page Builder admin UI.
	 *
	 * @param string    $widget_html Widget output.
	 * @param WP_Widget $the_widget  Widget object.
	 * @param array     $args        Widget args.
	 *
	 * @return string
	 */
	public function admin_widget_render_compat( $widget_html, $the_widget, $args ) {
		$is_builder_render_context = ! empty( $GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] ) ||
			! empty( $GLOBALS['SITEORIGIN_PANELS_PREVIEW_RENDER'] );

		if (
			! is_admin() ||
			! $is_builder_render_context ||
			! is_a( $the_widget, 'WP_Widget' )
		) {
			return $widget_html;
		}

		$ditty_widget_bases = apply_filters(
			'siteorigin_panels_ditty_widget_bases',
			array(
				'ditty-widget',
				'mtphr-dnt-widget',
			)
		);

		if ( ! in_array( $the_widget->id_base, $ditty_widget_bases, true ) ) {
			return $widget_html;
		}

		$this->compat_state['hits'][] = array(
			'id_base'   => $the_widget->id_base,
			'widget_id' => ! empty( $args['widget_id'] ) ? $args['widget_id'] : '',
		);

		// Return an HTML comment so the renderer short-circuits the widget output path.
		return '<!-- ' . esc_html__( 'Ditty widget output suppressed in Page Builder admin render context.', 'siteorigin-panels' ) . ' -->';
	}

	/**
	 * Register Ditty styles for generic admin conflict handling.
	 *
	 * @param array $handles Existing conflict handles.
	 *
	 * @return array
	 */
	public function admin_style_handles( $handles ) {
		$handles = array_merge(
			(array) $handles,
			(array) apply_filters(
				'siteorigin_panels_ditty_admin_style_handles',
				array(
					'ditty-displays',
					'ditty-admin',
					'ditty-admin-old',
					'ditty-settings',
					'ditty-editor',
					'ditty-editor-init',
					'ditty-news-ticker',
					'ditty-news-ticker-font',
					'ditty-fontawesome',
					'ditty-display-cache',
				)
			)
		);

		return $handles;
	}

	/**
	 * Register Ditty scripts for generic admin conflict handling.
	 *
	 * @param array $handles Existing conflict handles.
	 *
	 * @return array
	 */
	public function admin_script_handles( $handles ) {
		$handles = array_merge(
			(array) $handles,
			(array) apply_filters(
				'siteorigin_panels_ditty_admin_script_handles',
				array(
					'ditty',
					'ditty-display-cache',
					'ditty-slider',
					'ditty-helpers',
					'ditty-admin',
					'ditty-settings',
					'ditty-editor-init',
					'ditty-editor',
					'ditty-display-editor',
					'ditty-layout-editor',
					'ditty-fields',
					'ditty-news-ticker',
				)
			)
		);

		return $handles;
	}

	/**
	 * Exclude Ditty admin screens from generic admin conflict handling.
	 *
	 * @param array $matchers Existing screen matchers.
	 *
	 * @return array
	 */
	public function excluded_screen_matchers( $matchers ) {
		$matchers = array_merge( (array) $matchers, array( 'ditty' ) );

		return $matchers;
	}
}

new SiteOrigin_Panels_Compat_Ditty();
