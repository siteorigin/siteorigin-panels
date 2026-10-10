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

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

/**
 * Shared, ordered call log for the pre-write order tests. Off (null) unless a
 * test sets it to an array; the spies and shims below append to it.
 */
class Abilities_CallLog {
	public static $entries = null;

	public static function add( $name ) {
		if ( is_array( self::$entries ) ) {
			self::$entries[] = $name;
		}
	}
}

/**
 * Layout fixtures the builder can load: one row with one cell, and every
 * widget placed in that cell. The layout-update ability refuses a layout
 * without rows, cells and widget placements, so every write fixture uses
 * these.
 */
class Abilities_Fixtures {
	// The widget set the default sanitizer stand-ins return.
	const CLEANED = array( array( 'panels_info' => array( 'class' => 'Cleaned', 'grid' => 0, 'cell' => 0 ) ) );

	// A widget of $class placed in row 0, cell 0, with any extra $fields.
	public static function widget( $class, array $fields = array() ) {
		return array_merge(
			$fields,
			array( 'panels_info' => array( 'class' => $class, 'grid' => 0, 'cell' => 0 ) )
		);
	}

	// A layout of one row and one cell that holds $widgets as given.
	public static function layout( array $widgets = array() ) {
		return array(
			'widgets'    => $widgets,
			'grids'      => array( array( 'cells' => 1 ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ),
		);
	}
}

/**
 * Spyable stand-in for SiteOrigin_Panels_Admin::single()->process_raw_widgets().
 * Records the arguments it received so tests can assert the §3 sanitize contract.
 */
class Abilities_AdminSpy {
	public static $instance;
	public $process_args = null;
	public $copy_content_args = null;
	public $save_guard_used = false;

	public static function single() {
		return self::$instance;
	}

	public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
		$this->process_args = array( $widgets, $old_widgets, $escape_classes );

		// Simulate a cleaned widget set so tests can assert persisted data is the
		// sanitizer output, not raw input.
		return Abilities_Fixtures::CLEANED;
	}

	// Mirrors SiteOrigin_Panels_Admin::with_save_guard(): just runs the callback,
	// recording that the guard wrapper was used around the copy-content refresh.
	public function with_save_guard( $callback ) {
		$this->save_guard_used = true;

		return $callback();
	}

	// Mirrors SiteOrigin_Panels_Admin::copy_content_to_post(): records the args so
	// the meta-write tests can assert the copy-content refresh was invoked with the
	// final sanitized layout.
	public function copy_content_to_post( $post, $post_id, $panels_data ) {
		Abilities_CallLog::add( 'copy_content_to_post' );
		$this->copy_content_args = array( $post, $post_id, $panels_data );
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Admin' ) ) {
	class SiteOrigin_Panels_Admin {
		public static function single() {
			return Abilities_AdminSpy::single();
		}

		// Mirrors the real SiteOrigin_Panels_Admin::double_slash_string() so the
		// meta-write slashing (map_deep + this callback) runs in tests.
		public static function double_slash_string( $value ) {
			return is_string( $value ) ? addcslashes( $value, '\\' ) : $value;
		}

		// A copy of the real SiteOrigin_Panels_Admin::validate_layout_structure()
		// and its two helpers, which the meta write calls. The real class cannot
		// load in this suite. tests/fixtures/layout-structure-cases.php holds
		// the cases that both this copy and the real method must agree on.
		public static function validate_layout_structure( $layout ) {
			if ( ! is_array( $layout ) || empty( $layout ) ) {
				return null;
			}

			if ( array_keys( $layout ) === range( 0, count( $layout ) - 1 ) ) {
				return null;
			}

			foreach ( array( 'grids', 'grid_cells' ) as $key ) {
				if ( ! array_key_exists( $key, $layout ) || ! self::is_list( $layout[ $key ] ) ) {
					return null;
				}
			}

			if ( ! array_key_exists( 'widgets', $layout ) ) {
				$layout['widgets'] = array();
			} elseif ( ! self::is_list( $layout['widgets'] ) ) {
				return null;
			}

			return self::layout_references_resolve( $layout ) ? $layout : null;
		}

		private static function layout_references_resolve( $layout ) {
			foreach ( $layout['grids'] as $row ) {
				if ( ! is_array( $row ) ) {
					return false;
				}
			}

			$row_count = count( $layout['grids'] );
			$cells_per_row = array_fill( 0, max( $row_count, 1 ), 0 );

			foreach ( $layout['grid_cells'] as $cell ) {
				if ( ! is_array( $cell ) || ! isset( $cell['grid'] ) || ! is_numeric( $cell['grid'] ) ) {
					return false;
				}

				$row = (int) $cell['grid'];

				if ( $row < 0 || $row >= $row_count ) {
					return false;
				}

				$cells_per_row[ $row ] ++;
			}

			foreach ( $layout['widgets'] as $widget ) {
				$info = null;
				if ( is_array( $widget ) ) {
					if ( ! empty( $widget['panels_info'] ) && is_array( $widget['panels_info'] ) ) {
						$info = $widget['panels_info'];
					} elseif ( ! empty( $widget['info'] ) && is_array( $widget['info'] ) ) {
						$info = $widget['info'];
					}
				}

				if ( $info === null ) {
					return false;
				}

				if ( ! isset( $info['grid'], $info['cell'] ) || ! is_numeric( $info['grid'] ) || ! is_numeric( $info['cell'] ) ) {
					return false;
				}

				$row = (int) $info['grid'];
				$cell = (int) $info['cell'];

				if ( $row < 0 || $row >= $row_count || $cell < 0 || $cell >= $cells_per_row[ $row ] ) {
					return false;
				}
			}

			return true;
		}

		private static function is_list( $value ) {
			if ( ! is_array( $value ) ) {
				return false;
			}

			return empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		}

		// Mirrors the real SiteOrigin_Panels_Admin::kses_deep() shape (recursive
		// wp_kses_post over string leaves), with the same on*-attribute strip the
		// other suites use to emulate wp_kses_post. Lets tests observe the
		// unconditional meta-write kses floor.
		public static function kses_deep( $value ) {
			Abilities_CallLog::add( 'kses_deep' );

			if ( is_array( $value ) ) {
				return array_map( array( __CLASS__, 'kses_deep' ), $value );
			}

			if ( ! is_string( $value ) ) {
				return $value;
			}

			return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value );
		}
	}
}

/**
 * Spyable stand-in for SiteOrigin_Panels_Styles_Admin::single()->sanitize_all().
 */
class Abilities_StylesSpy {
	public static $instance;
	public $sanitize_all_called = false;
	public $remove_invalid_styles_input = null;

	public static function single() {
		return self::$instance;
	}

	public function sanitize_all( $panels_data ) {
		$this->sanitize_all_called = true;

		return $panels_data;
	}

	public function remove_invalid_styles( $panels_data ) {
		$this->remove_invalid_styles_input = $panels_data;

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

/**
 * Spyable stand-in for SiteOrigin_Panels_Compat_Layout_Block::single()
 * ->sanitize_block_untrusted() — the compat save chokepoint block writes now
 * route through. Records each call and the block it received (so tests can
 * assert the RAW incoming panelsData reaches the chokepoint), and mirrors the
 * real chokepoint's observable contract: sanitized widgets and innerHTML
 * removed.
 */
class Abilities_LayoutBlockSpy {
	public static $instance;
	public $untrusted_calls = 0;
	public $received_block = null;

	public static function single() {
		return self::$instance;
	}

	public function sanitize_block_untrusted( $block ) {
		$this->untrusted_calls++;
		$this->received_block = $block;

		$block['attrs']['panelsData']['widgets'] = Abilities_Fixtures::CLEANED;
		unset( $block['innerHTML'] );

		return $block;
	}

	// Mirrors the real layout-update entry point: the same chokepoint as
	// sanitize_block_untrusted() (counted there too, so existing tests keep
	// their meaning), plus the post ID and block index it was given. A test can
	// set $abort to a WP_Error to simulate the pre-write hook stopping the write.
	public $layout_update_args = null;
	public $abort = null;

	public function sanitize_block_for_layout_update( $block, $post_id, $block_index, $stored = null, $object_keys = array() ) {
		$this->layout_update_args = array( $post_id, $block_index, $stored, $object_keys );

		if ( $this->abort !== null ) {
			throw new SiteOrigin_Panels_Layout_Update_Aborted( $this->abort );
		}

		return $this->sanitize_block_untrusted( $block );
	}
}

/*
 * NOTE: defining this shim makes class_exists('SiteOrigin_Panels_Compat_Layout_Block')
 * TRUE for the whole default-suite process, so write_block_layout()'s
 * missing-chokepoint WP_Error guard is structurally untestable here (PHP cannot
 * undefine a class). The guard mirrors inc/ai-exposure.php's class_exists
 * precedent; its decline branch is two lines reviewed by inspection.
 * BLOCK_NAME must exist because the shared AI-exposure walk reads it once
 * class_exists() is satisfied.
 */
if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block' ) ) {
	class SiteOrigin_Panels_Compat_Layout_Block {
		const BLOCK_NAME = 'siteorigin-panels/layout-block';

		public static function single() {
			return Abilities_LayoutBlockSpy::single();
		}
	}
}

/**
 * Spyable stand-in for SiteOrigin_Panels_Sidebars_Emulator::single()
 * ->generate_sidebar_widget_ids(). Tags each widget so tests can prove the
 * emulator output is what gets persisted.
 */
class Abilities_EmulatorSpy {
	public static $instance;
	public $called = false;

	public static function single() {
		return self::$instance;
	}

	public function generate_sidebar_widget_ids( $widgets, $post_id ) {
		$this->called = true;

		return array( array( 'panels_info' => array( 'class' => 'EmulatorTagged', 'grid' => 0, 'cell' => 0 ) ) );
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Sidebars_Emulator' ) ) {
	class SiteOrigin_Panels_Sidebars_Emulator {
		public static function single() {
			return Abilities_EmulatorSpy::single();
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

/*
 * Real wp_slash()/wp_unslash() so a stubbed wp_update_post can mirror core's
 * unslashing (wp_insert_post runs wp_unslash on its input). This is what lets the
 * slashing regression test observe the bug the production wp_slash() guards
 * against: pre-fix the content reaches wp_update_post unslashed and the stub's
 * wp_unslash() then strips the JSON-escape backslashes; post-fix it is slashed
 * and survives the round-trip. Mirrors core's add_magic_quotes/stripslashes_deep.
 */
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}

		return is_string( $value ) ? addcslashes( $value, "'\"\\" ) : $value;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

// Core map_deep() polyfill so map_deep( $data, [Admin, 'double_slash_string'] )
// actually runs in the meta-slashing regression test. Mirrors core's recursion.
if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $property_name => $property_value ) {
				$value->$property_name = map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}

		return $value;
	}
}

// The abilities block walk delegates to SiteOrigin_Panels_AI_Exposure for the
// single shared qualifying-block walk; load it so this test is self-sufficient
// regardless of test execution order.
if ( ! class_exists( 'SiteOrigin_Panels_AI_Exposure' ) ) {
	require __DIR__ . '/../inc/ai-exposure.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Aborted' ) ) {
	require __DIR__ . '/../inc/layout-update-aborted.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Pre_Write' ) ) {
	require __DIR__ . '/../inc/layout-update-pre-write.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Unchanged' ) ) {
	require_once __DIR__ . '/../inc/layout-update-unchanged.php';
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
/**
 * A widget setting object that keeps a nested object in a private property.
 */
class Abilities_PrivateSettingHolder {
	private $setting;

	public function __construct( $setting ) {
		$this->setting = $setting;
	}

	public function setting() {
		return $this->setting;
	}
}

class AbilitiesTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		Abilities_AdminSpy::$instance       = new Abilities_AdminSpy();
		Abilities_StylesSpy::$instance      = new Abilities_StylesSpy();
		Abilities_EmulatorSpy::$instance    = new Abilities_EmulatorSpy();
		Abilities_LayoutBlockSpy::$instance = new Abilities_LayoutBlockSpy();

		Abilities_CallLog::$entries         = null;
		$this->pre_write_calls              = array();

		$GLOBALS['abilities_registered']           = array();
		$GLOBALS['ability_categories_registered']  = array();

		// Safe defaults the shared block walk + update routing touch. Individual
		// tests override these with Functions\when() as needed.
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'serialize_blocks' )->alias( fn( $blocks ) => $blocks );
		// Emulator off by default (mirrors the copy-content suite pattern); the
		// emulator-parity test overrides this.
		Functions\when( 'siteorigin_panels_setting' )->justReturn( false );
		// No untargetable Layout Block by default; the nested-block test overrides.
		Functions\when( 'has_block' )->justReturn( false );
		// The execute methods (layout_update/layout_get) now re-check edit_post
		// as defense in depth for direct callers; grant it by default so the
		// happy-path tests exercise behavior, and let the denial tests override.
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	private function abilities(): SiteOrigin_Panels_Abilities {
		return SiteOrigin_Panels_Abilities::single();
	}

	/**
	 * Arguments of each siteorigin_panels_layout_update_pre_write application.
	 *
	 * @var array
	 */
	private $pre_write_calls = array();

	/**
	 * Route apply_filters() so the pre-write tag is recorded (and logged in the
	 * shared call log) and answered by $listener; other tags pass through.
	 *
	 * @param callable|null $listener Receives ( $result, $panels_data, $post_id, $storage, $block_index ).
	 */
	private function listen_pre_write( $listener = null ) {
		Functions\when( 'apply_filters' )->alias(
			function () use ( $listener ) {
				$args = func_get_args();

				if ( $args[0] !== 'siteorigin_panels_layout_update_pre_write' ) {
					return $args[1];
				}

				Abilities_CallLog::add( 'pre_write' );
				$this->pre_write_calls[] = $args;

				return $listener === null ? $args[1] : call_user_func_array( $listener, array_slice( $args, 1 ) );
			}
		);
	}

	/**
	 * A classic (meta) post with no blocks and no stored layout.
	 */
	private function classic_post( $post_id ) {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => $post_id, 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	/**
	 * An Admin spy whose sanitizer returns $widgets unchanged.
	 */
	private function passthrough_admin_spy() {
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return $widgets;
			}
		};
	}

	private function assert_meta_write_did_not_happen() {
		$this->assertNull( Abilities_AdminSpy::$instance->copy_content_args, 'The copy-content mirror must not run after an abort.' );
	}

	// --- Permissions ---------------------------------------------------------

	public function test_layout_update_execute_denies_direct_call_without_edit_post() {
		// LOW-7: the execute method itself must re-check the capability, so a
		// direct in-process caller cannot bypass the permission callback.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_post' )->never();

		$result = $this->abilities()->layout_update(
			array( 'post_id' => 5, 'panels_data' => array( 'widgets' => array() ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_cannot_update_layout', $result->get_error_code() );
	}

	public function test_layout_get_execute_denies_direct_call_without_edit_post() {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->abilities()->layout_get( array( 'post_id' => 5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_cannot_read_layout', $result->get_error_code() );
	}

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

	// --- layout-update: input validation (Audit #2 MED-1 / MED-2) ------------

	public function test_object_panels_data_is_declined_and_does_not_wipe_meta() {
		// MED-2: a present-but-non-array panels_data must be declined, NOT
		// silently collapsed to array() (which on the meta path would delete the
		// existing classic layout and report success).
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7, 'post_content' => 'classic' ) );
		Functions\when( 'get_post_meta' )->justReturn( array( 'widgets' => array( 'existing' ) ) );
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array( 'post_id' => 7, 'panels_data' => (object) array( 'widgets' => array() ) )
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_object_widget_entry_is_coerced_and_sanitized_on_meta_write() {
		// MED-1: a stdClass widget entry must be coerced to an array so it reaches
		// the sanitizer, never persisted raw. The admin spy records what it saw.
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$received = null;
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public $seen_widgets = null;

			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->seen_widgets = $widgets;
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return Abilities_Fixtures::CLEANED;
			}
		};

		Functions\when( 'update_post_meta' )->justReturn( true );

		$object_widget = (object) array(
			'content'     => '<script>alert(1)</script>',
			'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0 ),
		);

		$this->abilities()->layout_update(
			array( 'post_id' => 9, 'panels_data' => Abilities_Fixtures::layout( array( $object_widget ) ) )
		);

		$seen = Abilities_AdminSpy::$instance->seen_widgets;
		$this->assertIsArray( $seen[0], 'The object widget entry must reach the sanitizer as an array.' );
		$this->assertSame(
			'<script>alert(1)</script>',
			$seen[0]['content'],
			'The coerced widget must carry its fields so the sanitizer can strip them — not be dropped or left an object.'
		);
	}

	public function test_scalar_widget_entry_is_dropped_before_persist() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public $seen_widgets = null;

			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->seen_widgets = $widgets;
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return $widgets;
			}
		};
		Functions\when( 'update_post_meta' )->justReturn( true );

		$this->abilities()->layout_update(
			array(
				'post_id'     => 9,
				'panels_data' => Abilities_Fixtures::layout( array( 'not-a-widget', Abilities_Fixtures::widget( 'Keep' ) ) ),
			)
		);

		$seen = Abilities_AdminSpy::$instance->seen_widgets;
		$this->assertCount( 1, $seen, 'The scalar widget entry must be dropped; only the valid array widget survives.' );
		$this->assertSame( 'Keep', $seen[0]['panels_info']['class'] );
	}

	// --- layout-update: block-stored writes (Phase 2c) -----------------------

	/**
	 * Build a parse_blocks() return for $n qualifying Layout Blocks, optionally
	 * interleaved with a non-layout block to prove indices count qualifying only.
	 *
	 * @param int  $n             Number of qualifying Layout Blocks.
	 * @param bool $interleave    Insert a core/paragraph before the blocks.
	 *
	 * @return array
	 */
	private function layout_blocks( int $n, bool $interleave = false ): array {
		$blocks = array();

		if ( $interleave ) {
			$blocks[] = array( 'blockName' => 'core/paragraph', 'attrs' => array() );
		}

		for ( $i = 0; $i < $n; $i++ ) {
			$blocks[] = array(
				'blockName' => 'siteorigin-panels/layout-block',
				'attrs'     => array( 'panelsData' => array( 'widgets' => array( 'existing-' . $i ) ) ),
			);
		}

		return $blocks;
	}

	public function test_update_single_block_no_index_writes_block_zero() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof WP_Error );

		// update_post_meta MUST NOT run on the block path; wp_update_post MUST.
		Functions\expect( 'update_post_meta' )->never();
		$saved = null;
		Functions\when( 'serialize_blocks' )->alias( fn( $blocks ) => $blocks );
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$saved ) {
				$saved = $args;

				return $args['ID'];
			}
		);

		$incoming = Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'New' ) ) );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 7,
				'panels_data' => $incoming,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertSame( 0, $result['block_index'] );

		// §3: the block write routes through the compat save chokepoint exactly
		// once, carrying the RAW incoming panelsData (the chokepoint owns the
		// whole filter → sanitize → forced floor sequence).
		$spy = Abilities_LayoutBlockSpy::$instance;
		$this->assertSame( 1, $spy->untrusted_calls, 'Block write must call sanitize_block_untrusted() exactly once.' );
		$this->assertSame(
			$incoming,
			$spy->received_block['attrs']['panelsData'],
			'The chokepoint must receive the raw incoming layout — no pre-processing in abilities code.'
		);

		// The deleted inline sanitize pass must be gone: neither
		// process_raw_widgets() nor sanitize_all() runs in abilities code on
		// the block path (single sanitize pass, inside the chokepoint only).
		$this->assertNull( Abilities_AdminSpy::$instance->process_args, 'No inline process_raw_widgets() on the block path.' );
		$this->assertFalse( Abilities_StylesSpy::$instance->sanitize_all_called, 'No inline sanitize_all() on the block path.' );

		// The written block carries the CHOKEPOINT output — sanitized.
		$this->assertSame(
			Abilities_Fixtures::CLEANED,
			$saved['post_content'][0]['attrs']['panelsData']['widgets']
		);
	}

	public function test_update_single_block_nonzero_index_declines() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\expect( 'wp_update_post' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 7,
				'panels_data' => array( 'widgets' => array() ),
				'block_index' => 1,
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( 0, Abilities_LayoutBlockSpy::$instance->untrusted_calls, 'No chokepoint sanitize on a declined write.' );
	}

	public function test_update_multi_block_targets_requested_index_only() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 9, 'post_content' => 'two blocks' ) );
		// Interleave a non-layout block to prove index counts qualifying blocks only.
		$blocks = $this->layout_blocks( 2, true );
		Functions\when( 'parse_blocks' )->justReturn( $blocks );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'serialize_blocks' )->alias( fn( $b ) => $b );

		$saved = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$saved ) {
				$saved = $args;

				return $args['ID'];
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 9,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'Incoming' ) ) ),
				'block_index' => 1,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertSame( 1, $result['block_index'] );

		// Layout block_index 1 == array key 2 (paragraph at 0, block0 at 1, block1 at 2).
		$written = $saved['post_content'];
		$this->assertSame(
			Abilities_Fixtures::CLEANED,
			$written[2]['attrs']['panelsData']['widgets'],
			'Targeted block (index 1) must receive the sanitized layout.'
		);
		// Block index 0 (array key 1) must be byte-identical to its original.
		$this->assertSame(
			array( 'existing-0' ),
			$written[1]['attrs']['panelsData']['widgets'],
			'Untargeted block must be left unchanged.'
		);
	}

	public function test_update_multi_block_missing_index_is_ambiguous() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 9, 'post_content' => 'two blocks' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 2 ) );
		Functions\expect( 'wp_update_post' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 9,
				'panels_data' => array( 'widgets' => array() ),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'block-ambiguous', $result['source'] );
		$this->assertStringContainsString( '0-1', $result['message'], 'Message lists the valid index range.' );
		$this->assertSame( 0, Abilities_LayoutBlockSpy::$instance->untrusted_calls, 'No chokepoint sanitize on an ambiguous decline.' );
	}

	public function test_update_multi_block_out_of_range_index_is_ambiguous() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 9, 'post_content' => 'two blocks' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 2 ) );
		Functions\expect( 'wp_update_post' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 9,
				'panels_data' => array( 'widgets' => array() ),
				'block_index' => 5,
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'block-ambiguous', $result['source'] );
		$this->assertStringContainsString( '0-1', $result['message'] );
	}

	// --- Regression: get/write index walks must agree (Blocking #1) ----------

	/**
	 * If a siteorigin_panels_data filter empties one structurally-qualifying block,
	 * BOTH read_layouts() and the targeted write must skip it identically, so an
	 * index emitted by get always resolves to the SAME block in the write. This is
	 * the highest-risk invariant of the slice; before the walk unification, the
	 * write counted the emptied block and the index targeted the wrong one.
	 */
	public function test_get_and_write_indices_agree_when_a_filter_empties_a_block() {
		// Three structurally-qualifying blocks at parse-keys 0,1,2. A filter empties
		// the MIDDLE one (key 1). Qualifying order then becomes: key0 -> index 0,
		// key2 -> index 1. So block_index 1 must write parse-key 2, never key 1.
		$blocks = array(
			array( 'blockName' => 'siteorigin-panels/layout-block', 'attrs' => array( 'panelsData' => array( 'id' => 'A', 'widgets' => array() ) ) ),
			array( 'blockName' => 'siteorigin-panels/layout-block', 'attrs' => array( 'panelsData' => array( 'id' => 'B', 'widgets' => array() ) ) ),
			array( 'blockName' => 'siteorigin-panels/layout-block', 'attrs' => array( 'panelsData' => array( 'id' => 'C', 'widgets' => array() ) ) ),
		);

		$post = (object) array( 'ID' => 21, 'post_content' => 'three blocks' );
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'parse_blocks' )->justReturn( $blocks );
		// Filter empties block B (the structurally-qualifying middle one).
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_data' && isset( $value['id'] ) && $value['id'] === 'B' ) {
					return array();
				}

				return $value;
			}
		);

		// What does read_layouts() emit? B must be skipped; A=0, C=1.
		$read = SiteOrigin_Panels_AI_Exposure::single()->read_layouts( 21 );
		$block_entries = array_values(
			array_filter( $read['layouts'], fn( $l ) => $l['storage'] === 'block' )
		);
		$this->assertCount( 2, $block_entries, 'Emptied block must not be surfaced.' );
		$this->assertSame( 'A', $block_entries[0]['panels_data']['id'] );
		$this->assertSame( 0, $block_entries[0]['block_index'] );
		$this->assertSame( 'C', $block_entries[1]['panels_data']['id'] );
		$this->assertSame( 1, $block_entries[1]['block_index'] );

		// Now write block_index 1. It MUST land on C (parse-key 2), not B (key 1).
		$saved = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$saved ) {
				$saved = $args;

				return $args['ID'];
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 21,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'NewForC' ) ) ),
				'block_index' => 1,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 1, $result['block_index'] );

		$written = $saved['post_content'];
		// Parse-key 2 (C) received the sanitized layout.
		$this->assertSame(
			Abilities_Fixtures::CLEANED,
			$written[2]['attrs']['panelsData']['widgets'],
			'block_index 1 must write parse-key 2 (C), not the emptied middle block.'
		);
		// The emptied middle block (parse-key 1, B) must be byte-identical.
		$this->assertSame( 'B', $written[1]['attrs']['panelsData']['id'] );
		$this->assertSame( array(), $written[1]['attrs']['panelsData']['widgets'] );
	}

	// --- Mixed post: block_index:null writes the meta layout (Required #3) ----

	public function test_mixed_post_no_index_writes_meta_not_block() {
		// Post has BOTH a meta layout and a Layout Block. With no block_index, the
		// write must honor the meta entry layout-get advertises (block_index:null),
		// i.e. write meta and leave the block alone.
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 31, 'post_content' => 'has a block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\when( 'get_post_meta' )->justReturn( array( 'widgets' => array( 'meta-old' ) ) );

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = array( $post_id, $key, $value );

				return true;
			}
		);
		// The block path must NOT run.
		Functions\expect( 'wp_update_post' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 31,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'MetaNew' ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertNotNull( $persisted, 'Meta layout must be written on a mixed post with no index.' );
		// Classic sanitize shape: old widgets passed through (arg1 not false here).
		$this->assertSame( array( 'meta-old' ), Abilities_AdminSpy::$instance->process_args[1] );
	}

	public function test_mixed_post_with_index_writes_targeted_block() {
		// Same mixed post, but block_index:0 explicitly targets the block.
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 31, 'post_content' => 'has a block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\when( 'get_post_meta' )->justReturn( array( 'widgets' => array( 'meta-old' ) ) );
		Functions\expect( 'update_post_meta' )->never();

		$saved = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$saved ) {
				$saved = $args;

				return $args['ID'];
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 31,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'BlockNew' ) ) ),
				'block_index' => 0,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertSame( 0, $result['block_index'] );
		$this->assertNotNull( $saved, 'Block must be written when an index is given.' );
	}

	// --- No silent success on a failed block save (Required #4) ---------------

	public function test_block_write_reports_failure_when_update_does_not_persist() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 41, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		// wp_update_post fails (returns 0).
		Functions\when( 'wp_update_post' )->justReturn( 0 );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 41,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertFalse( $result['updated'], 'A failed save must not report updated:true.' );
		$this->assertSame( 'block', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	// --- Regression: block write must slash for wp_update_post ----------------

	/**
	 * Locks the slashing contract for block writes. wp_update_post()/
	 * wp_insert_post() run wp_unslash() on their input, and serialize_blocks()
	 * JSON-encodes attrs so a '<' becomes the escape < (backslash + u003c). If
	 * the content reaches wp_update_post() UNslashed, core's unslashing strips that
	 * backslash and the stored markup corrupts to the literal "u003c...".
	 *
	 * The wp_update_post stub here MIRRORS core by wp_unslash()-ing its captured
	 * input; we then assert the persisted block content still round-trips to the
	 * ORIGINAL markup. Pre-fix (no wp_slash in write_block_layout) the backslash is
	 * stripped and this FAILS; post-fix (content slashed first) it PASSES.
	 */
	public function test_block_write_slashes_so_markup_survives_unslashing() {
		$original_html = '<h2>Hello</h2>';

		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 51, 'post_content' => 'one block' ) );

		// One existing qualifying block to target.
		Functions\when( 'parse_blocks' )->alias(
			function ( $content ) {
				// Decode our own serialized form on the round-trip read; otherwise
				// return the single empty qualifying block being written into.
				if ( is_string( $content ) && strpos( $content, '__SER__:' ) === 0 ) {
					return json_decode( substr( $content, 8 ), true );
				}

				return array(
					array(
						'blockName' => 'siteorigin-panels/layout-block',
						'attrs'     => array( 'panelsData' => array( 'widgets' => array( 'placeholder' ) ) ),
					),
				);
			}
		);

		// Faithful serialize: JSON-encode with core's tag escaping so '<' becomes
		// the backslash escape < — exactly the bytes wp_unslash would attack.
		Functions\when( 'serialize_blocks' )->alias(
			fn( $blocks ) => '__SER__:' . json_encode( $blocks, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP )
		);

		// The chokepoint spy normally replaces widgets with a fixed 'Cleaned' set
		// (no markup), which would hide the '<' under test. For THIS test, make
		// the chokepoint preserve the incoming markup so the serialized content
		// actually carries <.
		Abilities_LayoutBlockSpy::$instance = new class extends Abilities_LayoutBlockSpy {
			public function sanitize_block_untrusted( $block ) {
				$this->untrusted_calls++;
				$this->received_block = $block;

				return $block; // preserve markup verbatim for the slashing assertion
			}
		};

		// Mirror core: wp_update_post unslashes its input before persisting.
		$persisted_content = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr ) use ( &$persisted_content ) {
				$unslashed         = wp_unslash( $postarr );
				$persisted_content = $unslashed['post_content'];

				return $unslashed['ID'];
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 51,
				'block_index' => 0,
				'panels_data' => Abilities_Fixtures::layout(
					array( Abilities_Fixtures::widget( 'WP_Widget_Custom_HTML', array( 'content' => $original_html ) ) )
				),
			)
		);

		$this->assertTrue( $result['updated'] );

		// After core-style unslashing, the JSON escape must still carry its
		// backslash (\\u003c / \\u003C), never the corrupted bare u003c. Case-
		// insensitive: PHP's JSON tag escaping may emit either case.
		$this->assertMatchesRegularExpression(
			'/\\\\u003c/i',
			$persisted_content,
			'Persisted content must keep the JSON-escape backslash (production must wp_slash before wp_update_post).'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/"u003c/i',
			$persisted_content,
			'Bare "u003c" means the backslash was stripped by core unslashing — content corrupted.'
		);

		// And the block must round-trip back to the ORIGINAL markup.
		$persisted_blocks = parse_blocks( $persisted_content );
		$this->assertSame(
			$original_html,
			$persisted_blocks[0]['attrs']['panelsData']['widgets'][0]['content'],
			'Block widget markup must survive the write/unslash round-trip intact.'
		);
	}

	public function test_object_widget_entry_is_coerced_on_block_write() {
		// MED-1 on the block path: a stdClass widget entry reaches the chokepoint
		// as an array (the chokepoint spy records the block it received).
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 7, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => array( 'widgets' => array( 'existing' ) ) ),
				),
			)
		);
		Functions\when( 'wp_update_post' )->alias( fn( $args ) => $args['ID'] );

		$object_widget = (object) array(
			'content'     => '<script>alert(1)</script>',
			'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0 ),
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 7,
				'block_index' => 0,
				'panels_data' => Abilities_Fixtures::layout( array( $object_widget ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$received = Abilities_LayoutBlockSpy::$instance->received_block;
		$this->assertIsArray(
			$received['attrs']['panelsData']['widgets'][0],
			'The object widget entry must reach the chokepoint as an array.'
		);
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

		$hostile = Abilities_Fixtures::widget( 'Evil_Widget', array( 'raw' => '<script>' ) );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 12,
				'panels_data' => Abilities_Fixtures::layout( array( $hostile ) ),
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
			Abilities_Fixtures::CLEANED,
			$persisted[2]['widgets'],
			'Persisted widgets must be the sanitizer output, not raw ability input.'
		);
		$this->assertNotContains( $hostile, $persisted[2]['widgets'] );
	}

	public function test_meta_write_strips_inbound_sanitize_signature() {
		// LOW-5: a client-supplied/copied top-level sanitize_signature must never
		// be persisted into meta (nothing signs/verifies meta; persisting it would
		// leak a plausible-looking signature back out via layout-get).
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = $value;

				return true;
			}
		);

		$this->abilities()->layout_update(
			array(
				'post_id'     => 12,
				'panels_data' => array_merge(
					Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
					array( 'sanitize_signature' => 'forged-or-copied-signature' )
				),
			)
		);

		$this->assertNotNull( $persisted );
		$this->assertArrayNotHasKey(
			'sanitize_signature',
			$persisted,
			'The inbound signature must be stripped before the meta write.'
		);
	}

	public function test_meta_write_double_slashes_so_backslashes_survive_unslashing() {
		// update_post_meta() wp_unslash()es its input; without the production
		// map_deep(double_slash_string) wrap, backslashes in stored widget data
		// (e.g. a namespaced class 'SiteOrigin\Widget\Foo', or 'C:\path') are
		// silently stripped. This mirrors the block-path slashing regression test.
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// Sanitizer returns widgets that legitimately contain single backslashes,
		// plus a payload-bearing content field so this test also proves the
		// unconditional meta kses floor COMPOSES with the double-slash wrap
		// (floor first, then slashing — both HIGH-fix behaviors intact).
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return array(
					array(
						'panels_info' => array( 'class' => 'SiteOrigin\\Widget\\Foo', 'grid' => 0, 'cell' => 0 ),
						'text'        => 'C:\\path\\to\\file',
						'content'     => '<img src=x onerror=alert(1)>',
					),
				);
			}
		};

		// Mirror core: update_post_meta() unslashes its input before persisting.
		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = wp_unslash( $value );

				return true;
			}
		);

		$this->abilities()->layout_update(
			array(
				'post_id'     => 21,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		// After core-style unslashing, the single backslashes must remain intact —
		// proving the value was double-slashed before update_post_meta.
		$this->assertNotNull( $persisted );
		$this->assertSame(
			'SiteOrigin\\Widget\\Foo',
			$persisted['widgets'][0]['panels_info']['class'],
			'Namespaced widget class must keep its backslashes through the meta write.'
		);
		$this->assertSame(
			'C:\\path\\to\\file',
			$persisted['widgets'][0]['text'],
			'Backslash-bearing content must survive the meta write.'
		);
		$this->assertSame(
			'<img src=x>',
			$persisted['widgets'][0]['content'],
			'The unconditional meta kses floor must strip the payload while double-slashing keeps backslashes intact.'
		);
	}

	public function test_meta_write_floors_widget_content_unconditionally() {
		// Audit #1 fix 1b: the whole ability meta write is AI-originated, so the
		// kses floor runs with NO capability gate at all — nothing about the
		// requesting credential (e.g. an admin application password holding
		// unfiltered_html) can exempt it. Nothing signs meta; this write-time
		// floor is the only floor the classic-render surface gets.
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// The capability check passing must make no difference — production
		// consults no capability on this path.
		Functions\when( 'current_user_can' )->justReturn( true );

		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				// The widget's own sanitizer let the handler through (e.g. a
				// custom-HTML widget for an unfiltered_html author).
				return array(
					array(
						'panels_info' => array( 'class' => 'WP_Widget_Custom_HTML', 'grid' => 0, 'cell' => 0 ),
						'content'     => '<img src=x onerror=alert(1)>',
					),
				);
			}
		};

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = wp_unslash( $value );

				return true;
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 22,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame(
			'<img src=x>',
			$persisted['widgets'][0]['content'],
			'AI meta writes must be kses-floored before persist regardless of the credential\'s capabilities.'
		);
	}

	public function test_meta_write_runs_sidebars_emulator_when_enabled() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// Emulator ON (only) — everything else off.
		Functions\when( 'siteorigin_panels_setting' )->alias(
			fn( $key ) => $key === 'sidebars-emulator'
		);

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = $value;

				return true;
			}
		);

		$this->abilities()->layout_update(
			array(
				'post_id'     => 30,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertTrue( Abilities_EmulatorSpy::$instance->called, 'sidebars emulator must run when the setting is on.' );
		// The emulator output is what gets persisted (tagged widget).
		$this->assertSame( 'EmulatorTagged', $persisted['widgets'][0]['panels_info']['class'] );
	}

	public function test_meta_write_applies_data_pre_save_filter() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// A pre-save filter transforms the layout; its output must be persisted.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_data_pre_save' ) {
					$value['pre_save_marker'] = 'ran';
				}

				return $value;
			}
		);

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = $value;

				return true;
			}
		);

		$this->abilities()->layout_update(
			array(
				'post_id'     => 31,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertSame( 'ran', $persisted['pre_save_marker'] ?? null, 'siteorigin_panels_data_pre_save output must be persisted.' );
	}

	public function test_meta_write_removes_invalid_styles_after_the_pre_save_filter() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );

		// The style cleanup must see the filter's output, not its input: a
		// callback on this filter can itself write an invalid style.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_data_pre_save' ) {
					$value['pre_save_marker'] = 'ran';
				}

				return $value;
			}
		);

		$this->abilities()->layout_update(
			array(
				'post_id'     => 31,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$seen = Abilities_StylesSpy::$instance->remove_invalid_styles_input;
		$this->assertIsArray( $seen, 'remove_invalid_styles() must run on the ability write path.' );
		$this->assertSame( 'ran', $seen['pre_save_marker'] ?? null, 'remove_invalid_styles() must receive the pre-save filter output.' );
	}

	public function test_meta_write_of_empty_layout_deletes_meta() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// Sanitizer returns an EMPTY widget set → empty layout (no widgets, no grids).
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return array();
			}
		};

		$deleted = null;
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$deleted ) {
				$deleted = array( $post_id, $key );

				return true;
			}
		);
		// Persisting must NOT happen for an empty layout.
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 32,
				'panels_data' => array( 'widgets' => array() ),
			)
		);

		$this->assertSame( array( 32, 'panels_data' ), $deleted, 'empty layout must delete panels_data meta.' );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_untargetable_nested_layout_block_declines_instead_of_meta_write() {
		// Post whose only Layout Block is nested inside a core/group — the
		// top-level walk finds zero qualifying blocks, and there is no meta layout.
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 40, 'post_content' => 'group with nested layout block' ) );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'core/group',
					'attrs'     => array(),
					'innerBlocks' => array(
						array(
							'blockName' => 'siteorigin-panels/layout-block',
							'attrs'     => array( 'panelsData' => array( 'widgets' => array( 'nested' ) ) ),
						),
					),
				),
			)
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );      // no meta layout
		Functions\when( 'has_block' )->justReturn( true );        // a Layout Block IS present (nested)

		// Must NOT write meta OR block — decline instead.
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'wp_update_post' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 40,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_plain_post_with_no_blocks_still_takes_meta_path() {
		// No blocks at all, has_block false, no meta → existing meta-create behaviour.
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 41, 'post_content' => '' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'has_block' )->justReturn( false );

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = true;

				return true;
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 41,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertTrue( $persisted, 'plain post must still write meta.' );
	}

	public function test_read_layouts_skips_parse_blocks_on_empty_content() {
		// Empty post_content → get_qualifying_block_layouts must early-return and
		// never call parse_blocks (which the WP<5.0 guard also protects against).
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 42, 'post_content' => '' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'parse_blocks' )->never();

		$result = SiteOrigin_Panels_AI_Exposure::single()->read_layouts( 42 );

		$this->assertSame( 'none', $result['source'] );
		$this->assertSame( array(), $result['layouts'] );
	}

	public function test_update_meta_path_old_widgets_false_when_no_previous_layout() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$this->abilities()->layout_update(
			array(
				'post_id'     => 13,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		// Mirrors admin.php: old widgets arg is false when there is no prior layout.
		$this->assertFalse( Abilities_AdminSpy::$instance->process_args[1] );
	}

	// --- copy-content parity on the meta write (guarded) ----------------------

	public function test_meta_write_refreshes_copy_content_guarded_with_final_layout() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 14, 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( array( 'widgets' => array( 'old' ) ) );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 14,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertSame( 'meta', $result['source'] );

		// The copy-content refresh ran, wrapped in the save guard.
		$this->assertTrue( Abilities_AdminSpy::$instance->save_guard_used, 'copy-content refresh must run inside with_save_guard().' );
		$copy_args = Abilities_AdminSpy::$instance->copy_content_args;
		$this->assertNotNull( $copy_args, 'copy_content_to_post() must be called on the meta path.' );
		$this->assertSame( 14, $copy_args[1], 'copy_content_to_post() receives the post id.' );

		// It must receive the FINAL sanitized layout (the sanitizer output), not raw input.
		$this->assertSame(
			Abilities_Fixtures::CLEANED,
			$copy_args[2]['widgets'],
			'copy_content_to_post() must render the sanitized panels_data, never raw input.'
		);
	}

	public function test_block_write_does_not_refresh_copy_content() {
		// Block-stored write must NOT invoke copy-content (block layouts render dynamically).
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 15, 'post_content' => 'block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'serialize_blocks' )->alias( fn( $b ) => $b );
		Functions\when( 'wp_update_post' )->justReturn( 15 );

		$this->abilities()->layout_update(
			array(
				'post_id'     => 15,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'W' ) ) ),
			)
		);

		$this->assertNull(
			Abilities_AdminSpy::$instance->copy_content_args,
			'Block writes must not trigger the copy-content refresh.'
		);
	}

	// --- layout-update pre-write hook: classic (meta) path --------------------

	public function test_meta_pre_write_fires_once_with_the_final_layout_in_order() {
		$this->classic_post( 22 );
		$this->listen_pre_write();
		Abilities_CallLog::$entries = array();

		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				return array(
					array(
						'panels_info' => array( 'class' => 'WP_Widget_Custom_HTML', 'grid' => 0, 'cell' => 0 ),
						'content'     => '<img src=x onerror=alert(1)>',
					),
				);
			}
		};
		Abilities_StylesSpy::$instance = new class extends Abilities_StylesSpy {
			public function remove_invalid_styles( $panels_data ) {
				$panels_data['styles_checked'] = true;

				return $panels_data;
			}
		};

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				Abilities_CallLog::add( 'update_post_meta' );
				$persisted = wp_unslash( $value );

				return true;
			}
		);
		Functions\expect( 'delete_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 22,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertCount( 1, $this->pre_write_calls, 'The pre-write hook fires exactly once per write.' );

		list( , $initial, $payload, $post_id, $storage, $block_index ) = $this->pre_write_calls[0];
		$this->assertTrue( $initial );
		$this->assertSame( 22, $post_id );
		$this->assertSame( 'meta', $storage );
		$this->assertNull( $block_index );
		$this->assertSame( '<img src=x>', $payload['widgets'][0]['content'], 'The payload is the floored layout.' );
		$this->assertTrue( $payload['styles_checked'], 'The payload has been through remove_invalid_styles().' );
		$this->assertSame( $persisted, $payload, 'The payload equals the stored meta.' );

		$log = Abilities_CallLog::$entries;
		$pre_write = array_search( 'pre_write', $log, true );
		$this->assertLessThan( $pre_write, max( array_keys( $log, 'kses_deep', true ) ), 'The floor runs before the hook.' );
		$this->assertLessThan( array_search( 'update_post_meta', $log, true ), $pre_write, 'The hook runs before the meta update.' );
		$this->assertLessThan( array_search( 'copy_content_to_post', $log, true ), array_search( 'update_post_meta', $log, true ), 'The meta update runs before the copy-content mirror.' );
	}

	public function test_meta_pre_write_wp_error_stops_the_write() {
		$this->classic_post( 23 );
		$error = new WP_Error( 'addon_blocked', 'Blocked.', array( 'status' => 409 ) );
		$this->listen_pre_write(
			function () use ( $error ) {
				return $error;
			}
		);
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 23,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertSame( $error, $result, 'layout_update() returns the listener\'s own WP_Error.' );
		$this->assertCount( 1, $this->pre_write_calls );
		$this->assert_meta_write_did_not_happen();
	}

	public function test_meta_pre_write_false_stops_the_write_with_the_aborted_code() {
		$this->classic_post( 24 );
		$this->listen_pre_write(
			function () {
				return false;
			}
		);
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 24,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_layout_update_aborted', $result->get_error_code() );
		$this->assert_meta_write_did_not_happen();
	}

	public function test_meta_pre_write_fires_for_an_empty_layout_before_the_delete() {
		$this->classic_post( 25 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write();
		Functions\expect( 'delete_post_meta' )->once()->with( 25, 'panels_data' )->andReturn( true );
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 25,
				'panels_data' => array(),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertCount( 1, $this->pre_write_calls );
		$this->assertEmpty( $this->pre_write_calls[0][2]['widgets'] );
		$this->assertArrayNotHasKey( 'grids', $this->pre_write_calls[0][2] );
	}

	public function test_meta_pre_write_abort_on_an_empty_layout_keeps_the_meta() {
		$this->classic_post( 26 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write(
			function () {
				return new WP_Error( 'addon_blocked', 'Blocked.' );
			}
		);
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 26,
				'panels_data' => array(),
			)
		);

		$this->assertSame( 'addon_blocked', $result->get_error_code() );
		$this->assertCount( 1, $this->pre_write_calls );
	}

	public function test_meta_pre_write_null_result_continues_and_persists() {
		$this->classic_post( 27 );
		$this->listen_pre_write(
			function () {
				return null;
			}
		);
		Functions\expect( 'update_post_meta' )->once()->andReturn( true );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 27,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertNotNull( Abilities_AdminSpy::$instance->copy_content_args );
	}

	public function test_meta_pre_write_listener_cannot_change_a_nested_object() {
		$this->classic_post( 28 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write(
			function ( $result, $panels_data ) {
				$panels_data['widgets'][0]['setting']->color = 'blue';

				return $result;
			}
		);

		$setting        = new stdClass();
		$setting->color = 'red';

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = $value;

				return true;
			}
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 28,
				'panels_data' => Abilities_Fixtures::layout(
					array( Abilities_Fixtures::widget( 'X', array( 'setting' => $setting ) ) )
				),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'blue', $this->pre_write_calls[0][2]['widgets'][0]['setting']->color, 'The listener changed its own copy.' );
		$this->assertSame( 'red', $persisted['widgets'][0]['setting']->color, 'The stored value keeps the original property.' );
	}

	public function test_meta_write_with_a_non_plain_object_stops_before_the_hook() {
		$this->classic_post( 29 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write(
			function ( $result, $panels_data ) {
				$reach = Closure::bind(
					function ( $object ) {
						$object->setting->color = 'blue';
					},
					null,
					Abilities_PrivateSettingHolder::class
				);
				$reach( $panels_data['widgets'][0]['setting'] );

				return $result;
			}
		);
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$inner        = new stdClass();
		$inner->color = 'red';

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 29,
				'panels_data' => Abilities_Fixtures::layout(
					array( Abilities_Fixtures::widget( 'X', array( 'setting' => new Abilities_PrivateSettingHolder( $inner ) ) ) )
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_layout_update_unsupported_value', $result->get_error_code() );
		$this->assertCount( 0, $this->pre_write_calls, 'The hook does not fire.' );
		$this->assertSame( 'red', $inner->color, 'The caller\'s layout keeps its value.' );
		$this->assert_meta_write_did_not_happen();
	}

	// --- layout-update pre-write hook: Layout Block path ----------------------

	public function test_block_write_passes_post_id_and_index_zero_for_a_single_block() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 51, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\when( 'wp_update_post' )->justReturn( 51 );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 51,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'Incoming' ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( array( 51, 0 ), array_slice( Abilities_LayoutBlockSpy::$instance->layout_update_args, 0, 2 ) );
		$this->assertSame( 1, Abilities_LayoutBlockSpy::$instance->untrusted_calls );
	}

	public function test_block_write_passes_the_raw_stored_layout_and_object_keys() { // #1409
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 53, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\when( 'wp_update_post' )->justReturn( 53 );
		// A read filter that rewrites the layout must not become the baseline.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $tag === 'siteorigin_panels_data' && is_array( $value ) ? array( 'widgets' => array( 'rewritten' ) ) : $value;
			}
		);

		$this->abilities()->layout_update(
			array(
				'post_id'     => 53,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'Array' ), (object) Abilities_Fixtures::widget( 'Object' ) ) ),
			)
		);

		$args = Abilities_LayoutBlockSpy::$instance->layout_update_args;
		$this->assertSame( array( 'widgets' => array( 'existing-0' ) ), $args[2], 'the raw stored panelsData' );
		$this->assertSame( array( 1 ), $args[3], 'the key of the entry that arrived as an object' );
	}

	public function test_block_write_passes_the_requested_index_on_a_multi_block_post() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 52, 'post_content' => 'three blocks' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 3, true ) );
		Functions\when( 'wp_update_post' )->justReturn( 52 );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 52,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'Incoming' ) ) ),
				'block_index' => 2,
			)
		);

		$this->assertSame( 2, $result['block_index'] );
		$this->assertSame( array( 52, 2 ), array_slice( Abilities_LayoutBlockSpy::$instance->layout_update_args, 0, 2 ) );
	}

	public function test_block_write_abort_returns_the_error_and_does_not_update_the_post() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 53, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn( $this->layout_blocks( 1 ) );
		Functions\expect( 'wp_update_post' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$error = new WP_Error( 'addon_blocked', 'Blocked.', array( 'status' => 409 ) );
		Abilities_LayoutBlockSpy::$instance->abort = $error;

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 53,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'Incoming' ) ) ),
			)
		);

		$this->assertSame( $error, $result );
		$this->assertNull( Abilities_AdminSpy::$instance->copy_content_args );
	}

	// --- layout-update: layout structure rule (classic/meta path) -------------

	/**
	 * Cell row references the builder cannot resolve.
	 */
	public static function unresolved_cell_row_references() {
		return array(
			'a word'                    => array( 'x' ),
			'a word with a number'      => array( 'row-1' ),
			'a number then other text'  => array( '0 {} *' ),
			'a row that does not exist' => array( 7 ),
			'a negative row'            => array( -1 ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unresolved_cell_row_references' )]
	public function test_meta_write_refuses_a_cell_row_reference_that_does_not_resolve( $reference ) {
		$this->classic_post( 71 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$layout                          = Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) );
		$layout['grid_cells'][0]['grid'] = $reference;

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 71,
				'panels_data' => $layout,
			)
		);

		$this->assertSame( 71, $result['post_id'] );
		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		$this->assertNotSame( '', trim( $result['message'] ) );
		$this->assertCount( 0, $this->pre_write_calls, 'The pre-write hook does not fire for a refused layout.' );
		$this->assert_meta_write_did_not_happen();
	}

	public function test_meta_write_refuses_a_widget_reference_that_does_not_resolve() {
		$this->classic_post( 72 );
		$this->passthrough_admin_spy();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$layout                                   = Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) );
		$layout['widgets'][0]['panels_info']['cell'] = 3;

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 72,
				'panels_data' => $layout,
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		$this->assert_meta_write_did_not_happen();
	}

	public function test_meta_write_refuses_a_reference_changed_by_the_pre_save_filter() {
		// The check runs on the final layout, after the pre-save filter.
		$this->classic_post( 73 );
		$this->passthrough_admin_spy();
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_data_pre_save' ) {
					$value['grid_cells'][0]['grid'] = 'x';
				}

				return $value;
			}
		);
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 73,
				'panels_data' => Abilities_Fixtures::layout( array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		$this->assert_meta_write_did_not_happen();
	}

	public function test_meta_write_refuses_a_widgets_only_layout_with_a_clear_message() {
		$this->classic_post( 74 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 74,
				'panels_data' => array(
					'widgets' => array( array( 'panels_info' => array( 'class' => 'WP_Widget_Text' ), 'text' => 'Hello' ) ),
				),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
		// The message names each part of the layout the caller must supply.
		$this->assertStringContainsString( 'grids', $result['message'] );
		$this->assertStringContainsString( 'grid_cells', $result['message'] );
		$this->assertStringContainsString( 'panels_info.grid', $result['message'] );
		$this->assertStringContainsString( 'panels_info.cell', $result['message'] );
		$this->assertStringContainsString( 'layout-get', $result['message'] );
		$this->assertCount( 0, $this->pre_write_calls );
		$this->assert_meta_write_did_not_happen();
	}

	public function test_meta_write_refuses_placed_widgets_without_rows_and_cells() {
		$this->classic_post( 75 );
		$this->passthrough_admin_spy();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 75,
				'panels_data' => array( 'widgets' => array( Abilities_Fixtures::widget( 'X' ) ) ),
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'unsupported', $result['source'] );
	}

	public function test_meta_write_stores_a_layout_whose_references_resolve() {
		$this->classic_post( 76 );
		$this->passthrough_admin_spy();

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = wp_unslash( $value );

				return true;
			}
		);
		Functions\expect( 'delete_post_meta' )->never();

		$layout = array(
			'widgets'    => array(
				Abilities_Fixtures::widget( 'First' ),
				array( 'panels_info' => array( 'class' => 'Second', 'grid' => 1, 'cell' => 1 ) ),
			),
			'grids'      => array( array( 'cells' => 1 ), array( 'cells' => 2 ) ),
			'grid_cells' => array(
				array( 'grid' => 0, 'weight' => 1 ),
				array( 'grid' => 1, 'weight' => 0.5 ),
				array( 'grid' => 1, 'weight' => 0.5 ),
			),
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 76,
				'panels_data' => $layout,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertSame( $layout, $persisted, 'The check stores the layout as it is; it does not change it.' );
	}

	/**
	 * Requests that clear the classic layout. None holds a widget or a row.
	 */
	public static function clear_requests() {
		return array(
			'empty object'                   => array( array() ),
			'empty widgets list'             => array( array( 'widgets' => array() ) ),
			'the three empty lists'          => array(
				array(
					'widgets'    => array(),
					'grids'      => array(),
					'grid_cells' => array(),
				),
			),
			'a cell list alone, no rows'     => array( array( 'grid_cells' => array( array( 'grid' => 'x', 'weight' => 1 ) ) ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'clear_requests' )]
	public function test_meta_write_of_an_empty_layout_still_clears_the_layout( $panels_data ) {
		$this->classic_post( 77 );
		$this->passthrough_admin_spy();
		$this->listen_pre_write();
		Functions\expect( 'delete_post_meta' )->once()->with( 77, 'panels_data' )->andReturn( true );
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 77,
				'panels_data' => $panels_data,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertSame( 'Layout cleared.', $result['message'] );
		$this->assertCount( 1, $this->pre_write_calls, 'The pre-write hook still fires once for a clear.' );
	}

	/**
	 * Stored classic layouts, as layout-get returns them.
	 */
	public static function stored_layouts() {
		$cases = require __DIR__ . '/fixtures/layout-structure-cases.php';
		$sets  = array();

		foreach ( array( 'one row, one cell, one widget', 'two rows, three cells, widgets in each', 'older layout: placement under info', 'numeric string references', 'extra top-level and style keys' ) as $name ) {
			$sets[ $name ] = array( $cases['valid'][ $name ] );
		}

		return $sets;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'stored_layouts' )]
	public function test_a_layout_returned_by_layout_get_is_accepted_unchanged_by_layout_update( $stored ) {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 78, 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( $stored );
		$this->passthrough_admin_spy();

		$persisted = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$persisted ) {
				$persisted = wp_unslash( $value );

				return true;
			}
		);
		Functions\expect( 'delete_post_meta' )->never();

		$read = $this->abilities()->layout_get( array( 'post_id' => 78 ) );

		$this->assertSame( 'meta', $read['source'] );
		$this->assertSame( $stored, $read['layouts'][0]['panels_data'] );

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 78,
				'panels_data' => $read['layouts'][0]['panels_data'],
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertSame( $stored, $persisted, 'Writing back what layout-get returned stores the same layout.' );
	}

	// --- The Admin stand-in holds the same rule as the real class -------------

	public static function shared_valid_layouts() {
		$cases = require __DIR__ . '/fixtures/layout-structure-cases.php';

		return array_map(
			function ( $layout ) {
				return array( $layout );
			},
			$cases['valid']
		);
	}

	public static function shared_refused_values() {
		$cases = require __DIR__ . '/fixtures/layout-structure-cases.php';

		return array_map(
			function ( $value ) {
				return array( $value );
			},
			$cases['refused']
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'shared_valid_layouts' )]
	public function test_admin_stand_in_returns_each_shared_valid_layout( $layout ) {
		$expected = $layout;

		if ( ! array_key_exists( 'widgets', $expected ) ) {
			$expected['widgets'] = array();
		}

		$this->assertSame( $expected, SiteOrigin_Panels_Admin::validate_layout_structure( $layout ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'shared_refused_values' )]
	public function test_admin_stand_in_refuses_each_shared_refused_value( $value ) {
		$this->assertNull( SiteOrigin_Panels_Admin::validate_layout_structure( $value ) );
	}

	public function test_layout_update_schema_states_the_layout_shape_it_accepts() {
		$this->abilities()->register_abilities();

		$update      = $GLOBALS['abilities_registered']['siteorigin-panels/layout-update'];
		$description = $update['input_schema']['properties']['panels_data']['description'];

		foreach ( array( 'grids', 'grid_cells', 'panels_info.grid', 'panels_info.cell', 'layout-get' ) as $term ) {
			$this->assertStringContainsString( $term, $description );
		}
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
		$this->assertTrue(
			$get['meta']['annotations']['readonly'],
			'layout-get must be readonly under meta.annotations, the key core reads.'
		);
		$this->assertArrayNotHasKey(
			'readonly',
			$get['meta'],
			'A top-level meta.readonly is never read by core and must not be registered.'
		);
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
		$this->assertEmpty(
			$update['meta']['annotations']['readonly'] ?? null,
			'layout-update must NOT carry a readonly annotation; it is a POST write.'
		);
		$this->assertSame( 'siteorigin-panels', $update['category'] );

		// Phase 2c contract: block_index input + block-ambiguous output source.
		$this->assertArrayHasKey(
			'block_index',
			$update['input_schema']['properties'],
			'layout-update must accept block_index input.'
		);
		$this->assertContains(
			'block-ambiguous',
			$update['output_schema']['properties']['source']['enum'],
			'layout-update output source enum must include block-ambiguous.'
		);
		$this->assertArrayHasKey(
			'block_index',
			$update['output_schema']['properties'],
			'layout-update must echo block_index in output.'
		);
	}

	public function test_both_layout_abilities_are_public_to_mcp() {
		$this->abilities()->register_abilities();

		$get    = $GLOBALS['abilities_registered']['siteorigin-panels/layout-get'];
		$update = $GLOBALS['abilities_registered']['siteorigin-panels/layout-update'];

		$this->assertTrue( $get['meta']['mcp']['public'] );
		$this->assertTrue( $update['meta']['mcp']['public'] );
		$this->assertTrue( $get['meta']['annotations']['readonly'] );
		$this->assertArrayNotHasKey( 'readonly', $get['meta'] );
		$this->assertArrayNotHasKey( 'annotations', $update['meta'] );
		$this->assertArrayNotHasKey( 'public', $get['meta'], 'Only meta.mcp.public is set, not core meta.public.' );
		$this->assertArrayNotHasKey( 'public', $update['meta'] );
	}

	public function test_registers_the_ability_category() {
		$this->abilities()->register_ability_category();

		$this->assertArrayHasKey( 'siteorigin-panels', $GLOBALS['ability_categories_registered'] );
		$this->assertArrayHasKey( 'label', $GLOBALS['ability_categories_registered']['siteorigin-panels'] );
	}


	// --- Unchanged widgets (#1409), meta path --------------------------------
	// Row names (T1 ...) refer to the decision table for issue #1409.

	private function html( $text, $cell = 0, $id = 0, $widget_id = null ) {
		return array(
			'text'        => $text,
			'panels_info' => array(
				'class'     => 'Html',
				'grid'      => 0,
				'cell'      => $cell,
				'id'        => $id,
				'widget_id' => $widget_id === null ? 'w-' . md5( $text ) : $widget_id,
			),
		);
	}

	private function two_cells( array $widgets ) {
		return array(
			'widgets'    => $widgets,
			'grids'      => array( array( 'cells' => 2 ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 0.5 ), array( 'grid' => 0, 'weight' => 0.5 ) ),
		);
	}

	/**
	 * A classic post whose stored layout is $stored; records the stored value,
	 * the pre-write payload, and routes $filters by tag.
	 */
	private function meta_write( $stored, $incoming, array $filters = array() ) {
		$this->classic_post( 30 );
		$this->passthrough_admin_spy();
		if ( $this->meta_write_spy !== null ) {
			Abilities_AdminSpy::$instance = $this->meta_write_spy;
		}
		Functions\when( 'get_post_meta' )->justReturn( $stored );

		$this->persisted = null;
		$this->deleted   = false;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->persisted = $value;

				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function () {
				$this->deleted = true;

				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function () use ( $filters ) {
				$args = func_get_args();
				if ( $args[0] === 'siteorigin_panels_layout_update_pre_write' ) {
					$this->pre_write_calls[] = $args;

					return isset( $filters['pre_write'] ) ? call_user_func_array( $filters['pre_write'], array_slice( $args, 1 ) ) : $args[1];
				}

				return isset( $filters[ $args[0] ] ) ? call_user_func_array( $filters[ $args[0] ], array_slice( $args, 1 ) ) : $args[1];
			}
		);

		return $this->abilities()->layout_update( array( 'post_id' => 30, 'panels_data' => $incoming ) );
	}

	private $persisted = null;
	private $deleted   = false;

	private function meta_write_with( $spy, $stored, $incoming, array $filters = array() ) {
		$this->meta_write_spy = $spy;
		$result = $this->meta_write( $stored, $incoming, $filters );
		$this->meta_write_spy = null;

		return $result;
	}

	private $meta_write_spy = null;

	private function processed_texts() {
		$args = Abilities_AdminSpy::$instance->process_args;

		return $args === null ? array() : array_map(
			function ( $widget ) {
				return is_array( $widget ) && isset( $widget['text'] ) ? $widget['text'] : null;
			},
			$args[0]
		);
	}

	public function test_unchanged_widget_keeps_its_stored_value_and_changed_widget_is_floored() { // T1, T2, T30
		$stored   = $this->two_cells( array( $this->html( 'Embed <img src=x onerror=a()>' ), $this->html( 'Plain', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][0]['panels_info']['cell_index'] = 0;
		$incoming['widgets'][1]['text'] = 'Changed <img src=x onerror=b()>';

		$result = $this->meta_write( $stored, $incoming );

		$this->assertTrue( $result['updated'] );
		$this->assertSame( $stored['widgets'][0], $this->persisted['widgets'][0], 'kept byte-identical, cell_index as stored' );
		$this->assertSame( 'Changed <img src=x>', $this->persisted['widgets'][1]['text'] );
		$this->assertSame( $this->persisted, $this->pre_write_calls[0][2], 'the pre-write payload equals what is stored' );
		$this->assertNotContains( 'Embed <img src=x onerror=a()>', $this->processed_texts(), 'a kept widget never reaches process_raw_widgets()' );
	}

	public function test_moved_widget_is_kept_at_the_callers_position() { // T3
		$stored   = $this->two_cells( array( $this->html( 'Embed <img src=x onerror=a()>' ), $this->html( 'Plain', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][0]['panels_info']['cell'] = 1;
		$incoming['widgets'][0]['panels_info']['id']   = 1;
		$incoming['widgets'][1]['panels_info']['id']   = 0;

		$this->meta_write( $stored, $incoming );

		$this->assertSame( 'Embed <img src=x onerror=a()>', $this->persisted['widgets'][0]['text'] );
		$this->assertSame( 1, $this->persisted['widgets'][0]['panels_info']['cell'] );
	}

	public function test_a_copy_and_an_ambiguous_duplicate_are_floored() { // T5, T6
		$a      = $this->html( 'Embed <img src=x onerror=a()>' );
		$copy   = $a;
		$copy['panels_info']['cell'] = 1;
		$stored = $this->two_cells( array( $a ) );

		$this->meta_write( $stored, $this->two_cells( array( $a, $copy ) ) );
		$this->assertSame( $a, $this->persisted['widgets'][0] );
		$this->assertSame( 'Embed <img src=x>', $this->persisted['widgets'][1]['text'] );

		$this->meta_write( $stored, $this->two_cells( array( $a, $a, $copy ) ) );
		foreach ( $this->persisted['widgets'] as $widget ) {
			$this->assertSame( 'Embed <img src=x>', $widget['text'], 'P/P/Q: every copy floored' );
		}
	}

	public function test_a_change_to_any_field_floors_the_widget() { // T7
		$a      = $this->html( 'Embed <img src=x onerror=a()>' );
		$stored = $this->two_cells( array( $a ) );

		foreach ( array(
			array( 'text', 'Embed <img src=x onerror=a()> ' ),
			array( 'so_sidebar_emulator_id', 'html-1' ),
		) as $change ) {
			$incoming = $stored;
			$incoming['widgets'][0][ $change[0] ] = $change[1];
			$this->meta_write( $stored, $incoming );
			$this->assertStringNotContainsString( 'onerror', $this->persisted['widgets'][0]['text'], $change[0] );
		}

		$incoming = $stored;
		$incoming['widgets'][0]['panels_info']['raw'] = true;
		$this->meta_write( $stored, $incoming );
		$this->assertStringNotContainsString( 'onerror', $this->persisted['widgets'][0]['text'], 'raw added' );
	}

	public function test_widget_list_shapes_keep_todays_outcome() { // T9, T10, T16
		$stored = $this->two_cells( array( $this->html( 'Embed <img src=x onerror=a()>' ) ) );
		// Like the real process_raw_widgets(), which returns array() for an
		// empty or non-array list (inc/admin.php).
		$coercing = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return empty( $widgets ) || ! is_array( $widgets ) ? array() : $widgets;
			}
		};
		$this->meta_write_with( $coercing, $stored, array( 'widgets' => 'not a list' ) );
		$this->assertTrue( $this->deleted, 'a non-empty string with no rows clears, as today' );

		$this->meta_write_with( $coercing, $stored, array( 'widgets' => 'not a list', 'grids' => $stored['grids'], 'grid_cells' => $stored['grid_cells'] ) );
		$this->assertSame( array(), $this->persisted['widgets'], 'a non-empty string with rows stores no widgets, as today' );

		$this->meta_write_with( $coercing, $stored, array( 'widgets' => array( 'scalar', null ) ) + $stored );
		$this->assertSame( array(), $this->persisted['widgets'], 'scalars are dropped, as today' );

		$this->meta_write_with( $coercing, '', $stored );
		$this->assertSame( 'Embed <img src=x>', $this->persisted['widgets'][0]['text'], 'no stored layout: everything floored' );
	}

	public function test_a_filter_that_reorders_list_keys_is_declined() { // T11
		$stored   = $this->two_cells( array( $this->html( 'A' ), $this->html( 'B', 0, 1 ), $this->html( 'C', 1, 0 ) ) );
		$result   = $this->meta_write(
			$stored,
			$stored,
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) {
					$panels_data['widgets'] = array( 2 => $panels_data['widgets'][2], 0 => $panels_data['widgets'][0], 1 => $panels_data['widgets'][1] );

					return $panels_data;
				},
			)
		);

		$this->assertFalse( $result['updated'] );
		$this->assertNull( $this->persisted );
	}

	public function test_kept_widgets_keep_list_order_in_a_mixed_update() { // T38, T25
		$stored   = $this->two_cells( array( $this->html( 'A <img src=x onerror=a()>' ), $this->html( 'B', 0, 1 ), $this->html( 'C <img src=x onerror=c()>', 1, 0 ) ) );
		$incoming = $stored;
		$incoming['widgets'][1]['text'] = 'B2';

		$this->meta_write( $stored, $incoming );

		$this->assertSame( array( 0, 1, 2 ), array_keys( $this->persisted['widgets'] ) );
		$this->assertSame( $stored['widgets'][0], $this->persisted['widgets'][0] );
		$this->assertSame( 'B2', $this->persisted['widgets'][1]['text'] );
		$this->assertSame( $stored['widgets'][2], $this->persisted['widgets'][2] );

		$swapped = $stored;
		$swapped['widgets'] = array( $stored['widgets'][2], $stored['widgets'][1], $stored['widgets'][0] );
		$this->meta_write( $stored, $swapped );
		$this->assertSame( array( 0, 1, 2 ), array_keys( $this->persisted['widgets'] ) );
		$this->assertSame( $stored['widgets'][2], $this->persisted['widgets'][0], 'values swapped at keys 0 and 2 are kept in that order' );
	}

	public function test_server_edits_after_restore_floor_the_widget() { // T13
		$stored = $this->two_cells( array( $this->html( 'Embed <img src=x onerror=a()>' ), $this->html( 'Other <img src=x onerror=o()>', 0, 1 ) ) );

		$this->meta_write(
			$stored,
			$stored,
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) {
					$panels_data['widgets'][0]['extra'] = 'added';

					return $panels_data;
				},
			)
		);
		$this->assertSame( 'Embed <img src=x>', $this->persisted['widgets'][0]['text'], 'edited by a pre-save callback' );
		$this->assertSame( $stored['widgets'][1], $this->persisted['widgets'][1] );

		$this->meta_write(
			$stored,
			$stored,
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) {
					$panels_data['widgets'] = array( $panels_data['widgets'][1], $panels_data['widgets'][0] );

					return $panels_data;
				},
			)
		);
		$this->assertSame( 'Other <img src=x>', $this->persisted['widgets'][0]['text'], 'swapped: both floored' );
		$this->assertSame( 'Embed <img src=x>', $this->persisted['widgets'][1]['text'] );

		Abilities_StylesSpy::$instance = new class extends Abilities_StylesSpy {
			public function sanitize_all( $panels_data ) {
				$panels_data['widgets'][0]['panels_info']['style'] = array( 'padding' => '1px' );

				return $panels_data;
			}
		};
		$this->meta_write( $stored, $stored );
		$this->assertSame( 'Embed <img src=x>', $this->persisted['widgets'][0]['text'], 'sanitize_all() rewrote its style' );
	}

	public function test_kept_widget_takes_only_this_writes_emulator_values() { // T15
		$stored = $this->two_cells( array( $this->html( 'Embed <img src=x onerror=a()>' ) ) );
		Functions\when( 'siteorigin_panels_setting' )->justReturn( true );
		Abilities_EmulatorSpy::$instance = new class extends Abilities_EmulatorSpy {
			public function generate_sidebar_widget_ids( $widgets, $post_id ) {
				foreach ( $widgets as $i => &$widget ) {
					$widget['so_sidebar_emulator_id'] = 'html-' . $post_id . $i;
					$widget['option_name']            = 'widget_html';
				}

				return $widgets;
			}
		};

		$this->meta_write( $stored, $stored );
		$this->assertSame( 'Embed <img src=x onerror=a()>', $this->persisted['widgets'][0]['text'] );
		$this->assertSame( 'html-300', $this->persisted['widgets'][0]['so_sidebar_emulator_id'] );

		$this->meta_write(
			$stored,
			$stored,
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) {
					$panels_data['widgets'][0]['so_sidebar_emulator_id'] = 'html-other';

					return $panels_data;
				},
			)
		);
		$this->assertSame( 'Embed <img src=x>', $this->persisted['widgets'][0]['text'] );
	}

	public function test_object_values_follow_the_stored_object_contract() { // T17, T18, T19, T36
		$setting       = new stdClass();
		$setting->html = '<img src=x onerror=s()>';
		$a             = $this->html( 'A' );
		$a['setting']  = $setting;
		$stored        = $this->two_cells( array( $a ) );

		$this->meta_write( $stored, $this->two_cells( array( unserialize( serialize( $a ) ) ) ) );
		$this->assertInstanceOf( stdClass::class, $this->persisted['widgets'][0]['setting'] );
		$this->assertSame( '<img src=x onerror=s()>', $this->persisted['widgets'][0]['setting']->html, 'an unchanged object is kept' );

		$changed = unserialize( serialize( $a ) );
		$changed['text'] = 'A2';
		$this->meta_write( $stored, $this->two_cells( array( $changed ) ) );
		$this->assertInstanceOf( stdClass::class, $this->persisted['widgets'][0]['setting'] );
		$this->assertSame( '<img src=x>', $this->persisted['widgets'][0]['setting']->html, 'strings inside a changed widget\'s object are floored' );

		$this->meta_write( $stored, $this->two_cells( array( (object) $a ) ) );
		$this->assertSame( '<img src=x>', $this->persisted['widgets'][0]['setting']->html, 'a top-level object entry is never kept' );

		$moved = unserialize( serialize( $a ) );
		$moved['panels_info']['cell'] = 1;
		$moved['panels_info']['id']   = (object) array( 'v' => '<img src=x onerror=i()>' );
		$this->meta_write( $stored, $this->two_cells( array( $moved ) ) );
		$this->assertSame( '<img src=x>', $this->persisted['widgets'][0]['panels_info']['id']->v, 'a moved position value is floored' );
	}

	public function test_unsafe_stored_widgets_never_block_or_reach_a_replacement() { // T20, T21, T37, T39
		$cyclic                     = $this->html( 'Old', 0, 0, 'same-id' );
		$cyclic['loop']             = array( 'x' => 1 );
		$cyclic['loop']['self']     = &$cyclic['loop'];
		$other                      = $this->html( 'Old2', 0, 1, 'other-id' );
		$other['holder']            = new ArrayObject( array() );
		$stored                     = $this->two_cells( array( $cyclic, $other ) );
		$replacement                = $this->html( 'New <img src=x onerror=n()>', 0, 0, 'same-id' );
		$replacement2               = $this->html( 'New2', 0, 1, 'other-id' );

		$result = $this->meta_write( $stored, $this->two_cells( array( $replacement, $replacement2 ) ) );

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'New <img src=x>', $this->persisted['widgets'][0]['text'] );
		$this->assertSame( array(), Abilities_AdminSpy::$instance->process_args[1] === false ? array() : Abilities_AdminSpy::$instance->process_args[1], 'neither unsafe stored widget is passed as an old instance' );

		$result = $this->meta_write( $stored, $this->two_cells( array() ) );
		$this->assertTrue( $result['updated'], 'removing them succeeds' );
	}

	public function test_cyclic_incoming_values_are_declined() { // T33, T40
		$loop         = array( 'x' => 1 );
		$loop['self'] = &$loop;
		$widget       = $this->html( 'A' );
		$widget['loop'] = $loop;

		$result = $this->meta_write( '', $this->two_cells( array( $widget ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'siteorigin_panels_layout_update_unsupported_value', $result->get_error_code() );
		$this->assertNull( $this->persisted );

		$object         = new stdClass();
		$object->holder = new stdClass();
		$object->holder->back = $object;
		$result = $this->meta_write( '', $this->two_cells( array( $object ) ) );
		$this->assertInstanceOf( WP_Error::class, $result, 'a cycle exposed by the top-level cast' );

		$result = $this->meta_write(
			'',
			$this->two_cells( array( $this->html( 'A' ) ) ),
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) use ( $loop ) {
					$panels_data['widgets'][0]['loop'] = $loop;

					return $panels_data;
				},
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result, 'a cycle returned by a pre-save callback' );
		$this->assertNull( $this->persisted );
	}

	public function test_references_cannot_change_what_is_stored() { // T22, T23
		$stored   = $this->two_cells( array( $this->html( 'Embed <img src=x onerror=a()>' ) ) );
		$kept = array();

		$this->meta_write(
			$stored,
			$stored,
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) use ( &$kept ) {
					$panels_data['grids'][0]['style'] = array( 'class' => 'row' );
					// An element of $kept becomes a reference to the row's slot.
					$kept[0] = &$panels_data['grids'][0]['style']['class'];

					return $panels_data;
				},
				'pre_write' => function ( $result ) use ( &$kept ) {
					$kept[0] = '"><script>evil()</script>';

					return $result;
				},
			)
		);
		$this->assertSame( 'Embed <img src=x onerror=a()>', $this->persisted['widgets'][0]['text'] );
		$this->assertSame( 'row', $this->persisted['grids'][0]['style']['class'], 'a reference a filter kept into the rows cannot reach storage' );

		$setting       = new stdClass();
		$setting->html = 'stored';
		$with_object   = $this->html( 'A' );
		$with_object['setting'] = $setting;
		$object_layout = $this->two_cells( array( $with_object ) );
		$this->meta_write(
			$object_layout,
			unserialize( serialize( $object_layout ) ),
			array(
				'siteorigin_panels_data_pre_save' => function ( $panels_data ) {
					$panels_data['widgets'][0]['setting']->html = '<img src=x onerror=p()>';

					return $panels_data;
				},
			)
		);
		$this->assertSame( '<img src=x>', $this->persisted['widgets'][0]['setting']->html, 'an in-place edit of a kept widget\'s object is floored' );

		$shared         = new stdClass();
		$shared->html   = 'stored';
		$a              = $this->html( 'A' );
		$a['setting']   = $shared;
		$stored         = $this->two_cells( array( $a, $this->html( 'B', 0, 1 ) ) );
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );
				if ( is_array( $old_widgets ) && isset( $old_widgets[0]['setting'] ) ) {
					$old_widgets[0]['setting']->html = '<img src=x onerror=u()>';
				}

				return $widgets;
			}
		};
		$incoming = unserialize( serialize( $stored ) );
		$incoming['widgets'][1]['text'] = 'B2';
		Functions\when( 'get_post_meta' )->justReturn( $stored );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		$this->persisted = null;
		$this->abilities()->layout_update( array( 'post_id' => 30, 'panels_data' => $incoming ) );
		$this->assertSame( 'stored', $this->persisted['widgets'][0]['setting']->html, 'the kept widget is the frozen stored value' );
	}

	public function test_deep_values_have_no_depth_limit() { // T34
		$deep = 'deep <img src=x onerror=d()>';
		for ( $i = 0; $i < 1000; $i++ ) {
			$deep = array( 'n' => $deep );
		}
		$a           = $this->html( 'A' );
		$a['nested'] = $deep;
		$stored      = $this->two_cells( array( $a ) );

		$this->meta_write( $stored, $stored );
		$this->assertSame( $a, $this->persisted['widgets'][0], 'a deep identical widget is kept' );

		$changed         = $a;
		$changed['text'] = 'A2';
		$this->meta_write( $stored, $this->two_cells( array( $changed ) ) );
		$leaf = $this->persisted['widgets'][0]['nested'];
		for ( $i = 0; $i < 1000; $i++ ) {
			$leaf = $leaf['n'];
		}
		$this->assertSame( 'deep <img src=x>', $leaf );
	}
}
