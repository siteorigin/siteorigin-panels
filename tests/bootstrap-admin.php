<?php
/**
 * Bootstrap for the copy-content parity suite.
 *
 * Loads the REAL SiteOrigin_Panels_Admin (inc/admin.php) before any test-local
 * shim can claim the class name. The default suite's AbilitiesTest defines a
 * minimal SiteOrigin_Panels_Admin stand-in for its own spying; running the
 * parity tests in their own suite with this bootstrap avoids that name clash so
 * we exercise the real copy_content_to_post() method.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
	require __DIR__ . '/../inc/admin.php';
}
