<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A class that records whether unserialize() ever instantiated it. Serialized
 * into a post_translations description it stands in for an attacker's gadget:
 * a plain unserialize() runs its __wakeup(), the compat class must not.
 *
 * A named class rather than an anonymous one so this file stays parseable by
 * the build toolchain's bundled php-parser.
 */
class PolylangUnserializeGadget {
	public static $woken = false;

	public function __wakeup() {
		self::$woken = true;
	}
}

/**
 * Pins how SiteOrigin_Panels_Compat_Polylang reads the post_translations term
 * description.
 *
 * The description is editable term data, so it is unserialized with
 * allowed_classes => false. These tests assert two things: a serialized object
 * payload never instantiates a class and lands on the "no sync" path, and the
 * serialized arrays exercised here, in the shape Polylang writes, decode
 * exactly as maybe_unserialize() decodes them. That reference result is
 * computed in-test with a plain
 * unserialize() so the parity assertion does not depend on hand-typed arrays.
 *
 * No WordPress is loaded here. get_object_term_cache() is stubbed per case and
 * is_serialized() is a copy of core's strict-mode body.
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them. The
 * `: void` return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class PolylangTranslationsUnserializeTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const FROM = 12;
	const TO = 34;

	/**
	 * Warnings and notices raised while the code under test runs, as
	 * array( errno, errstr ) pairs. An @-suppressed call stays silent here as it
	 * does in production; the check is the one PHPUnit's own handler uses,
	 * because PHPUnit masks error_reporting() to fatal levels during a test.
	 */
	private $errors = array();

	const INSUPPRESSIBLE_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Polylang', false ) ) {
			require_once dirname( __DIR__ ) . '/compat/polylang.php';
		}

		PolylangUnserializeGadget::$woken = false;
		$this->errors = array();
		set_error_handler( array( $this, 'collect_error' ) );

		Functions\when( 'is_serialized' )->alias( array( __CLASS__, 'is_serialized_stub' ) );
	}

	protected function tearDown(): void {
		restore_error_handler();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function collect_error( $errno, $errstr ) {
		$suppressed = ( error_reporting() & ~self::INSUPPRESSIBLE_LEVELS ) === 0;
		if ( $suppressed ) {
			return true;
		}
		$this->errors[] = array( $errno, $errstr );

		return true;
	}

	/**
	 * Verbatim strict-mode body of WordPress core's is_serialized()
	 * (wp-includes/functions.php, WordPress 7.1.1).
	 */
	public static function is_serialized_stub( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
				// Or else fall through.
			case 'a':
			case 'O':
			case 'E':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;\$/", $data );
		}

		return false;
	}

	/**
	 * A fresh instance per case: the constructor registers a filter and the
	 * language cache is per instance, so bypassing it keeps every case isolated.
	 */
	private function polylang() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Polylang::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function invoke( $object, $method, array $args ) {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	/**
	 * One term-cache lookup per case: a case whose stub is never consulted
	 * fails rather than passing on an empty cache, and a second lookup within
	 * the case (the instance re-reads whenever its cached result is empty)
	 * fails the once() expectation.
	 */
	private function expect_description( $description ) {
		Functions\expect( 'get_object_term_cache' )
			->once()
			->with( self::FROM, 'post_translations' )
			->andReturn( array( (object) array( 'description' => $description ) ) );
	}

	private function language_data( $description ) {
		$this->expect_description( $description );

		return $this->invoke( $this->polylang(), 'get_pll_language_cache', array( self::FROM ) );
	}

	/**
	 * The language data as maybe_unserialize() semantics produce it: a plain
	 * unserialize() with default options, followed by the same flip and unset
	 * the compat class applies. Objects in the payload are instantiated here;
	 * that is the reference behaviour the compat class must match for arrays
	 * and must not share for objects.
	 */
	private function maybe_unserialize_language_data( $description ) {
		$language_data = @unserialize( trim( $description ) );
		if ( empty( $language_data['sync'] ) || ! is_array( $language_data['sync'] ) ) {
			return array();
		}
		$sync_data = $language_data['sync'];
		unset( $language_data['sync'] );

		return array(
			'posts' => array_flip( $language_data ),
			'sync' => $sync_data,
		);
	}

	private function gadget( $properties = '0:{}' ) {
		$class = PolylangUnserializeGadget::class;

		return sprintf( 'O:%d:"%s":%s', strlen( $class ), $class, $properties );
	}

	private static function synced_description() {
		return 'a:3:{s:2:"en";i:12;s:2:"fr";i:34;s:4:"sync";a:2:{s:2:"en";i:12;s:2:"fr";i:12;}}';
	}

	private static function unsynced_description() {
		return 'a:2:{s:2:"en";i:12;s:2:"fr";i:34;}';
	}

	public function test_realistic_polylang_description_decodes_exactly_as_before() {
		$description = self::synced_description();

		$result = $this->language_data( $description );

		$this->assertSame(
			array(
				'posts' => array( 12 => 'en', 34 => 'fr' ),
				'sync' => array( 'en' => 12, 'fr' => 12 ),
			),
			$result
		);
		$this->assertSame( $this->maybe_unserialize_language_data( $description ), $result );
		$this->assertSame( array(), $this->errors );
	}

	public function test_description_without_sync_key_means_no_sync_as_before() {
		$description = self::unsynced_description();

		$result = $this->language_data( $description );

		$this->assertSame( array(), $result );
		$this->assertSame( $this->maybe_unserialize_language_data( $description ), $result );
		$this->assertSame( array(), $this->errors );
	}

	public static function no_sync_descriptions() {
		return array(
			'empty string' => array( '' ),
			'null' => array( null ),
			'plain text' => array( 'Hello' ),
			'corrupt serialized array' => array( 'a:5:{}' ),
			'serialized null' => array( 'N;' ),
			'serialized integer' => array( 'i:5;' ),
			'sync is not an array' => array( 'a:3:{s:2:"en";i:12;s:2:"fr";i:34;s:4:"sync";s:3:"yes";}' ),
			'sync is an empty array' => array( 'a:3:{s:2:"en";i:12;s:2:"fr";i:34;s:4:"sync";a:0:{}}' ),
		);
	}

	#[DataProvider( 'no_sync_descriptions' )]
	public function test_non_array_and_malformed_descriptions_mean_no_sync_silently( $description ) {
		$result = $this->language_data( $description );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), $this->errors );
	}

	public function test_serialized_object_payload_is_never_instantiated_and_means_no_sync() {
		$description = $this->gadget( '1:{s:4:"sync";a:1:{s:2:"en";i:1;}}' );

		$result = $this->language_data( $description );

		$this->assertFalse( PolylangUnserializeGadget::$woken, 'unserialize() instantiated the payload class' );
		$this->assertSame( array(), $result );
		$this->assertSame( array(), $this->errors );

		// A plain unserialize() of the same payload does wake the gadget, so
		// the flag can tell the two decoders apart.
		@unserialize( $description );
		$this->assertTrue( PolylangUnserializeGadget::$woken );
	}

	public function test_object_nested_under_sync_is_an_incomplete_class_and_never_enables_sync() {
		$description = 'a:3:{s:2:"en";i:12;s:2:"fr";i:34;s:4:"sync";a:2:{s:2:"en";i:12;s:2:"fr";' . $this->gadget() . '}}';
		$polylang = $this->polylang();
		$this->expect_description( $description );

		$result = $this->invoke( $polylang, 'get_pll_language_cache', array( self::FROM ) );

		$this->assertFalse( PolylangUnserializeGadget::$woken );
		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $result['sync']['fr'] );
		$this->assertSame( array( 12 => 'en', 34 => 'fr' ), $result['posts'] );

		$keys = $polylang->copy_panels_data( array( 'a' ), true, self::FROM, self::TO, 'fr' );

		$this->assertSame( array( 'a' ), $keys );
		$this->assertFalse( PolylangUnserializeGadget::$woken );
		$this->assertSame( array(), $this->errors );
	}

	public function test_object_as_a_language_value_is_skipped_with_the_same_warning_as_before() {
		$description = 'a:3:{s:2:"en";i:12;s:2:"fr";' . $this->gadget() . 's:4:"sync";a:2:{s:2:"en";i:12;s:2:"fr";i:12;}}';
		$polylang = $this->polylang();
		$this->expect_description( $description );

		$result = $this->invoke( $polylang, 'get_pll_language_cache', array( self::FROM ) );

		$this->assertFalse( PolylangUnserializeGadget::$woken );
		$this->assertSame( array( 12 => 'en' ), $result['posts'] );
		$this->assertSame( array( 'en' => 12, 'fr' => 12 ), $result['sync'] );
		$this->assertCount( 1, $this->errors );
		$this->assertStringContainsString( 'array_flip', $this->errors[0][1] );
		$fixed_error = $this->errors[0];

		$keys = $polylang->copy_panels_data( array( 'a' ), true, self::FROM, self::TO, 'fr' );

		$this->assertSame( array( 'a' ), $keys );
		$this->assertCount( 1, $this->errors, 'copy_panels_data() reads the cache, not the term again' );

		// maybe_unserialize() semantics instantiate the gadget and raise the
		// identical array_flip warning: the warning belongs to array_flip on an
		// object value, not to the allowed_classes guard.
		$this->errors = array();
		$this->maybe_unserialize_language_data( $description );
		$this->assertTrue( PolylangUnserializeGadget::$woken );
		$this->assertSame( array( $fixed_error ), $this->errors );
	}

	public function test_copy_panels_data_adds_panels_data_when_the_group_is_synced() {
		$this->expect_description( self::synced_description() );

		$keys = $this->polylang()->copy_panels_data( array( 'a' ), true, self::FROM, self::TO, 'fr' );

		$this->assertSame( array( 'a', 'panels_data' ), $keys );
		$this->assertSame( array(), $this->errors );
	}

	public function test_copy_panels_data_removes_panels_data_when_the_group_is_not_synced() {
		$this->expect_description( self::unsynced_description() );

		$keys = $this->polylang()->copy_panels_data( array( 'a', 'panels_data' ), true, self::FROM, self::TO, 'fr' );

		$this->assertSame( array( 'a' ), $keys );
		$this->assertSame( array(), $this->errors );
	}
}
