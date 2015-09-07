<?php
$builder_id = uniqid();
?>

<div id="siteorigin-panels-metabox" class="siteorigin-panels-builder">
	<?php do_action('siteorigin_panels_before_interface') ?>
	<?php wp_nonce_field('save', '_sopanels_nonce') ?>

	<script type="text/javascript">
		// Create the panels_data input
		var builderId = "<?php echo esc_attr($builder_id) ?>";
		document.write( '<input name="panels_data" type="hidden" class="siteorigin-panels-data-field" id="panels-data-field-' + builderId + '" />' );
		document.getElementById('panels-data-field-<?php echo esc_attr($builder_id) ?>').value = decodeURIComponent("<?php echo rawurlencode( json_encode($panels_data) ); ?>");
	</script>

	<?php do_action('siteorigin_panels_metabox_end'); ?>
</div>