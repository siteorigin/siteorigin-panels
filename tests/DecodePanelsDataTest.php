<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Signals that decode_panels_data() terminated the request. In production
 * wp_die() exits; here it throws so termination is assertable.
 *
 * A named class rather than an anonymous one so this file stays parseable by
 * the build toolchain's bundled php-parser.
 */
class DecodePanelsDataDied extends \Exception {
}

/**
 * Regression test locking the decode bound in
 * SiteOrigin_Panels_Admin_Layouts::decode_panels_data().
 *
 * json_decode() coerces a non-string argument to string, which makes every JSON
 * number and `true` a fixed point: json_decode( 0 ) returns 0 forever. The
 * helper used to re-invoke itself on its own output, so those inputs consumed
 * the whole PHP memory_limit and fataled. These tests assert it now terminates
 * for the entire scalar class while still importing the double encoded layouts
 * produced by versions 2.29.9 to 2.32.1.
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them. The
 * `: void` return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class DecodePanelsDataTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// decode_panels_data() is always called without a file here, so
		// delete_file() short circuits on ! empty( $file ) and never touches
		// the disk.
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new DecodePanelsDataDied();
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * inc/admin-layouts.php has no top level side effects, so requiring it is
	 * safe. The real constructor registers five hooks, so bypass it.
	 */
	private function decoder() {
		require_once dirname( __DIR__ ) . '/inc/admin-layouts.php';

		$class = new \ReflectionClass( 'SiteOrigin_Panels_Admin_Layouts' );

		return $class->newInstanceWithoutConstructor();
	}

	public function test_single_encoded_layout_decodes_to_array() {
		$layout = array(
			'widgets'    => array( array( 'text' => 'hi' ) ),
			'grids'      => array(),
			'grid_cells' => array(),
		);

		$this->assertSame(
			$layout,
			$this->decoder()->decode_panels_data( json_encode( $layout ) )
		);
	}

	/**
	 * The 2.29.9 to 2.32.1 export writer double encoded its output. Those files
	 * must still import.
	 */
	public function test_double_encoded_layout_decodes_to_array() {
		$layout = array(
			'widgets' => array(),
			'grids'   => array(),
		);

		$this->assertSame(
			$layout,
			$this->decoder()->decode_panels_data( json_encode( json_encode( $layout ) ) )
		);
	}

	public function test_triple_encoded_layout_is_within_the_bound() {
		$layout = array( 'widgets' => array() );

		$this->assertSame(
			$layout,
			$this->decoder()->decode_panels_data(
				json_encode( json_encode( json_encode( $layout ) ) )
			)
		);
	}

	public function test_quadruple_encoded_layout_is_rejected() {
		$payload = json_encode(
			json_encode(
				json_encode( json_encode( array( 'widgets' => array() ) ) )
			)
		);

		$this->expectException( DecodePanelsDataDied::class );
		$this->decoder()->decode_panels_data( $payload );
	}

	/**
	 * Every payload here is a json_decode fixed point and looped forever before
	 * the fix. This test completing at all is the regression lock.
	 */
	#[DataProvider( 'scalar_fixed_point_provider' )]
	public function test_scalar_fixed_points_terminate( $payload ) {
		$this->expectException( DecodePanelsDataDied::class );
		$this->decoder()->decode_panels_data( $payload );
	}

	public static function scalar_fixed_point_provider() {
		return array(
			'zero'         => array( '0' ),
			'one'          => array( '1' ),
			'negative'     => array( '-1' ),
			'float'        => array( '1.5' ),
			'float zero'   => array( '0.0' ),
			'exponent'     => array( '1e10' ),
			'big float'    => array( '9223372036854775808' ),
			'true'         => array( 'true' ),
			'padded zero'  => array( ' 0 ' ),
			'quoted zero'  => array( '"0"' ),
			'quoted true'  => array( '"true"' ),
			'quoted float' => array( '"1.5"' ),
		);
	}

	/**
	 * These already terminated before the fix, through the json_last_error
	 * branch. Locked so the new bound does not change their behaviour.
	 */
	#[DataProvider( 'terminating_payload_provider' )]
	public function test_non_array_payloads_are_rejected( $payload ) {
		$this->expectException( DecodePanelsDataDied::class );
		$this->decoder()->decode_panels_data( $payload );
	}

	public static function terminating_payload_provider() {
		return array(
			'false'        => array( 'false' ),
			'null'         => array( 'null' ),
			'empty string' => array( '""' ),
			'plain string' => array( '"str"' ),
			'malformed'    => array( '{"a":' ),
		);
	}
}
