<?php
/**
 * When the Livemesh SiteOrigin Widgets settings are updated,
 * clear the Page Builder widget cache.
 *
 * This callback is triggered whenever the lsow_settings option is updated.
 * This will only happen after a setting is actually changed.
 *
 * @param mixed  $old_value The previous value of the lsow_settings option.
 * @param mixed  $value     The new value being saved to the lsow_settings option.
 * @param string $option    The name of the option being updated (should be 'lsow_settings').
*/
function siteorigin_panels_lsow_settings_update( $old_value, $value, $option ) {
	delete_transient( 'siteorigin_panels_widgets' );
	delete_transient( 'siteorigin_panels_widget_dialog_tabs' );
}
add_action( 'update_option_lsow_settings', 'siteorigin_panels_lsow_settings_update', 10, 3 );
