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
	public $copy_content_args = null;
	public $save_guard_used = false;

	public static function single() {
		return self::$instance;
	}

	public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
		$this->process_args = array( $widgets, $old_widgets, $escape_classes );

		// Simulate a cleaned widget set so tests can assert persisted data is the
		// sanitizer output, not raw input.
		return array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) );
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

/**
 * Spyable stand-in for SiteOrigin_Panels_Compat_Layout_Block::single()
 * ->sanitize_block_untrusted() — the compat save chokepoint block writes now
 * route through. Records each call and the block it received (so tests can
 * assert the RAW incoming panelsData reaches the chokepoint), and mirrors the
 * real chokepoint's observable contract: sanitized widgets, a signature, and
 * innerHTML removed.
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

		$block['attrs']['panelsData']['widgets'] = array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) );
		$block['attrs']['panelsData']['sanitize_signature'] = 'chokepoint-signed';
		unset( $block['innerHTML'] );

		return $block;
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

		return array( array( 'panels_info' => array( 'class' => 'EmulatorTagged' ) ) );
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

		Abilities_AdminSpy::$instance       = new Abilities_AdminSpy();
		Abilities_StylesSpy::$instance      = new Abilities_StylesSpy();
		Abilities_EmulatorSpy::$instance    = new Abilities_EmulatorSpy();
		Abilities_LayoutBlockSpy::$instance = new Abilities_LayoutBlockSpy();

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

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 7,
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'New' ) ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertSame( 0, $result['block_index'] );

		// §3: the block write routes through the compat save chokepoint exactly
		// once, carrying the RAW incoming panelsData (the chokepoint owns the
		// whole filter → sanitize → forced floor → sign sequence).
		$spy = Abilities_LayoutBlockSpy::$instance;
		$this->assertSame( 1, $spy->untrusted_calls, 'Block write must call sanitize_block_untrusted() exactly once.' );
		$this->assertSame(
			array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'New' ) ) ) ),
			$spy->received_block['attrs']['panelsData'],
			'The chokepoint must receive the raw incoming layout — no pre-processing in abilities code.'
		);

		// The deleted inline sanitize pass must be gone: neither
		// process_raw_widgets() nor sanitize_all() runs in abilities code on
		// the block path (single sanitize pass, inside the chokepoint only).
		$this->assertNull( Abilities_AdminSpy::$instance->process_args, 'No inline process_raw_widgets() on the block path.' );
		$this->assertFalse( Abilities_StylesSpy::$instance->sanitize_all_called, 'No inline sanitize_all() on the block path.' );

		// The written block carries the CHOKEPOINT output — sanitized and signed.
		$this->assertSame(
			array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) ),
			$saved['post_content'][0]['attrs']['panelsData']['widgets']
		);
		$this->assertSame(
			'chokepoint-signed',
			$saved['post_content'][0]['attrs']['panelsData']['sanitize_signature'],
			'The persisted block must carry the chokepoint-produced signature.'
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
				'panels_data' => array( 'widgets' => array( 'incoming' ) ),
				'block_index' => 1,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertSame( 1, $result['block_index'] );

		// Layout block_index 1 == array key 2 (paragraph at 0, block0 at 1, block1 at 2).
		$written = $saved['post_content'];
		$this->assertSame(
			array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) ),
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
				'panels_data' => array( 'widgets' => array( 'new-for-C' ) ),
				'block_index' => 1,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 1, $result['block_index'] );

		$written = $saved['post_content'];
		// Parse-key 2 (C) received the sanitized layout.
		$this->assertSame(
			array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) ),
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
				'panels_data' => array( 'widgets' => array( 'meta-new' ) ),
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
				'panels_data' => array( 'widgets' => array( 'block-new' ) ),
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
				'panels_data' => array( 'widgets' => array() ),
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
				'panels_data' => array(
					'widgets' => array(
						array( 'panels_info' => array( 'class' => 'WP_Widget_Custom_HTML' ), 'content' => $original_html ),
					),
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

	public function test_meta_write_double_slashes_so_backslashes_survive_unslashing() {
		// update_post_meta() wp_unslash()es its input; without the production
		// map_deep(double_slash_string) wrap, backslashes in stored widget data
		// (e.g. a namespaced class 'SiteOrigin\Widget\Foo', or 'C:\path') are
		// silently stripped. This mirrors the block-path slashing regression test.
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// Sanitizer returns widgets that legitimately contain single backslashes.
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return array(
					array(
						'panels_info' => array( 'class' => 'SiteOrigin\\Widget\\Foo' ),
						'text'        => 'C:\\path\\to\\file',
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
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
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
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
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
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
			)
		);

		$this->assertSame( 'ran', $persisted['pre_save_marker'] ?? null, 'siteorigin_panels_data_pre_save output must be persisted.' );
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
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
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
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
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
				'panels_data' => array( 'widgets' => array() ),
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
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
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
			array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) ),
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
				'panels_data' => array( 'widgets' => array( 'w' ) ),
			)
		);

		$this->assertNull(
			Abilities_AdminSpy::$instance->copy_content_args,
			'Block writes must not trigger the copy-content refresh.'
		);
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

	public function test_registers_the_ability_category() {
		$this->abilities()->register_ability_category();

		$this->assertArrayHasKey( 'siteorigin-panels', $GLOBALS['ability_categories_registered'] );
		$this->assertArrayHasKey( 'label', $GLOBALS['ability_categories_registered']['siteorigin-panels'] );
	}
}
