<?php

namespace SiteOrigin\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * A siteorigin_panels_data_pre_save callback runs AFTER sanitize_all(), so a
 * style it writes as null or a scalar reaches the database unless the save
 * path cleans the filter's output as well. These tests drive the real
 * save_post() and save_home_page() with a callback that writes null on a
 * widget, a row and a cell, and read what the write function receives.
 *
 * Each test runs in a SEPARATE PROCESS so the real SiteOrigin_Panels_Admin and
 * SiteOrigin_Panels_Styles_Admin can be loaded without colliding with the
 * stand-ins other tests in this suite declare.
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them.
 */
class SavePostPreSaveFilterNullStyleTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'map_deep' )->returnArg();
		Functions\when( 'siteorigin_panels_setting' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7, 'post_status' => 'publish' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( 7 );
		Functions\when( 'sanitize_post_field' )->returnArg( 2 );
		Functions\when( 'wp_update_post' )->justReturn( 7 );
		Functions\when( 'wp_insert_post' )->justReturn( 7 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_publish_post' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );

		// The pre-save callback under test: it writes null styles at all three
		// paths, the shape a migration on this filter produced in the field.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_data_pre_save' ) {
					$value['widgets'][0]['panels_info']['style'] = null;
					$value['grids'][0]['style'] = null;
					$value['grid_cells'][0]['style'] = null;
				}

				return $value;
			}
		);

		if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
			eval(
				'class SiteOrigin_Panels {'
				. ' public static function get_widget_instance( $class ) { return null; }'
				. '}'
			);
		}

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

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require dirname( __DIR__ ) . '/inc/admin.php';
		}

		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Styles_Admin', 'sanitize_style_fields' ),
			'Expected the REAL SiteOrigin_Panels_Styles_Admin; a test stub claimed the class name first.'
		);
		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Admin', 'copy_content_to_post' ),
			'Expected the REAL SiteOrigin_Panels_Admin; a test stub claimed the class name first.'
		);

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function admin() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Admin::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function layout_json() {
		return json_encode(
			array(
				'widgets'    => array(
					array(
						'title'       => 'w',
						'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0, 'id' => 0, 'style' => array() ),
					),
				),
				'grids'      => array( array( 'cells' => 1, 'style' => array() ) ),
				'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
			)
		);
	}

	private function assert_no_style_keys( $panels_data ) {
		$this->assertIsArray( $panels_data, 'a layout was written' );
		$this->assertArrayNotHasKey( 'style', $panels_data['widgets'][0]['panels_info'], 'widget style written by the pre-save filter must be removed' );
		$this->assertArrayNotHasKey( 'style', $panels_data['grids'][0], 'row style written by the pre-save filter must be removed' );
		$this->assertArrayNotHasKey( 'style', $panels_data['grid_cells'][0], 'cell style written by the pre-save filter must be removed' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_save_post_removes_null_styles_written_by_the_pre_save_filter() {
		$written = null;
		Functions\when( 'update_metadata' )->alias(
			function ( $type, $id, $key, $value ) use ( &$written ) {
				$written = $value;

				return true;
			}
		);
		Functions\expect( 'delete_post_meta' )->never();

		$_POST['_sopanels_nonce'] = 'nonce';
		$_POST['panels_data'] = $this->layout_json();

		$this->admin()->save_post( 7 );

		$this->assert_no_style_keys( $written );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_save_home_page_removes_null_styles_written_by_the_pre_save_filter() {
		$written = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) use ( &$written ) {
				if ( $key === 'panels_data' ) {
					$written = $value;
				}

				return true;
			}
		);

		$_POST = array(
			'_sopanels_home_nonce'          => 'nonce',
			'panels_data'                   => $this->layout_json(),
			'post_content'                  => 'content',
			'siteorigin_panels_home_enabled' => 1,
		);

		$this->admin()->save_home_page();

		$this->assert_no_style_keys( $written );
	}
}
