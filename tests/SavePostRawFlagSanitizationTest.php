<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Regression test locking the stored-XSS fix in
 * SiteOrigin_Panels_Admin::process_raw_widgets().
 *
 * The client-controlled `raw` flag (and the `$force` argument) must NOT decide
 * whether a widget's update() / sanitization runs. A widget whose class resolves
 * to one with an update() method must ALWAYS be passed through update(), and a
 * widget whose class does NOT resolve must be wp_kses_post()'d for users lacking
 * the `unfiltered_html` capability.
 *
 * NOTE: This test is intentionally self-contained. The shared SiteOriginTests
 * base class / phpunit.xml / composer PSR-4 autoload referenced by the plan live
 * on the feature/ai-exposure-phase1-tests branch and are NOT present on this
 * branch. See the "Open question for next agent" note in docs/plans/current.md.
 */
class SavePostRawFlagSanitizationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Minimal WP function stubs used by process_raw_widgets().
		Functions\when( '__' )->returnArg();

		// apply_filters: return the value being filtered unchanged so the test
		// observes the raw output of process_raw_widgets() itself.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		// wp_kses_post(): emulate the relevant behaviour — strip the on* event
		// handler attribute that carries the XSS payload. This is enough to prove
		// the fallback branch actually sanitized.
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $value );
			}
		);

		$this->require_admin_class();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load inc/admin.php and the SiteOrigin_Panels stub it depends on once.
	 */
	private function require_admin_class() {
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

		// Stubs for the WP base bits inc/admin.php references at include time.
		if ( ! function_exists( 'add_action' ) ) {
			Functions\when( 'add_action' )->justReturn( true );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once dirname( __DIR__ ) . '/inc/admin.php';
		}
	}

	private function admin() {
		// process_raw_widgets() is a plain instance method that does not rely on
		// constructor state. The real constructor instantiates several admin
		// collaborator singletons (Widget_Dialog, etc.) that are out of scope
		// here, so create the object WITHOUT invoking the constructor.
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Admin::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * A widget stub whose update() sanitizes its content the way a real
	 * capability-gated widget (e.g. WP_Widget_Custom_HTML) would.
	 */
	private function sanitizing_widget_stub() {
		return new class() {
			public function update( $new, $old ) {
				$new['content'] = preg_replace(
					'/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
					'',
					(string) $new['content']
				);

				return $new;
			}
		};
	}

	private const PAYLOAD = '<img src=x onerror=alert(1)>';
	private const CLEANED = '<img src=x>';

	// --- Core defect: no `raw` key, $force = false, must still sanitize. -----

	public function test_widget_without_raw_flag_is_still_updated() {
		\SiteOrigin_Panels::$instance_resolver = fn() => $this->sanitizing_widget_stub();
		Functions\when( 'current_user_can' )->justReturn( false );

		$widgets = array(
			array(
				'content'     => self::PAYLOAD,
				'panels_info' => array( 'class' => 'WP_Widget_Custom_HTML' ),
			),
		);

		$result = $this->admin()->process_raw_widgets( $widgets, array(), false, false );

		$this->assertSame( self::CLEANED, $result[0]['content'], 'update() must run even when the raw flag is absent.' );
		$this->assertArrayNotHasKey( 'raw', $result[0]['panels_info'] );
	}

	public function test_raw_false_is_ignored_and_widget_still_updated() {
		\SiteOrigin_Panels::$instance_resolver = fn() => $this->sanitizing_widget_stub();
		Functions\when( 'current_user_can' )->justReturn( false );

		$widgets = array(
			array(
				'content'     => self::PAYLOAD,
				'panels_info' => array( 'class' => 'WP_Widget_Custom_HTML', 'raw' => false ),
			),
		);

		$result = $this->admin()->process_raw_widgets( $widgets, array(), false, false );

		$this->assertSame( self::CLEANED, $result[0]['content'], 'raw => false must not skip update().' );
		$this->assertArrayNotHasKey( 'raw', $result[0]['panels_info'] );
	}

	// --- Fallback branch: unresolved class + no unfiltered_html. -------------

	public function test_unresolved_class_is_kses_filtered_for_unprivileged_user() {
		\SiteOrigin_Panels::$instance_resolver = fn() => null;
		Functions\when( 'current_user_can' )->justReturn( false );

		$widgets = array(
			array(
				'content'     => self::PAYLOAD,
				'panels_info' => array( 'class' => 'Some_Unknown_Widget' ),
			),
		);

		$result = $this->admin()->process_raw_widgets( $widgets, array(), false, false );

		$this->assertSame( self::CLEANED, $result[0]['content'], 'Unresolved widget class must be wp_kses_post()ed for unprivileged users.' );
		$this->assertArrayNotHasKey( 'raw', $result[0]['panels_info'] );
	}

	public function test_unresolved_class_passes_through_for_privileged_user() {
		\SiteOrigin_Panels::$instance_resolver = fn() => null;
		Functions\when( 'current_user_can' )->justReturn( true );

		$widgets = array(
			array(
				'content'     => self::PAYLOAD,
				'panels_info' => array( 'class' => 'Some_Unknown_Widget' ),
			),
		);

		$result = $this->admin()->process_raw_widgets( $widgets, array(), false, false );

		$this->assertSame( self::PAYLOAD, $result[0]['content'], 'unfiltered_html users keep raw markup (fallback is a no-op).' );
		$this->assertArrayNotHasKey( 'raw', $result[0]['panels_info'] );
	}
}
