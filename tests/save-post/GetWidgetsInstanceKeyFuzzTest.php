<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzzes how SiteOrigin_Panels_Admin::get_widgets() names widget factory entries.
 *
 * Each case builds a random factory from fixed seeds. Entries mix the keys
 * WordPress gives (a class name, spl_object_hash() before 7.1, the integer
 * spl_object_id() in 7.1.0, 'spl_object_id:' . spl_object_id() from 7.1.1)
 * with keys plugins choose: names shared by several widgets of one class
 * (#696), numbers, and strings that only look like object keys. Widget classes
 * are namespaced to varied depths and some names carry UTF-8 bytes.
 *
 * The rule is restated here, separately from the code: an entry is listed under
 * its object's class when its key matches a 32-character hash or equals that
 * object's own spl_object_id(), as an int or with the 'spl_object_id:' prefix.
 * Every other key is listed as it is. The seeds are fixed so a failure repeats.
 */
class GetWidgetsInstanceKeyFuzzTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const ITERATIONS = 400;

	private static $classes = array();

	private $factory_before;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		$this->factory_before = isset( $GLOBALS['wp_widget_factory'] ) ? $GLOBALS['wp_widget_factory'] : null;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_widget_factory'] = $this->factory_before;
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function seeds() {
		return array(
			'seed 1'    => array( 1 ),
			'seed 1462' => array( 1462 ),
			'seed 7101' => array( 7101 ),
		);
	}

	/**
	 * Declare a pool of widget stand-in classes once, namespaced to depths 0 to 4.
	 */
	private static function widget_classes() {
		if ( self::$classes ) {
			return self::$classes;
		}

		mt_srand( 99 );
		for ( $i = 0; $i < 24; $i++ ) {
			$depth = $i % 5;
			$parts = array();
			for ( $d = 0; $d < $depth; $d++ ) {
				$parts[] = 'FuzzNs' . $i . '_' . $d . ( $d % 2 ? "\xc3\xa9" : '' );
			}
			$short = 'FuzzWidget' . $i . ( $i % 3 ? '' : "\xc3\xa9" );
			$namespace = 'SiteOrigin\\Tests\\Fuzz' . ( $parts ? '\\' . implode( '\\', $parts ) : '' );
			if ( ! class_exists( $namespace . '\\' . $short, false ) ) {
				eval( "namespace $namespace; class $short { public \$name; public \$widget_options = array(); public function __construct( \$name ) { \$this->name = \$name; } }" );
			}
			self::$classes[] = $namespace . '\\' . $short;
		}

		return self::$classes;
	}

	private static function expected_name( $key, $widget ) {
		if ( preg_match( '/[0-9a-f]{32}/', (string) $key ) ) {
			return get_class( $widget );
		}

		$id = spl_object_id( $widget );

		return ( $key === $id || $key === 'spl_object_id:' . $id ) ? get_class( $widget ) : $key;
	}

	/**
	 * A factory key of one of thirteen shapes for this widget.
	 */
	private static function random_key( $class, $widget, $other, $n ) {
		$id = spl_object_id( $widget );
		switch ( mt_rand( 0, 12 ) ) {
			case 0:
				return $class;
			case 1:
				return spl_object_hash( $widget );
			case 2:
				return $id;
			case 3:
				return 'spl_object_id:' . $id;
			case 4:
				return 'Plugin_Widget_Key_' . mt_rand();
			case 5:
				$key = mt_rand( 1, 99999 );
				return $key === $id ? $key + 1 : $key;
			case 6:
				return 'spl_object_id:' . ( $id + mt_rand( 1, 50 ) );
			case 7:
				return 'spl_object_id:' . $id . ( mt_rand( 0, 1 ) ? "\n" : ' ' );
			case 8:
				return 'spl_object_id:0' . $id;
			case 9:
				return $other !== $widget ? spl_object_id( $other ) : 'Plugin_Key_' . $n;
			case 10:
				return $other !== $widget ? 'spl_object_id:' . spl_object_id( $other ) : 'Plugin_Key_B_' . $n;
			case 11:
				return 'SPL_OBJECT_ID:' . $id;
			default:
				return 'spl_object_id:' . $id . 'x';
		}
	}

	#[DataProvider( 'seeds' )]
	public function test_random_factories_are_named_by_the_rule( $seed ) {
		$classes = self::widget_classes();
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Admin::class );
		$admin = $reflection->newInstanceWithoutConstructor();
		mt_srand( $seed );

		for ( $iteration = 0; $iteration < self::ITERATIONS; $iteration++ ) {
			$widgets = array();
			$factory = array();
			$count = mt_rand( 1, 12 );
			for ( $n = 0; $n < $count; $n++ ) {
				$class = $classes[ mt_rand( 0, count( $classes ) - 1 ) ];
				$widget = new $class( "Seed $seed widget $iteration.$n" );
				$widgets[] = $widget;
				$other = $widgets[ mt_rand( 0, count( $widgets ) - 1 ) ];
				// PHP turns a decimal string key into an int, as it does in WordPress's own array.
				$factory[ self::random_key( $class, $widget, $other, $n ) ] = $widget;
			}

			$expected = array();
			foreach ( $factory as $key => $widget ) {
				$expected[ (string) self::expected_name( $key, $widget ) ] = $widget->name;
			}

			$GLOBALS['wp_widget_factory'] = (object) array( 'widgets' => $factory );
			$listed = array();
			foreach ( $admin->get_widgets() as $key => $entry ) {
				$this->assertSame( (string) $key, (string) $entry['class'], "Seed $seed iteration $iteration: a widget's class matches its list key." );
				$listed[ (string) $key ] = $entry['title'];
			}

			ksort( $expected );
			ksort( $listed );
			$this->assertSame( $expected, $listed, "Seed $seed iteration $iteration lists each widget under the name the rule gives." );
		}
	}
}
