<?php
/**
 * Bootstrap for the default suite (phpunit.xml).
 *
 * Loads Patchwork BEFORE PHPUnit includes any test file. PHPUnit discovers the
 * suite alphabetically, so tests/AbilitiesTest.php — which defines wp_unslash()
 * and friends at file scope — is included first; without Patchwork already
 * initialised, those definitions are un-instrumented and Brain Monkey's
 * Functions\when( 'wp_unslash' ) in DecodePanelsDataTest throws
 * Patchwork\Exceptions\DefinedTooEarly. Loading Patchwork here means every
 * file included afterwards is instrumented and its functions are redefinable.
 *
 * Deliberately does NOT load inc/admin.php: AbilitiesTest supplies its own
 * SiteOrigin_Panels_Admin stand-in, which is why this suite cannot share
 * tests/bootstrap-admin.php (that bootstrap loads the real Admin class).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';
