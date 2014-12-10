<div id="siteorigin-panels-metabox" class="siteorigin-panels-builder">
	<?php do_action('siteorigin_panels_before_interface') ?>
	<?php wp_nonce_field('save', '_sopanels_nonce') ?>

	<input name="panels_data" value="<?php echo esc_attr( json_encode( $panels_data ) ) ?>" type="hidden" class="siteorigin-panels-data-field" />

	<?php do_action('siteorigin_panels_metabox_end'); ?>
</div>