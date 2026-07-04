<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/*
 * Define the shared global `SiteOrigin_Panels` facade stub at FILE LOAD time.
 * PHPUnit loads every test file while building the suite, BEFORE any test
 * executes, whereas tests/LayoutBlockRenderTrustSignatureTest.php only evals
 * its (smaller) facade inside require_classes() at setUp() time. Defining the
 * superset here therefore wins deterministically in combined runs regardless
 * of execution order, and the class_exists guard keeps coexistence a no-op
 * when several files carrying this identical definition are loaded together.
 * The superset adds static renderer() support, required by tests that drive
 * the real save path through render_and_restore_post_globals().
 */
if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
	eval(
		'class SiteOrigin_Panels {'
		. ' public static $instance_resolver = null;'
		. ' public static $renderer = null;'
		. ' public static function get_widget_instance( $class ) {'
		. '   return self::$instance_resolver ? call_user_func( self::$instance_resolver, $class ) : null;'
		. ' }'
		. ' public static function renderer() {'
		. '   return self::$renderer;'
		. ' }'
		. '}'
	);
}

/**
 * Regression tests for the wp_json_encode() failure guard in
 * sign_panels_data() / verify_panels_data().
 *
 * wp_json_encode() can return false (malformed UTF-8, resource refs, depth
 * exceeded). Before the guard, that false silently coerced to '' inside the
 * HMAC input concatenation, producing a "valid-looking" signature over
 * "$version|" not tied to the real content; and verify_panels_data() passing
 * a false computed signature into hash_equals() would throw a PHP 8 TypeError
 * (an uncaught fatal on the render path). These tests lock the fail-closed
 * behavior for both.
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockSignatureEncodeFailureTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Minimal WP function stubs used by sign/verify. wp_json_encode() is
		// deliberately NOT stubbed here — each test stubs it per-case.
		Functions\when( '__' )->returnArg();

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );

		$this->require_classes();
	}

	protected function tearDown(): void {
		\SiteOrigin_Panels::$instance_resolver = null;
		\SiteOrigin_Panels::$renderer = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load inc/admin.php and compat/layout-block.php plus the stub
	 * collaborators they depend on, once per process. The SiteOrigin_Panels
	 * facade itself is defined at file-load time above.
	 */
	private function require_classes() {
		$collaborators = array(
			'SiteOrigin_Panels_Admin_Widget_Dialog',
			'SiteOrigin_Panels_Admin_Widgets_Bundle',
			'SiteOrigin_Panels_Admin_Layouts',
			'SiteOrigin_Panels_Admin_Dashboard',
		);

		foreach ( $collaborators as $collaborator ) {
			if ( ! class_exists( $collaborator, false ) ) {
				eval(
					'class ' . $collaborator . ' {'
					. ' public static function single() {'
					. '   static $single;'
					. '   return empty( $single ) ? $single = new self() : $single;'
					. ' }'
					. '}'
				);
			}
		}

		if ( ! class_exists( 'SiteOrigin_Installer', false ) ) {
			eval( 'class SiteOrigin_Installer {}' );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin', false ) ) {
			eval(
				'class SiteOrigin_Panels_Styles_Admin {'
				. ' public static function single() {'
				. '   static $single;'
				. '   return empty( $single ) ? $single = new self() : $single;'
				. ' }'
				. ' public function sanitize_all( $panels_data ) {'
				. '   return $panels_data;'
				. ' }'
				. '}'
			);
		}

		if ( ! function_exists( 'add_action' ) ) {
			Functions\when( 'add_action' )->justReturn( true );
		}

		if ( ! function_exists( 'add_filter' ) ) {
			Functions\when( 'add_filter' )->justReturn( true );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once dirname( __DIR__ ) . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once dirname( __DIR__ ) . '/compat/layout-block.php';
		}
	}

	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Invoke a private method on the Layout Block compat instance.
	 */
	private function invoke( $object, $method, array $args ) {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	private function panels_data_fixture() {
		return array(
			'widgets' => array(
				array(
					'content'     => 'encode-failure-content',
					'panels_info' => array( 'class' => 'SomeWidget' ),
				),
			),
		);
	}

	// --- (a) sign_panels_data() fails closed on wp_json_encode() failure. ----

	public function test_sign_returns_false_when_wp_json_encode_fails() {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$result = $this->invoke( $this->layout_block(), 'sign_panels_data', array( $this->panels_data_fixture() ) );

		// hash_hmac() is a pure PHP core function (not stubbable via Brain
		// Monkey) and ALWAYS returns a string — so a strict false here is
		// structural proof the guard returned before hash_hmac() was reached,
		// i.e. no signature was computed over the coerced-empty "$version|"
		// input.
		$this->assertFalse( $result, 'sign_panels_data() must return false, not a signature over empty input.' );
		$this->assertIsNotString( $result );
	}

	// --- (b) verify_panels_data() fails closed, without a PHP 8 TypeError. ---

	public function test_verify_returns_false_not_typeerror_when_wp_json_encode_fails() {
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$panels_data = $this->panels_data_fixture();
		// A plausible, well-formed signature string: the failure under test is
		// the COMPUTED side returning false, not the stored side being absent
		// (which would take the 'missing' branch before ever signing).
		$panels_data['sanitize_signature'] = str_repeat( 'ab', 32 );

		// Without the is_string() guard on the computed signature, this call
		// would throw TypeError from hash_equals( false, ... ) on PHP 8 — the
		// assertion below only being reached at all proves the guard works;
		// the false return proves it fails closed into the 'invalid' branch.
		$result = $this->invoke( $this->layout_block(), 'verify_panels_data', array( $panels_data ) );

		$this->assertFalse( $result, 'An unverifiable signature must fail closed, exactly like an invalid one.' );
	}

	// --- (c) Control: the guard does not interfere with the normal path. -----

	public function test_sign_and_verify_unaffected_when_wp_json_encode_succeeds() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$block = $this->layout_block();
		$panels_data = $this->panels_data_fixture();

		$signature = $this->invoke( $block, 'sign_panels_data', array( $panels_data ) );

		$this->assertIsString( $signature );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			$signature,
			'Normal-path signing must still produce an HMAC-SHA256 hex digest.'
		);

		$panels_data['sanitize_signature'] = $signature;
		$this->assertTrue(
			$this->invoke( $block, 'verify_panels_data', array( $panels_data ) ),
			'Normal-path verification must still succeed against a fresh signature.'
		);
	}
}
