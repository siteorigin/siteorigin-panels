<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A theme declares Page Builder support either bare or with a settings array.
 * Bare, WordPress stores the feature as true and get_theme_support() hands that
 * boolean back, so the settings reader must not treat it as a list of arguments.
 *
 * Each row below is a value get_theme_support() can return. A row pins the
 * settings that come out, the value handed to wp_parse_args() and how many times
 * the parser fell through to wp_parse_str(), so a change that arrives at the same
 * settings by a different route still fails.
 */
class SettingsThemeSupportTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const INSUPPRESSIBLE_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * The settings a theme is treated as having contributed, once the reader has
	 * decided what to do with the theme support value.
	 */
	private $parsed_theme_settings;

	/**
	 * How many times wp_parse_args() fell through to wp_parse_str(), which is the
	 * branch a non-array value takes.
	 */
	private $parse_str_calls;

	/**
	 * Diagnostics raised while the code under test runs, as array( errno, errstr )
	 * pairs. The suppression check is the one PHPUnit's own handler uses, because
	 * PHPUnit masks error_reporting() to fatal levels during a test.
	 */
	private $errors = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( 'SiteOrigin_Panels_Settings', false ) ) {
			require_once dirname( __DIR__ ) . '/inc/settings.php';
		}

		$this->errors = array();
		$this->parsed_theme_settings = null;
		$this->parse_str_calls = 0;
		set_error_handler( array( $this, 'collect_error' ) );

		Functions\when( '__' )->returnArg();

		// The defaults are supplied here rather than by the plugin's own callback,
		// which apply_filters() does not run under Brain Monkey. Every other tag,
		// including the one the finished settings pass through, is left alone.
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			if ( $tag === 'siteorigin_panels_settings_defaults' ) {
				return array( 'from' => 'default', 'only-default' => true );
			}

			return $value;
		} );

		Functions\when( 'update_option' )->alias( function ( $option ) {
			$this->fail( 'get() wrote ' . $option . '; the stored settings fixture should keep the migration branch shut.' );
		} );

		Functions\when( 'wp_parse_str' )->alias( array( $this, 'parse_str_stub' ) );
		Functions\when( 'wp_parse_args' )->alias( array( $this, 'parse_args_stub' ) );
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
	 * Verbatim body of WordPress core's wp_parse_str() (wp-includes/formatting.php),
	 * counting the calls so a row can assert which branch wp_parse_args() took.
	 */
	public function parse_str_stub( $input_string, &$result ) {
		$this->parse_str_calls++;
		parse_str( (string) $input_string, $result );
		$result = apply_filters( 'wp_parse_str', $result );
	}

	/**
	 * WordPress core's wp_parse_args() (wp-includes/functions.php), with the array
	 * branch assigning a copy where core binds a reference, which no caller here
	 * relies on. It also records the first argument of the first call, which is what
	 * the reader decided the theme contributed.
	 */
	public function parse_args_stub( $args, $defaults = array() ) {
		if ( $this->parsed_theme_settings === null && $this->parse_str_calls === 0 ) {
			$this->parsed_theme_settings = array( $args );
		}

		if ( is_object( $args ) ) {
			$parsed_args = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed_args = $args;
		} else {
			$this->parse_str_stub( $args, $parsed_args );
		}

		if ( is_array( $defaults ) && $defaults ) {
			return array_merge( $defaults, $parsed_args );
		}

		return $parsed_args;
	}

	/**
	 * Point the reader at one theme support value and one stored option. Called
	 * from the test body rather than setUp(), which runs before the data provider
	 * hands over the row.
	 */
	private function boot( $theme_support, array $stored ) {
		// Answers only for the feature the reader is supposed to ask about, and
		// returns false for anything else exactly as WordPress does, so asking for
		// the wrong feature fails the row rather than silently receiving the fixture.
		Functions\when( 'get_theme_support' )->alias( function ( $feature ) use ( $theme_support ) {
			return $feature === 'siteorigin-panels' ? $theme_support : false;
		} );

		Functions\when( 'get_option' )->alias( function ( $option, $default_value = false ) use ( $stored ) {
			return $option === 'siteorigin_panels_settings' ? $stored : $default_value;
		} );

		return new \SiteOrigin_Panels_Settings();
	}

	public static function theme_support_values(): array {
		$defaults = array( 'from' => 'default', 'only-default' => true );

		return array(
			'feature not registered' => array(
				false,
				$defaults,
				array(),
				0,
			),
			'declared without settings' => array(
				true,
				$defaults,
				array(),
				0,
			),
			'declared with a settings array' => array(
				array( array( 'from' => 'theme', 'only-theme' => true ) ),
				array( 'from' => 'theme', 'only-default' => true, 'only-theme' => true ),
				array( 'from' => 'theme', 'only-theme' => true ),
				0,
			),
			'declared with an empty outer array' => array(
				array(),
				$defaults,
				array(),
				0,
			),
			'declared with null' => array(
				array( null ),
				$defaults,
				null,
				1,
			),
			'declared with true' => array(
				array( true ),
				// array_merge() renumbers the integer key parse_str() produces from "1".
				array( 'from' => 'default', 'only-default' => true, 0 => '' ),
				true,
				1,
			),
			'declared with a string' => array(
				array( 'foo' ),
				array( 'from' => 'default', 'only-default' => true, 'foo' => '' ),
				'foo',
				1,
			),
		);
	}

	#[DataProvider( 'theme_support_values' )]
	public function test_every_theme_support_value_is_read_the_same_way( $theme_support, array $expected_settings, $expected_parsed, int $expected_parse_str_calls ) {
		$settings = $this->boot( $theme_support, array() );

		$read = $settings->get();

		// The diagnostic is the reported defect, so it is asserted first: a value
		// assertion failing ahead of it would leave it unevaluated.
		$this->assertSame(
			array(),
			$this->errors,
			'Reading the settings raised a PHP diagnostic.'
		);
		$this->assertSame(
			$expected_settings,
			$read,
			'The settings this theme support value produces changed.'
		);
		$this->assertSame(
			array( $expected_parsed ),
			$this->parsed_theme_settings,
			'A different value reached wp_parse_args() than before.'
		);
		$this->assertSame(
			$expected_parse_str_calls,
			$this->parse_str_calls,
			'wp_parse_args() took a different branch than before.'
		);
	}

	public function test_a_stored_setting_outranks_the_theme_and_the_theme_outranks_the_default() {
		$settings = $this->boot(
			array( array( 'from' => 'theme', 'only-theme' => true ) ),
			array( 'from' => 'stored' )
		);

		$all = $settings->get();

		$this->assertSame( 'stored', $all['from'], 'A stored setting must outrank the theme.' );
		$this->assertTrue( $all['only-theme'], 'A theme setting the option does not carry must survive.' );
		$this->assertTrue( $all['only-default'], 'A default neither of them carries must survive.' );
		$this->assertSame( array(), $this->errors );
	}
}
