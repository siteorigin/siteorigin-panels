<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Heading Color style field of cells and widgets.
 *
 * The field puts a --so-heading-color custom property on the style wrapper,
 * and filter_css_object() adds one rule that colours h1 to h6 with it and one
 * that makes links in those headings inherit the heading colour. An empty
 * field, and the key on a row, change nothing.
 *
 * Drives the real SiteOrigin_Panels_Styles with the real CSS builder. No arrow
 * functions or anonymous classes (build-toolchain parser compatibility);
 * `: void` on setUp/tearDown.
 */
class StylesHeadingColorTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const HEADINGS = ':is(h1,h2,h3,h4,h5,h6)';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$settings = array(
			'tablet-width'  => 1024,
			'mobile-width'  => 780,
			'tablet-layout' => false,
			'inline-styles' => false,
			'parallax-type' => 'modern',
		);

		Functions\when( 'siteorigin_panels_setting' )->alias(
			function ( $key = '' ) use ( $settings ) {
				return isset( $settings[ $key ] ) ? $settings[ $key ] : false;
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults = array() ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);

		// The style CSS filters run the callbacks the constructor registers on
		// them (asserted in test_the_css_callback_is_on_the_cell_and_widget_filters_only).
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				$args = array_slice( func_get_args(), 1 );

				if ( in_array( $tag, array( 'siteorigin_panels_row_style_css', 'siteorigin_panels_cell_style_css', 'siteorigin_panels_widget_style_css' ), true ) ) {
					$value = call_user_func_array( array( 'SiteOrigin_Panels_Styles', 'general_style_css' ), $args );
				}

				if ( in_array( $tag, array( 'siteorigin_panels_cell_style_css', 'siteorigin_panels_widget_style_css' ), true ) ) {
					$value = \SiteOrigin_Panels_Styles::heading_color_css( $value, $args[1] );
				}

				return $value;
			}
		);

		$root = dirname( dirname( __DIR__ ) );

		if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
			// filter_css_object() asks only whether the browser is a legacy one.
			eval(
				'class SiteOrigin_Panels {'
				. ' public static function is_legacy_browser() { return false; }'
				. ' public static function container_settings() { return array( "css_override" => false ); }'
				. ' }'
			);
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Css_Builder', false ) ) {
			require_once $root . '/inc/css-builder.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles', false ) ) {
			require_once $root . '/inc/styles.php';
		}

		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Styles', 'filter_css_object' ),
			'Expected the REAL SiteOrigin_Panels_Styles; a test stub claimed the class name first.'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A one-row, one-cell, one-widget layout as filter_css_object() reads it.
	 */
	private function layout( array $row_style, array $cell_style, array $widget_style ) {
		return array(
			array(
				'style' => $row_style,
				'cells' => array(
					array(
						'style'   => $cell_style,
						'widgets' => array(
							array( 'panels_info' => array( 'style' => $widget_style ) ),
						),
					),
				),
			),
		);
	}

	private function css( $post_id, array $layout ) {
		$builder = \SiteOrigin_Panels_Styles::filter_css_object( new \SiteOrigin_Panels_Css_Builder(), array(), $post_id, $layout );

		return $builder->get_css();
	}

	public function test_the_field_follows_link_hover_color_in_cells_and_widgets_and_rows_have_none() {
		$expected = array(
			'name'     => 'Heading Color',
			'type'     => 'color',
			'group'    => 'design',
			'priority' => 18,
		);

		$cell   = \SiteOrigin_Panels_Styles::cell_style_fields( array() );
		$widget = \SiteOrigin_Panels_Styles::widget_style_fields( array() );

		$this->assertSame( $expected, $cell['heading_color'] );
		$this->assertSame( $expected, $widget['heading_color'] );
		$this->assertSame( 17, $cell['link_color_hover']['priority'] );
		$this->assertSame( 17, $widget['link_color_hover']['priority'] );
		$this->assertArrayNotHasKey( 'heading_color', \SiteOrigin_Panels_Styles::row_style_fields( array() ) );
	}

	public function test_the_css_callback_is_on_the_cell_and_widget_filters_only() {
		$styles = new \SiteOrigin_Panels_Styles();

		$this->assertNotFalse( has_filter( 'siteorigin_panels_cell_style_css', array( $styles, 'heading_color_css' ) ) );
		$this->assertNotFalse( has_filter( 'siteorigin_panels_widget_style_css', array( $styles, 'heading_color_css' ) ) );
		$this->assertFalse( has_filter( 'siteorigin_panels_row_style_css', array( $styles, 'heading_color_css' ) ) );
	}

	public function test_a_set_field_adds_the_custom_property_and_an_empty_one_adds_nothing() {
		$this->assertSame( array( 'color' => '#111111', '--so-heading-color' => '#abcdef' ), \SiteOrigin_Panels_Styles::heading_color_css( array( 'color' => '#111111' ), array( 'heading_color' => '#abcdef' ) ) );
		$this->assertSame( array(), \SiteOrigin_Panels_Styles::heading_color_css( array(), array( 'heading_color' => '' ) ) );
		$this->assertSame( array(), \SiteOrigin_Panels_Styles::heading_color_css( array(), array() ) );
	}

	public function test_an_empty_field_gives_the_same_css_as_no_field() {
		$styles = array( 'font_color' => '#eeeeee', 'link_color' => '#dddddd', 'background' => '#222222' );

		$this->assertSame(
			$this->css( 12, $this->layout( $styles, $styles, $styles ) ),
			$this->css( 12, $this->layout( $styles + array( 'heading_color' => '' ), $styles + array( 'heading_color' => '' ), $styles + array( 'heading_color' => '' ) ) )
		);
		$this->assertSame( '', $this->css( 12, $this->layout( array(), array( 'heading_color' => '' ), array( 'heading_color' => '' ) ) ) );
	}

	public function test_the_key_on_a_row_changes_nothing() {
		$this->assertSame( '', $this->css( 12, $this->layout( array( 'heading_color' => '#123456' ), array(), array() ) ) );
		$this->assertSame(
			$this->css( 12, $this->layout( array( 'background' => '#222222' ), array(), array() ) ),
			$this->css( 12, $this->layout( array( 'background' => '#222222', 'heading_color' => '#123456' ), array(), array() ) )
		);
	}

	public function test_cell_and_widget_colours_share_one_heading_rule_and_one_link_rule() {
		$this->assertSame(
			'#pgc-12-0-0> .panel-cell-style { --so-heading-color:#abcdef } '
			. '.panel-grid-cell:where(#pgc-12-0-0) > .panel-cell-style ' . self::HEADINGS . ' , .so-panel:where(#panel-12-0-0-0) > .panel-widget-style ' . self::HEADINGS . ' { color:var(--so-heading-color) } '
			. ':where(#pgc-12-0-0) > .panel-cell-style ' . self::HEADINGS . ' a , :where(#panel-12-0-0-0) > .panel-widget-style ' . self::HEADINGS . ' a { color:inherit } '
			. '#panel-12-0-0-0> .panel-widget-style { --so-heading-color:#fedcba } ',
			$this->css( 12, $this->layout( array(), array( 'heading_color' => '#abcdef' ), array( 'heading_color' => '#fedcba' ) ) )
		);
	}

	public function test_an_unsafe_layout_id_is_cleaned_in_every_heading_selector() {
		$css = $this->css( '</style>gb<x>', $this->layout( array(), array( 'heading_color' => '#abcdef' ), array( 'heading_color' => '#fedcba' ) ) );

		$this->assertStringNotContainsString( '<', $css );
		$this->assertStringContainsString( '.panel-grid-cell:where(#pgc-stylegbx-0-0) > .panel-cell-style ' . self::HEADINGS, $css );
		$this->assertStringContainsString( ':where(#panel-stylegbx-0-0-0) > .panel-widget-style ' . self::HEADINGS . ' a', $css );
	}
}
