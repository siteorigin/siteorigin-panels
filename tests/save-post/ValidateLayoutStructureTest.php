<?php

namespace SiteOrigin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit test of SiteOrigin_Panels_Admin::validate_layout_structure(),
 * the structural rule extracted from decode_panels_data().
 *
 * The rule uses only PHP builtins, and tests/bootstrap-admin.php loads the
 * real class, so these run without any WordPress function. The cases are in
 * tests/fixtures/layout-structure-cases.php, which the default suite also
 * runs against its stand-in copy of the rule.
 *
 * No arrow functions or anonymous classes (build-toolchain parser
 * compatibility).
 */
class ValidateLayoutStructureTest extends TestCase {
	private static function cases( $group ) {
		$cases = require dirname( __DIR__ ) . '/fixtures/layout-structure-cases.php';
		$sets  = array();

		foreach ( $cases[ $group ] as $name => $layout ) {
			$sets[ $name ] = array( $layout );
		}

		return $sets;
	}

	public static function valid_layouts() {
		return self::cases( 'valid' );
	}

	public static function refused_values() {
		return self::cases( 'refused' );
	}

	#[DataProvider( 'valid_layouts' )]
	public function test_a_layout_the_builder_can_load_is_returned( $layout ) {
		$expected = $layout;

		if ( ! array_key_exists( 'widgets', $expected ) ) {
			$expected['widgets'] = array();
		}

		$this->assertSame( $expected, \SiteOrigin_Panels_Admin::validate_layout_structure( $layout ) );
	}

	#[DataProvider( 'refused_values' )]
	public function test_a_value_the_builder_cannot_load_gives_null( $value ) {
		$this->assertNull( \SiteOrigin_Panels_Admin::validate_layout_structure( $value ) );
	}

	/**
	 * decode_panels_data() must apply this rule and no other to the decoded
	 * JSON, so the editor save and an array write path agree on every layout.
	 */
	#[DataProvider( 'valid_layouts' )]
	public function test_decode_panels_data_returns_what_the_rule_returns_for_a_valid_layout( $layout ) {
		$this->assertSame(
			\SiteOrigin_Panels_Admin::validate_layout_structure( $layout ),
			\SiteOrigin_Panels_Admin::decode_panels_data( json_encode( $layout ) )
		);
	}

	#[DataProvider( 'refused_values' )]
	public function test_decode_panels_data_refuses_what_the_rule_refuses( $value ) {
		if ( $value === false ) {
			// The builder submits `false` to clear the layout. decode_panels_data()
			// answers that before it applies the rule.
			$this->assertSame(
				array(
					'widgets'    => array(),
					'grids'      => array(),
					'grid_cells' => array(),
				),
				\SiteOrigin_Panels_Admin::decode_panels_data( 'false' )
			);

			return;
		}

		$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( json_encode( $value ) ) );
	}

	public function test_a_missing_widgets_list_is_filled_in_as_empty() {
		$layout = \SiteOrigin_Panels_Admin::validate_layout_structure(
			array(
				'grids'      => array( array( 'cells' => 1 ) ),
				'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ),
			)
		);

		$this->assertSame( array(), $layout['widgets'] );
	}
}
