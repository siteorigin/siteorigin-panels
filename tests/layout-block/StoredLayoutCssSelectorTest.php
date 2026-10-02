<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Locks the CSS both renderers generate for a stored layout whose row, cell or
 * widget reference is not a number.
 *
 * generate_css() reads a stored layout as it is; nothing re-saves it first.
 * So the generated CSS must hold only well-formed selectors for any stored
 * reference, and a layout with ordinary references must give the same CSS as
 * before.
 *
 * Drives the real SiteOrigin_Panels_Renderer::generate_css() and
 * SiteOrigin_Panels_Renderer_Legacy::generate_css() with the real CSS builder.
 * No arrow functions or anonymous classes (build-toolchain parser
 * compatibility); `: void` on setUp/tearDown.
 */
class StoredLayoutCssSelectorTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const MARKUP_REFERENCE = 'x</style><script>alert(1)</script><style>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$settings = array(
			'tablet-width'                       => 780,
			'mobile-width'                       => 480,
			'margin-bottom'                      => 30,
			'margin-bottom-last-row'             => false,
			'row-mobile-margin-bottom'           => '',
			'margin-sides'                       => 30,
			'responsive'                         => true,
			'tablet-layout'                      => false,
			'mobile-cell-margin'                 => 30,
			'widget-mobile-margin-bottom'        => '',
			'inline-styles'                      => false,
			'display-empty-rows-with-background' => false,
		);

		Functions\when( 'siteorigin_panels_setting' )->alias(
			function ( $key = '' ) use ( $settings ) {
				if ( $key === '' ) {
					return $settings;
				}

				return isset( $settings[ $key ] ) ? $settings[ $key ] : false;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'is_rtl' )->justReturn( false );
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);

		if ( ! function_exists( 'add_action' ) ) {
			Functions\when( 'add_action' )->justReturn( true );
		}

		if ( ! function_exists( 'add_filter' ) ) {
			Functions\when( 'add_filter' )->justReturn( true );
		}

		$root = dirname( dirname( __DIR__ ) );

		if ( ! class_exists( 'SiteOrigin_Panels_Styles', false ) ) {
			// generate_css() asks only whether a row, cell or widget has an overlay.
			eval(
				'class SiteOrigin_Panels_Styles {'
				. ' public static function single() {'
				. '   static $single;'
				. '   return empty( $single ) ? $single = new self() : $single;'
				. ' }'
				. ' public static function has_overlay( $context ) {'
				. '   return false;'
				. ' }'
				. '}'
			);
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Css_Builder', false ) ) {
			require_once $root . '/inc/css-builder.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Renderer', false ) ) {
			require_once $root . '/inc/renderer.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Renderer_Legacy', false ) ) {
			require_once $root . '/inc/renderer-legacy.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A renderer built without its constructor (which adds a WordPress hook),
	 * with the theme container settings a site without a container filter has.
	 */
	private function renderer( $class ) {
		$reflection = new \ReflectionClass( $class );
		$renderer   = $reflection->newInstanceWithoutConstructor();

		$container = new \ReflectionProperty( \SiteOrigin_Panels_Renderer::class, 'container' );
		$container->setAccessible( true );
		$container->setValue(
			$renderer,
			array(
				'selector'     => '',
				'width'        => '',
				'full_width'   => false,
				'css_override' => false,
			)
		);

		return $renderer;
	}

	/**
	 * A stored layout: one row, two cells, one widget in the first cell.
	 *
	 * @param mixed $second_cell_row The row reference of the second cell.
	 * @param array $widget_info     Extra panels_info for the widget.
	 */
	private function stored_layout( $second_cell_row, array $widget_info = array() ) {
		return array(
			'widgets'    => array(
				array(
					'text'        => 'Hello',
					'panels_info' => array_merge(
						array(
							'class' => 'WP_Widget_Text',
							'grid'  => 0,
							'cell'  => 0,
							'id'    => 0,
							'style' => array(),
						),
						$widget_info
					),
				),
			),
			'grids'      => array(
				array( 'cells' => 2, 'style' => array() ),
			),
			'grid_cells' => array(
				array( 'grid' => 0, 'weight' => 0.5 ),
				array( 'grid' => $second_cell_row, 'weight' => 0.5 ),
			),
		);
	}

	private function assert_no_markup( $css ) {
		$this->assertNotSame( '', $css, 'The layout must generate CSS.' );
		$this->assertStringNotContainsString( '<', $css, 'Generated CSS must not hold a tag.' );
		$this->assertStringNotContainsStringIgnoringCase( '</style', $css );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $css );
	}

	/**
	 * Every selector of the generated CSS: the text before each declaration block.
	 */
	private function selectors( $css ) {
		$selectors = array();

		// Drop the media query wrappers, then read each `selector { declarations }`.
		$rules = preg_replace( '/@media[^{]*\{/', '', $css );

		foreach ( explode( '}', $rules ) as $rule ) {
			$parts = explode( '{', $rule, 2 );

			if ( count( $parts ) === 2 && trim( $parts[0] ) !== '' ) {
				$selectors[] = trim( $parts[0] );
			}
		}

		return $selectors;
	}

	/* ---- Modern renderer ---- */

	public function test_modern_renderer_keeps_a_markup_cell_row_reference_inside_a_selector() {
		$css = $this->renderer( \SiteOrigin_Panels_Renderer::class )
			->generate_css( 42, $this->stored_layout( self::MARKUP_REFERENCE ) );

		$this->assert_no_markup( $css );
		$this->assertStringContainsString( '#pgc-42-xstylescriptalert1scriptstyle-0', $css, 'The reference stays in the selector as plain identifier characters.' );
	}

	public function test_modern_renderer_keeps_a_rule_cell_row_reference_inside_a_selector() {
		$css = $this->renderer( \SiteOrigin_Panels_Renderer::class )
			->generate_css( 42, $this->stored_layout( '0 , body { display:none } #x' ) );

		foreach ( $this->selectors( $css ) as $selector ) {
			$this->assertStringNotContainsString( 'body', str_replace( '0bodydisplaynonex', '', $selector ), 'No selector may target an element outside the layout: ' . $selector );
		}
		$this->assertStringNotContainsString( 'display:none } #x', $css );
		$this->assertStringContainsString( '#pgc-42-0bodydisplaynonex-0', $css );
	}

	/**
	 * The expected string is the CSS generated before the index handling was
	 * added to the CSS builder, captured as a literal.
	 */
	public function test_modern_renderer_output_is_unchanged_for_ordinary_references() {
		$expected = '#pgc-42-0-0 , #pgc-42-0-1 { width:50%;width:calc(50% - ( 0.5 * 30px ) ) } '
			. '#pl-42 .so-panel { margin-bottom:30px } '
			. '#pl-42 .so-panel:last-of-type { margin-bottom:0px } '
			. '@media (max-width:480px){ '
			. '#pg-42-0.panel-no-style, #pg-42-0.panel-has-style > .panel-row-style, #pg-42-0 { -webkit-flex-direction:column;-ms-flex-direction:column;flex-direction:column } '
			. '#pg-42-0 > .panel-grid-cell , #pg-42-0 > .panel-row-style > .panel-grid-cell { width:100%;margin-right:0 } '
			. '#pgc-42-0-0 { margin-bottom:30px } '
			. '#pl-42 .panel-grid-cell { padding:0 } '
			. '#pg-42-0 .panel-grid-cell-empty { display:none } '
			. '#pl-42 .panel-grid .panel-grid-cell-mobile-last { margin-bottom:0px } '
			. ' } ';

		$this->assertSame(
			$expected,
			$this->renderer( \SiteOrigin_Panels_Renderer::class )->generate_css( 42, $this->stored_layout( 0 ) )
		);
	}

	/* ---- Legacy renderer ---- */

	public function test_legacy_renderer_keeps_a_markup_cell_row_reference_inside_a_selector() {
		$css = $this->renderer( \SiteOrigin_Panels_Renderer_Legacy::class )
			->generate_css( 42, $this->stored_layout( self::MARKUP_REFERENCE ) );

		$this->assert_no_markup( $css );
		$this->assertStringContainsString( '#pgc-42-xstylescriptalert1scriptstyle-0', $css );
		$this->assertStringContainsString( '#xstylescriptalert1scriptstyle', $css, 'The row selector carries the same plain identifier.' );
	}

	public function test_legacy_renderer_keeps_a_markup_widget_id_inside_a_selector() {
		$css = $this->renderer( \SiteOrigin_Panels_Renderer_Legacy::class )->generate_css(
			42,
			$this->stored_layout(
				0,
				array(
					'id'    => self::MARKUP_REFERENCE,
					'style' => array( 'link_color' => '#ff0000' ),
				)
			)
		);

		$this->assert_no_markup( $css );
		$this->assertStringContainsString( '#panel-42-0-0-xstylescriptalert1scriptstyle a { color:#ff0000 } ', $css );
	}

	public function test_legacy_renderer_keeps_markup_widget_row_and_cell_references_inside_a_selector() {
		$css = $this->renderer( \SiteOrigin_Panels_Renderer_Legacy::class )->generate_css(
			42,
			$this->stored_layout(
				0,
				array(
					'grid'  => '0<i>',
					'cell'  => '0</style>',
					'id'    => '0<b>',
					'style' => array( 'link_color' => '#ff0000' ),
				)
			)
		);

		$this->assert_no_markup( $css );
		$this->assertStringContainsString( '#panel-42-0i-0style-0b a { color:#ff0000 } ', $css );
	}

	/**
	 * The expected string is the CSS generated before the index handling was
	 * added to the CSS builder, captured as a literal.
	 */
	public function test_legacy_renderer_output_is_unchanged_for_ordinary_references() {
		$expected = '#pgc-42-0-0 , #pgc-42-0-1 { width:50% } '
			. '#pg-42-0 { margin-left:-15px;margin-right:-15px } '
			. '#pg-42-0 > .panel-grid-cell , #pg-42-0 > .panel-row-style > .panel-grid-cell { padding-left:15px;padding-right:15px } '
			. '#pl-42 .so-panel { margin-bottom:30px } '
			. '#pl-42 .so-panel:last-child { margin-bottom:0px } '
			. '#panel-42-0-0-0 a { color:#ff0000 } '
			. '@media (max-width:480px){ '
			. '#pl-42 .panel-grid-cell { float:none;width:auto } '
			. '#pl-42 .panel-grid { margin-left:0;margin-right:0 } '
			. '#pl-42 .panel-grid-cell { padding:0 } '
			. '#pg-42-0 .panel-grid-cell-empty { display:none } '
			. '#pl-42 .panel-grid .panel-grid-cell-mobile-last , #pg-42-0 > .panel-grid-cell , #pg-42-0 > .panel-row-style > .panel-grid-cell:last-child { margin-bottom:0px } '
			. '#pg-42-0 > .panel-grid-cell , #pg-42-0 > .panel-row-style > .panel-grid-cell { margin-bottom:30px } '
			. ' } ';

		$this->assertSame(
			$expected,
			$this->renderer( \SiteOrigin_Panels_Renderer_Legacy::class )->generate_css(
				42,
				$this->stored_layout( 0, array( 'style' => array( 'link_color' => '#ff0000' ) ) )
			)
		);
	}
}
