<?php
/**
 * Generic admin asset conflict handling for Page Builder screens.
 */
class SiteOrigin_Panels_Compat_Admin_Asset_Conflicts {
	/**
	 * Removed asset handles for debugging.
	 *
	 * @var array
	 */
	public $removed = array(
		'styles'  => array(),
		'scripts' => array(),
	);

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'dequeue_conflicting_assets' ), 1000 );
	}

	/**
	 * Dequeue conflicting plugin assets on Page Builder screens.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 *
	 * @return void
	 */
	public function dequeue_conflicting_assets( $hook_suffix ) {
		if ( ! $this->is_siteorigin_builder_screen( $hook_suffix ) ) {
			return;
		}

		$screen = get_current_screen();
		$should_dequeue = apply_filters( 'siteorigin_panels_admin_conflict_should_dequeue', true, $hook_suffix, $screen );
		if ( ! $should_dequeue ) {
			return;
		}

		$style_handles = apply_filters( 'siteorigin_panels_admin_conflict_style_handles', array(), $hook_suffix, $screen );
		$script_handles = apply_filters( 'siteorigin_panels_admin_conflict_script_handles', array(), $hook_suffix, $screen );

		$removed = array(
			'styles'  => array(),
			'scripts' => array(),
		);

		foreach ( $this->sanitize_handles( $style_handles ) as $handle ) {
			if ( ! $this->is_style_available_for_dequeue( $handle ) ) {
				continue;
			}

			if ( wp_style_is( $handle, 'enqueued' ) ) {
				$removed['styles'][] = $handle;
			}
			wp_dequeue_style( $handle );
		}

		foreach ( $this->sanitize_handles( $script_handles ) as $handle ) {
			if ( ! $this->is_script_available_for_dequeue( $handle ) ) {
				continue;
			}

			if ( wp_script_is( $handle, 'enqueued' ) ) {
				$removed['scripts'][] = $handle;
			}
			wp_dequeue_script( $handle );
		}

		$this->removed = $removed;
	}

	/**
	 * Check whether a style handle exists in a dequeueable state.
	 *
	 * @param string $handle Style handle.
	 *
	 * @return bool
	 */
	private function is_style_available_for_dequeue( $handle ) {
		return
			wp_style_is( $handle, 'registered' ) ||
			wp_style_is( $handle, 'enqueued' ) ||
			wp_style_is( $handle, 'queue' );
	}

	/**
	 * Check whether a script handle exists in a dequeueable state.
	 *
	 * @param string $handle Script handle.
	 *
	 * @return bool
	 */
	private function is_script_available_for_dequeue( $handle ) {
		return
			wp_script_is( $handle, 'registered' ) ||
			wp_script_is( $handle, 'enqueued' ) ||
			wp_script_is( $handle, 'queue' );
	}

	/**
	 * Check if current admin screen is a SiteOrigin builder screen.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 *
	 * @return bool
	 */
	private function is_siteorigin_builder_screen( $hook_suffix ) {
		if (
			! is_admin() ||
			! class_exists( 'SiteOrigin_Panels_Admin' ) ||
			! SiteOrigin_Panels_Admin::is_admin() ||
			! function_exists( 'get_current_screen' )
		) {
			return false;
		}

		$screen = get_current_screen();
		if ( empty( $screen ) ) {
			return false;
		}

		$screen_id = ! empty( $screen->id ) ? (string) $screen->id : '';
		$post_type = ! empty( $screen->post_type ) ? (string) $screen->post_type : '';
		$excluded_screen_matchers = apply_filters(
			'siteorigin_panels_admin_conflict_excluded_screen_matchers',
			array(),
			$hook_suffix,
			$screen
		);

		foreach ( $excluded_screen_matchers as $matcher ) {
			if (
				! is_string( $matcher ) ||
				$matcher === ''
			) {
				continue;
			}

			if (
				false !== strpos( $screen_id, $matcher ) ||
				false !== strpos( $post_type, $matcher ) ||
				false !== strpos( (string) $hook_suffix, $matcher )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize a list of potential script or style handles.
	 *
	 * @param mixed $handles Candidate handles.
	 *
	 * @return array
	 */
	private function sanitize_handles( $handles ) {
		if ( ! is_array( $handles ) ) {
			return array();
		}

		$handles = array_filter( $handles, 'is_string' );
		$handles = array_filter( $handles, 'strlen' );

		return array_values( array_unique( $handles ) );
	}
}
