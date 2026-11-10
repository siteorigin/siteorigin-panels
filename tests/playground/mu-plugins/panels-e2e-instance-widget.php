<?php
/**
 * Plugin Name: Page Builder instance widget e2e helper
 * Description: Test-only must-use plugin. Never shipped (the release build excludes tests/).
 *
 * A namespaced widget registered as an object, the way Polylang 3.9 registers its
 * Language switcher. WordPress keys such a widget by an id that changes between
 * requests, so Page Builder must list and store it under its class. The class is
 * namespaced so its name carries backslashes through the editor, save and render.
 * Its text field shares the class of the builder text widget's field, so the
 * builder helpers can fill it.
 */

namespace Panels_E2E;

class Instance_Widget extends \WP_Widget {
	public function __construct() {
		parent::__construct(
			'panels_e2e_instance',
			'Panels E2E Instance',
			array( 'description' => 'Registered as an object, for the builder tests.' )
		);
	}

	public function widget( $args, $instance ) {
		echo '<div class="panels-e2e-instance">' . esc_html( isset( $instance['text'] ) ? $instance['text'] : '' ) . '</div>';
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
		register_widget( new Instance_Widget() );
	}
);
