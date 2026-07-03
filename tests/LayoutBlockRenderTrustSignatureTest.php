<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Widget stub whose update() sanitizes its content the way a real
 * capability-gated widget (e.g. WP_Widget_Custom_HTML) would. Declared as a
 * named class (rather than an anonymous class) so the file stays parseable by
 * older PHP parsers in the build toolchain.
 */
class LayoutBlockTrustSanitizingWidgetStub {
	public function update( $new, $old ) {
		$new['content'] = preg_replace(
			'/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			'',
			(string) $new['content']
		);

		return $new;
	}
}

/**
 * Widget stub whose update() mutates content in a detectable, one-way manner
 * (appends '-SANITIZED') and counts invocations, so tests can prove whether
 * sanitization ran zero, one, or two times against the same data.
 */
class LayoutBlockTrustMarkerWidgetStub {
	public $update_calls = 0;

	public function update( $new, $old ) {
		$this->update_calls++;
		$new['content'] = (string) $new['content'] . '-SANITIZED';

		return $new;
	}
}

/**
 * Regression tests for the Layout Block render-time trust signature
 * (SiteOrigin_Panels_Compat_Layout_Block::prepare_render_panels_data()).
 *
 * Properties locked by this test:
 * (a) Unsigned (or forged-signature) panels_data sent to the render path is
 *     ALWAYS strictly re-sanitized — the stored-XSS fix's invariant holds at
 *     the new prepare_render_panels_data() entry point.
 * (b) Validly-signed panels_data renders byte-identical, with NO second
 *     execution of widget update() sanitizers, regardless of the CURRENT
 *     viewer's capabilities — the trust decision is capability-independent.
 * (c) Any tampering after signing invalidates the signature and fails closed
 *     back to the strict sanitize path.
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

		// apply_filters: return the value being filtered unchanged. This also
		// covers the 'siteorigin_panels_sanitize_version' tag, which passes
		// through its 'panels:1' default — a stable, deterministic version
		// string for signing.
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

		// wp_salt(): fixed test salt — no real WP install/AUTH_KEY exists in
		// this unit context. hash_hmac()/hash_equals() are pure PHP core
		// functions and are deliberately NOT stubbed.
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );

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
			require_once dirname( __DIR__ ) . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once dirname( __DIR__ ) . '/compat/layout-block.php';
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

	private const PAYLOAD = '<img src=x onerror=alert(1)>';
	private const CLEANED = '<img src=x>';

	// --- (a) Unsigned/forged payloads must always take the strict path. ------

	public function test_unsigned_payload_takes_strict_path() {
		$stub = new LayoutBlockTrustSanitizingWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( false );

		$panels_data = array(
			'widgets' => array(
				array(
					'content'     => self::PAYLOAD,
					'panels_info' => array( 'class' => 'LayoutBlockTrustSanitizingWidget' ),
				),
			),
		);

		$result = $this->invoke( $this->layout_block(), 'prepare_render_panels_data', array( $panels_data ) );

		$this->assertSame(
			self::CLEANED,
			$result['widgets'][0]['content'],
			'A payload with no signature must be strictly re-sanitized on render.'
		);
	}

	public function test_forged_signature_takes_strict_path_and_is_stripped() {
		$stub = new LayoutBlockTrustSanitizingWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( false );

		$panels_data = array(
			'widgets' => array(
				array(
					'content'     => self::PAYLOAD,
					'panels_info' => array( 'class' => 'LayoutBlockTrustSanitizingWidget' ),
				),
			),
			'sanitize_signature' => 'forged-garbage-signature',
		);

		$result = $this->invoke( $this->layout_block(), 'prepare_render_panels_data', array( $panels_data ) );

		$this->assertSame(
			self::CLEANED,
			$result['widgets'][0]['content'],
			'A forged signature must never bypass strict re-sanitization.'
		);
		$this->assertArrayNotHasKey(
			'sanitize_signature',
			$result,
			'The strict path must strip the inbound forged signature key.'
		);
	}

	// --- (b) Validly-signed data renders unmodified, capability-independent. -

	public function test_signed_payload_renders_unmodified_without_resanitization() {
		$stub = new LayoutBlockTrustMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		// The viewer lacks unfiltered_html for BOTH the save simulation and
		// the render call: trust must be independent of the CURRENT viewer's
		// capability. This is the exact bug being fixed — previously an
		// unprivileged viewer triggered re-sanitization on every render.
		Functions\when( 'current_user_can' )->justReturn( false );

		$panels_data = array(
			'widgets' => array(
				array(
					'content'     => 'trusted-content',
					'panels_info' => array( 'class' => 'LayoutBlockTrustMarkerWidget' ),
				),
			),
		);

		$block = $this->layout_block();

		// Simulate the original save: strict sanitize, then sign — mirroring
		// render_layout_block()'s save-time branch.
		$signed = $this->invoke( $block, 'sanitize_panels_data', array( $panels_data ) );
		$this->assertSame(
			'trusted-content-SANITIZED',
			$signed['widgets'][0]['content'],
			'update() must run during the save-time sanitize.'
		);
		$this->assertSame( 1, $stub->update_calls, 'update() runs exactly once during save.' );

		$signed['sanitize_signature'] = $this->invoke( $block, 'sign_panels_data', array( $signed ) );

		// Render for an unprivileged viewer.
		$result = $this->invoke( $block, 'prepare_render_panels_data', array( $signed ) );

		$this->assertSame(
			'trusted-content-SANITIZED',
			$result['widgets'][0]['content'],
			'Signed content must render byte-identical to what was signed — no second -SANITIZED suffix.'
		);
		$this->assertSame(
			1,
			$stub->update_calls,
			'update() must NOT run again on the trusted render path.'
		);
	}

	// --- (c) Tampering invalidates the signature and fails closed. -----------

	public function test_tampered_payload_fails_verification_and_resanitizes() {
		$stub = new LayoutBlockTrustMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( false );

		$panels_data = array(
			'widgets' => array(
				array(
					'content'     => 'trusted-content',
					'panels_info' => array( 'class' => 'LayoutBlockTrustMarkerWidget' ),
				),
			),
		);

		$block = $this->layout_block();

		// Produce a validly-signed payload, as in test (b).
		$signed = $this->invoke( $block, 'sanitize_panels_data', array( $panels_data ) );
		$signed['sanitize_signature'] = $this->invoke( $block, 'sign_panels_data', array( $signed ) );
		$this->assertSame( 1, $stub->update_calls );

		// Tamper with a field WITHOUT updating the signature.
		$signed['widgets'][0]['content'] .= 'X';
		$tampered_value = $signed['widgets'][0]['content'];

		$result = $this->invoke( $block, 'prepare_render_panels_data', array( $signed ) );

		$this->assertNotSame(
			$tampered_value,
			$result['widgets'][0]['content'],
			'Tampered content must not pass through unmodified — verification must fail closed.'
		);
		$this->assertSame(
			'trusted-content-SANITIZEDX-SANITIZED',
			$result['widgets'][0]['content'],
			'The strict fallback must have re-run update() against the tampered value.'
		);
		$this->assertSame(
			2,
			$stub->update_calls,
			'update() must run a second time when verification fails.'
		);
		$this->assertArrayNotHasKey(
			'sanitize_signature',
			$result,
			'The strict path must strip the now-invalid signature key.'
		);
	}
}
