<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins which strings SiteOrigin_Panels_Admin::kses_deep() hands to
 * wp_kses_post() and which it leaves alone (#1377).
 *
 * The floor must pass a string to wp_kses_post() only when it is
 * markup-shaped: it contains `<` or `>`, or an entity-shaped reference
 * (`&name;`, `&#123;`, `&#x1F;`). Every other string gets wp_kses_no_null()
 * only, so a bare `&` in a query string never becomes `&amp;`.
 *
 * Runs under tests/bootstrap-admin.php (phpunit-save-post.xml) so the REAL
 * SiteOrigin_Panels_Admin is under test; the default suite's AbilitiesTest
 * shim claims the class name first there, and its kses_deep() is a stand-in.
 *
 * NOTE: avoids arrow functions and anonymous classes (build-toolchain parser
 * compatibility); `: void` return types on setUp()/tearDown() are required by
 * PHPUnit 12.
 */
class KsesDeepTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Every argument wp_kses_post() received during the current test.
	 *
	 * @var array
	 */
	public static $kses_post_calls = array();

	/**
	 * Every argument wp_kses_no_null() received during the current test.
	 *
	 * @var array
	 */
	public static $kses_no_null_calls = array();

	/**
	 * Recording spy for wp_kses_post(). Emulates the two behaviours this suite
	 * cares about: strip a <script> element and normalise a bare `&` to
	 * `&amp;` (the exact rewrite that broke the posts query string).
	 */
	public static function kses_post_spy( $value ) {
		self::$kses_post_calls[] = $value;
		$value = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $value );

		return preg_replace( '/&(?!(?:#[0-9]+|#[xX][0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]*);)/', '&amp;', $value );
	}

	/**
	 * Recording stand-in for wp_kses_no_null(): strip NUL bytes only.
	 */
	public static function kses_no_null_stub( $value, $options = null ) {
		self::$kses_no_null_calls[] = $value;

		return str_replace( "\0", '', (string) $value );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		self::$kses_post_calls    = array();
		self::$kses_no_null_calls = array();

		Functions\when( 'wp_kses_post' )->alias( array( __CLASS__, 'kses_post_spy' ) );
		Functions\when( 'wp_kses_no_null' )->alias( array( __CLASS__, 'kses_no_null_stub' ) );

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once dirname( __DIR__ ) . '/inc/admin.php';
		}

		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Admin', 'process_raw_widgets' ),
			'Expected the REAL SiteOrigin_Panels_Admin; a test stub claimed the class name first.'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * AC1: strings without markup are returned byte-identical and never reach
	 * wp_kses_post().
	 */
	public static function untouched_strings() {
		return array(
			'posts query string' => array( 'post_type=post&tax_query=category:jobs&orderby=date' ),
			'AT&T'               => array( 'AT&T' ),
			'a&b=c'              => array( 'a&b=c' ),
			'x & y'              => array( 'x & y' ),
			'plain text'         => array( 'Hello world' ),
			'empty string'       => array( '' ),
			'bare & at end'      => array( 'trailing &' ),
			'&; is not entity'   => array( 'a &; b' ),
			'&#; is not entity'  => array( 'a &#; b' ),
			'&#x; is not entity' => array( 'a &#x; b' ),
			'&name no semicolon' => array( 'a &amp b' ),
		);
	}

	#[DataProvider( 'untouched_strings' )]
	public function test_string_without_markup_is_untouched_and_skips_kses_post( $input ) {
		$result = \SiteOrigin_Panels_Admin::kses_deep( $input );

		$this->assertSame( $input, $result, 'A string without markup must come back byte-identical.' );
		$this->assertSame( array(), self::$kses_post_calls, 'wp_kses_post() must NOT be called for a string without markup.' );
		$this->assertSame( array( $input ), self::$kses_no_null_calls, 'wp_kses_no_null() must run once on a string without markup.' );
	}

	/**
	 * AC1: the surviving query string still parses into its keys.
	 */
	public function test_posts_query_string_still_parses_after_floor() {
		$result = \SiteOrigin_Panels_Admin::kses_deep( 'post_type=post&tax_query=category:jobs&orderby=date' );

		parse_str( $result, $args );

		$this->assertSame(
			array(
				'post_type' => 'post',
				'tax_query' => 'category:jobs',
				'orderby'   => 'date',
			),
			$args
		);
		$this->assertArrayNotHasKey( 'amp;tax_query', $args );
	}

	/**
	 * AC2: markup-shaped strings still go through wp_kses_post().
	 */
	public static function markup_shaped_strings() {
		return array(
			'lt'                => array( 'a < b' ),
			'gt'                => array( 'a > b' ),
			'tag'               => array( '<b>x</b>' ),
			'script'            => array( '<script>alert(1)</script><b>x</b>' ),
			'&lt;'              => array( 'a &lt; b' ),
			'&#60;'             => array( 'a &#60; b' ),
			'&#x3C;'            => array( 'a &#x3C; b' ),
			'&#X3c; upper X'    => array( 'a &#X3c; b' ),
			'&LT; upper'        => array( 'a &LT; b' ),
			'&amp;'             => array( 'post_type=post&amp;tax_query=category:jobs' ),
			'&nbsp;'            => array( 'a&nbsp;b' ),
			'entity with digit' => array( 'a &frac12; b' ),
		);
	}

	#[DataProvider( 'markup_shaped_strings' )]
	public function test_markup_shaped_string_goes_through_kses_post( $input ) {
		$result = \SiteOrigin_Panels_Admin::kses_deep( $input );

		$this->assertSame( array( $input ), self::$kses_post_calls, 'wp_kses_post() must be called exactly once with the markup-shaped string.' );
		$this->assertSame( array(), self::$kses_no_null_calls, 'wp_kses_no_null() must not run on the kses_post branch.' );
		$this->assertSame( self::kses_post_spy_expected( $input ), $result, 'The floor must return what wp_kses_post() returned.' );
	}

	/**
	 * What kses_post_spy() returns for $input, computed without recording.
	 */
	private static function kses_post_spy_expected( $input ) {
		$calls  = self::$kses_post_calls;
		$result = self::kses_post_spy( $input );
		self::$kses_post_calls = $calls;

		return $result;
	}

	/**
	 * AC2 (already-corrupted stored value): `&amp;tax_query=` is entity-shaped,
	 * so it keeps going through kses and is NOT healed here.
	 */
	public function test_already_corrupted_query_string_stays_as_stored() {
		$stored = 'post_type=post&amp;tax_query=category:jobs';

		$this->assertSame( $stored, \SiteOrigin_Panels_Admin::kses_deep( $stored ) );
		$this->assertSame( array( $stored ), self::$kses_post_calls );
	}

	/**
	 * AC3: strings without markup still lose NUL bytes.
	 */
	public function test_nul_bytes_are_stripped_from_string_without_markup() {
		$this->assertSame( 'ab', \SiteOrigin_Panels_Admin::kses_deep( "a\0b" ) );
		$this->assertSame( array(), self::$kses_post_calls, 'A NUL byte alone must not route through wp_kses_post().' );
		$this->assertSame( array( "a\0b" ), self::$kses_no_null_calls );
	}

	/**
	 * AC4: non-string leaves are preserved as-is and never reach either kses
	 * function.
	 */
	public static function non_string_leaves() {
		return array(
			'int'   => array( 42 ),
			'zero'  => array( 0 ),
			'float' => array( 1.5 ),
			'true'  => array( true ),
			'false' => array( false ),
			'null'  => array( null ),
		);
	}

	#[DataProvider( 'non_string_leaves' )]
	public function test_non_string_leaf_is_preserved( $input ) {
		$this->assertSame( $input, \SiteOrigin_Panels_Admin::kses_deep( $input ) );
		$this->assertSame( array(), self::$kses_post_calls );
		$this->assertSame( array(), self::$kses_no_null_calls );
	}

	/**
	 * AC4: nested arrays keep their structure and keys; each leaf is routed
	 * by its own shape.
	 */
	public function test_nested_array_structure_is_preserved_and_leaves_routed_by_shape() {
		$input = array(
			'posts'    => 'post_type=post&tax_query=category:jobs',
			'html'     => '<script>alert(1)</script><b>x</b>',
			'count'    => 3,
			'enabled'  => false,
			'ratio'    => 0.5,
			'nothing'  => null,
			'nested'   => array(
				'label' => 'AT&T',
				'raw'   => "a\0b",
				'deep'  => array( 'entity' => 'a &lt; b', 10 => 10 ),
			),
			7          => 'x & y',
		);

		$result = \SiteOrigin_Panels_Admin::kses_deep( $input );

		$this->assertSame(
			array(
				'posts'    => 'post_type=post&tax_query=category:jobs',
				'html'     => '<b>x</b>',
				'count'    => 3,
				'enabled'  => false,
				'ratio'    => 0.5,
				'nothing'  => null,
				'nested'   => array(
					'label' => 'AT&T',
					'raw'   => 'ab',
					'deep'  => array( 'entity' => 'a &lt; b', 10 => 10 ),
				),
				7          => 'x & y',
			),
			$result
		);

		$this->assertSame(
			array( '<script>alert(1)</script><b>x</b>', 'a &lt; b' ),
			self::$kses_post_calls,
			'Only the markup-shaped leaves may reach wp_kses_post().'
		);
		$this->assertSame(
			array( 'post_type=post&tax_query=category:jobs', 'AT&T', "a\0b", 'x & y' ),
			self::$kses_no_null_calls,
			'Every other string leaf goes through wp_kses_no_null().'
		);
	}
}
