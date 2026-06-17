<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

/*
 * Minimal, test-local class shims. Brain Monkey mocks functions, not classes,
 * so the WordPress + Page Builder collaborator classes that inc/abilities.php
 * touches are stubbed here only if the real ones are not already loaded. Keep
 * them minimal — just enough for SiteOrigin_Panels_Abilities to run as a unit.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

/**
 * Spyable stand-in for SiteOrigin_Panels_Admin::single()->process_raw_widgets().
 * Records the arguments it received so tests can assert the §3 sanitize contract.
 */
class Abilities_AdminSpy {
	public static $instance;
	public $process_args = null;

	public static function single() {
		return self::$instance;
	}

	public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
		$this->process_args = array( $widgets, $old_widgets, $escape_classes );

		// Simulate a cleaned widget set so tests can assert persisted data is the
		// sanitizer output, not raw input.
		return array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) );
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Admin' ) ) {
	class SiteOrigin_Panels_Admin {
		public static function single() {
			return Abilities_AdminSpy::single();
		}
	}
}

/**
 * Spyable stand-in for SiteOrigin_Panels_Styles_Admin::single()->sanitize_all().
 */
class Abilities_StylesSpy {
	public static $instance;
	public $sanitize_all_called = false;

	public static function single() {
		return self::$instance;
	}

	public function sanitize_all( $panels_data ) {
		$this->sanitize_all_called = true;

		return $panels_data;
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin' ) ) {
	class SiteOrigin_Panels_Styles_Admin {
		public static function single() {
			return Abilities_StylesSpy::single();
		}
	}
}

/*
 * Real-function stubs for the Abilities API. register_abilities() / the category
 * registration guard on function_exists(); Brain Monkey cannot satisfy a
 * function_exists() check, so we define these as genuine functions that capture
 * each registration into globals for the registration-shape test to inspect.
 */
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $id, $args ) {
		$GLOBALS['abilities_registered'][ $id ] = $args;

		return true;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( $id, $args ) {
		$GLOBALS['ability_categories_registered'][ $id ] = $args;

		return true;
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Abilities' ) ) {
	require __DIR__ . '/../inc/abilities.php';
}

/**
 * Unit tests for SiteOrigin_Panels_Abilities.
 *
 * Covers the locked layout-get / layout-update contracts and the §3 guarantee
 * that ability-supplied layouts are re-sanitized through process_raw_widgets()
 * before persistence. Mirrors inc/abilities.php; production must not change to
 * fit a test.
 */
class AbilitiesTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		Abilities_AdminSpy::$instance  = new Abilities_AdminSpy();
		Abilities_StylesSpy::$instance = new Abilities_StylesSpy();

		$GLOBALS['abilities_registered']           = array();
		$GLOBALS['ability_categories_registered']  = array();
	}

	private function abilities(): SiteOrigin_Panels_Abilities {
		return SiteOrigin_Panels_Abilities::single();
	}

	// --- Permissions ---------------------------------------------------------

	public function test_layout_get_permission_denied_without_edit_post() {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->abilities()->layout_get_permission( array( 'post_id' => 5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_cannot_read_layout', $result->get_error_code() );
	}

	public function test_layout_update_permission_denied_without_edit_post() {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->abilities()->layout_update_permission( array( 'post_id' => 5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_cannot_update_layout', $result->get_error_code() );
	}

	public function test_layout_update_permission_granted_with_edit_post() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( $this->abilities()->layout_update_permission( array( 'post_id' => 5 ) ) );
	}

	// --- layout-update: missing post -----------------------------------------

	public function test_update_missing_post_is_declined() {
		Functions\when( 'get_post' )->justReturn( null );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 99,
				'panels_data' => array( 'widgets' => array() ),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	// --- layout-update: block-stored declined --------------------------------

	public function test_update_block_stored_post_is_declined_without_write() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => '<!-- block -->' ) );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => array( 'widgets' => array() ) ),
				),
			)
		);

		// update_post_meta MUST NOT be called on the block path.
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 7,
				'panels_data' => array( 'widgets' => array() ),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
		// Sanitizer must not have run for a declined write.
		$this->assertNull( Abilities_AdminSpy::$instance->process_args );
	}

	// --- layout-update: meta path persists sanitized data --------------------

	public function test_update_meta_path_sanitizes_and_persists() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( array( 'widgets' => array( 'old-widget' ) ) );

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = array( $post_id, $key, $value );

				return true;
			}
		);

		$hostile = array( 'panels_info' => array( 'class' => 'Evil_Widget' ), 'raw' => '<script>' );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 12,
				'panels_data' => array( 'widgets' => array( $hostile ) ),
			)
		);

		// Contract.
		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );

		// §3: incoming raw widgets reached the sanitizer (arg0), with old widgets
		// (arg1) and $escape_classes=false (arg2) mirroring the classic save shape.
		$args = Abilities_AdminSpy::$instance->process_args;
		$this->assertNotNull( $args, 'process_raw_widgets() was never invoked.' );
		$this->assertContains( $hostile, $args[0] );
		$this->assertSame( array( 'old-widget' ), $args[1] );
		$this->assertFalse( $args[2] );

		// sanitize_all() ran, mirroring the classic save path.
		$this->assertTrue( Abilities_StylesSpy::$instance->sanitize_all_called );

		// Persisted widgets are the SANITIZER output, never the raw hostile input.
		$this->assertNotNull( $persisted );
		$this->assertSame( 12, $persisted[0] );
		$this->assertSame( 'panels_data', $persisted[1] );
		$this->assertSame(
			array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) ),
			$persisted[2]['widgets'],
			'Persisted widgets must be the sanitizer output, not raw ability input.'
		);
		$this->assertNotContains( $hostile, $persisted[2]['widgets'] );
	}

	public function test_update_meta_path_old_widgets_false_when_no_previous_layout() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$this->abilities()->layout_update(
			array(
				'post_id'     => 13,
				'panels_data' => array( 'widgets' => array() ),
			)
		);

		// Mirrors admin.php: old widgets arg is false when there is no prior layout.
		$this->assertFalse( Abilities_AdminSpy::$instance->process_args[1] );
	}

	// --- Registration shape (locks the public surface) -----------------------

	public function test_registers_exactly_the_two_locked_abilities() {
		$this->abilities()->register_abilities();

		$registered = $GLOBALS['abilities_registered'];

		$this->assertSame(
			array( 'siteorigin-panels/layout-get', 'siteorigin-panels/layout-update' ),
			array_keys( $registered ),
			'Exactly the two locked ability ids must be registered.'
		);
	}

	public function test_layout_get_registration_meta_and_category() {
		$this->abilities()->register_abilities();

		$get = $GLOBALS['abilities_registered']['siteorigin-panels/layout-get'];

		$this->assertTrue( $get['meta']['show_in_rest'] );
		$this->assertTrue( $get['meta']['readonly'], 'layout-get must be readonly.' );
		$this->assertSame( 'siteorigin-panels', $get['category'] );
	}

	public function test_layout_update_registration_meta_and_category() {
		$this->abilities()->register_abilities();

		$update = $GLOBALS['abilities_registered']['siteorigin-panels/layout-update'];

		$this->assertTrue( $update['meta']['show_in_rest'] );
		$this->assertArrayNotHasKey(
			'readonly',
			$update['meta'],
			'layout-update must NOT be marked readonly.'
		);
		$this->assertSame( 'siteorigin-panels', $update['category'] );
	}

	public function test_registers_the_ability_category() {
		$this->abilities()->register_ability_category();

		$this->assertArrayHasKey( 'siteorigin-panels', $GLOBALS['ability_categories_registered'] );
		$this->assertArrayHasKey( 'label', $GLOBALS['ability_categories_registered']['siteorigin-panels'] );
	}
}
