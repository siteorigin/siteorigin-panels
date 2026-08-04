<?php

namespace SiteOrigin\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Regression tests for the cold-registry style-wipe guard in
 * SiteOrigin_Panels_Styles_Admin::sanitize_style_fields().
 *
 * When the style-field registry is COLD (the siteorigin_panels_*_style_fields
 * filters return nothing for a section), sanitize_style_fields() used to wipe
 * the stored style to array() — destroying user data every time sanitize_all()
 * ran on stored panels_data with an unhydrated registry. The guard preserves
 * the stored value instead (mirrors so-widgets-bundle PR #2316). The ceiling of
 * a preserved-but-unvalidated style value is arbitrary CSS, never script:
 * css-builder.php runs wp_strip_all_tags() over every key and value before
 * output.
 *
 * Each test runs in a SEPARATE PROCESS: the default suite's AbilitiesTest
 * defines a stand-in SiteOrigin_Panels_Styles_Admin at file scope, which would
 * otherwise claim the class name before this test could load the real one. A
 * canary assertion (the stub has no sanitize_style_fields()) guarantees these
 * tests exercise the real class and fail loudly if they ever don't.
 *
 * Under Brain Monkey there are no real WordPress filters: apply_filters()
 * returns the value unchanged by default, so the style-field registry is
 * naturally cold — exactly the state under test (the integration-environment
 * equivalent of remove_all_filters() on both style-field filters).
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them.
 *
 * @package siteorigin-panels
 */
class StylesAdminColdRegistryTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();

		// Mirror of WP core's wp_strip_all_tags() — the exact transform
		// css-builder.php applies to every style key and value before output.
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text, $remove_breaks = false ) {
				$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
				$text = strip_tags( $text );

				if ( $remove_breaks ) {
					$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
				}

				return trim( $text );
			}
		);

		// inc/styles-admin.php calls SiteOrigin_Panels_Styles::single() at file
		// scope; a minimal stand-in satisfies that without loading the whole
		// styles registry (whose absence IS the cold state under test).
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

		// Canary: the real class (not AbilitiesTest's spy stub, which lacks
		// sanitize_style_fields()) must be the one loaded in this process.
		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Styles_Admin', 'sanitize_style_fields' ),
			'Expected the REAL SiteOrigin_Panels_Styles_Admin; a test stub claimed the class name first.'
		);
	}

	/**
	 * A grid style saved by the user, containing values a cold registry cannot
	 * validate.
	 *
	 * @return array panels_data with one styled grid.
	 */
	private function panels_data_with_styled_grid() {
		return array(
			'widgets' => array(),
			'grids' => array(
				array(
					'style' => array(
						'background' => '#ffffff',
						'padding' => '10px',
						'row_css' => 'border: 1px solid red;',
					),
				),
			),
			'grid_cells' => array(),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cold_registry_preserves_style_instead_of_wiping() {
		$panels_data = $this->panels_data_with_styled_grid();

		$result = \SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		$style = $result['grids'][0]['style'];
		$this->assertNotSame( array(), $style, 'Cold registry wiped the stored grid style to array().' );
		$this->assertSame( '#ffffff', $style['background'] );
		$this->assertSame( '10px', $style['padding'] );
		$this->assertSame( 'border: 1px solid red;', $style['row_css'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_preserved_row_css_survives_css_builder_strip() {
		$panels_data = $this->panels_data_with_styled_grid();

		$result = \SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		$row_css = $result['grids'][0]['style']['row_css'];
		$this->assertSame(
			$row_css,
			wp_strip_all_tags( $row_css ),
			'Preserved row_css must pass css-builder.php\'s wp_strip_all_tags() unchanged.'
		);
	}
}
