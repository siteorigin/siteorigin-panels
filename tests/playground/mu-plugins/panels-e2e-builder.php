<?php
/**
 * Plugin Name: Page Builder builder e2e helpers
 * Description: Test-only must-use plugin for the builder browser tests. Never shipped (the release build excludes tests/).
 *
 * - A text widget with one text field, so a test can add, edit and find a widget by its text.
 * - A widget area printed in the footer when it holds widgets.
 * - Option switches, all off by default: the custom home page setting, and the classic widgets screen.
 * - REST routes (manage_options only) to read and write an allow-listed option and to seed a layout.
 */

/**
 * Text widget for the builder tests.
 */
class Panels_E2E_Text_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'panels_e2e_text',
			'Panels E2E Text',
			array( 'description' => 'One text field, for the builder tests.' )
		);
	}

	public function widget( $args, $instance ) {
		echo '<div class="panels-e2e-text">' . esc_html( isset( $instance['text'] ) ? $instance['text'] : '' ) . '</div>';
	}

	public function update( $new_instance, $old_instance ) {
		return array( 'text' => isset( $new_instance['text'] ) ? (string) $new_instance['text'] : '' );
	}

	public function form( $instance ) {
		$text = isset( $instance['text'] ) ? $instance['text'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>">Text</label>
			<input
				class="widefat panels-e2e-text-field"
				type="text"
				id="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'text' ) ); ?>"
				value="<?php echo esc_attr( $text ); ?>"
			/>
		</p>
		<?php
	}
}

add_action(
	'widgets_init',
	function () {
		register_widget( 'Panels_E2E_Text_Widget' );
		register_sidebar(
			array(
				'id'   => 'panels-e2e-sidebar',
				'name' => 'Panels E2E Sidebar',
			)
		);
	}
);

add_action(
	'wp_footer',
	function () {
		if ( is_active_sidebar( 'panels-e2e-sidebar' ) ) {
			echo '<div id="panels-e2e-sidebar">';
			dynamic_sidebar( 'panels-e2e-sidebar' );
			echo '</div>';
		}
	}
);

add_filter(
	'siteorigin_panels_settings',
	function ( $settings ) {
		if ( get_option( 'panels_e2e_home_page' ) ) {
			$settings['home-page'] = true;
		}

		return $settings;
	}
);

add_filter(
	'use_widgets_block_editor',
	function ( $use ) {
		return get_option( 'panels_e2e_classic_widgets' ) ? false : $use;
	}
);

add_action(
	'rest_api_init',
	function () {
		$permission = function () {
			return current_user_can( 'manage_options' );
		};

		$allowed = array(
			'panels_e2e_home_page',
			'panels_e2e_classic_widgets',
			'sidebars_widgets',
			'widget_siteorigin-panels-builder',
			'widget_block',
			'show_on_front',
			'page_on_front',
			'siteorigin_panels_home_page_id',
		);

		register_rest_route(
			'panels-e2e/v1',
			'/ui/option',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $permission,
					'callback'            => function ( WP_REST_Request $request ) use ( $allowed ) {
						$name = (string) $request->get_param( 'name' );

						if ( ! in_array( $name, $allowed, true ) ) {
							return new WP_Error( 'panels_e2e_option', 'Option not allowed.', array( 'status' => 400 ) );
						}

						wp_cache_delete( $name, 'options' );
						wp_cache_delete( 'alloptions', 'options' );

						return array(
							'name'   => $name,
							'exists' => get_option( $name, null ) !== null,
							'value'  => get_option( $name, null ),
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $permission,
					'callback'            => function ( WP_REST_Request $request ) use ( $allowed ) {
						$name = (string) $request->get_param( 'name' );

						if ( ! in_array( $name, $allowed, true ) ) {
							return new WP_Error( 'panels_e2e_option', 'Option not allowed.', array( 'status' => 400 ) );
						}

						// exists: false restores an option that did not exist before the test.
						if ( $request->get_param( 'exists' ) === false ) {
							delete_option( $name );
						} else {
							update_option( $name, $request->get_param( 'value' ) );
						}

						return array(
							'name'   => $name,
							'exists' => get_option( $name, null ) !== null,
							'value'  => get_option( $name, null ),
						);
					},
				),
			)
		);

		register_rest_route(
			'panels-e2e/v1',
			'/ui/seed-layout',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => function ( WP_REST_Request $request ) {
					$post_id     = (int) $request->get_param( 'post_id' );
					$panels_data = $request->get_param( 'panels_data' );

					if ( ! get_post( $post_id ) || ! is_array( $panels_data ) ) {
						return new WP_Error( 'panels_e2e_seed', 'A post ID and a layout are required.', array( 'status' => 400 ) );
					}

					update_post_meta( $post_id, 'panels_data', wp_slash( $panels_data ) );

					return array( 'post_id' => $post_id );
				},
			)
		);
	}
);
