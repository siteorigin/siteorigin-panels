<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A submitted layout that is not a layout never reaches a write: the save
 * paths refuse it and leave the stored layout in place, on the post screen,
 * the home page screen and the Layout Builder widget alike.
 *
 * Uses named classes and closures only; the i18n .pot extraction's bundled
 * php-parser cannot read arrow functions or anonymous classes.
 */
class SavePostMalformedPayloadTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$this->require_admin_class();
		$this->require_layout_widget();

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load inc/admin.php with the facade stub it references at include time.
	 */
	private function require_admin_class() {
		if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
			eval(
				'class SiteOrigin_Panels {'
				. ' public static $instance_resolver = null;'
				. ' public static function get_widget_instance( $class ) {'
				. '   return self::$instance_resolver ? call_user_func( self::$instance_resolver, $class ) : null;'
				. ' }'
				. '}'
			);
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin', false ) ) {
			// sanitize_all() passes the layout through unchanged so the test
			// observes what the save path itself does with it.
			eval(
				'class SiteOrigin_Panels_Styles_Admin {'
				. ' private static $instance;'
				. ' public static $sanitize_calls = 0;'
				. ' public static function single() {'
				. '   if ( empty( self::$instance ) ) { self::$instance = new self(); }'
				. '   return self::$instance;'
				. ' }'
				. ' public function sanitize_all( $panels_data ) { self::$sanitize_calls++; return $panels_data; }'
				. '}'
			);
		}

		if ( ! function_exists( 'add_action' ) ) {
			Functions\when( 'add_action' )->justReturn( true );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/inc/admin.php';
		}
	}

	/**
	 * Load the Layout Builder widget on top of a minimal WP_Widget stand-in.
	 */
	private function require_layout_widget() {
		if ( ! class_exists( 'WP_Widget', false ) ) {
			eval(
				'class WP_Widget {'
				. ' public function __construct( $id_base = null, $name = null, $widget_options = array(), $control_options = array() ) {}'
				. '}'
			);
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Widgets_Layout', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/inc/widgets/layout.php';
		}
	}

	private function admin() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Admin::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function in_save_post( $admin ) {
		$property = new \ReflectionProperty( \SiteOrigin_Panels_Admin::class, 'in_save_post' );
		$property->setAccessible( true );

		return $property->getValue( $admin );
	}

	private function empty_layout() {
		return array(
			'widgets'    => array(),
			'grids'      => array(),
			'grid_cells' => array(),
		);
	}

	private function valid_layout() {
		return array(
			'widgets'    => array(),
			'grids'      => array( array( 'cells' => 1, 'style' => array() ) ),
			'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
		);
	}

	/* ---- decode_panels_data() ---- */

	public function test_decoder_rejects_malformed_json() {
		$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( '{bad' ) );
	}

	public function test_decoder_rejects_scalars_and_null() {
		foreach ( array( 'null', '1', '"x"', 'true' ) as $json ) {
			$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( $json ), $json );
		}
	}

	public function test_decoder_rejects_lists_and_objects_without_layout_keys() {
		foreach ( array( '[1]', '[{}]', '{"foo":"bar"}' ) as $json ) {
			$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( $json ), $json );
		}
	}

	public function test_decoder_rejects_scalar_layout_members() {
		foreach ( array( '{"grids":"x","grid_cells":[]}', '{"grids":[],"grid_cells":1}', '{"widgets":"","grids":[],"grid_cells":[]}' ) as $json ) {
			$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( $json ), $json );
		}
	}

	public function test_decoder_rejects_partial_layouts_without_rows_and_cells() {
		// The builder always serializes grids and grid_cells; an object missing
		// either would read as an empty layout and delete the stored one.
		foreach ( array( '{"widgets":[]}', '{"grids":[]}', '{"grid_cells":[]}', '{"widgets":[],"grids":[]}' ) as $json ) {
			$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( $json ), $json );
		}
	}

	public function test_decoder_fills_in_missing_widgets_when_rows_and_cells_are_present() {
		$decoded = \SiteOrigin_Panels_Admin::decode_panels_data( '{"grids":[],"grid_cells":[]}' );

		$this->assertEquals( $this->empty_layout(), $decoded );
	}

	public function test_decoder_turns_false_into_the_empty_layout() {
		$this->assertSame( $this->empty_layout(), \SiteOrigin_Panels_Admin::decode_panels_data( 'false' ) );
	}

	public function test_decoder_rejects_empty_object_and_empty_list() {
		// The builder never submits either; once decoded they are the same
		// empty array, and an empty array must not clear a stored layout.
		foreach ( array( '{}', '[]' ) as $json ) {
			$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( $json ), $json );
		}
	}

	public function test_decoder_returns_a_layout_unchanged() {
		$layout = $this->valid_layout();
		$layout['extra'] = 'kept';

		$this->assertSame( $layout, \SiteOrigin_Panels_Admin::decode_panels_data( json_encode( $layout ) ) );
	}

	public function test_decoder_keeps_escaped_quotes_and_backslashes() {
		$layout = $this->valid_layout();
		$layout['widgets'][] = array( 'title' => 'He said "hi" \\ C:\\path', 'panels_info' => array( 'class' => 'X' ) );

		$decoded = \SiteOrigin_Panels_Admin::decode_panels_data( json_encode( $layout ) );

		$this->assertSame( 'He said "hi" \\ C:\\path', $decoded['widgets'][0]['title'] );
	}

	public function test_decoder_rejects_non_strings() {
		$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( null ) );
		$this->assertNull( \SiteOrigin_Panels_Admin::decode_panels_data( array() ) );
	}

	/* ---- save_post() ---- */

	public function test_save_post_with_malformed_payload_touches_nothing() {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		// The first thing save_post() does after accepting a payload.
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'update_metadata' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$_POST['_sopanels_nonce'] = 'nonce';
		$_POST['panels_data'] = '{bad';

		$admin = $this->admin();
		$admin->save_post( 42 );

		$this->assertEmpty( $this->in_save_post( $admin ), 'the save guard was never raised' );
	}

	public function test_save_post_with_a_list_payload_touches_nothing() {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$_POST['_sopanels_nonce'] = 'nonce';
		$_POST['panels_data'] = '[1]';

		$this->admin()->save_post( 42 );
	}

	/* ---- save_home_page() ---- */

	private function stub_home_page_collaborators() {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->justReturn( array( 'widgets' => array() ) );
		Functions\when( 'sanitize_post_field' )->returnArg( 2 );
		Functions\when( 'wp_update_post' )->justReturn( 7 );
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7, 'post_status' => 'publish' ) );
		Functions\when( 'siteorigin_panels_setting' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'map_deep' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_publish_post' )->justReturn( true );
	}

	public function test_home_page_with_malformed_payload_writes_nothing_and_reports_no_save() {
		$this->stub_home_page_collaborators();
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'wp_update_post' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$_POST['_sopanels_home_nonce'] = 'nonce';
		$_POST['panels_data'] = '{bad';
		$_POST['post_content'] = 'content';

		$admin = $this->admin();
		$admin->save_home_page();

		$this->assertFalse( $admin->home_page_saved() );
	}

	public function test_home_page_clears_on_false_and_refuses_an_empty_object() {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$_POST = array( '_sopanels_home_nonce' => 'nonce', 'panels_data' => '{}', 'post_content' => 'content' );
		$admin = $this->admin();
		$admin->save_home_page();
		$this->assertFalse( $admin->home_page_saved(), '{} is not a clear' );

		foreach ( array( 'false' ) as $json ) {
			Monkey\tearDown();
			Monkey\setUp();
			Functions\when( '__' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			$this->stub_home_page_collaborators();

			$written = null;
			Functions\expect( 'update_post_meta' )
				->once()
				->andReturnUsing(
					function ( $id, $key, $value ) use ( &$written ) {
						$written = $value;
						return true;
					}
				);

			$_POST = array(
				'_sopanels_home_nonce'          => 'nonce',
				'panels_data'                   => $json,
				'post_content'                  => 'content',
				'siteorigin_panels_home_enabled' => 1,
			);

			$admin = $this->admin();
			$admin->save_home_page();

			$this->assertSame( $this->empty_layout(), $written, $json );
			$this->assertTrue( $admin->home_page_saved(), $json );
		}
	}

	public function test_home_page_saved_flag_resets_on_a_later_malformed_save() {
		$this->stub_home_page_collaborators();
		Functions\when( 'update_post_meta' )->justReturn( true );

		$_POST = array(
			'_sopanels_home_nonce'          => 'nonce',
			'panels_data'                   => json_encode( $this->valid_layout() ),
			'post_content'                  => 'content',
			'siteorigin_panels_home_enabled' => 1,
		);

		$admin = $this->admin();
		$admin->save_home_page();
		$this->assertTrue( $admin->home_page_saved() );

		$_POST['panels_data'] = '{bad';
		$admin->save_home_page();
		$this->assertFalse( $admin->home_page_saved(), 'a no-op save on the same instance must not read as saved' );
	}

	/* ---- Layout Builder widget ---- */

	private function widget() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Widgets_Layout::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	public function test_layout_widget_keeps_old_layout_when_field_is_absent() {
		$old = array( 'builder_id' => 'old', 'panels_data' => $this->valid_layout() );

		$updated = $this->widget()->update( array( 'title' => 'x' ), $old );

		$this->assertSame( $this->valid_layout(), $updated['panels_data'] );
		$this->assertNotSame( 'old', $updated['builder_id'], 'a locked nested save still gets a fresh builder id' );
		$this->assertSame( 'x', $updated['title'] );
	}

	public function test_layout_widget_returns_old_instance_on_malformed_string() {
		$old = array( 'builder_id' => 'old', 'panels_data' => $this->valid_layout() );

		$updated = $this->widget()->update( array( 'panels_data' => '{bad' ), $old );

		$this->assertSame( $old, $updated );
	}

	public function test_layout_widget_still_sanitizes_an_array_layout() {
		$old = array( 'builder_id' => 'old', 'panels_data' => array() );
		$new = array( 'panels_data' => $this->valid_layout() );
		$calls_before = \SiteOrigin_Panels_Styles_Admin::$sanitize_calls;

		$updated = $this->widget()->update( $new, $old );

		$this->assertSame( $this->valid_layout(), $updated['panels_data'] );
		$this->assertNotSame( 'old', $updated['builder_id'] );
		$this->assertSame( $calls_before + 1, \SiteOrigin_Panels_Styles_Admin::$sanitize_calls, 'sanitize_all() ran on the array layout' );
	}

	public function test_layout_widget_does_not_sanitize_when_the_field_is_absent_or_refused() {
		$old = array( 'builder_id' => 'old', 'panels_data' => $this->valid_layout() );
		$calls_before = \SiteOrigin_Panels_Styles_Admin::$sanitize_calls;

		$this->widget()->update( array( 'title' => 'x' ), $old );
		$this->widget()->update( array( 'panels_data' => '{bad' ), $old );

		$this->assertSame( $calls_before, \SiteOrigin_Panels_Styles_Admin::$sanitize_calls, 'nothing to sanitize on the preserving paths' );
	}

	public function test_layout_widget_decodes_a_valid_string_layout() {
		$old = array( 'builder_id' => 'old', 'panels_data' => array() );
		$new = array( 'panels_data' => json_encode( $this->valid_layout() ) );

		$updated = $this->widget()->update( $new, $old );

		$this->assertSame( $this->valid_layout(), $updated['panels_data'] );
	}
}
