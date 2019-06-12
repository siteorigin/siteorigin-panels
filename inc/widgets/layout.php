<?php

/**
 * This widget give you the full Page Builder interface inside a widget. Fully nestable.
 *
 * Class SiteOrigin_Panels_Widgets_Builder
 */
class SiteOrigin_Panels_Widgets_Layout extends WP_Widget {
	function __construct() {
		parent::__construct(
			'siteorigin-panels-builder',
			// TRANSLATORS: This is the name of a widget
			__( 'Layout Builder', 'siteorigin-panels' ),
			array(
				'description' => __( 'A complete SiteOrigin Page Builder layout as a widget.', 'siteorigin-panels' ),
				'panels_title' => false,
			),
			array(
			)
		);
	}
	
	function widget($args, $instance) {
		if( empty($instance['panels_data']) ) return;
		
		if( is_string( $instance['panels_data'] ) ) {
			$instance['panels_data'] = json_decode( $instance['panels_data'], true );
		}
		if(empty($instance['panels_data']['widgets'])) return;
		
		if( ! empty( $instance['panels_data']['widgets'] ) ) {
			foreach( $instance['panels_data']['widgets'] as & $widget ) {
				$widget['panels_info']['class'] = str_replace( '&#92;', '\\', $widget['panels_info']['class'] );
			}
		}
		
		if( empty( $instance['builder_id'] ) ) $instance['builder_id'] = uniqid();
		
		echo $args['before_widget'];
		$is_content_render = ! empty( $GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] ) &&
                             siteorigin_panels_setting( 'copy-styles' );
		$is_preview_render = ! empty( $GLOBALS['SITEORIGIN_PANELS_PREVIEW_RENDER'] );

		echo SiteOrigin_Panels::renderer()->render(
		        'w'.$instance['builder_id'],
                true,
                $instance['panels_data'],
                $layout_data,
                $is_content_render || $is_preview_render
        );
		echo $args['after_widget'];
	}
	
	function update($new, $old) {
		$new['builder_id'] = uniqid();
		
		if( is_string($new['panels_data']) && ! empty( $new['panels_data'] ) ) {
			// This is still in a string format, so we'll convert it to an array for sanitization
			$new['panels_data'] = json_decode( $new['panels_data'], true );
		}
		
		if ( ! empty( $new['panels_data'] ) ) {
			if ( ! empty( $new['panels_data']['widgets'] ) ) {
				$new['panels_data']['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets(
					$new['panels_data']['widgets'],
					! empty( $old['panels_data']['widgets'] ) ? $old['panels_data']['widgets'] : false
				);
				foreach( $new['panels_data']['widgets'] as & $widget ) {
					$widget['panels_info']['class'] = str_replace( '\\', '&#92;', $widget['panels_info']['class'] );
				}
			}
			
			$new['panels_data'] = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $new['panels_data'] );
		}
		
		return $new;
	}
	
	function form( $instance ){
		
		if ( ! is_admin() ) {
			?>
			<p>
				<?php _e( 'This widget can currently only be used in the WordPress admin interface.', 'siteorigin-panels' ) ?>
			</p>
			<?php
			return;
		}
		
		$instance = wp_parse_args($instance, array(
			'panels_data' => '',
			'builder_id' => uniqid(),
		) );
		$form_id = uniqid();
		
		if( ! empty( $instance['panels_data']['widgets'] ) ) {
			foreach( $instance['panels_data']['widgets'] as & $widget ) {
				$widget['panels_info']['class'] = str_replace( '&#92;', '\\', $widget['panels_info']['class'] );
			}
		}
		
		if( ! is_string( $instance['panels_data'] ) ) {
			$instance['panels_data'] = json_encode( $instance['panels_data'] );
		}
		
		?>
		<div class="siteorigin-page-builder-widget" id="siteorigin-page-builder-widget-<?php echo esc_attr( $form_id ) ?>" data-builder-id="<?php echo esc_attr( $form_id ) ?>" data-type="layout_widget">
			<p>
				<button class="button-secondary siteorigin-panels-display-builder" ><?php _e('Open Builder', 'siteorigin-panels') ?></button>
			</p>
			
			<input type="hidden" data-panels-filter="json_parse" value="" class="panels-data" name="<?php echo $this->get_field_name('panels_data') ?>" id="<?php echo $this->get_field_id('panels_data') ?>" />
			
			<script type="text/javascript">
				( function( panelsData ){
					// Create the panels_data input
					document.getElementById('<?php echo $this->get_field_id('panels_data') ?>').value = JSON.stringify( panelsData );
				} )( <?php echo $instance['panels_data']; ?> );
			</script>
			
			<input type="hidden" value="<?php echo esc_attr( $instance['builder_id'] ) ?>" name="<?php echo $this->get_field_name('builder_id') ?>" />
		</div>
		<script type="text/javascript">
			if(
				typeof jQuery.fn.soPanelsSetupBuilderWidget != 'undefined' &&
				( ! jQuery('body').hasClass('wp-customizer') || jQuery( "#siteorigin-page-builder-widget-<?php echo esc_attr( $form_id ) ?>").closest( '.panel-dialog' ).length )
			) {
				jQuery( "#siteorigin-page-builder-widget-<?php echo esc_attr( $form_id ) ?>").soPanelsSetupBuilderWidget();
			}
		</script>
		<?php
	}
	
}
