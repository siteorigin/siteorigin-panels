<?php
// Load Page Builder from another plugin directory for this wp-cli process only.
// Usage: SO_PANELS_DIR=siteorigin-panels-wt wp --require=tests/harness/with-plugin-dir.php eval-file <harness>
$so_dir = getenv( 'SO_PANELS_DIR' );
if ( $so_dir && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) use ( $so_dir ) {
		foreach ( (array) $plugins as $i => $plugin ) {
			if ( $plugin === 'siteorigin-panels/siteorigin-panels.php' ) {
				$plugins[ $i ] = $so_dir . '/siteorigin-panels.php';
			}
		}
		return $plugins;
	} );
}
