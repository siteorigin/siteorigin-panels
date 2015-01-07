<?php
$builder_id = uniqid();
?>

<div id="siteorigin-panels-metabox" class="siteorigin-panels-builder">
	<?php do_action('siteorigin_panels_before_interface') ?>
	<?php wp_nonce_field('save', '_sopanels_nonce') ?>

	<input name="panels_data" value="" type="hidden" class="siteorigin-panels-data-field" id="panels-data-field-<?php echo esc_attr($builder_id) ?>" />
	<script type="text/javascript">
		document.getElementById('panels-data-field-<?php echo esc_attr($builder_id) ?>').value = JSON.stringify( JSON.parse("<?php echo addslashes(json_encode($panels_data)) ?>") );
	</script>

	<?php do_action('siteorigin_panels_metabox_end'); ?>
</div>