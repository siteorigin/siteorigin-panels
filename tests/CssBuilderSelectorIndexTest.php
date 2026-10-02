<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the selector index handling of SiteOrigin_Panels_Css_Builder.
 *
 * add_row_css(), add_cell_css() and add_widget_css() place the layout, row,
 * cell and widget indexes into a selector. Every index must give a well-formed
 * selector, and every index that was already well formed must give the same
 * bytes as before.
 *
 * The builder uses only PHP builtins plus wp_strip_all_tags(), so this runs
 * without a WordPress bootstrap. No arrow functions or anonymous classes
 * (build-toolchain parser compatibility); `: void` on setUp/tearDown.
 */
class CssBuilderSelectorIndexTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);

		if ( ! class_exists( 'SiteOrigin_Panels_Css_Builder', false ) ) {
			require_once dirname( __DIR__ ) . '/inc/css-builder.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The CSS one builder call produces.
	 */
	private function css( $method, array $args ) {
		$builder = new \SiteOrigin_Panels_Css_Builder();
		call_user_func_array( array( $builder, $method ), $args );

		return $builder->get_css();
	}

	/**
	 * The selector text of one builder call: everything before the declaration block.
	 */
	private function selector( $method, array $args ) {
		$css = $this->css( $method, $args );
		$end = strrpos( $css, ' { color:red } ' );

		$this->assertNotFalse( $end, 'The declaration block must close the output: ' . $css );
		$this->assertSame( strlen( $css ) - strlen( ' { color:red } ' ), $end, 'Nothing may follow the declaration block: ' . $css );

		return substr( $css, 0, $end );
	}

	private function assert_well_formed( $selector, $context ) {
		foreach ( array( '<', '{', '}', ';', '"', "'", "\n", "\r", "\t", '\\', '/', '*', '(', ')', '[', ']', '@', '!', '=' ) as $character ) {
			$this->assertStringNotContainsString( $character, $selector, $context . ' must not contain ' . json_encode( $character ) );
		}
	}

	/* ---- Legitimate indexes: byte-identical output ---- */

	public static function legitimate_calls() {
		$red = array( 'color' => 'red' );

		return array(
			'row: all rows (false)'                  => array( 'add_row_css', array( 12, false, '', $red ), '#pl-12 .panel-grid { color:red } ' ),
			'row: integer index'                     => array( 'add_row_css', array( 12, 3, '', $red ), '#pg-12-3 { color:red } ' ),
			'row: integer index, layout specified'   => array( 'add_row_css', array( 12, 3, '', $red, 1920, true ), '#pl-12 #pg-12-3 { color:red } ' ),
			'row: numeric string "0"'                => array( 'add_row_css', array( 12, '0', '', $red ), '#0 { color:red } ' ),
			'row: string row id'                     => array( 'add_row_css', array( 12, 'myrow', '', $red ), '#myrow { color:red } ' ),
			'row: string row id, layout specified'   => array( 'add_row_css', array( 12, 'myrow', '', $red, 1920, true ), '#pl-12 #myrow { color:red } ' ),
			'row: Layout Block id, sub selectors'    => array(
				'add_row_css',
				array( 'gb42-6a6f097d9d775', 0, array( '.panel-no-style', '.panel-has-style > .panel-row-style' ), $red ),
				'#pg-gb42-6a6f097d9d775-0.panel-no-style, #pg-gb42-6a6f097d9d775-0.panel-has-style > .panel-row-style { color:red } ',
			),
			'row: non-ASCII string row id'           => array( 'add_row_css', array( 12, 'über-row', '', $red ), '#über-row { color:red } ' ),
			'row: CJK string row id'                 => array( 'add_row_css', array( 12, '行-1', '', $red ), '#行-1 { color:red } ' ),
			'cell: all cells (false, false)'         => array( 'add_cell_css', array( 12, false, false, '', $red ), '#pl-12 .panel-grid-cell { color:red } ' ),
			'cell: all cells in an integer row'      => array(
				'add_cell_css',
				array( 12, 3, false, '', $red ),
				'#pg-12-3 > .panel-grid-cell , #pg-12-3 > .panel-row-style > .panel-grid-cell { color:red } ',
			),
			'cell: all cells in a string row'        => array(
				'add_cell_css',
				array( 12, 'myrow', false, '', $red ),
				'#myrow > .panel-grid-cell , #myrow > .panel-row-style > .panel-grid-cell { color:red } ',
			),
			'cell: all cells in row "0", layout specified' => array(
				'add_cell_css',
				array( 12, '0', false, ':last-child', $red, 1920, true ),
				'#pl-12 #0 > .panel-grid-cell , #pl-12 #0 > .panel-row-style > .panel-grid-cell:last-child { color:red } ',
			),
			'cell: specific cell'                    => array( 'add_cell_css', array( 12, 3, 1, '', $red ), '#pgc-12-3-1 { color:red } ' ),
			'cell: specific cell, layout specified'  => array( 'add_cell_css', array( 12, 3, 0, '', $red, 1920, true ), '#pl-12 #pgc-12-3-0 { color:red } ' ),
			'cell: specific cell, string indexes'    => array( 'add_cell_css', array( 12, 'myrow', '0', '', $red ), '#pgc-12-myrow-0 { color:red } ' ),
			'widget: all widgets'                    => array( 'add_widget_css', array( 12, false, false, false, '', $red ), '#pl-12 .so-panel { color:red } ' ),
			'widget: all widgets in an integer row'  => array( 'add_widget_css', array( 12, 3, false, false, '', $red ), '#pg-12-3 .so-panel { color:red } ' ),
			'widget: all widgets in a string row'    => array( 'add_widget_css', array( 12, 'myrow', false, false, '', $red, 1920, true ), '#pl-12 #myrow .so-panel { color:red } ' ),
			'widget: all widgets in a cell'          => array( 'add_widget_css', array( 12, 3, 1, false, '', $red ), '#pgc-12-3-1 .so-panel { color:red } ' ),
			'widget: specific widget'                => array( 'add_widget_css', array( 12, 3, 1, 2, '', $red ), '#panel-12-3-1-2 { color:red } ' ),
			'widget: specific widget, sub selector'  => array( 'add_widget_css', array( 12, 3, 0, 0, ' a', $red, 1920, true ), '#pl-12 #panel-12-3-0-0 a { color:red } ' ),
			'widget: specific widget, numeric strings' => array( 'add_widget_css', array( 12, '0', '0', '0', '', $red ), '#panel-12-0-0-0 { color:red } ' ),
		);
	}

	/**
	 * Each expected string is the output of the builder before the index
	 * handling was added, captured as a literal.
	 */
	#[DataProvider( 'legitimate_calls' )]
	public function test_legitimate_indexes_are_byte_identical( $method, $args, $expected ) {
		$this->assertSame( $expected, $this->css( $method, $args ) );
	}

	/* ---- Indexes that hold characters a selector cannot carry ---- */

	/**
	 * Index value => the value the selector must carry in its place.
	 */
	public static function unsafe_indexes() {
		return array(
			'markup'                      => array( '</style><script>x</script><style>', 'stylescriptxscriptstyle' ),
			'braces and universal'        => array( '0 {} *', '0' ),
			'space'                       => array( 'a b', 'ab' ),
			'trailing newline'            => array( "a\n", 'a' ),
			'declaration block'           => array( 'x{color:red}', 'xcolorred' ),
			'selector list'               => array( 'a,b', 'ab' ),
			'child combinator'            => array( 'a>b', 'ab' ),
			'double quote'                => array( 'a"b', 'ab' ),
			'single quote'                => array( "a'b", 'ab' ),
			'semicolon'                   => array( 'a;b', 'ab' ),
			'class, pseudo and attribute' => array( 'a.b:c#d[e]', 'abcde' ),
			'comment'                     => array( 'a/*b*/c', 'abc' ),
			'backslash'                   => array( 'a\\b', 'ab' ),
			'nothing left'                => array( '<>', '' ),
			'non-ASCII kept, markup cut'  => array( 'über<x', 'überx' ),
			'non-ASCII kept, space cut'   => array( 'café bar', 'cafébar' ),
			'CJK kept, braces cut'        => array( '行{}1', '行1' ),
			'invalid UTF-8'               => array( "\xff\xfeab<c", 'abc' ),
			'invalid UTF-8 with markup'   => array( "a\xc3</style>", 'astyle' ),
		);
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_row_index_gives_a_well_formed_row_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#' . $safe, $this->selector( 'add_row_css', array( 12, $unsafe, '', $red ) ) );
		$this->assertSame( '#pl-12 #' . $safe, $this->selector( 'add_row_css', array( 12, $unsafe, '', $red, 1920, true ) ) );
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_row_index_gives_a_well_formed_cells_in_row_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame(
			'#' . $safe . ' > .panel-grid-cell , #' . $safe . ' > .panel-row-style > .panel-grid-cell',
			$this->selector( 'add_cell_css', array( 12, $unsafe, false, '', $red ) )
		);
		$this->assertSame(
			'#pl-12 #' . $safe . ' > .panel-grid-cell , #pl-12 #' . $safe . ' > .panel-row-style > .panel-grid-cell',
			$this->selector( 'add_cell_css', array( 12, $unsafe, false, '', $red, 1920, true ) )
		);
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_row_or_cell_index_gives_a_well_formed_specific_cell_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#pgc-12-' . $safe . '-1', $this->selector( 'add_cell_css', array( 12, $unsafe, 1, '', $red ) ) );
		$this->assertSame( '#pgc-12-3-' . $safe, $this->selector( 'add_cell_css', array( 12, 3, $unsafe, '', $red ) ) );
		$this->assertSame( '#pl-12 #pgc-12-' . $safe . '-' . $safe, $this->selector( 'add_cell_css', array( 12, $unsafe, $unsafe, '', $red, 1920, true ) ) );
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_row_index_gives_a_well_formed_widgets_in_row_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#' . $safe . ' .so-panel', $this->selector( 'add_widget_css', array( 12, $unsafe, false, false, '', $red ) ) );
		$this->assertSame( '#pl-12 #' . $safe . ' .so-panel', $this->selector( 'add_widget_css', array( 12, $unsafe, false, false, '', $red, 1920, true ) ) );
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_row_or_cell_index_gives_a_well_formed_widgets_in_cell_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#pgc-12-' . $safe . '-1 .so-panel', $this->selector( 'add_widget_css', array( 12, $unsafe, 1, false, '', $red ) ) );
		$this->assertSame( '#pgc-12-3-' . $safe . ' .so-panel', $this->selector( 'add_widget_css', array( 12, 3, $unsafe, false, '', $red ) ) );
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_row_cell_or_widget_index_gives_a_well_formed_specific_widget_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#panel-12-' . $safe . '-1-2', $this->selector( 'add_widget_css', array( 12, $unsafe, 1, 2, '', $red ) ) );
		$this->assertSame( '#panel-12-3-' . $safe . '-2', $this->selector( 'add_widget_css', array( 12, 3, $unsafe, 2, '', $red ) ) );
		$this->assertSame( '#panel-12-3-1-' . $safe, $this->selector( 'add_widget_css', array( 12, 3, 1, $unsafe, '', $red ) ) );
		$this->assertSame(
			'#pl-12 #panel-12-' . $safe . '-' . $safe . '-' . $safe . ' a',
			$this->selector( 'add_widget_css', array( 12, $unsafe, $unsafe, $unsafe, ' a', $red, 1920, true ) )
		);
	}

	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_layout_index_gives_a_well_formed_selector_in_every_method( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#pl-' . $safe . ' .panel-grid', $this->selector( 'add_row_css', array( $unsafe, false, '', $red ) ) );
		$this->assertSame( '#pl-' . $safe . ' #pg-' . $safe . '-3', $this->selector( 'add_row_css', array( $unsafe, 3, '', $red, 1920, true ) ) );
		$this->assertSame( '#pl-' . $safe . ' .panel-grid-cell', $this->selector( 'add_cell_css', array( $unsafe, false, false, '', $red ) ) );
		$this->assertSame( '#pgc-' . $safe . '-3-1', $this->selector( 'add_cell_css', array( $unsafe, 3, 1, '', $red ) ) );
		$this->assertSame( '#pl-' . $safe . ' .so-panel', $this->selector( 'add_widget_css', array( $unsafe, false, false, false, '', $red ) ) );
		$this->assertSame( '#panel-' . $safe . '-3-1-2', $this->selector( 'add_widget_css', array( $unsafe, 3, 1, 2, '', $red ) ) );
	}

	/**
	 * The same indexes through every selector shape, checked character by
	 * character, so a new selector shape that skips the index handling is
	 * caught even when its exact text is not listed above.
	 */
	#[DataProvider( 'unsafe_indexes' )]
	public function test_unsafe_index_never_puts_a_structural_character_in_a_selector( $unsafe, $safe ) {
		$red = array( 'color' => 'red' );

		$calls = array(
			array( 'add_row_css', array( $unsafe, $unsafe, '', $red ) ),
			array( 'add_row_css', array( $unsafe, $unsafe, '', $red, 1920, true ) ),
			array( 'add_row_css', array( $unsafe, false, '', $red ) ),
			array( 'add_cell_css', array( $unsafe, false, false, '', $red ) ),
			array( 'add_cell_css', array( $unsafe, $unsafe, false, '', $red, 1920, true ) ),
			array( 'add_cell_css', array( $unsafe, $unsafe, $unsafe, '', $red, 1920, true ) ),
			array( 'add_widget_css', array( $unsafe, false, false, false, '', $red ) ),
			array( 'add_widget_css', array( $unsafe, $unsafe, false, false, '', $red, 1920, true ) ),
			array( 'add_widget_css', array( $unsafe, $unsafe, $unsafe, false, '', $red, 1920, true ) ),
			array( 'add_widget_css', array( $unsafe, $unsafe, $unsafe, $unsafe, '', $red, 1920, true ) ),
		);

		foreach ( $calls as $index => $call ) {
			$this->assert_well_formed( $this->selector( $call[0], $call[1] ), $call[0] . ' call ' . $index );
		}
	}

	/* ---- Index types the builder branches on ---- */

	public function test_integer_zero_and_string_zero_keep_their_separate_selectors() {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#pg-12-0', $this->selector( 'add_row_css', array( 12, 0, '', $red ) ), 'Integer 0 is a positional row.' );
		$this->assertSame( '#0', $this->selector( 'add_row_css', array( 12, '0', '', $red ) ), 'String "0" is a string row id.' );
	}

	public function test_false_still_means_every_row_cell_and_widget() {
		$red = array( 'color' => 'red' );

		$this->assertSame( '#pl-12 .panel-grid', $this->selector( 'add_row_css', array( 12, false, '', $red ) ) );
		$this->assertSame( '#pl-12 .panel-grid-cell', $this->selector( 'add_cell_css', array( 12, false, false, '', $red ) ) );
		$this->assertSame( '#pl-12 .so-panel', $this->selector( 'add_widget_css', array( 12, false, false, false, '', $red ) ) );
	}

	public function test_an_index_that_is_already_well_formed_is_returned_unchanged_a_second_time() {
		$red = array( 'color' => 'red' );

		$first  = $this->selector( 'add_row_css', array( 12, 'a b<c', '', $red ) );
		$second = $this->selector( 'add_row_css', array( 12, substr( $first, 1 ), '', $red ) );

		$this->assertSame( '#abc', $first );
		$this->assertSame( $first, $second );
	}
}
