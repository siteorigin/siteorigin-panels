<?php

/**
 * Get the settings
 *
 * @param string $key Only get a specific key.
 * @return mixed
 */
function siteorigin_panels_setting($key = ''){

	global $siteorigin_panels_settings;
	if( !has_action('after_setup_theme') ) {
		$siteorigin_panels_settings = false;
	}

	if( empty($settings) ){
		//
		$old_settings = get_option( 'siteorigin_panels_display', array() );
		if( !empty($old_settings) ) {
			// Get the current settings
			$current_settings = get_option('siteorigin_panels_display', array());
			$post_types = get_option('siteorigin_panels_post_types' );
			if( !empty($post_types) ) $current_settings['post-types'] = $post_types;

			update_option( 'siteorigin_panels_settings', $current_settings );
			delete_option( 'siteorigin_panels_display' );
			delete_option( 'siteorigin_panels_post_types' );
		}
		else {
			$current_settings = get_option( 'siteorigin_panels_settings', array() );
		}

		// Get the settings provided by the theme
		$theme_settings = get_theme_support('siteorigin-panels');
		if( !empty($theme_settings) ) $theme_settings = $theme_settings[0];
		else $theme_settings = array();

		$settings = wp_parse_args( $theme_settings, apply_filters( 'siteorigin_panels_settings_defaults', array(
			'home-page' => false,
			'home-page-default' => false,
			'home-template' => 'home-panels.php',
			'post-types' => array('page', 'post'),

			'bundled-widgets' => get_option( 'siteorigin_panels_is_using_bundled', false ),
			'responsive' => true,
			'mobile-width' => 780,

			'margin-bottom' => 30,
			'margin-sides' => 30,
			'affiliate-id' => apply_filters( 'siteorigin_panels_affiliate_id', false ),
			'copy-content' => true,
			'animations' => true,
			'inline-css' => true,
		) ) );
		$settings = wp_parse_args( $current_settings, $settings);

		// Filter these settings
		$settings = apply_filters('siteorigin_panels_settings', $settings);
	}

	if( !empty( $key ) ) return isset( $settings[$key] ) ? $settings[$key] : null;
	return $settings;
}

/**
 * Add the options page
 */
function siteorigin_panels_options_admin_menu() {
	add_options_page( __('SiteOrigin Page Builder', 'siteorigin-panels'), __('SiteOrigin Page Builder', 'siteorigin-panels'), 'manage_options', 'siteorigin_panels', 'siteorigin_panels_options_page' );
}
add_action( 'admin_menu', 'siteorigin_panels_options_admin_menu' );

/**
 * Display the admin page.
 */
function siteorigin_panels_options_page(){
	include plugin_dir_path(SITEORIGIN_PANELS_BASE_FILE) . '/tpl/options.php';
}

/**
 * Display the field for selecting the post types
 */
function siteorigin_panels_options_field_post_types( $panels_post_types ){
	$all_post_types = array_values( array_merge( array( 'page' => 'page', 'post' => 'post' ), get_post_types( array( '_builtin' => false ) ) ) );

	// These are post types we know we don't want to show
	$all_post_types = array_diff($all_post_types, array(
		// Meta Slider
		'ml-slider'
	) );

	foreach($all_post_types as $type){
		$info = get_post_type_object($type);
		if(empty($info->labels->name)) continue;
		$checked = in_array(
			$type,
			$panels_post_types
		);

		?>
		<label>
			<input type="checkbox" name="siteorigin_panels_post_types[<?php echo esc_attr($type) ?>]" <?php checked($checked) ?> />
			<?php echo esc_html($info->labels->name) ?>
		</label><br/>
		<?php
	}

	?><p class="description"><?php _e('Post types that will have the page builder available', 'siteorigin-panels') ?></p><?php
}

function siteorigin_panels_options_field( $id, $value, $title, $description = false ){
	?>
	<tr>
		<th scope="row"><strong><?php echo esc_html($title) ?></strong></th>
		<td>
			<?php
			switch($id) {
				case 'responsive' :
				case 'copy-content' :
				case 'animations' :
				case 'inline-css' :
				case 'bundled-widgets' :
					?><label><input type="checkbox" name="siteorigin_panels_settings[<?php echo esc_attr($id) ?>]" <?php checked($value) ?> /> <?php _e('Enabled', 'siteorigin-panels') ?></label><?php
					break;
				case 'mobile-width' :
				case 'margin-bottom' :
				case 'margin-sides' :
					?><input type="text" name="siteorigin_panels_settings[<?php echo esc_attr($id) ?>]" value="<?php echo esc_attr($value) ?>" class="small-text" /> <?php _e('px', 'siteorigin-panels') ?><?php
					break;
			}
			?>
			<p class="description"><?php echo esc_html($description) ?></p>
		</td>
	</tr>
	<?php
}

function siteorigin_panels_save_options(){
	// Lets save us some settings
	if( !current_user_can('manage_options') ) return;
	if( empty($_POST['_wpnonce']) || !wp_verify_nonce( $_POST['_wpnonce'], 'save_panels_settings' ) ) return;

	// Save the post types settings
	$post_types = isset( $_POST['siteorigin_panels_post_types'] ) ? array_keys( $_POST['siteorigin_panels_post_types'] ) : array();

	$settings = isset( $_POST['siteorigin_panels_settings'] ) ? $_POST['siteorigin_panels_settings'] : array();
	foreach($settings as $f => $v){
		switch($f){
			case 'inline-css' :
			case 'responsive' :
			case 'copy-content' :
			case 'animations' :
			case 'bundled-widgets' :
			$settings[$f] = !empty($settings[$f]);
				break;
			case 'margin-bottom' :
			case 'margin-sides' :
			case 'mobile-width' :
			$settings[$f] = intval($settings[$f]);
				break;
		}
	}

	// Checkbox settings
	$settings['responsive'] = !empty($settings['responsive']);
	$settings['copy-content'] = !empty($settings['copy-content']);
	$settings['animations'] = !empty($settings['animations']);
	$settings['inline-css'] = !empty($settings['inline-css']);
	$settings['bundled-widgets'] = !empty($settings['bundled-widgets']);

	// Post type settings
	$settings['post-types'] = $post_types;

	update_option('siteorigin_panels_settings', $settings);

	global $siteorigin_panels_settings;
	$siteorigin_panels_settings = false;
}
add_action('load-settings_page_siteorigin_panels', 'siteorigin_panels_save_options');