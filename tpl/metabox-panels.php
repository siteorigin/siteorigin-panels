<?php
global $post;
$builder_id = uniqid();
$builder_type = apply_filters( 'siteorigin_panels_post_builder_type', 'editor_attached', $post, $panels_data );
$builder_supports = apply_filters( 'siteorigin_panels_builder_supports', array(), $post, $panels_data );
?>

<div id="siteorigin-panels-metabox"
	data-builder-type="<?php echo esc_attr( $builder_type ) ?>"
	data-preview-url="<?php echo SiteOrigin_Panels::preview_url() ?>"
	data-builder-supports="<?php echo esc_attr( json_encode( $builder_supports ) ) ?>"
	<?php if( !empty( $_GET['so_live_editor'] ) ) echo 'data-live-editor="1"' ?>
	>
	<?php do_action('siteorigin_panels_before_interface') ?>
	<?php wp_nonce_field('save', '_sopanels_nonce') ?>

	<script type="text/javascript">
		( function( builderId, panelsData ){
			// Create the panels_data input
			document.write( '<input name="panels_data" type="hidden" class="siteorigin-panels-data-field" id="panels-data-field-' + builderId + '" />' );
			document.getElementById('panels-data-field-<?php echo esc_attr($builder_id) ?>').value = JSON.stringify( panelsData );
		} )( "<?php echo esc_attr($builder_id) ?>", <?php echo json_encode( $panels_data ); ?> );
	</script>

	<?php do_action('siteorigin_panels_metabox_end'); ?>
</div>
