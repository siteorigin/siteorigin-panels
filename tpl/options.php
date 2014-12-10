<?php $settings = siteorigin_panels_setting(); ?>

<div class="wrap">
	<div id="icon-options-general" class="icon32"><br></div>
	<h2><?php _e('SiteOrigin Page Builder', 'siteorigin-panels') ?></h2>

	<form action="<?php echo admin_url( 'options-general.php?page=siteorigin_panels' ) ?>" method="POST">

		<pre><?php //var_dump($settings) ?></pre>

		<h3><?php _e('General', 'siteorigin-panels') ?></h3>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><strong><?php _e('Post Types', 'siteorigin-panels') ?></strong></th>
					<td>
						<?php siteorigin_panels_options_field_post_types($settings['post-types']) ?>
					</td>
				</tr>

				<?php
				siteorigin_panels_options_field(
					'copy-content',
					$settings['copy-content'],
					__('Copy Content', 'siteorigin-panels'),
					__('Copy content from Page Builder into the standard content editor.', 'siteorigin-panels')
				);

				siteorigin_panels_options_field(
					'animations',
					$settings['animations'],
					__('Animations', 'siteorigin-panels'),
					__('Disable animations for improved performance.', 'siteorigin-panels')
				);

				siteorigin_panels_options_field(
					'bundled-widgets',
					$settings['bundled-widgets'],
					__('Bundled Widgets', 'siteorigin-panels'),
					__('Include the bundled widgets.', 'siteorigin-panels')
				);

				?>

			</tbody>
		</table>

		<h3><?php _e('Display', 'siteorigin-panels') ?></h3>
		<table class="form-table">
			<tbody>

				<?php

				siteorigin_panels_options_field(
					'responsive',
					$settings['responsive'],
					__('Responsive Layout', 'siteorigin-panels'),
					__('Should the layout collapse for mobile devices.', 'siteorigin-panels')
				);

				siteorigin_panels_options_field(
					'mobile-width',
					$settings['mobile-width'],
					__('Mobile Width', 'siteorigin-panels')
				);

				siteorigin_panels_options_field(
					'margin-bottom',
					$settings['margin-bottom'],
					__('Row Bottom Margin', 'siteorigin-panels')
				);

				siteorigin_panels_options_field(
					'margin-sides',
					$settings['margin-sides'],
					__('Cell Side Margins', 'siteorigin-panels')
				);

				siteorigin_panels_options_field(
					'inline-css',
					$settings['inline-css'],
					__('Inline CSS', 'siteorigin-panels')
				);

				?>

			</tbody>
		</table>


		<?php wp_nonce_field('save_panels_settings'); ?>
		<p class="submit">
			<input type="submit" class="button-primary" value="<?php esc_attr_e('Save Settings', 'siteorigin-panels') ?>"/>
		</p>

	</form>
</div>