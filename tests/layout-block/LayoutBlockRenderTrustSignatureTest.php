<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Guard tests for the Layout Block render entry points
 * (SiteOrigin_Panels_Compat_Layout_Block::render_layout_block() /
 * sanitize_panels_data()).
 *
 * Render is unconditionally structural — there is no render-time trust
 * marker; save-time chokepoints own markup protection. What remains locked
 * here:
 * (a) Non-array panelsData on the render path returns the graceful
 *     placeholder, never a fatal.
 * (b) sanitize_panels_data() passes a non-array input through unchanged.
 *
 * NOTE: This test is intentionally self-contained, following the conventions
 * of tests/SavePostRawFlagSanitizationTest.php: no shared base class,
 * phpunit.xml, or composer autoload exists on this branch. To keep the file
 * parseable by the build toolchain's bundled php-parser it avoids arrow
 * functions and anonymous classes; the `: void` return types on
 * setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockRenderTrustSignatureTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Minimal WP function stubs used by the code under test.
		Functions\when( '__' )->returnArg();

		// apply_filters: return the value being filtered unchanged.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		// wp_kses_post(): emulate the relevant behaviour — strip on* event
		// handler attributes carrying the XSS payload.
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $value );
			}
		);

		// wp_json_encode(): PHP's native json_encode is a faithful stand-in.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->require_classes();
	}

	protected function tearDown(): void {
		\SiteOrigin_Panels::$instance_resolver = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load inc/admin.php and compat/layout-block.php plus the stub
	 * collaborators they depend on, once per process.
	 */
	private function require_classes() {
		if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
			// Stub the SiteOrigin_Panels facade. get_widget_instance() is
			// re-pointed per-test via the static $instance_resolver closure.
			eval(
				'class SiteOrigin_Panels {'
				. ' public static $instance_resolver = null;'
				. ' public static function get_widget_instance( $class ) {'
				. '   return self::$instance_resolver ? call_user_func( self::$instance_resolver, $class ) : null;'
				. ' }'
				. '}'
			);
		}

		// SiteOrigin_Panels_Admin::single() runs the real constructor, which
		// instantiates these collaborator singletons and includes the
		// installer when SiteOrigin_Installer is absent. Stub them so the
		// constructor can run without pulling in the whole admin stack.
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
			// Presence alone stops the admin constructor including the real
			// installer bootstrap file.
			eval( 'class SiteOrigin_Installer {}' );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin', false ) ) {
			// sanitize_all() is out of scope here; identity passthrough lets
			// the tests observe process_raw_widgets()' behaviour directly.
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

		// Stubs for the WP base bits the classes reference at include time.
		if ( ! function_exists( 'add_action' ) ) {
			Functions\when( 'add_action' )->justReturn( true );
		}

		if ( ! function_exists( 'add_filter' ) ) {
			Functions\when( 'add_filter' )->justReturn( true );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/compat/layout-block.php';
		}
	}

	/**
	 * Build the Layout Block compat instance WITHOUT running its real
	 * constructor (which registers WP hooks out of scope for a unit test).
	 */
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

	// --- (a) Non-array panelsData must not fatal on the render path. ------

	public function test_non_array_panels_data_render_path_does_not_fatal() {
		$cases = array( 'malformed', 42, null, new \stdClass() );
		foreach ( $cases as $bad ) {
			$result = $this->layout_block()->render_layout_block( array( 'panelsData' => $bad ) );
			$this->assertIsString( $result ); // graceful placeholder div, no fatal
		}
	}

	// --- (b) sanitize_panels_data() passes a non-array through unchanged. ------

	public function test_non_array_panels_data_sanitize_chokepoint_returns_unchanged() {
		$result = $this->invoke( $this->layout_block(), 'sanitize_panels_data', array( 'malformed' ) );
		$this->assertSame( 'malformed', $result );
	}
}
