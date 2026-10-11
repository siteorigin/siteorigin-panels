<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
 * Widget stub whose update() returns the instance unchanged and records the
 * content of each widget it was given.
 */
class UnchangedRecordingWidgetStub {
	public $updated = array();

	public function update( $new, $old ) {
		$this->updated[] = isset( $new['content'] ) ? $new['content'] : null;

		return $new;
	}
}

class UnchangedRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * The Layout Block path of issue #1409 on the REAL
 * SiteOrigin_Panels_Compat_Layout_Block: a layout-update write keeps the
 * stored value of widgets the caller sent back unchanged and floors every
 * other widget; an editor save is unchanged.
 */
class LayoutBlockLayoutUpdateUnchangedTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private const EMBED = 'Embed <img src=x onerror=a()>';

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
	 * @var UnchangedRecordingWidgetStub
	 */
	private $widget_stub;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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
		Functions\when( 'current_user_can' )->justReturn( true );

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

		$this->widget_stub = new UnchangedRecordingWidgetStub();
		$stub = $this->widget_stub;
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new UnchangedRendererStub();
	}

	protected function tearDown(): void {
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

		if ( $tag === 'siteorigin_panels_layout_update_pre_write' ) {
			$this->pre_write_calls[] = $args;
		}

		if ( isset( $this->callbacks[ $tag ] ) ) {
			return call_user_func_array( $this->callbacks[ $tag ], array_slice( $args, 1 ) );
		}

		return $args[1];
	}

	private function require_classes() {
		foreach ( array( 'SiteOrigin_Panels_Admin_Widget_Dialog', 'SiteOrigin_Panels_Admin_Widgets_Bundle', 'SiteOrigin_Panels_Admin_Layouts', 'SiteOrigin_Panels_Admin_Dashboard' ) as $collaborator ) {
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

		if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Unchanged', false ) ) {
			require_once $root . '/inc/layout-update-unchanged.php';
		}

		require_once __DIR__ . '/map-deep.php';
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

	private function widget( $content, $cell = 0, $id = 0 ) {
		return array(
			'content'     => $content,
			'panels_info' => array(
				'class'     => 'UnchangedRecordingWidget',
				'grid'      => 0,
				'cell'      => $cell,
				'id'        => $id,
				'widget_id' => 'w-' . md5( $content ),
			),
		);
	}

	private function layout( array $widgets ) {
		return array(
			'widgets'    => $widgets,
			'grids'      => array( array( 'cells' => 2 ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 0.5 ), array( 'grid' => 0, 'weight' => 0.5 ) ),
		);
	}

	private function block_for( array $panels_data ) {
		return array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array( 'panelsData' => $panels_data, 'builder_id' => 'gbtest1' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Run a layout-update write of $incoming over $stored; returns the stored
	 * panelsData read back through the block JSON round trip.
	 */
	private function write( $stored, $incoming, $object_keys = array(), $block = null ) {
		$block  = $block === null ? $this->layout_block() : $block;
		$result = $block->sanitize_block_for_layout_update( $this->block_for( $incoming ), 77, 0, $stored, $object_keys );

		$parsed = parse_blocks( serialize_block( $result ) );

		return $parsed[0]['attrs']['panelsData'];
	}

	private function expect_decline( $code, $stored, $incoming ) {
		$calls = count( $this->pre_write_calls );

		try {
			$this->write( $stored, $incoming );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( $code, $e->get_error()->get_error_code() );
			$this->assertCount( $calls, $this->pre_write_calls, 'nothing reaches the hook' );

			return;
		}

		$this->fail( 'The write must stop with ' . $code . '.' );
	}

	public function test_unchanged_widget_skips_update_and_keeps_its_stored_value() {
		$stored   = $this->layout( array( $this->widget( self::EMBED ), $this->widget( 'Plain', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][0]['panels_info']['cell_index'] = 0;
		$incoming['widgets'][1]['content'] = 'Changed <img src=x onerror=b()>';

		$block   = $this->layout_block();
		$written = $this->write( $stored, $incoming, array(), $block );

		$this->assertSame( $stored['widgets'][0], $written['widgets'][0] );
		$this->assertSame( 'Changed <img src=x>', $written['widgets'][1]['content'] );
		$this->assertNotContains( self::EMBED, $this->widget_stub->updated, 'a kept widget skips update()' );
		$this->assertSame( $written, $this->pre_write_calls[0][2], 'the payload equals what is stored' );
		$this->assertNull( $this->read( $block, 'layout_update_pre_write' ), 'the context is cleared after the call' );
	}

	public function test_memo_pass_does_not_update_a_kept_widget() {
		$stored = $this->layout( array( $this->widget( self::EMBED ) ) );
		$block  = $this->layout_block();
		$result = $block->sanitize_block_for_layout_update( $this->block_for( $stored ), 77, 0, $stored, array() );
		$before = $this->widget_stub->updated;

		$block->sanitize_block( $result );

		$this->assertSame( $before, $this->widget_stub->updated );
	}

	public function test_moved_widget_is_kept_at_the_callers_position() {
		$stored   = $this->layout( array( $this->widget( self::EMBED ), $this->widget( 'Plain', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][0]['panels_info']['cell'] = 1;
		$incoming['widgets'][0]['panels_info']['id']   = null;
		$incoming['widgets'][1]['panels_info']['id']   = 0;

		$written = $this->write( $stored, $incoming );

		$this->assertSame( self::EMBED, $written['widgets'][0]['content'] );
		$this->assertSame( 1, $written['widgets'][0]['panels_info']['cell'] );
		$this->assertArrayHasKey( 'id', $written['widgets'][0]['panels_info'] );
		$this->assertNull( $written['widgets'][0]['panels_info']['id'], 'present null is kept' );
	}

	public function test_a_moved_widget_whose_panels_info_is_a_reference_keeps_its_position() {
		$stored   = $this->layout( array( $this->widget( self::EMBED ) ) );
		$info     = $stored['widgets'][0]['panels_info'];
		$info['cell'] = 1;
		$incoming = $stored;
		$incoming['widgets'][0]['panels_info'] = &$info;

		$written = $this->write( $stored, $incoming );

		$this->assertSame( self::EMBED, $written['widgets'][0]['content'] );
		$this->assertSame( 1, $written['widgets'][0]['panels_info']['cell'] );
		$this->assertSame( 0, $written['widgets'][0]['panels_info']['grid'] );
	}

	public function test_ai_filter_return_decides_whether_widgets_are_kept() {
		$stored = $this->layout( array( $this->widget( self::EMBED ) ) );

		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function () {
			return 'not an array';
		};
		$this->assertSame( self::EMBED, $this->write( $stored, $stored )['widgets'][0]['content'], 'an ignored return keeps matching' );

		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) {
			$panels_data['widgets'][0]['extra'] = (object) array( 'html' => '<img src=x onerror=x()>' );

			return $panels_data;
		};
		$written = $this->write( $stored, $stored );
		$this->assertSame( 'Embed <img src=x>', $written['widgets'][0]['content'], 'the edited widget is floored' );
		$this->assertSame( '<img src=x>', $written['widgets'][0]['extra']['html'], 'strings inside an added object are floored' );

		$two = $this->layout( array( $this->widget( self::EMBED ), $this->widget( 'Plain', 0, 1 ) ) );
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) {
			$panels_data['widgets'][1]['content'] = 'Edited by the filter';

			return $panels_data;
		};
		$written = $this->write( $two, $two );
		$this->assertSame( 'Embed <img src=x>', $written['widgets'][0]['content'], 'a replaced layout is floored whole, untouched widgets included' );

		$shared       = new \stdClass();
		$shared->html = 'stored';
		$with_object  = $this->layout( array( array_merge( $this->widget( self::EMBED ), array( 'setting' => $shared ) ) ) );
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) {
			$panels_data['widgets'][0]['setting']->html = 'edited in place';

			return $panels_data;
		};
		$written = $this->write( unserialize( serialize( $with_object ) ), $with_object );
		$this->assertSame( 'Embed <img src=x>', $written['widgets'][0]['content'], 'an in-place edit is compared after the filter and floored' );
	}

	public function test_ai_filter_that_reorders_keys_is_declined() {
		$stored = $this->layout( array( $this->widget( 'A' ), $this->widget( 'B', 0, 1 ), $this->widget( 'C', 1, 0 ) ) );
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) {
			$panels_data['widgets'] = array( 2 => $panels_data['widgets'][2], 0 => $panels_data['widgets'][0], 1 => $panels_data['widgets'][1] );

			return $panels_data;
		};

		$this->expect_decline( 'siteorigin_panels_layout_update_unresolved_reference', $stored, $stored );
	}

	public function test_cyclic_values_are_declined() {
		$loop         = array( 'x' => 1 );
		$loop['self'] = &$loop;
		$cyclic       = $this->widget( 'A' );
		$cyclic['loop'] = $loop;

		$this->expect_decline( 'siteorigin_panels_layout_update_unsupported_value', null, $this->layout( array( $cyclic ) ) );

		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) use ( $loop ) {
			$panels_data['widgets'][0]['loop'] = $loop;

			return $panels_data;
		};
		$this->expect_decline( 'siteorigin_panels_layout_update_unsupported_value', null, $this->layout( array( $this->widget( 'A' ) ) ) );
	}

	public function test_stored_shapes_and_list_order() {
		$a       = $this->widget( self::EMBED );
		$a['panels_info']['raw'] = true;
		$stored  = $this->layout( array( 'scalar', $a, $this->widget( 'B', 0, 1 ) ) );
		$written = $this->write( $stored, $this->layout( array( $a, $this->widget( 'B2', 0, 1 ), $this->widget( 'C <img src=x onerror=c()>', 1, 0 ) ) ) );

		$this->assertSame( array( 0, 1, 2 ), array_keys( $written['widgets'] ) );
		$this->assertSame( $a, $written['widgets'][0], 'kept with its legacy raw key; scalars never match' );
		$this->assertSame( 'B2', $written['widgets'][1]['content'] );
		$this->assertSame( 'C <img src=x>', $written['widgets'][2]['content'] );

		$written = $this->write( null, $this->layout( array( $this->widget( self::EMBED ) ) ) );
		$this->assertSame( 'Embed <img src=x>', $written['widgets'][0]['content'], 'no stored layout: everything floored' );
	}

	public function test_deep_values_within_block_storage() {
		$deep = 'deep <img src=x onerror=d()>';
		for ( $i = 0; $i < 500; $i++ ) {
			$deep = array( 'n' => $deep );
		}
		$a           = $this->widget( 'A' );
		$a['nested'] = $deep;
		$stored      = $this->layout( array( $a ) );

		$this->assertSame( $a, $this->write( $stored, $stored )['widgets'][0], '500 levels: identical widget kept' );

		$changed = $a;
		$changed['content'] = 'A2';
		$leaf    = $this->write( $stored, $this->layout( array( $changed ) ) )['widgets'][0]['nested'];
		for ( $i = 0; $i < 500; $i++ ) {
			$leaf = $leaf['n'];
		}
		$this->assertSame( 'deep <img src=x>', $leaf, '500 levels: changed widget floored' );

		$too_deep = 'x';
		for ( $i = 0; $i < 1000; $i++ ) {
			$too_deep = array( 'n' => $too_deep );
		}
		$this->expect_decline( 'siteorigin_panels_layout_update_unstable', null, $this->layout( array( array_merge( $this->widget( 'A' ), array( 'nested' => $too_deep ) ) ) ) );
	}

	public function test_editor_save_keeps_its_floor() {
		Functions\when( 'current_user_can' )->justReturn( false );
		$setting       = new \stdClass();
		$setting->html = '<img src=x onerror=e()>';
		$widget        = array_merge( $this->widget( self::EMBED ), array( 'setting' => $setting ) );

		$result = $this->layout_block()->sanitize_block( $this->block_for( $this->layout( array( $widget ) ) ) );

		$this->assertSame( 'Embed <img src=x>', $result['attrs']['panelsData']['widgets'][0]['content'], 'kses_deep() as today' );
		$this->assertSame( '<img src=x onerror=e()>', $result['attrs']['panelsData']['widgets'][0]['setting']->html, 'today\'s floor does not descend objects' );
		$this->assertCount( 0, $this->pre_write_calls );
	}

	// --- A kept widget that WordPress kses would change -------------------------

	/**
	 * A content_save_pre stand-in for core kses: removes iframes and on*
	 * attributes, and escapes a bare &.
	 */
	private function core_kses() {
		$this->callbacks['content_save_pre'] = function ( $content ) {
			$content = preg_replace( '#<iframe[^>]*>(<\\\\/iframe>)?#', '', $content );
			$content = preg_replace( '/\s*on\w+=[^\s>"\\\\]+/i', '', $content );

			return preg_replace( '/&(?!amp;)/', '&amp;', $content );
		};
	}

	private function without_unfiltered_html() {
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return $capability !== 'unfiltered_html';
			}
		);
	}

	public static function sites() {
		return array(
			'single site' => array( false, "The layout wasn't saved because it has an embed or HTML your account can't save. Ask a user who can save HTML on this site to make the change." ),
			'multisite'   => array( true, "The layout wasn't saved because it has an embed or HTML your account can't save. Ask a Super Admin for the multisite network to make the change." ),
		);
	}

	#[DataProvider( 'sites' )]
	public function test_a_kept_widget_kses_would_change_stops_the_write( $multisite, $message ) {
		Functions\when( 'is_multisite' )->justReturn( $multisite );
		$this->without_unfiltered_html();
		$this->core_kses();
		$stored   = $this->layout( array( $this->widget( self::EMBED ), $this->widget( 'Plain', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][1]['content'] = 'Changed';

		try {
			$this->write( $stored, $incoming );
			$this->fail( 'The write must stop.' );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$error = $e->get_error();
			$this->assertSame( 'siteorigin_panels_layout_update_needs_unfiltered_html', $error->get_error_code() );
			$this->assertSame( array( 'status' => 403 ), $error->get_error_data() );
			$this->assertSame( $message, $error->get_error_message() );
		}

		$this->assertCount( 0, $this->pre_write_calls, 'nothing reaches the hook' );
	}

	public function test_a_moved_kept_widget_kses_would_change_stops_the_write() {
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->without_unfiltered_html();
		$this->core_kses();
		$stored   = $this->layout( array( $this->widget( self::EMBED ) ) );
		$incoming = $stored;
		$incoming['widgets'][0]['panels_info']['cell'] = 1;

		$this->expect_decline( 'siteorigin_panels_layout_update_needs_unfiltered_html', $stored, $incoming );
	}

	public function test_kept_widgets_kses_leaves_alone_are_written() {
		$this->without_unfiltered_html();
		$this->core_kses();
		$stored   = $this->layout( array( $this->widget( 'Plain' ), $this->widget( 'Tom & Jerry', 0, 1 ), $this->widget( 'B', 1, 0 ) ) );
		$incoming = $stored;
		$incoming['widgets'][2]['content'] = 'Changed <img src=x onerror=b()>';

		$written = $this->write( $stored, $incoming );

		$this->assertSame( $stored['widgets'][0], $written['widgets'][0] );
		$this->assertSame( $stored['widgets'][1], $written['widgets'][1], 'the & repair settles the text' );
		$this->assertSame( 'Changed <img src=x>', $written['widgets'][2]['content'] );
		$this->assertSame( $written, $this->pre_write_calls[0][2] );
	}

	public function test_new_and_changed_widgets_kses_strips_do_not_stop_the_write() {
		$this->without_unfiltered_html();
		$this->core_kses();
		$stored   = $this->layout( array( $this->widget( 'Plain' ), $this->widget( 'B', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][1]['content'] = 'Changed <img src=x onerror=b()>';
		$incoming['widgets'][2]           = $this->widget( 'New <iframe src="https://example.com"></iframe>', 1, 0 );

		$written = $this->write( $stored, $incoming );

		$this->assertSame( $stored['widgets'][0], $written['widgets'][0] );
		$this->assertSame( 'Changed <img src=x>', $written['widgets'][1]['content'] );
		$this->assertSame( 'New ', $written['widgets'][2]['content'], 'kses strips the new widget\'s iframe, as today' );
	}

	public function test_a_user_with_unfiltered_html_is_never_refused() {
		$this->core_kses();
		$stored   = $this->layout( array( $this->widget( self::EMBED ), $this->widget( 'Plain', 0, 1 ) ) );
		$incoming = $stored;
		$incoming['widgets'][1]['content'] = 'Changed';

		$written = $this->write( $stored, $incoming );

		$this->assertSame( 'Embed <img src=x>', $written['widgets'][0]['content'], 'a save filter still runs; the rule is gated on the capability' );
		$this->assertCount( 1, $this->pre_write_calls );
	}
}
