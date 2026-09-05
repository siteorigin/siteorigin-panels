<?php

namespace SiteOrigin\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * A style entry that is not an array must not survive sanitization: a null
 * one crashes the builder's style checks when the layout loads, and nothing
 * else can render or sanitize it.
 *
 * The first group of tests drives sanitize_all() only and never names the
 * helper, so it can be run against a version of the sanitizer that lacks the
 * helper and still fail on the assertion rather than on a missing method.
 * The second group tests the helper's own contract.
 *
 * Each test runs in a SEPARATE PROCESS: the default suite's AbilitiesTest
 * defines a stand-in SiteOrigin_Panels_Styles_Admin at file scope, which would
 * otherwise claim the class name before this test could load the real one.
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them.
 */
class StylesAdminNullStyleTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);

		if ( ! class_exists( 'SiteOrigin_Panels_Styles', false ) ) {
			eval(
				'class SiteOrigin_Panels_Styles {'
				. ' public static function single() {'
				. '  static $single;'
				. '  if ( $single === null ) { $single = new self(); }'
				. '  return $single;'
				. ' }'
				. '}'
			);
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin', false ) ) {
			require dirname( __DIR__ ) . '/inc/styles-admin.php';
		}

		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Styles_Admin', 'sanitize_style_fields' ),
			'Expected the REAL SiteOrigin_Panels_Styles_Admin; a test stub claimed the class name first.'
		);
	}

	private function admin() {
		return \SiteOrigin_Panels_Styles_Admin::single();
	}

	/**
	 * One widget, one row and one cell, each carrying the given style value.
	 */
	private function layout_with_style( $style ) {
		return array(
			'widgets'    => array(
				array(
					'title'       => 'w',
					'panels_info' => array(
						'class' => 'WP_Widget_Text',
						'grid'  => 0,
						'cell'  => 0,
						'id'    => 0,
						'style' => $style,
					),
				),
			),
			'grids'      => array( array( 'cells' => 1, 'style' => $style ) ),
			'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => $style ) ),
		);
	}

	private function assert_no_style_keys( $panels_data, $message ) {
		$this->assertArrayNotHasKey( 'style', $panels_data['widgets'][0]['panels_info'], "widget: $message" );
		$this->assertArrayNotHasKey( 'style', $panels_data['grids'][0], "grid: $message" );
		$this->assertArrayNotHasKey( 'style', $panels_data['grid_cells'][0], "cell: $message" );
	}

	/* ---- sanitize_all() only: these never name the helper ---- */

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sanitize_all_removes_a_null_widget_row_and_cell_style() {
		$out = $this->admin()->sanitize_all( $this->layout_with_style( null ) );

		$this->assert_no_style_keys( $out, 'a null style must be removed' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sanitize_all_removes_scalar_styles() {
		foreach ( array( '', 0, 'x', false ) as $scalar ) {
			$out = $this->admin()->sanitize_all( $this->layout_with_style( $scalar ) );

			$this->assert_no_style_keys( $out, 'scalar ' . var_export( $scalar, true ) . ' must be removed' );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sanitize_all_keeps_array_styles() {
		$out = $this->admin()->sanitize_all( $this->layout_with_style( array( 'background' => '#fff' ) ) );

		$this->assertSame( '#fff', $out['grids'][0]['style']['background'] );
		$this->assertSame( '#fff', $out['widgets'][0]['panels_info']['style']['background'] );
		$this->assertSame( '#fff', $out['grid_cells'][0]['style']['background'] );

		$out = $this->admin()->sanitize_all( $this->layout_with_style( array() ) );

		$this->assertSame( array(), $out['grids'][0]['style'], 'an empty array style is kept, not removed' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sanitize_all_cleans_a_null_written_by_the_migration_filter() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_data_migration' ) {
					$value['grids'][0]['style'] = null;
					$value['widgets'][0]['panels_info']['style'] = null;
				}

				return $value;
			}
		);

		$out = $this->admin()->sanitize_all( $this->layout_with_style( array( 'background' => '#fff' ) ) );

		$this->assertArrayNotHasKey( 'style', $out['grids'][0] );
		$this->assertArrayNotHasKey( 'style', $out['widgets'][0]['panels_info'] );
		$this->assertSame( '#fff', $out['grid_cells'][0]['style']['background'], 'the untouched cell style is kept' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sanitize_all_skips_list_items_that_are_not_arrays() {
		$layout = $this->layout_with_style( array( 'background' => '#fff' ) );
		$layout['widgets'][] = 'not a widget';
		$layout['grids'][] = 7;
		$layout['grid_cells'][] = null;

		$out = $this->admin()->sanitize_all( $layout );

		$this->assertSame( 'not a widget', $out['widgets'][1] );
		$this->assertSame( 7, $out['grids'][1] );
		$this->assertNull( $out['grid_cells'][1] );
		$this->assertSame( '#fff', $out['grids'][0]['style']['background'] );
	}

	/* ---- the helper's own contract ---- */

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_helper_removes_only_non_array_styles_at_the_three_paths() {
		$in = $this->layout_with_style( null );
		$in['widgets'][0]['style'] = null; // not a style path; left alone

		$out = $this->admin()->remove_invalid_styles( $in );

		$this->assert_no_style_keys( $out, 'helper' );
		$this->assertArrayHasKey( 'style', $out['widgets'][0], 'a key outside the three style paths is untouched' );

		$kept = $this->admin()->remove_invalid_styles( $this->layout_with_style( array( 'a' => 1 ) ) );

		$this->assertSame( array( 'a' => 1 ), $kept['grids'][0]['style'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_helper_tolerates_missing_lists_absent_keys_and_odd_items() {
		$this->assertSame( array(), $this->admin()->remove_invalid_styles( array() ) );
		$this->assertSame( array( 'widgets' => 'x' ), $this->admin()->remove_invalid_styles( array( 'widgets' => 'x' ) ) );

		$in = array(
			'widgets'    => array( 'string', array( 'no panels_info' => true ), array( 'panels_info' => 'scalar' ), array( 'panels_info' => array( 'class' => 'X' ) ) ),
			'grids'      => array( 3, array( 'cells' => 1 ) ),
			'grid_cells' => array( null, array( 'grid' => 0 ) ),
		);

		$this->assertSame( $in, $this->admin()->remove_invalid_styles( $in ), 'nothing to remove, nothing changed' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_helper_returns_non_array_input_unchanged_and_is_idempotent() {
		$this->assertNull( $this->admin()->remove_invalid_styles( null ) );
		$this->assertFalse( $this->admin()->remove_invalid_styles( false ) );
		$this->assertSame( 'x', $this->admin()->remove_invalid_styles( 'x' ) );

		$once = $this->admin()->remove_invalid_styles( $this->layout_with_style( null ) );
		$twice = $this->admin()->remove_invalid_styles( $once );

		$this->assertSame( $once, $twice );
	}
}
