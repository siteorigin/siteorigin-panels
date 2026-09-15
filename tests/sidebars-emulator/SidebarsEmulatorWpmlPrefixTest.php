<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * SiteOrigin_Panels_Sidebars_Emulator::register_widgets() strips the WPML
 * URL prefix from the request path before looking the page up by path.
 *
 * WPML 5.0 separates a language's code from the directory it is served at,
 * so the prefix comes from the `wpml_language_codes_map` filter rather than
 * the code itself. These tests pin that lookup, its fallbacks, and the
 * regex quoting of the prefix.
 *
 * One test method per request path: each drives register_widgets() once
 * against one get_page_by_path() expectation.
 *
 * Build-toolchain note: no arrow functions or anonymous classes, so the file
 * stays parseable by the bundled php-parser used for .pot extraction.
 */
class SidebarsEmulatorWpmlPrefixTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'siteorigin_panels_setting' )->justReturn( array( 'page' ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'trailingslashit' )->alias(
			function ( $value ) {
				return rtrim( $value, '/' ) . '/';
			}
		);
		Functions\when( 'get_option' )->justReturn( 0 );

		if ( ! class_exists( 'SiteOrigin_Panels_Sidebars_Emulator', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/inc/sidebars-emulator.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Drive register_widgets() for one request path and assert the path that
	 * reaches get_page_by_path().
	 */
	private function assert_lookup_path( $request_path, $expected_path, $language, $map = null ) {
		Functions\when( 'add_query_arg' )->justReturn( $request_path );

		Filters\expectApplied( 'wpml_current_language' )->once()->andReturn( $language );

		if ( $map === null ) {
			Filters\expectApplied( 'wpml_language_codes_map' )->never();
		} else {
			Filters\expectApplied( 'wpml_language_codes_map' )->once()->andReturn( $map );
		}

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( $expected_path, OBJECT, array( 'page' ) )
			->andReturn( null );

		\SiteOrigin_Panels_Sidebars_Emulator::single()->register_widgets();
	}

	public function test_mapped_prefix_is_stripped() {
		$this->assert_lookup_path( '/de-de/about/', '/about/', 'de', array( 'de' => 'de-de' ) );
	}

	public function test_identity_map_strips_the_code() {
		// WPML 4.x returns the seed array unchanged.
		Functions\when( 'add_query_arg' )->justReturn( '/de/about/' );
		Filters\expectApplied( 'wpml_current_language' )->once()->andReturn( 'de' );
		Filters\expectApplied( 'wpml_language_codes_map' )->once()->andReturnArg( 0 );
		Functions\expect( 'get_page_by_path' )
			->once()
			->with( '/about/', OBJECT, array( 'page' ) )
			->andReturn( null );

		\SiteOrigin_Panels_Sidebars_Emulator::single()->register_widgets();
	}

	public function test_no_wpml_leaves_path_untouched_and_skips_the_map() {
		$this->assert_lookup_path( '/de/about/', '/de/about/', null );
	}

	public function test_prefix_with_regex_metacharacter_is_stripped() {
		$this->assert_lookup_path( '/en.us/about/', '/about/', 'en', array( 'en' => 'en.us' ) );
	}

	public function test_prefix_metacharacter_is_quoted() {
		$this->assert_lookup_path( '/enXus/about/', '/enXus/about/', 'en', array( 'en' => 'en.us' ) );
	}

	public function test_partial_map_falls_back_to_the_code() {
		$this->assert_lookup_path( '/de/about/', '/about/', 'de', array( 'fr' => 'fr-fr' ) );
	}

	public function test_empty_map_value_falls_back_to_the_code() {
		$this->assert_lookup_path( '/de/about/', '/about/', 'de', array( 'de' => '' ) );
	}

	public function test_non_string_map_value_falls_back_to_the_code() {
		$this->assert_lookup_path( '/de/about/', '/about/', 'de', array( 'de' => array( 'x' ) ) );
	}

	public function test_zero_prefix_is_a_valid_prefix() {
		$this->assert_lookup_path( '/0/about/', '/about/', 'de', array( 'de' => '0' ) );
	}

	public function test_mapped_prefix_registers_the_page_widgets() {
		Functions\when( 'add_query_arg' )->justReturn( '/de-de/about/' );
		Filters\expectApplied( 'wpml_current_language' )->once()->andReturn( 'de' );
		Filters\expectApplied( 'wpml_language_codes_map' )->once()->andReturn( array( 'de' => 'de-de' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( '/about/', OBJECT, array( 'page' ) )
			->andReturn( (object) array( 'ID' => 42 ) );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, 'panels_data', true )
			->andReturn(
				array(
					'widgets' => array(
						array(
							'panels_info'            => array( 'class' => 'WP_Widget_Text' ),
							'so_sidebar_emulator_id' => 'text-3',
							'option_name'            => 'widget_text',
						),
					),
				)
			);

		\SiteOrigin_Panels_Sidebars_Emulator::single()->register_widgets();

		$this->assertTrue( (bool) Filters\has( 'option_widget_text' ) );
	}
}
