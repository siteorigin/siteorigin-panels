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

	/* ---- The fast path gives the same result as the full check ---- */

	/**
	 * The index handling without its fast paths: the UTF-8 check and the two
	 * patterns, applied to every string on every call. The builder must return
	 * the same bytes as this for every input.
	 */
	private static function reference_index( $index ) {
		if ( ! is_string( $index ) ) {
			return $index;
		}

		if ( ! mb_check_encoding( $index, 'UTF-8' ) ) {
			return preg_replace( '/[^A-Za-z0-9_-]/', '', $index );
		}

		if ( preg_match( '/^[\x{0080}-\x{10FFFF}A-Za-z0-9_-]+\z/u', $index ) ) {
			return $index;
		}

		return preg_replace( '/[^\x{0080}-\x{10FFFF}A-Za-z0-9_-]/u', '', $index );
	}

	private function builder_index( $index ) {
		$method = new \ReflectionMethod( \SiteOrigin_Panels_Css_Builder::class, 'safe_selector_index' );
		$method->setAccessible( true );

		return $method->invoke( new \SiteOrigin_Panels_Css_Builder(), $index );
	}

	public function test_the_empty_string_is_returned_unchanged() {
		$this->assertSame( '', $this->builder_index( '' ) );
		$this->assertSame( '', self::reference_index( '' ) );
		$this->assertSame( '# { color:red } ', $this->css( 'add_row_css', array( 12, '', '', array( 'color' => 'red' ) ) ) );
	}

	public function test_every_single_byte_gives_the_same_result_as_the_full_check() {
		for ( $byte = 0; $byte < 256; $byte++ ) {
			$index = chr( $byte );

			$this->assertSame( self::reference_index( $index ), $this->builder_index( $index ), 'Byte ' . $byte );
			$this->assertSame( self::reference_index( 'a' . $index . 'b' ), $this->builder_index( 'a' . $index . 'b' ), 'Byte ' . $byte . ' inside a safe string' );
		}
	}

	public function test_the_safe_ascii_characters_are_exactly_the_ones_kept() {
		$kept = '';

		for ( $byte = 0; $byte < 128; $byte++ ) {
			$kept .= $this->builder_index( chr( $byte ) );
		}

		$this->assertSame( '-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz', $kept );
	}

	public function test_generated_strings_give_the_same_result_as_the_full_check() {
		// Safe ASCII, other ASCII, multi-byte characters and bytes that are not
		// valid UTF-8, mixed at random with a fixed seed.
		$pieces = array(
			'a', 'Z', '0', '9', '_', '-', 'gb42', 'row',
			' ', '<', '>', '{', '}', ';', ':', '.', '#', ',', '"', "'", '/', '*', '\\', "\n", "\t", "\0", '$', '[', ']', '(', ')',
			'ü', 'é', '行', '😀', "\u{00A0}", "\u{2028}",
			"\xff", "\xfe", "\xc3", "\x80", "\xe2\x82",
		);
		$last = count( $pieces ) - 1;

		mt_srand( 1379 );

		for ( $run = 0; $run < 20000; $run++ ) {
			$index  = '';
			$length = mt_rand( 0, 8 );

			for ( $position = 0; $position < $length; $position++ ) {
				// Lean towards the safe pieces so both paths get many inputs.
				$index .= $pieces[ mt_rand( 0, mt_rand( 0, 3 ) === 0 ? $last : 7 ) ];
			}

			$this->assertSame( self::reference_index( $index ), $this->builder_index( $index ), 'Index ' . bin2hex( $index ) );
		}
	}

	/**
	 * The row selector one builder gives for $index, with the CSS it has
	 * collected so far cleared. The builder keeps its record of checked
	 * indexes between calls.
	 */
	private function row_selector_on( $builder, $index ) {
		$builder->css = array();
		$builder->add_row_css( 12, $index, '', array( 'color' => 'red' ), 1920, true );

		return $builder->get_css();
	}

	public function test_one_builder_gives_the_same_result_for_every_call_with_generated_strings() {
		// The same generated strings, all through ONE builder, so an index it
		// has already checked is used again and again between other indexes.
		$pieces = array(
			'a', 'Z', '0', '9', '_', '-', 'gb42', 'row',
			' ', '<', '{', ';', '.', ',', "\n", 'ü', '行', "\xff", "\xc3",
		);
		$last    = count( $pieces ) - 1;
		$builder = new \SiteOrigin_Panels_Css_Builder();

		mt_srand( 1379 );

		for ( $run = 0; $run < 20000; $run++ ) {
			$index  = '';
			$length = mt_rand( 0, 3 );

			for ( $position = 0; $position < $length; $position++ ) {
				$index .= $pieces[ mt_rand( 0, mt_rand( 0, 2 ) === 0 ? $last : 7 ) ];
			}

			$this->assertSame(
				'#pl-12 #' . self::reference_index( $index ) . ' { color:red } ',
				$this->row_selector_on( $builder, $index ),
				'Index ' . bin2hex( $index )
			);
		}
	}

	public function test_a_checked_index_does_not_let_a_different_index_through() {
		$builder = new \SiteOrigin_Panels_Css_Builder();

		// Check and record these first.
		$this->assertSame( '#pl-12 #42 { color:red } ', $this->row_selector_on( $builder, '42' ) );
		$this->assertSame( '#pl-12 #abc { color:red } ', $this->row_selector_on( $builder, 'abc' ) );
		$this->assertSame( '#pl-12 # { color:red } ', $this->row_selector_on( $builder, '' ) );

		// Indexes that are near a recorded one are still checked.
		$this->assertSame( '#pl-12 #42 { color:red } ', $this->row_selector_on( $builder, '42 ' ) );
		$this->assertSame( '#pl-12 #42 { color:red } ', $this->row_selector_on( $builder, ' 42' ) );
		$this->assertSame( '#pl-12 #42 { color:red } ', $this->row_selector_on( $builder, '+42' ) );
		$this->assertSame( '#pl-12 #420 { color:red } ', $this->row_selector_on( $builder, '42.0' ) );
		$this->assertSame( '#pl-12 #042 { color:red } ', $this->row_selector_on( $builder, '042<' ) );
		$this->assertSame( '#pl-12 #abc { color:red } ', $this->row_selector_on( $builder, 'abc<' ) );
		$this->assertSame( '#pl-12 #abc { color:red } ', $this->row_selector_on( $builder, "abc\n" ) );
		$this->assertSame( '#pl-12 # { color:red } ', $this->row_selector_on( $builder, ' ' ) );
		$this->assertSame( '#pl-12 # { color:red } ', $this->row_selector_on( $builder, "\0" ) );

		// An integer index is a positional row, also after the string "42" was recorded.
		$this->assertSame( '#pl-12 #pg-12-42 { color:red } ', $this->row_selector_on( $builder, 42 ) );

		// The recorded indexes give the same result again.
		$this->assertSame( '#pl-12 #42 { color:red } ', $this->row_selector_on( $builder, '42' ) );
		$this->assertSame( '#pl-12 #abc { color:red } ', $this->row_selector_on( $builder, 'abc' ) );
	}

	public function test_an_index_with_other_characters_is_cleaned_on_every_call_of_one_builder() {
		$builder = new \SiteOrigin_Panels_Css_Builder();

		for ( $call = 0; $call < 3; $call++ ) {
			$this->assertSame( '#pl-12 #ab { color:red } ', $this->row_selector_on( $builder, 'a b' ) );
			$this->assertSame( '#pl-12 #überx { color:red } ', $this->row_selector_on( $builder, 'über<x' ) );
			$this->assertSame( '#pl-12 #über-row { color:red } ', $this->row_selector_on( $builder, 'über-row' ) );
		}
	}

	public function test_values_that_are_not_strings_are_returned_as_they_are() {
		foreach ( array( 0, 7, -1, false, true, null, 1.5 ) as $index ) {
			$this->assertSame( $index, $this->builder_index( $index ) );
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
