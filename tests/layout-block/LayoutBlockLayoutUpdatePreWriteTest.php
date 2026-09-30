<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/*
 * Define the shared global `SiteOrigin_Panels` facade stub at FILE LOAD time,
 * identical to tests/layout-block/LayoutBlockInsertPostDataValidationTest.php,
 * so combined-suite runs get the superset definition regardless of load order.
 */
if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
	eval(
		'class SiteOrigin_Panels {'
		. ' public static $instance_resolver = null;'
		. ' public static $renderer = null;'
		. ' public static function get_widget_instance( $class ) {'
		. '   return self::$instance_resolver ? call_user_func( self::$instance_resolver, $class ) : null;'
		. ' }'
		. ' public static function renderer() {'
		. '   return self::$renderer;'
		. ' }'
		. '}'
	);
}

if ( ! class_exists( 'WP_Error', false ) ) {
	eval(
		'class WP_Error {'
		. ' public $code; public $message; public $data;'
		. ' public function __construct( $code = "", $message = "", $data = array() ) {'
		. '   $this->code = $code; $this->message = $message; $this->data = $data;'
		. ' }'
		. ' public function get_error_code() { return $this->code; }'
		. ' public function get_error_message() { return $this->message; }'
		. ' public function get_error_data() { return $this->data; }'
		. '}'
	);
}

/**
 * Widget stub whose update() returns content unchanged while counting calls.
 */
class PreWriteIdentityWidgetStub {
	public $update_calls = 0;

	public function update( $new, $old ) {
		$this->update_calls++;

		return $new;
	}
}

class HiddenSetting {
	private $token = 'keep-me';
}

/**
 * Renderer stub that records each render in the shared call log.
 */
class PreWriteRendererStub {
	public static $log = null;

	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		if ( is_array( self::$log ) ) {
			self::$log[] = 'render';
		}

		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * The Layout Block path of the `siteorigin_panels_layout_update_pre_write`
 * hook, on the REAL SiteOrigin_Panels_Compat_Layout_Block:
 * (a) fires once with the post ID, index and floored payload;
 * (b) order: AI pre-save filter, stored-form finalisation, hook, render;
 * (c) the payload equals the stored form under a kses-like save filter;
 * (d) a form that does not settle within 8 passes stops the write before
 *     the hook; one that settles on pass 8 is written;
 * (e)/(f) aborts leave no render and restore every flag, and write no memo;
 * (g) a nested save does not fire the hook;
 * (h) other save entry points never fire it or run the save filters;
 * (i) an empty block fires once with an empty layout.
 *
 * Self-contained per this suite's conventions; avoids arrow functions and
 * anonymous classes.
 */
class LayoutBlockLayoutUpdatePreWriteTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private const PAYLOAD = '<img src=x onerror=alert(1)>';
	private const CLEANED = '<img src=x>';

	/**
	 * Ordered log of filters, the hook and renders.
	 *
	 * @var array
	 */
	private $log = array();

	/**
	 * Arguments of each pre-write hook application.
	 *
	 * @var array
	 */
	private $pre_write_calls = array();

	/**
	 * Per-tag filter callbacks. A missing tag passes its value through.
	 *
	 * @var array<string,callable>
	 */
	private $callbacks = array();

	/**
	 * @var PreWriteIdentityWidgetStub
	 */
	private $widget_stub;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->log             = array();
		$this->pre_write_calls = array();
		$this->callbacks       = array();

		Functions\when( '__' )->returnArg();

		$test = $this;
		Functions\when( 'apply_filters' )->alias(
			function () use ( $test ) {
				return $test->dispatch_filter( func_get_args() );
			}
		);

		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $value );
			}
		);
		Functions\when( 'wp_kses_no_null' )->alias(
			function ( $value, $options = null ) {
				return str_replace( "\0", '', (string) $value );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// JSON round trip for one self-closing block; slashing is identity.
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'serialize_block' )->alias(
			function ( $block ) {
				return '<!-- wp:' . $block['blockName'] . ' ' . json_encode( $block['attrs'] ) . ' /-->';
			}
		);
		Functions\when( 'parse_blocks' )->alias(
			function ( $content ) {
				$blocks = array();

				if ( preg_match( '#^<!-- wp:([a-z0-9/-]+) (\{.*\}) /-->$#s', trim( (string) $content ), $matches ) ) {
					$blocks[] = array(
						'blockName'    => $matches[1],
						'attrs'        => json_decode( $matches[2], true ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					);
				}

				return $blocks;
			}
		);

		$this->require_classes();

		$this->widget_stub = new PreWriteIdentityWidgetStub();
		$stub = $this->widget_stub;
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		PreWriteRendererStub::$log = &$this->log;
		\SiteOrigin_Panels::$renderer = new PreWriteRendererStub();
	}

	protected function tearDown(): void {
		PreWriteRendererStub::$log = null;
		\SiteOrigin_Panels::$instance_resolver = null;
		\SiteOrigin_Panels::$renderer = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The apply_filters() stand-in. Public so the alias closure can reach it.
	 */
	public function dispatch_filter( array $args ) {
		$tag = $args[0];

		if ( in_array( $tag, array( 'siteorigin_panels_ai_block_layout_pre_save', 'pre_post_content', 'content_save_pre', 'siteorigin_panels_layout_update_pre_write' ), true ) ) {
			$this->log[] = $tag;
		}

		if ( $tag === 'siteorigin_panels_layout_update_pre_write' ) {
			$this->pre_write_calls[] = $args;
		}

		if ( isset( $this->callbacks[ $tag ] ) ) {
			return call_user_func_array( $this->callbacks[ $tag ], array_slice( $args, 1 ) );
		}

		return $args[1];
	}

	private function require_classes() {
		$collaborators = array(
			'SiteOrigin_Panels_Admin_Widget_Dialog',
			'SiteOrigin_Panels_Admin_Widgets_Bundle',
			'SiteOrigin_Panels_Admin_Layouts',
			'SiteOrigin_Panels_Admin_Dashboard',
		);

		foreach ( $collaborators as $collaborator ) {
			if ( ! class_exists( $collaborator, false ) ) {
				eval(
					'class ' . $collaborator . ' {'
					. ' public static function single() {'
					. '   static $single;'
					. '   return empty( $single ) ? $single = new self() : $single;'
					. ' }'
					. '}'
				);
			}
		}

		if ( ! class_exists( 'SiteOrigin_Installer', false ) ) {
			eval( 'class SiteOrigin_Installer {}' );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin', false ) ) {
			eval(
				'class SiteOrigin_Panels_Styles_Admin {'
				. ' public static function single() {'
				. '   static $single;'
				. '   return empty( $single ) ? $single = new self() : $single;'
				. ' }'
				. ' public function sanitize_all( $panels_data ) {'
				. '   return $panels_data;'
				. ' }'
				. '}'
			);
		}

		if ( ! function_exists( 'add_action' ) ) {
			Functions\when( 'add_action' )->justReturn( true );
		}

		if ( ! function_exists( 'add_filter' ) ) {
			Functions\when( 'add_filter' )->justReturn( true );
		}

		$root = dirname( dirname( __DIR__ ) );

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once $root . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once $root . '/compat/layout-block.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Aborted', false ) ) {
			require_once $root . '/inc/layout-update-aborted.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Pre_Write', false ) ) {
			require_once $root . '/inc/layout-update-pre-write.php';
		}
	}

	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function read( $object, $property ) {
		$reflection = new \ReflectionProperty( get_class( $object ), $property );
		$reflection->setAccessible( true );

		return $reflection->getValue( $object );
	}

	private function simulate( $object, $panels_data ) {
		$reflection = new \ReflectionMethod( get_class( $object ), 'simulate_post_save' );
		$reflection->setAccessible( true );

		return $reflection->invoke( $object, $panels_data );
	}

	private function widget( $content ) {
		return array(
			'content'     => $content,
			'panels_info' => array( 'class' => 'PreWriteIdentityWidget' ),
		);
	}

	private function block_for( array $panels_data ) {
		return array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => $panels_data,
				'builder_id' => 'gbtest1',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	private function layout( $content ) {
		return array( 'widgets' => array( $this->widget( $content ) ) );
	}

	/**
	 * A content_save_pre stand-in that escapes a bare & the way core kses does.
	 */
	private function escape_bare_ampersands() {
		$this->callbacks['content_save_pre'] = function ( $content ) {
			return preg_replace( '/&(?!amp;)/', '&amp;', $content );
		};
	}

	private function expect_abort( $block, $code, $content = 'Probe' ) {
		try {
			$block->sanitize_block_for_layout_update( $this->block_for( $this->layout( $content ) ), 77, 1 );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( $code, $e->get_error()->get_error_code() );

			return $e;
		}

		$this->fail( 'The write must stop with ' . $code . '.' );
	}

	// --- (a) Fires once with the resolved context and the floored payload. ----

	public function test_fires_once_with_post_id_index_and_floored_payload() {
		// The forced floor applies even for a capable user.
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = $this->layout_block()->sanitize_block_for_layout_update(
			$this->block_for( $this->layout( self::PAYLOAD ) ),
			'77',
			'1'
		);

		$this->assertCount( 1, $this->pre_write_calls );
		list( , $initial, $payload, $post_id, $storage, $block_index ) = $this->pre_write_calls[0];
		$this->assertTrue( $initial );
		$this->assertSame( 77, $post_id );
		$this->assertSame( 'block', $storage );
		$this->assertSame( 1, $block_index );
		$this->assertSame( self::CLEANED, $payload['widgets'][0]['content'] );
		$this->assertSame( $payload, $result['attrs']['panelsData'], 'The stored panelsData equals the payload.' );
		$this->assertSame( 1, $this->widget_stub->update_calls );
	}

	// --- (b) Order. ------------------------------------------------------------

	public function test_order_is_ai_filter_then_finalisation_then_hook_then_render() {
		$result = $this->layout_block()->sanitize_block_for_layout_update(
			$this->block_for( $this->layout( 'Probe' ) ),
			77,
			0
		);

		$ai        = array_search( 'siteorigin_panels_ai_block_layout_pre_save', $this->log, true );
		$save_pre  = array_search( 'content_save_pre', $this->log, true );
		$pre_write = array_search( 'siteorigin_panels_layout_update_pre_write', $this->log, true );
		$render    = array_search( 'render', $this->log, true );

		$this->assertNotFalse( $ai );
		$this->assertNotFalse( $save_pre );
		$this->assertNotFalse( $render );
		$this->assertLessThan( $save_pre, $ai );
		$this->assertLessThan( $pre_write, $save_pre );
		$this->assertLessThan( $render, $pre_write );
		$this->assertSame( array( 'render' ), array_slice( $this->log, $render ), 'Nothing runs after the render.' );
		$this->assertArrayHasKey( 'contentPreview', $result['attrs'] );
	}

	// --- (c) The payload equals the stored form. ---------------------------------

	public function test_payload_equals_the_stored_form_under_kses_like_save_filters() {
		$this->escape_bare_ampersands();
		$block = $this->layout_block();

		$result = $block->sanitize_block_for_layout_update(
			$this->block_for(
				array(
					'widgets' => array(
						$this->widget( 'Tom & Jerry' ),
						$this->widget( '<strong>Tom & Jerry</strong>' ),
					),
				)
			),
			77,
			0
		);

		$payload = $this->pre_write_calls[0][2];
		$this->assertSame( 'Tom & Jerry', $payload['widgets'][0]['content'], 'The tag-free string keeps its bare & after the repair.' );
		$this->assertSame( '<strong>Tom &amp; Jerry</strong>', $payload['widgets'][1]['content'], 'The payload holds the form the save filters store.' );
		$this->assertSame( $payload, $result['attrs']['panelsData'] );
		$this->assertSame( $payload, $this->simulate( $block, $payload ), 'A further save pass changes nothing.' );
	}

	// --- (d) A form that does not settle stops the write. ------------------------

	public function test_unsettled_form_stops_the_write_before_the_hook_and_render() {
		$this->callbacks['content_save_pre'] = function ( $content ) {
			return str_replace( 'Probe', 'Probe!', $content );
		};

		$this->expect_abort( $this->layout_block(), 'siteorigin_panels_layout_update_unstable' );

		$this->assertCount( 0, $this->pre_write_calls );
		$this->assertNotContains( 'render', $this->log );
		$this->assertCount( 8, array_keys( $this->log, 'content_save_pre', true ), 'The pass limit is 8.' );
	}

	public function test_form_that_settles_on_the_last_allowed_pass_is_written() {
		// Each pass removes one "~", so seven of them settle on pass 8.
		$this->callbacks['content_save_pre'] = function ( $content ) {
			return preg_replace( '/~/', '', $content, 1 );
		};

		$result = $this->layout_block()->sanitize_block_for_layout_update(
			$this->block_for( $this->layout( 'Probe~~~~~~~' ) ),
			77,
			0
		);

		$this->assertCount( 8, array_keys( $this->log, 'content_save_pre', true ) );
		$this->assertCount( 1, $this->pre_write_calls );
		$this->assertSame( 'Probe', $this->pre_write_calls[0][2]['widgets'][0]['content'] );
		$this->assertSame( $this->pre_write_calls[0][2], $result['attrs']['panelsData'] );
	}

	public function test_form_that_needs_a_ninth_pass_stops_the_write() {
		// Eight "~" need a ninth pass to settle.
		$this->callbacks['content_save_pre'] = function ( $content ) {
			return preg_replace( '/~/', '', $content, 1 );
		};

		$this->expect_abort( $this->layout_block(), 'siteorigin_panels_layout_update_unstable', 'Probe~~~~~~~~' );

		$this->assertCount( 0, $this->pre_write_calls );
		$this->assertNotContains( 'render', $this->log );
		$this->assertCount( 8, array_keys( $this->log, 'content_save_pre', true ) );
	}

	public function test_double_escaped_entity_text_from_an_author_is_written() {
		// Kses-like: pads a two-digit numeric entity, as core kses does.
		$this->callbacks['content_save_pre'] = function ( $content ) {
			return preg_replace( '/&#(\d{2});/', '&#0$1;', $content );
		};

		$result = $this->layout_block()->sanitize_block_for_layout_update(
			$this->block_for( $this->layout( '&amp;amp;#91;x&amp;amp;#93;' ) ),
			77,
			0
		);

		// The repair removes one amp; level per pass; the form settles on pass 4.
		$this->assertCount( 4, array_keys( $this->log, 'content_save_pre', true ) );
		$this->assertCount( 1, $this->pre_write_calls );
		$this->assertSame( '&#091;x&#093;', $this->pre_write_calls[0][2]['widgets'][0]['content'] );
		$this->assertSame( $this->pre_write_calls[0][2], $result['attrs']['panelsData'] );
	}

	public function test_block_lost_by_the_save_filters_stops_the_write() {
		$this->callbacks['content_save_pre'] = function () {
			return '';
		};

		$this->expect_abort( $this->layout_block(), 'siteorigin_panels_layout_update_unstable' );

		$this->assertCount( 0, $this->pre_write_calls );
		$this->assertNotContains( 'render', $this->log );
	}

	public function test_unsupported_object_stops_the_write_before_stored_form_simulation() {
		$block = $this->layout_block();
		$raw = $this->block_for( $this->layout( 'Probe' ) );
		$raw['attrs']['panelsData']['widgets'][0]['setting'] = new HiddenSetting();

		try {
			$block->sanitize_block_for_layout_update( $raw, 77, 0 );
			$this->fail( 'The write must stop before the object is JSON encoded.' );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( 'siteorigin_panels_layout_update_unsupported_value', $e->get_error()->get_error_code() );
		}

		$this->assertCount( 0, $this->pre_write_calls );
		$this->assertNotContains( 'content_save_pre', $this->log );
		$this->assertNotContains( 'render', $this->log );
		$this->assertSame( array(), $this->read( $block, 'sanitized_this_request' ), 'No memo entry for an aborted block.' );
	}

	public function test_unsupported_object_from_ai_filter_stops_the_write_before_stored_form_simulation() {
		$block = $this->layout_block();
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) {
			$panels_data['widgets'][0]['setting'] = new HiddenSetting();

			return $panels_data;
		};

		try {
			$block->sanitize_block_for_layout_update( $this->block_for( $this->layout( 'Probe' ) ), 77, 0 );
			$this->fail( 'The write must stop before the object is JSON encoded.' );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( 'siteorigin_panels_layout_update_unsupported_value', $e->get_error()->get_error_code() );
		}

		$this->assertCount( 0, $this->pre_write_calls );
		$this->assertNotContains( 'content_save_pre', $this->log );
		$this->assertNotContains( 'render', $this->log );
		$this->assertSame( array(), $this->read( $block, 'sanitized_this_request' ), 'No memo entry for an aborted block.' );
	}

	// --- (e) WP_Error from the hook. ---------------------------------------------

	public function test_wp_error_stops_the_write_restores_state_and_records_no_memo() {
		$error = new \WP_Error( 'addon_blocked', 'Blocked.', array( 'status' => 409 ) );
		$this->callbacks['siteorigin_panels_layout_update_pre_write'] = function () use ( $error ) {
			return $error;
		};

		$block = $this->layout_block();
		$raw   = $this->block_for( $this->layout( 'Probe' ) );

		try {
			$block->sanitize_block_for_layout_update( $raw, 77, 1 );
			$this->fail( 'The write must stop.' );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( $error, $e->get_error() );
		}

		$this->assertNotContains( 'render', $this->log );
		$this->assertFalse( $this->read( $block, 'force_kses_floor' ) );
		$this->assertTrue( $this->read( $block, 'return_layout' ) );
		$this->assertNull( $this->read( $block, 'layout_update_pre_write' ) );
		$this->assertSame( array(), $this->read( $block, 'sanitized_this_request' ), 'No memo entry for an aborted block.' );

		// A later plain save of the same raw block still sanitizes.
		unset( $this->callbacks['siteorigin_panels_layout_update_pre_write'] );
		$calls = $this->widget_stub->update_calls;
		$block->sanitize_block( $raw );
		$this->assertSame( $calls + 1, $this->widget_stub->update_calls );
	}

	// --- (f) false from the hook. --------------------------------------------------

	public function test_false_stops_the_write_with_the_aborted_code() {
		$this->callbacks['siteorigin_panels_layout_update_pre_write'] = function () {
			return false;
		};

		$this->expect_abort( $this->layout_block(), 'siteorigin_panels_layout_update_aborted' );

		$this->assertNotContains( 'render', $this->log );
	}

	// --- (g) A nested save does not fire the hook. -----------------------------------

	public function test_nested_save_from_the_ai_filter_does_not_fire_the_hook() {
		$block  = $this->layout_block();
		$nested = false;
		$test   = $this;
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) use ( $block, &$nested, $test ) {
			if ( ! $nested ) {
				$nested = true;
				$block->sanitize_block( $test->inner_block() );
			}

			return $panels_data;
		};

		$block->sanitize_block_for_layout_update( $this->block_for( $this->layout( 'Outer' ) ), 77, 0 );

		$this->assertTrue( $nested );
		$this->assertSame( 2, count( array_keys( $this->log, 'render', true ) ), 'Both blocks rendered.' );
		$this->assertCount( 1, $this->pre_write_calls, 'Only the target block fires the hook.' );
		$this->assertSame( 'Outer', $this->pre_write_calls[0][2]['widgets'][0]['content'] );
	}

	/**
	 * A second block for the nested save. Public for the filter closure.
	 */
	public function inner_block() {
		$inner = $this->block_for( $this->layout( 'Inner' ) );
		$inner['attrs']['builder_id'] = 'gbinner';

		return $inner;
	}

	// --- (h) Other save entry points never fire the hook. --------------------------

	public function test_plain_and_untrusted_saves_never_fire_the_hook_or_the_save_filters() {
		$block = $this->layout_block();

		$block->sanitize_block( $this->block_for( $this->layout( 'Plain' ) ) );
		$block->sanitize_block_untrusted( $this->block_for( $this->layout( 'Untrusted' ) ) );

		$this->assertCount( 0, $this->pre_write_calls );
		$this->assertNotContains( 'content_save_pre', $this->log );
		$this->assertNotContains( 'pre_post_content', $this->log );
		$this->assertSame( 2, count( array_keys( $this->log, 'render', true ) ) );
	}

	// --- (i) An empty block fires once with an empty layout. -------------------------

	public function test_empty_block_fires_once_with_an_empty_layout_and_no_render() {
		$block = $this->block_for( array() );

		$result = $this->layout_block()->sanitize_block_for_layout_update( $block, 77, 2 );

		$this->assertCount( 1, $this->pre_write_calls );
		$this->assertSame( array(), $this->pre_write_calls[0][2] );
		$this->assertSame( 2, $this->pre_write_calls[0][5] );
		$this->assertNotContains( 'render', $this->log );
		$this->assertSame( $block, $result );
	}
}
