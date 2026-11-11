<?php
// Load Page Builder from another plugin directory for this wp-cli process only.
// Usage: SO_PANELS_DIR=siteorigin-panels-wt wp --require=tests/harness/with-plugin-dir.php eval-file <harness>
// Covers a site activation (active_plugins) and a network activation (active_sitewide_plugins).
$so_dir = getenv( 'SO_PANELS_DIR' );
if ( $so_dir && class_exists( 'WP_CLI' ) ) {
	$so_main = 'siteorigin-panels/siteorigin-panels.php';
	WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) use ( $so_dir, $so_main ) {
		foreach ( (array) $plugins as $i => $plugin ) {
			if ( $plugin === $so_main ) {
				$plugins[ $i ] = $so_dir . '/siteorigin-panels.php';
			}
		}
		return $plugins;
	} );
	WP_CLI::add_wp_hook( 'site_option_active_sitewide_plugins', function ( $plugins ) use ( $so_dir, $so_main ) {
		if ( is_array( $plugins ) && isset( $plugins[ $so_main ] ) ) {
			$plugins[ $so_dir . '/siteorigin-panels.php' ] = $plugins[ $so_main ];
			unset( $plugins[ $so_main ] );
		}
		return $plugins;
	} );
}
