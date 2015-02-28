<div class="wrap" id="panels-settings-page">
	<div class="settings-banner">
		<span class="icon">
			<img src="<?php echo plugin_dir_url(__FILE__) ?>../images/icon-layer.png" class="layer-3" />
			<img src="<?php echo plugin_dir_url(__FILE__) ?>../images/icon-layer.png" class="layer-2" />
			<img src="<?php echo plugin_dir_url(__FILE__) ?>../images/icon-layer.png" class="layer-1" />
		</span>
		<h1><?php _e('SiteOrigin Page Builder', 'siteorigin-panels') ?></h1>
	</div>

	<ul class="settings-nav">
		<?php
		foreach($settings_fields as $section_id => $section) {
			?><li><a href="#<?php echo esc_attr( $section_id ) ?>"><?php echo esc_html( $section['title'] ) ?></a></li><?php
		}
		?>
	</ul>

	<div id="panels-settings-sections">
		<?php
		foreach($settings_fields as $section_id => $section) {
			?>
			<div id="panels-settings-section-<?php echo esc_attr($section_id) ?>" class="panels-settings-section">
				<table class="form-table">
					<tbody>
						<?php foreach( $section['fields'] as $field_id => $field ) : ?>
							<tr>
								<th scope="row"><label><?php echo esc_html($field['label']) ?></label></th>
								<td>
									<?php
									$this->display_field( $field_id, $field );
									if( !empty($field['description']) ) {
										?>
										<small class="description">
											<?php
											echo wp_kses( $field['description'], array(
												'a' => array(
													'href' => array(),
													'title' => array()
												),
												'em' => array(),
												'strong' => array(),
											) );
											?>
										</small>
										<?php
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
		?>
	</div>

	<div class="submit">
		<input type="submit" value="<?php _e('Save Settings', 'siteorigin-panels') ?>" class="button-primary" />
	</div>

</div>