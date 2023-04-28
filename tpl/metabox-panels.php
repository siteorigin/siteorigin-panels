<div id="siteorigin-panels-metabox"
	data-builder-type="<?php echo esc_attr( $builder_type ); ?>"
	data-preview-url="<?php echo $preview_url; ?>"
	data-builder-supports="<?php echo esc_attr( json_encode( $builder_supports ) ); ?>"
	<?php if ( ! empty( $_GET['so_live_editor'] ) ) { ?>
		data-live-editor="1"
		data-live-editor-close="<?php echo siteorigin_panels_setting( 'live-editor-quick-link' ); ?>"
	<?php } ?>
	>
	<?php do_action( 'siteorigin_panels_before_interface' ); ?>
	<?php wp_nonce_field( 'save', '_sopanels_nonce' ); ?>

	<script type="text/javascript">
		( function( builderId, panelsData ){
			// Create the panels_data input
			document.write( '<input name="panels_data" type="hidden" class="siteorigin-panels-data-field" id="panels-data-field-' + builderId + '" />' );
			document.getElementById('panels-data-field-<?php echo esc_attr( $builder_id ); ?>').value = JSON.stringify( panelsData );
		} )( "<?php echo esc_attr( $builder_id ); ?>", <?php echo json_encode( $panels_data ); ?> );
	</script>

	<?php do_action( 'siteorigin_panels_metabox_end' ); ?>
</div>

<?php if ( ! empty( $preview_content ) ) { ?>
	<textarea class="siteorigin-panels-preview-content" style="display: none;"><?php echo esc_textarea( $preview_content ); ?></textarea>
<?php } ?>
