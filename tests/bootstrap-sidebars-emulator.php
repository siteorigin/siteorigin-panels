<?php
/**
 * Bootstrap for the sidebars-emulator suite.
 *
 * The default suite's AbilitiesTest defines a SiteOrigin_Panels_Sidebars_Emulator
 * stand-in for its own spying, so the real class cannot load there. This suite
 * runs on its own so tests can exercise the real register_widgets() method.
 *
 * The class file is required from each test's setUp(), after Brain Monkey has
 * supplied add_action() and add_filter(): the file registers its hooks at load.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
