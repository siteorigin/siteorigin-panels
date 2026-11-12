<?php
/**
 * Plugin Name: Panels E2E pane widget
 * Description: Test fixtures for empty and failing builder panes. A stand-in for third-party tab and accordion widgets that hold a Page Builder layout per pane and JSON-encode an empty pane as `""`, such as Tabs Widget for Page Builder by ProteusThemes; a widget class whose builder model throws while the builder constructs it; and a prebuilt layout holding a Layout Builder widget whose inner layout is `""`.
 */

/**
 * Builds its form the way Tabs Widget for Page Builder by ProteusThemes does:
 * each pane's stored layout is JSON-encoded into a hidden `panels-data` field,
 * so an empty pane (stored as `''`) renders `""`, and a new pane starts at `""`.
 *
 * `update()` stores a pane's decoded layout, or `''` when it decodes to nothing.
 * A pane whose field is missing from the submission keeps its old layout in
 * "keep-old" mode and becomes `''` in "default-empty" mode, the way the
 * ProteusThemes widget treats it.
 */
class Panels_E2E_Pane_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'panels_e2e_pane',
			'Panels E2E Pane',
			array( 'description' => 'Panes that each hold a layout, for the builder tests.' )
		);
	}

	public function widget( $args, $instance ) {
		$items = isset( $instance['items'] ) && is_array( $instance['items'] ) ? $instance['items'] : array();

		echo '<div class="panels-e2e-pane-widget">';
		foreach ( $items as $key => $item ) {
			echo '<div class="panels-e2e-pane-output">';
			if ( ! empty( $item['panels_data'] ) && is_array( $item['panels_data'] ) ) {
				echo siteorigin_panels_render( 'w' . $this->id . '-' . $key, true, $item['panels_data'] );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	public function update( $new_instance, $old_instance ) {
		$mode      = isset( $new_instance['mode'] ) && $new_instance['mode'] === 'keep-old' ? 'keep-old' : 'default-empty';
		$old_items = isset( $old_instance['items'] ) && is_array( $old_instance['items'] ) ? $old_instance['items'] : array();
		$items     = array();

		foreach ( isset( $new_instance['items'] ) && is_array( $new_instance['items'] ) ? $new_instance['items'] : array() as $key => $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'panels_data', $item ) ) {
				$panels_data = $mode === 'keep-old' && isset( $old_items[ $key ]['panels_data'] ) ? $old_items[ $key ]['panels_data'] : '';
			} else {
				$panels_data = is_string( $item['panels_data'] ) ? json_decode( $item['panels_data'], true ) : $item['panels_data'];

				if ( empty( $panels_data ) || ! is_array( $panels_data ) ) {
					$panels_data = '';
				} elseif ( ! empty( $panels_data['widgets'] ) ) {
					$old_widgets = isset( $old_items[ $key ]['panels_data']['widgets'] ) ? $old_items[ $key ]['panels_data']['widgets'] : false;
					$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], $old_widgets );
				}
			}

			$items[ $key ] = array( 'panels_data' => $panels_data );
		}

		return array(
			'mode'  => $mode,
			'items' => $items,
		);
	}

	public function form( $instance ) {
		$items   = isset( $instance['items'] ) && is_array( $instance['items'] ) ? $instance['items'] : array();
		$mode    = isset( $instance['mode'] ) && $instance['mode'] === 'keep-old' ? 'keep-old' : 'default-empty';
		$form_id = uniqid( 'panels-e2e-pane-' );
		$next    = $items ? max( array_keys( $items ) ) + 1 : 0;
		?>
		<div class="panels-e2e-pane-form" id="<?php echo esc_attr( $form_id ); ?>" data-next="<?php echo (int) $next; ?>">
			<input type="hidden" name="<?php echo esc_attr( $this->get_field_name( 'mode' ) ); ?>" value="<?php echo esc_attr( $mode ); ?>" />
			<div class="panels-e2e-panes">
				<?php
				foreach ( $items as $key => $item ) {
					$this->pane( $key, wp_json_encode( isset( $item['panels_data'] ) ? $item['panels_data'] : '' ) );
				}
				?>
			</div>
			<button type="button" class="button panels-e2e-add-pane">Add pane</button>
			<script type="text/template" class="panels-e2e-pane-template"><?php $this->pane( '{{key}}', '""' ); ?></script>
		</div>
		<script type="text/javascript">
			( function ( $ ) {
				var $form = $( '#<?php echo esc_js( $form_id ); ?>' );
				if ( typeof $.fn.soPanelsSetupBuilderWidget === 'undefined' ) {
					return;
				}
				$form.find( '.panels-e2e-panes .siteorigin-page-builder-widget' ).soPanelsSetupBuilderWidget();
				$form.find( '.panels-e2e-add-pane' ).on( 'click', function ( e ) {
					e.preventDefault();
					var key = parseInt( $form.attr( 'data-next' ), 10 );
					$form.attr( 'data-next', key + 1 );
					var $pane = $( $form.find( '.panels-e2e-pane-template' ).html().replace( /\{\{key\}\}/g, key ) );
					$form.find( '.panels-e2e-panes' ).append( $pane );
					$pane.find( '.siteorigin-page-builder-widget' ).soPanelsSetupBuilderWidget();
				} );
			} )( jQuery );
		</script>
		<?php
	}

	/**
	 * One pane: a key field, so the item is submitted even when its layout
	 * field is disabled, and the layout field holding `$value` as given.
	 */
	private function pane( $key, $value ) {
		$base = $this->get_field_name( 'items' ) . '[' . $key . ']';
		?>
		<div class="panels-e2e-pane" data-key="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base . '[key]' ); ?>" value="<?php echo esc_attr( $key ); ?>" />
			<div class="siteorigin-page-builder-widget siteorigin-panels-builder" data-type="layout_widget">
				<p><a href="#" class="button-secondary siteorigin-panels-display-builder">Open Builder</a></p>
				<input type="hidden" data-panels-filter="json_parse" class="panels-data" value="<?php echo esc_attr( $value ); ?>" name="<?php echo esc_attr( $base . '[panels_data]' ); ?>" />
			</div>
		</div>
		<?php
	}
}

/**
 * The builder model of this widget throws while the builder constructs it,
 * the shape of a layout that fails part-way through loading.
 */
class Panels_E2E_Boom_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct( 'panels_e2e_boom', 'Panels E2E Boom', array( 'description' => 'Fails while the builder loads it.' ) );
	}

	public function widget( $args, $instance ) {
		echo '<div class="panels-e2e-boom">' . esc_html( isset( $instance['text'] ) ? $instance['text'] : '' ) . '</div>';
	}

	public function update( $new_instance, $old_instance ) {
		return array( 'text' => isset( $new_instance['text'] ) ? (string) $new_instance['text'] : '' );
	}

	public function form( $instance ) {
		echo '<p>Boom</p>';
	}
}

add_action(
	'widgets_init',
	function () {
		register_widget( 'Panels_E2E_Pane_Widget' );
		register_widget( 'Panels_E2E_Boom_Widget' );
	}
);

add_action(
	'admin_print_footer_scripts',
	function () {
		if ( ! wp_script_is( 'so-panels-admin', 'done' ) ) {
			return;
		}
		?>
		<script type="text/javascript">
			( function () {
				if ( ! window.panels || ! panels.model || ! panels.model.widget ) {
					return;
				}
				var initialize = panels.model.widget.prototype.initialize;
				panels.model.widget.prototype.initialize = function () {
					if ( this.get( 'class' ) === 'Panels_E2E_Boom_Widget' ) {
						throw new TypeError( "Cannot read properties of null (reading 'disable_widget')" );
					}

					return initialize.apply( this, arguments );
				};
			} )();
		</script>
		<?php
	},
	100
);

// A prebuilt layout holding a Layout Builder widget whose inner layout is `""`.
add_filter(
	'siteorigin_panels_prebuilt_layouts',
	function ( $layouts ) {
		$layouts['panels-e2e-nested-empty'] = array(
			'name'        => 'Panels E2E nested empty',
			'description' => 'A Layout Builder widget whose inner layout is an empty string.',
			'widgets'     => array(
				array(
					'panels_data' => '""',
					'builder_id'  => 'panelse2enested',
					'panels_info' => array(
						'class'     => 'SiteOrigin_Panels_Widgets_Layout',
						'raw'       => false,
						'grid'      => 0,
						'cell'      => 0,
						'id'        => 0,
						'widget_id' => 'panels-e2e-nested-empty',
						'style'     => array(),
					),
				),
			),
			'grids'       => array( array( 'cells' => 1, 'style' => array() ) ),
			'grid_cells'  => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
		);

		return $layouts;
	}
);
