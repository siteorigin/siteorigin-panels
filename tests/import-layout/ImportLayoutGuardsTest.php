<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Signals that action_import_layout() terminated via wp_die(). In production
 * wp_die() exits; here it throws so termination is assertable.
 *
 * A named class rather than an anonymous one so this file stays parseable by
 * the build toolchain's bundled php-parser.
 */
class ImportLayoutDied extends \Exception {
}

/**
 * Signals that action_import_layout() terminated via wp_send_json_error().
 * Distinct from ImportLayoutDied: wp_die() is reachable at five sites (nonce,
 * upload, both decode bails, and the success-path terminator), so "Guard 1
 * fired" must be distinguishable from "an earlier bail or normal termination".
 *
 * A named class rather than an anonymous one so this file stays parseable by
 * the build toolchain's bundled php-parser.
 */
class ImportLayoutJsonError extends \Exception {
}

/*
 * Define the global `SiteOrigin_Panels_Admin` spy at FILE LOAD time.
 * action_import_layout() reaches process_raw_widgets() through the static
 * singleton `SiteOrigin_Panels_Admin::single()`, which Brain Monkey cannot
 * intercept, so a global class shim is required. The eval'd code lands in the
 * global namespace and is invisible to the i18n .pot extraction's php-parser;
 * the class_exists guard keeps coexistence a no-op if another file in a
 * combined run defines the class first. Spy state lives in a static so it
 * survives regardless of instance identity and is reset per-test in setUp().
 */
if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
	eval(
		'class SiteOrigin_Panels_Admin {'
		. ' public static $spy_args = null;'
		. ' private static $single = null;'
		. ' public static function single() {'
		. '   if ( self::$single === null ) { self::$single = new self(); }'
		. '   return self::$single;'
		. ' }'
		. ' public function process_raw_widgets() {'
		. '   self::$spy_args = func_get_args();'
		. '   return array();'
		. ' }'
		. '}'
	);
}

/**
 * Regression tests for the two post-decode guards in
 * SiteOrigin_Panels_Admin_Layouts::action_import_layout():
 *
 * - Guard 1 (inc/admin-layouts.php:670-673): the `siteorigin_panels_data`
 *   filter may return a non-array; the guard terminates via
 *   wp_send_json_error() instead of fataling on `$panels_data['widgets']`.
 * - Guard 2 (inc/admin-layouts.php:675-677): a decoded layout with no
 *   `widgets` key gets `widgets` defaulted to array() before
 *   process_raw_widgets() is called.
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them. The
 * `: void` return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class ImportLayoutGuardsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Path of the staged upload temp file, if any. Assigned by stage_upload()
	 * and cleaned in tearDown().
	 */
	private $temp_file = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		Functions\when( 'wp_die' )->alias(
			function () {
				throw new ImportLayoutDied();
			}
		);

		Functions\when( 'wp_send_json_error' )->alias(
			function () {
				throw new ImportLayoutJsonError();
			}
		);

		$_REQUEST['_panelsnonce'] = 'nonce';

		\SiteOrigin_Panels_Admin::$spy_args = null;
	}

	protected function tearDown(): void {
		unset( $_REQUEST['_panelsnonce'] );
		unset( $_FILES['panels_import_data'] );
		if ( $this->temp_file !== null && file_exists( $this->temp_file ) ) {
			unlink( $this->temp_file ); // normally already gone: decode unlinks it
		}
		$this->temp_file = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * inc/admin-layouts.php has no top level side effects, so requiring it is
	 * safe. The real constructor registers hooks, so bypass it.
	 */
	private function layouts() {
		require_once dirname( __DIR__, 2 ) . '/inc/admin-layouts.php';

		$class = new \ReflectionClass( 'SiteOrigin_Panels_Admin_Layouts' );

		return $class->newInstanceWithoutConstructor();
	}

	/**
	 * The upload preamble checks tmp_name with native file_exists(), which
	 * cannot be stubbed, so stage a real temp file.
	 */
	private function stage_upload( $json_string ) {
		$this->temp_file = tempnam( sys_get_temp_dir(), 'sopanels' );
		file_put_contents( $this->temp_file, $json_string );
		// Only tmp_name is ever read (:658-664); error/name/size never inspected.
		$_FILES['panels_import_data'] = array( 'tmp_name' => $this->temp_file );
		return $this->temp_file;
	}

	public static function non_array_filter_result_provider() {
		return array(
			'string result' => array( 'not an array' ),
			'null result'   => array( null ),
		);
	}

	#[DataProvider( 'non_array_filter_result_provider' )]
	public function test_non_array_filter_result_sends_json_error( $filter_result ) {
		$this->stage_upload( json_encode( array( 'widgets' => array(), 'grids' => array() ) ) );

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) use ( $filter_result ) {
				if ( $tag === 'siteorigin_panels_data' ) {
					return $filter_result; // the provider's non-array value
				}
				return $value; // null-safe passthrough for every other tag
			}
		);

		$guard_fired = false;
		ob_start();
		try {
			$this->layouts()->action_import_layout();
		} catch ( ImportLayoutJsonError $e ) {
			$guard_fired = true; // ONLY wp_send_json_error() throws this type
		} catch ( ImportLayoutDied $e ) {
			// An earlier wp_die() bail (nonce :653, upload :661, decode :471/:491)
			// reached wp_die() before Guard 1 — name the failure explicitly.
			$this->fail( 'Unexpected wp_die() bail before Guard 1 (nonce/upload/decode preamble).' );
		} finally {
			ob_end_clean();
		}
		// Reaching here with $guard_fired === false means NO exception was thrown at
		// all (guard missing, fatal path), and the next assertion fails the test.
		$this->assertTrue( $guard_fired, 'Guard 1 must terminate via wp_send_json_error().' );
		$this->assertNull(
			\SiteOrigin_Panels_Admin::$spy_args,
			'process_raw_widgets() must never be called when the filter returns a non-array.'
		);
		$this->assertFileDoesNotExist(
			$this->temp_file,
			'decode_panels_data() must have deleted the uploaded temp file before the guard.'
		);
	}

	public function test_missing_widgets_key_defaults_to_empty_array() {
		$grids      = array( array( 'cells' => 1, 'style' => array() ) );
		$grid_cells = array( array( 'grid' => 0, 'weight' => 1 ) );

		// Mirrors the manually-verified real-world layout export: grids and
		// grid_cells present, NO `widgets` key.
		$this->stage_upload(
			json_encode( array( 'grids' => $grids, 'grid_cells' => $grid_cells ) )
		);

		$completed = false;
		$output    = '';
		ob_start();
		try {
			$this->layouts()->action_import_layout();
		} catch ( ImportLayoutDied $e ) {
			$completed = true; // the success-path wp_die() at :686
		} catch ( ImportLayoutJsonError $e ) {
			$this->fail( 'Guard 1 fired unexpectedly — the fixture should be a valid array.' );
		} finally {
			$output = ob_get_clean();
		}

		$this->assertTrue( $completed, 'Import must run to the success-path wp_die().' );
		$this->assertFileDoesNotExist(
			$this->temp_file,
			'decode_panels_data() must have deleted the uploaded temp file on the success path.'
		);

		$this->assertNotNull(
			\SiteOrigin_Panels_Admin::$spy_args,
			'process_raw_widgets() must be called on the success path.'
		);
		$this->assertSame(
			array(),
			\SiteOrigin_Panels_Admin::$spy_args[0],
			'Guard 2 must hand process_raw_widgets() exactly an empty array, not null/missing.'
		);
		$this->assertSame(
			array( array(), array(), true, true ),
			\SiteOrigin_Panels_Admin::$spy_args,
			'The :679 call contract is ( $widgets, array(), true, true ).'
		);

		$decoded = json_decode( $output, true );
		$this->assertIsArray( $decoded, 'The import must echo JSON that decodes to an array.' );
		$this->assertSame( array(), $decoded['widgets'], 'The echoed layout must carry widgets => array().' );
		$this->assertSame( $grids, $decoded['grids'], 'grids must round-trip through the import.' );
		$this->assertSame( $grid_cells, $decoded['grid_cells'], 'grid_cells must round-trip through the import.' );
	}
}
