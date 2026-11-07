<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
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
 * Behaves like WP_Widget_Custom_HTML::update(): kses for a user without
 * unfiltered_html, otherwise unchanged.
 */
class MarkupRemovedKsesWidgetStub {
	public function update( $new, $old ) {
		if ( ! \current_user_can( 'unfiltered_html' ) && isset( $new['content'] ) ) {
			$new['content'] = \wp_kses_post( $new['content'] );
		}

		return $new;
	}
}

/**
 * A widget whose update() throws.
 */
class MarkupRemovedThrowingWidgetStub {
	public function update( $new, $old ) {
		throw new \RuntimeException( 'widget update failed' );
	}
}

/**
 * A widget whose update() runs process_raw_widgets() on its own inner widgets,
 * like the Layout Builder widget or a save started from inside an update().
 */
class MarkupRemovedNestedWidgetStub {
	public function update( $new, $old ) {
		$new['inner'] = \SiteOrigin_Panels_Admin::single()->process_raw_widgets( $new['inner'], false, true );

		return $new;
	}
}

class MarkupRemovedRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * The Layout Block markup-removed report through the real save chokepoint
 * (#1454): which saves report, with which identifiers, and that reporting
 * never changes what is stored.
 */
class LayoutBlockMarkupRemovedTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Every report fired, in order.
	 *
	 * @var array
	 */
	private $reports = array();

	/**
	 * Filter tag => callable, used by the apply_filters() stand-in.
	 *
	 * @var array
	 */
	public $callbacks = array();

	/**
	 * What parse_blocks() returns next.
	 *
	 * @var array|null
	 */
	private $parsed = null;

	/**
	 * Whether the recording listener throws after recording.
	 *
	 * @var bool
	 */
	private $listener_throws = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->reports   = array();
		$this->callbacks = array();
		$this->parsed    = null;
		$this->listener_throws = false;

		Functions\when( '__' )->returnArg();

		$test = $this;
		Functions\when( 'apply_filters' )->alias(
			function () use ( $test ) {
				$args = func_get_args();
				if ( isset( $test->callbacks[ $args[0] ] ) ) {
					return call_user_func_array( $test->callbacks[ $args[0] ], array_slice( $args, 1 ) );
				}

				return $args[1];
			}
		);

		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				$value = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', (string) $value );
				$value = preg_replace( '#</?center\b[^>]*>#i', '', $value );

				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value );
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
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Content is a JSON list of parsed blocks, so any block tree round-trips.
		Functions\when( 'serialize_blocks' )->alias(
			function ( $blocks ) {
				return json_encode( array_values( $blocks ) );
			}
		);
		Functions\when( 'serialize_block' )->alias(
			function ( $block ) {
				return json_encode( array( $block ) );
			}
		);
		Functions\when( 'parse_blocks' )->alias(
			function ( $content ) {
				$blocks = json_decode( (string) $content, true );

				return is_array( $blocks ) ? $blocks : array();
			}
		);

		$this->require_classes();

		\SiteOrigin_Panels::$instance_resolver = function ( $class ) {
			switch ( $class ) {
				case 'MarkupRemovedKses':
					return new MarkupRemovedKsesWidgetStub();
				case 'MarkupRemovedThrowing':
					return new MarkupRemovedThrowingWidgetStub();
				case 'MarkupRemovedNested':
					return new MarkupRemovedNestedWidgetStub();
			}

			return null;
		};
		\SiteOrigin_Panels::$renderer = new MarkupRemovedRendererStub();

		Actions\expectDone( \SiteOrigin_Panels_Markup_Removal::ACTION )->zeroOrMoreTimes()->whenHappen(
			function ( $report ) use ( $test ) {
				$test->record_report( $report );
			}
		);
	}

	protected function tearDown(): void {
		\SiteOrigin_Panels::$instance_resolver = null;
		\SiteOrigin_Panels::$renderer = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function record_report( $report ) {
		$this->reports[] = $report;
		if ( $this->listener_throws ) {
			throw new \RuntimeException( 'listener failed' );
		}
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
		foreach ( array(
			'SiteOrigin_Panels_Admin'                   => '/inc/admin.php',
			'SiteOrigin_Panels_Compat_Layout_Block'     => '/compat/layout-block.php',
			'SiteOrigin_Panels_Layout_Update_Aborted'   => '/inc/layout-update-aborted.php',
			'SiteOrigin_Panels_Layout_Update_Pre_Write' => '/inc/layout-update-pre-write.php',
			'SiteOrigin_Panels_Layout_Update_Unchanged' => '/inc/layout-update-unchanged.php',
			'SiteOrigin_Panels_Markup_Removal'          => '/inc/markup-removal.php',
		) as $class => $file ) {
			if ( ! class_exists( $class, false ) ) {
				require_once $root . $file;
			}
		}
		require_once __DIR__ . '/map-deep.php';
	}

	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function widget( $id, $content, $class = 'MarkupRemovedKses' ) {
		return array(
			'content'     => $content,
			'panels_info' => array(
				'class'     => $class,
				'grid'      => 0,
				'cell'      => 0,
				'id'        => 0,
				'widget_id' => $id,
			),
		);
	}

	private function layout_block_of( array $widgets ) {
		return array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => array(
					'widgets'    => $widgets,
					'grids'      => array( array( 'cells' => 1 ) ),
					'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ),
				),
				'builder_id' => 'gbtest1',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	private function rest_save( $compat, array $blocks, $id = 42 ) {
		return $compat->server_side_validation(
			(object) array(
				'ID'           => $id,
				'post_content' => json_encode( $blocks ),
			),
			null
		);
	}

	private function finding( $block, $widget_id, $source = 'page_builder', $widget = 0, $class = 'MarkupRemovedKses', $block_index = null ) {
		return array(
			'block'       => $block,
			'block_index' => $block_index,
			'widget'      => $widget,
			'widget_id'   => $widget_id,
			'class'       => $class,
			'source'      => $source,
		);
	}

	const IFRAME = '<p>a</p><iframe src="https://example.com/embed"></iframe>';

	public function test_rest_save_reports_once_with_the_post_id_and_document_ordinal() {
		$empty = array( 'blockName' => 'siteorigin-panels/layout-block', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
		$group = array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array(
				$this->layout_block_of( array( $this->widget( 'plain', '<b>kept</b>' ) ) ),
				$this->layout_block_of( array( $this->widget( 'embed', self::IFRAME ) ) ),
			),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$this->rest_save( $this->layout_block(), array( $empty, $group ) );

		$this->assertSame(
			array(
				array(
					'post_id' => 42,
					'context' => 'rest_pre_insert',
					'widgets' => array( $this->finding( 2, 'embed' ) ),
				),
			),
			$this->reports
		);
	}

	public function test_a_nested_save_from_the_ai_filter_is_not_credited_and_does_not_shift_the_ordinal() {
		$compat = $this->layout_block();
		$nested = $this->layout_block_of( array( $this->widget( 'nested', self::IFRAME ) ) );
		$done   = false;
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) use ( $compat, $nested, &$done ) {
			if ( ! $done ) {
				$done = true;
				$compat->sanitize_blocks( $nested );
			}

			return $panels_data;
		};

		$this->rest_save(
			$compat,
			array(
				$this->layout_block_of( array( $this->widget( 'a', self::IFRAME ) ) ),
				$this->layout_block_of( array( $this->widget( 'b', self::IFRAME ) ) ),
			)
		);

		$this->assertCount( 1, $this->reports );
		$this->assertSame( array( $this->finding( 0, 'a' ), $this->finding( 1, 'b' ) ), $this->reports[0]['widgets'] );
	}

	public function test_a_memo_hit_does_not_report_again() {
		$compat = $this->layout_block();
		$saved  = $this->rest_save( $compat, array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ) ) );

		$compat->server_side_validation( (object) array( 'ID' => 42, 'post_content' => $saved->post_content ), null );

		$this->assertCount( 1, $this->reports );
	}

	public function test_a_capable_save_does_not_report() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$saved = $this->rest_save( $this->layout_block(), array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ) ) );

		$this->assertSame( array(), $this->reports );
		$this->assertStringContainsString( 'iframe', $saved->post_content );
	}

	public function test_an_iframe_added_by_the_ai_filter_and_floored_reports() {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->callbacks['siteorigin_panels_ai_block_layout_pre_save'] = function ( $panels_data ) {
			$panels_data['widgets'][0]['content'] = self::IFRAME;

			return $panels_data;
		};

		$this->rest_save( $this->layout_block(), array( $this->layout_block_of( array( $this->widget( 'w1', 'plain' ) ) ) ) );

		$this->assertSame( array( $this->finding( 0, 'w1' ) ), $this->reports[0]['widgets'] );
	}

	public function test_a_widget_that_throws_in_a_later_block_still_reports_the_earlier_block() {
		try {
			$this->rest_save(
				$this->layout_block(),
				array(
					$this->layout_block_of( array( $this->widget( 'a', self::IFRAME ) ) ),
					$this->layout_block_of( array( $this->widget( 'x', 'text', 'MarkupRemovedThrowing' ) ) ),
				)
			);
			$this->fail( 'The widget exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'widget update failed', $e->getMessage() );
		}

		$this->assertSame( array( $this->finding( 0, 'a' ) ), $this->reports[0]['widgets'] );
	}

	public function test_a_widget_that_throws_after_an_earlier_widget_in_the_block_still_reports_that_widget() {
		try {
			$this->rest_save(
				$this->layout_block(),
				array(
					$this->layout_block_of(
						array(
							$this->widget( 'w1', self::IFRAME ),
							$this->widget( 'w2', 'text', 'MarkupRemovedThrowing' ),
						)
					),
				)
			);
			$this->fail( 'The widget exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'widget update failed', $e->getMessage() );
		}

		$this->assertCount( 1, $this->reports );
		$this->assertSame( array( $this->finding( 0, 'w1' ) ), $this->reports[0]['widgets'] );
	}

	public function test_a_completed_block_reports_each_widget_once() {
		$this->rest_save(
			$this->layout_block(),
			array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ), $this->widget( 'w2', '<a href=x onclick=y>l</a>' ) ) ) )
		);

		$this->assertSame(
			array( $this->finding( 0, 'w1' ), $this->finding( 0, 'w2', 'page_builder', 1 ) ),
			$this->reports[0]['widgets']
		);
	}

	public function test_reporting_does_not_change_the_stored_content() {
		$blocks = array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ), $this->widget( 'w2', 'Tom & Jerry' ) ) ) );

		$reported = $this->rest_save( $this->layout_block(), $blocks );

		// The same blocks sanitized with no attempt open, so nothing records.
		$plain = $this->layout_block()->sanitize_blocks( $blocks[0] );

		$this->assertSame( json_encode( array( $plain ) ), $reported->post_content );
		$this->assertCount( 1, $this->reports );
	}

	public function test_a_throwing_listener_fails_the_save_like_any_action() {
		$blocks = array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ) );

		$this->listener_throws = true;
		try {
			$this->rest_save( $this->layout_block(), $blocks );
			$this->fail( 'The listener exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'listener failed', $e->getMessage() );
		}

		$this->assertCount( 1, $this->reports, 'The listener ran.' );
	}

	public function test_a_listener_exception_during_a_failing_save_keeps_the_save_exception_as_previous() {
		$this->listener_throws = true;
		try {
			$this->rest_save(
				$this->layout_block(),
				array(
					$this->layout_block_of(
						array(
							$this->widget( 'w1', self::IFRAME ),
							$this->widget( 'w2', 'text', 'MarkupRemovedThrowing' ),
						)
					),
				)
			);
			$this->fail( 'An exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'listener failed', $e->getMessage() );
			$this->assertInstanceOf( \RuntimeException::class, $e->getPrevious() );
			$this->assertSame( 'widget update failed', $e->getPrevious()->getMessage() );
		}
	}

	public function test_an_ability_write_reports_its_block_index() {
		$this->layout_block()->sanitize_block_for_layout_update(
			$this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ),
			77,
			2
		);

		$this->assertSame(
			array(
				array(
					'post_id' => 77,
					'context' => 'ability',
					'widgets' => array( $this->finding( null, 'w1', 'page_builder', 0, 'MarkupRemovedKses', 2 ) ),
				),
			),
			$this->reports
		);
	}

	public function test_an_ability_write_stopped_by_the_pre_write_hook_still_reports() {
		$this->callbacks['siteorigin_panels_layout_update_pre_write'] = function () {
			return new \WP_Error( 'stopped', 'Stopped by a test.' );
		};

		try {
			$this->layout_block()->sanitize_block_for_layout_update(
				$this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ),
				77,
				0
			);
			$this->fail( 'The pre-write hook must stop the write.' );
		} catch ( \SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( 'stopped', $e->get_error()->get_error_code() );
		}

		$this->assertCount( 1, $this->reports );
		$this->assertSame( 'w1', $this->reports[0]['widgets'][0]['widget_id'] );
	}

	public function test_an_ability_write_reports_what_the_settling_save_filters_removed_as_theirs() {
		$this->callbacks['content_save_pre'] = function ( $content ) {
			return str_replace( '<strong>k<\/strong>', 'k', $content );
		};

		$this->layout_block()->sanitize_block_for_layout_update(
			$this->layout_block_of( array( $this->widget( 'w1', '<iframe src=x></iframe><strong>k</strong>' ) ) ),
			77,
			0
		);

		$this->assertSame(
			array( 'page_builder', 'post_save_filters' ),
			array_column( $this->reports[0]['widgets'], 'source' )
		);
	}

	/**
	 * Run validate_post_data() the way wp_insert_post() does: $data has been
	 * through the post save filters, $unsanitized is what the caller passed.
	 */
	private function insert( $compat, array $submitted_blocks, $stored_content, $id = 0, $post_type = 'page' ) {
		return $compat->validate_post_data(
			array(
				'post_type'    => $post_type,
				'post_content' => $stored_content,
			),
			array( 'ID' => $id ),
			array( 'post_content' => json_encode( $submitted_blocks ) )
		);
	}

	public function test_a_no_user_insert_stripped_by_the_save_filters_reports_them_on_create_and_update() {
		$submitted = array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ), $this->widget( 'w2', 'Tom & Jerry' ) ) ) );
		// What core kses leaves: the iframe is gone, & is escaped.
		$filtered  = array( $this->layout_block_of( array( $this->widget( 'w1', '<p>a</p>' ), $this->widget( 'w2', 'Tom &amp; Jerry' ) ) ) );

		$this->insert( $this->layout_block(), $submitted, json_encode( $filtered ), 0 );
		$this->insert( $this->layout_block(), $submitted, json_encode( $filtered ), 55 );

		$expected = array( $this->finding( 0, 'w1', 'post_save_filters' ) );
		$this->assertSame(
			array(
				array( 'post_id' => 0, 'context' => 'wp_insert_post_data', 'widgets' => $expected ),
				array( 'post_id' => 55, 'context' => 'wp_insert_post_data', 'widgets' => $expected ),
			),
			$this->reports
		);
	}

	public function test_the_insert_pass_after_a_rest_save_does_not_report_again() {
		$compat = $this->layout_block();
		$saved  = $this->rest_save( $compat, array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ), $this->widget( 'w2', 'Tom & Jerry' ) ) ) ) );

		$escaped = str_replace( 'Tom & Jerry', 'Tom &amp; Jerry', $saved->post_content );
		$compat->validate_post_data(
			array( 'post_type' => 'page', 'post_content' => $escaped ),
			array( 'ID' => 42 ),
			array( 'post_content' => $saved->post_content )
		);

		$this->assertCount( 1, $this->reports );
		$this->assertSame( 'rest_pre_insert', $this->reports[0]['context'] );
	}

	public function test_a_rest_save_reports_twice_when_a_save_filter_removes_more_after_page_builder() {
		$compat = $this->layout_block();
		$saved  = $this->rest_save( $compat, array( $this->layout_block_of( array( $this->widget( 'w1', '<iframe src=x></iframe><strong>k</strong>' ) ) ) ) );

		// A content_save_pre filter at a later priority strips <strong>, which
		// Page Builder's kses kept.
		$compat->validate_post_data(
			array( 'post_type' => 'page', 'post_content' => str_replace( '<strong>k<\/strong>', 'k', $saved->post_content ) ),
			array( 'ID' => 42 ),
			array( 'post_content' => $saved->post_content )
		);

		$this->assertSame( array( 'rest_pre_insert', 'wp_insert_post_data' ), array_column( $this->reports, 'context' ) );
		$this->assertSame( 'page_builder', $this->reports[0]['widgets'][0]['source'] );
		$this->assertSame( 'post_save_filters', $this->reports[1]['widgets'][0]['source'] );
	}

	public function test_blocks_swapped_or_markup_moved_by_a_save_filter_do_not_report() {
		// A capable user, so Page Builder's own pass keeps the iframes and only
		// the save-filter comparison is under test.
		Functions\when( 'current_user_can' )->justReturn( true );
		$a =$this->layout_block_of( array( $this->widget( 'a', self::IFRAME ) ) );
		$b = $this->layout_block_of( array( $this->widget( 'b', 'plain' ) ) );
		$this->insert( $this->layout_block(), array( $a, $b ), json_encode( array( $b, $a ) ) );

		$moved = array( $this->layout_block_of( array( $this->widget( 'a', '' ), $this->widget( 'b', self::IFRAME ) ) ) );
		$this->insert( $this->layout_block(), array( $this->layout_block_of( array( $this->widget( 'a', self::IFRAME ), $this->widget( 'b', '' ) ) ) ), json_encode( $moved ) );

		$this->assertSame( array(), $this->reports );
	}

	public function test_reordered_blocks_with_a_removal_name_the_right_block() {
		$a = $this->layout_block_of( array( $this->widget( 'a', self::IFRAME ) ) );
		$b = $this->layout_block_of( array( $this->widget( 'b', 'plain' ) ) );
		$stripped_a = $this->layout_block_of( array( $this->widget( 'a', '<p>a</p>' ) ) );

		$this->insert( $this->layout_block(), array( $a, $b ), json_encode( array( $b, $stripped_a ) ) );

		$this->assertSame( array( $this->finding( 1, 'a', 'post_save_filters' ) ), $this->reports[0]['widgets'] );
	}

	public function test_a_removed_only_block_or_emptied_post_still_reports() {
		$submitted = array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ) );
		$paragraph = array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>x</p>', 'innerContent' => array() ) );

		$this->insert( $this->layout_block(), $submitted, json_encode( $paragraph ) );
		$this->insert( $this->layout_block(), $submitted, '' );

		$null = array( $this->finding( null, null, 'post_save_filters', null, null ) );
		$this->assertSame( array( $null, $null ), array_column( $this->reports, 'widgets' ) );
	}

	public function test_a_revision_never_reports() {
		$submitted = array( $this->layout_block_of( array( $this->widget( 'w1', self::IFRAME ) ) ) );

		$this->insert( $this->layout_block(), $submitted, '', 0, 'revision' );

		$this->assertSame( array(), $this->reports );
	}

	public function test_process_raw_widgets_calls_the_observer_once_per_widget_on_both_branches() {
		$seen = array();
		$admin = \SiteOrigin_Panels_Admin::single();
		$admin->observe_next_process_raw_widgets(
			function ( $key, $input, $output ) use ( &$seen ) {
				$seen[] = array( $key, $input['panels_info']['widget_id'], $output['panels_info']['widget_id'], $output['content'] );
			}
		);

		$admin->process_raw_widgets(
			array(
				3 => $this->widget( 'known', self::IFRAME ),
				7 => $this->widget( 'unknown', self::IFRAME, 'NoSuchWidget' ),
			),
			false,
			true
		);

		$this->assertSame(
			array(
				array( 3, 'known', 'known', '<p>a</p>' ),
				array( 7, 'unknown', 'unknown', '<p>a</p>' ),
			),
			$seen
		);

		// Taken once: the next call sees no observer.
		$admin->process_raw_widgets( array( $this->widget( 'again', 'x' ) ), false, true );
		$this->assertCount( 2, $seen );
	}

	public function test_a_legacy_widget_with_info_is_named_on_a_rest_save() {
		$legacy = array(
			'content' => self::IFRAME,
			'info'    => array( 'class' => 'MarkupRemovedKses', 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => 'legacy' ),
		);

		$this->rest_save( $this->layout_block(), array( $this->layout_block_of( array( $legacy ) ) ) );

		$this->assertSame( array( $this->finding( 0, 'legacy' ) ), $this->reports[0]['widgets'] );
	}

	public function test_the_observer_sees_a_legacy_widget_as_it_entered() {
		$inputs = array();
		$admin  = \SiteOrigin_Panels_Admin::single();
		$admin->observe_next_process_raw_widgets(
			function ( $key, $input, $output ) use ( &$inputs ) {
				$inputs[] = array( isset( $input['info']['widget_id'] ) ? $input['info']['widget_id'] : null, $output['panels_info']['widget_id'] );
			}
		);

		$admin->process_raw_widgets(
			array( array( 'content' => 'x', 'info' => array( 'class' => 'MarkupRemovedKses', 'widget_id' => 'legacy' ) ) ),
			false,
			true
		);

		$this->assertSame( array( array( 'legacy', 'legacy' ) ), $inputs );
	}

	public function test_a_nested_process_raw_widgets_call_never_sees_the_outer_observer() {
		$seen  = array();
		$outer = $this->widget( 'outer', '' , 'MarkupRemovedNested' );
		$outer['inner'] = array( $this->widget( 'outer', self::IFRAME ) );

		$admin = \SiteOrigin_Panels_Admin::single();
		$admin->observe_next_process_raw_widgets(
			function ( $key, $input, $output ) use ( &$seen ) {
				$seen[] = $key;
			}
		);
		$admin->process_raw_widgets( array( $outer ), false, true );

		$this->assertSame( array( 0 ), $seen );
	}

	public function test_the_observer_is_gone_after_a_widget_throws() {
		$calls = 0;
		$admin = \SiteOrigin_Panels_Admin::single();
		$admin->observe_next_process_raw_widgets(
			function () use ( &$calls ) {
				$calls++;
			}
		);

		try {
			$admin->process_raw_widgets( array( $this->widget( 'w1', 'x', 'MarkupRemovedThrowing' ) ), false, true );
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$admin->process_raw_widgets( array( $this->widget( 'w2', 'x' ) ), false, true );
		$this->assertSame( 0, $calls );
	}
}
