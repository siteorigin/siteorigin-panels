<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

/**
 * Arity-sweep regression test for the AI-untrusted invariant.
 *
 * Post-merge, process_raw_widgets() carries develop's 5-param signature
 * ( $widgets, $old_widgets, $escape_classes, $force, $trusted ), where a
 * truthy 5th argument skips sanitization entirely. The invariant this file
 * pins: NO AI call site may ever pass `$trusted = true` — the only legitimate
 * 5-arg caller in the codebase is the signature-gated trusted render path
 * (compat/layout-block.php::prepare_render_panels_data()).
 *
 * Concretely:
 *  (a) the ability META write invokes process_raw_widgets() with at most
 *      three arguments — $force and $trusted are never even supplied;
 *  (b) the ability BLOCK write performs NO direct sanitize at all: its sole
 *      sanitize entry is the compat chokepoint
 *      (SiteOrigin_Panels_Compat_Layout_Block::sanitize_block_untrusted()),
 *      whose internal call is likewise 3-arg strict and forces the kses floor.
 *
 * Uses the same shims AbilitiesTest defines at file load (PHPUnit loads every
 * default-suite file before tests run).
 */
class AiCallSiteArityTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		Abilities_AdminSpy::$instance       = new Abilities_AdminSpy();
		Abilities_StylesSpy::$instance      = new Abilities_StylesSpy();
		Abilities_EmulatorSpy::$instance    = new Abilities_EmulatorSpy();
		Abilities_LayoutBlockSpy::$instance = new Abilities_LayoutBlockSpy();

		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'serialize_blocks' )->alias( fn( $blocks ) => $blocks );
		Functions\when( 'siteorigin_panels_setting' )->justReturn( false );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_update_post' )->alias( fn( $args ) => $args['ID'] );
	}

	private function abilities(): SiteOrigin_Panels_Abilities {
		return SiteOrigin_Panels_Abilities::single();
	}

	public function test_meta_write_passes_at_most_three_args_and_never_trusted() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 61, 'post_content' => 'classic content' ) );
		Functions\when( 'parse_blocks' )->justReturn( array() );

		// Arity-recording spy: capture EVERY argument actually supplied, not
		// just the declared parameters.
		Abilities_AdminSpy::$instance = new class extends Abilities_AdminSpy {
			public $raw_args = null;

			public function process_raw_widgets( $widgets, $old_widgets = array(), $escape_classes = false, $force = false ) {
				$this->raw_args     = func_get_args();
				$this->process_args = array( $widgets, $old_widgets, $escape_classes );

				return array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) );
			}
		};

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 61,
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );

		$raw_args = Abilities_AdminSpy::$instance->raw_args;
		$this->assertNotNull( $raw_args, 'The meta write must sanitize via process_raw_widgets().' );
		$this->assertLessThanOrEqual(
			3,
			count( $raw_args ),
			'AI meta write must pass at most 3 args — $force and $trusted must never be supplied.'
		);
		$this->assertArrayNotHasKey( 4, $raw_args, 'No 5th ($trusted) argument may exist on an AI call site.' );
	}

	public function test_block_write_sole_sanitize_entry_is_the_untrusted_chokepoint() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 62, 'post_content' => 'one block' ) );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => array( 'widgets' => array( 'existing' ) ) ),
				),
			)
		);

		$result = $this->abilities()->layout_update(
			array(
				'post_id'     => 62,
				'panels_data' => array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'X' ) ) ) ),
			)
		);

		$this->assertTrue( $result['updated'] );

		// The chokepoint is the ONLY sanitize entry on the block path: the
		// forced-floor chokepoint ran exactly once, and abilities code invoked
		// neither process_raw_widgets() nor sanitize_all() directly (so there
		// is no direct call whose arity could ever grow a $trusted arg).
		$this->assertSame( 1, Abilities_LayoutBlockSpy::$instance->untrusted_calls );
		$this->assertNull( Abilities_AdminSpy::$instance->process_args, 'No direct process_raw_widgets() on the AI block path.' );
		$this->assertFalse( Abilities_StylesSpy::$instance->sanitize_all_called, 'No direct sanitize_all() on the AI block path.' );
	}
}
