<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/*
 * Define the shared global `SiteOrigin_Panels` facade stub at FILE LOAD time,
 * identical to the other files in this suite, so combined-suite runs get the
 * superset definition regardless of load order.
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

/**
 * Widget stub whose update() is a no-op that counts invocations, so a test can
 * prove the widget sanitizer ran exactly once across both save hooks: the
 * same-request memo only matches when the second hook's input equals the
 * first hook's output byte-for-byte.
 */
class AmpersandRepairCountingWidgetStub {
	public $update_calls = 0;

	public function update( $new, $old ) {
		$this->update_calls++;

		return $new;
	}
}

/**
 * Renderer stub standing in for SiteOrigin_Panels::renderer()'s real renderer,
 * invoked by render_layout_block()'s save branch to produce contentPreview.
 */
class AmpersandRepairRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * #1377, second mechanism: for a user without `unfiltered_html`, WordPress
 * core's own kses (content_save_pre → wp_pre_kses_block_attributes() for
 * posts; WP_Widget_Block::update() for block widget areas) rewrites every
 * bare `&` in every panelsData string to `&amp;` AFTER the REST-stage
 * sanitize and BEFORE the wp_insert_post_data safety net. The safety net then
 * re-runs the widget update() on `post_type=post&amp;tax_query=…` and the
 * taxonomy filter is lost.
 *
 * These tests pin the repair: in validate_post_data() and
 * validate_widget_block_option() only, only when the user lacks
 * `unfiltered_html`, tag-free widget strings get `&amp;` → `&` back before
 * sanitize_blocks() runs, so the memo still matches and update() runs once.
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockCoreKsesAmpersandRepairTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** Every string handed to the wp_kses_post() spy this test. */
	private $kses_post_calls = array();

	public static function slash_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'slash_deep' ), $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	public static function unslash_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'unslash_deep' ), $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	/**
	 * Emulate what wp_kses_normalize_entities() does to a bare `&`: it becomes
	 * `&amp;` unless it already starts an entity-shaped reference. Applied to
	 * every string leaf, which is what wp_pre_kses_block_attributes() →
	 * filter_block_kses_value() does to block attributes.
	 */
	public static function core_kses_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'core_kses_deep' ), $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $value );

		return preg_replace( '/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);)/', '&amp;', $value );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->kses_post_calls = array();

		Functions\when( '__' )->returnArg();

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		// Recording wp_kses_post() spy with the real-world `&` → `&amp;` rewrite
		// and <script> stripping, so any string that wrongly reaches it is
		// visible in the returned value as well as in the log.
		$calls = &$this->kses_post_calls;
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) use ( &$calls ) {
				$calls[] = $value;

				return self::core_kses_deep( (string) $value );
			}
		);

		Functions\when( 'wp_kses_no_null' )->alias(
			function ( $value, $options = null ) {
				return str_replace( "\0", '', (string) $value );
			}
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_slash' )->alias( array( __CLASS__, 'slash_deep' ) );
		Functions\when( 'wp_unslash' )->alias( array( __CLASS__, 'unslash_deep' ) );

		// Default: the author LACKS unfiltered_html. Capable-user cases override.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );

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

		Functions\when( 'serialize_blocks' )->alias(
			function ( $blocks ) {
				$serialized = array();

				foreach ( $blocks as $block ) {
					$serialized[] = '<!-- wp:' . $block['blockName'] . ' ' . wp_json_encode( $block['attrs'] ) . ' /-->';
				}

				return implode( "\n", $serialized );
			}
		);

		$this->require_classes();

		\SiteOrigin_Panels::$renderer = new AmpersandRepairRendererStub();
	}

	protected function tearDown(): void {
		\SiteOrigin_Panels::$instance_resolver = null;
		\SiteOrigin_Panels::$renderer = null;
		Monkey\tearDown();
		parent::tearDown();
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

		if ( ! class_exists( 'SiteOrigin_Panels_Admin', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/compat/layout-block.php';
		}
	}

	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function invoke( $object, $method, array $args ) {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	private function use_counting_widget() {
		$stub = new AmpersandRepairCountingWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		return $stub;
	}

	private const POSTS_QUERY = 'post_type=post&tax_query=category:jobs&orderby=date';
	private const POSTS_QUERY_KSESED = 'post_type=post&amp;tax_query=category:jobs&amp;orderby=date';
	private const SCRIPT_SIBLING = '<script>alert(1)</script><b>x</b>';

	private function layout_block_fixture( $posts, $builder_id ) {
		return array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => array(
					'widgets' => array(
						array(
							'posts'       => $posts,
							'title'       => 'Jobs & Careers',
							'text'        => self::SCRIPT_SIBLING,
							'panels_info' => array( 'class' => 'AmpersandRepairCountingWidget' ),
						),
					),
				),
				'builder_id' => $builder_id,
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	// --- AC8: the helper's per-string contract. -------------------------------

	public static function repair_cases() {
		return array(
			'posts query string'                 => array( 'post_type=post&amp;tax_query=x', 'post_type=post&tax_query=x' ),
			'entity-shaped after decode'         => array( 'a&amp;foo;b', 'a&foo;b' ),
			'double-escaped tags stay text'      => array( '&amp;lt;script&amp;gt;', '&lt;script&gt;' ),
			'markup with < is left alone'        => array( '<b>a&amp;b</b>', '<b>a&amp;b</b>' ),
			'no > character: decodes, still text' => array( 'x &amp;gt; y', 'x &gt; y' ),
			'a > character is left alone'        => array( 'x > y &amp; z', 'x > y &amp; z' ),
			'bare & is untouched'                => array( 'AT&T', 'AT&T' ),
			'no ampersand'                       => array( 'plain', 'plain' ),
			'empty string'                       => array( '', '' ),
		);
	}

	#[DataProvider( 'repair_cases' )]
	public function test_repair_helper_decodes_amp_only_in_tag_free_strings( $input, $expected ) {
		$panels_data = array( 'widgets' => array( array( 'field' => $input ) ) );

		$repaired = $this->invoke( $this->layout_block(), 'repair_core_kses_ampersands', array( $panels_data ) );

		$this->assertSame( $expected, $repaired['widgets'][0]['field'] );
	}

	public function test_repair_helper_leaves_non_strings_and_structure_alone() {
		$panels_data = array(
			'widgets'    => array(
				array(
					'int'         => 7,
					'float'       => 1.5,
					'true'        => true,
					'false'       => false,
					'null'        => null,
					'nested'      => array(
						'deep' => array( 'q' => 'a=1&amp;b=2', 'n' => 0 ),
					),
					'panels_info' => array( 'class' => 'X', 'id' => 3 ),
				),
			),
			'grids'      => array( array( 'cells' => 1 ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ),
		);

		$repaired = $this->invoke( $this->layout_block(), 'repair_core_kses_ampersands', array( $panels_data ) );

		$expected = $panels_data;
		$expected['widgets'][0]['nested']['deep']['q'] = 'a=1&b=2';
		$this->assertSame( $expected, $repaired, 'Only tag-free string leaves change; every other value and the array shape are preserved.' );

		$this->assertSame( 'nope', $this->invoke( $this->layout_block(), 'repair_core_kses_ampersands', array( 'nope' ) ) );
		$this->assertSame( array(), $this->invoke( $this->layout_block(), 'repair_core_kses_ampersands', array( array() ) ) );
	}

	public function test_repair_walks_nested_layout_blocks() {
		$inner = $this->layout_block_fixture( self::POSTS_QUERY_KSESED, 'gbinner' );
		$group = array(
			'blockName'    => 'core/group',
			'attrs'        => array( 'label' => 'a&amp;b' ),
			'innerBlocks'  => array( $inner ),
			'innerHTML'    => '',
			'innerContent' => array( null ),
		);

		$repaired = $this->invoke( $this->layout_block(), 'repair_core_kses_ampersands_in_blocks', array( $group ) );

		$this->assertSame( self::POSTS_QUERY, $repaired['innerBlocks'][0]['attrs']['panelsData']['widgets'][0]['posts'] );
		$this->assertSame( 'a&amp;b', $repaired['attrs']['label'], 'Only Layout Block panelsData is repaired; other blocks are not ours to touch.' );
	}

	// --- AC9: the post save path. ---------------------------------------------

	/**
	 * Editor REST save by a user WITHOUT unfiltered_html:
	 *   server_side_validation() (REST stage: strict sanitize + kses floor)
	 *   → core content_save_pre kses (emulated: every bare & → &amp;)
	 *   → validate_post_data() (wp_insert_post_data safety net).
	 */
	public function test_low_capability_post_save_keeps_ampersands_and_runs_update_once() {
		$stub = $this->use_counting_widget();
		$compat = $this->layout_block();

		// Hook 1: rest_pre_insert_*.
		$prepared = new \stdClass();
		$prepared->post_content = serialize_blocks( array( $this->layout_block_fixture( self::POSTS_QUERY, 'gb1377b' ) ) );
		$prepared = $compat->server_side_validation( $prepared, null );

		$this->assertSame( 1, $stub->update_calls, 'The REST stage sanitizes once.' );
		$this->assertContains( self::SCRIPT_SIBLING, $this->kses_post_calls, 'The markup-shaped sibling reaches the kses spy at the REST stage.' );

		$rest_stage = parse_blocks( $prepared->post_content )[0]['attrs']['panelsData'];
		$this->assertSame( self::POSTS_QUERY, $rest_stage['widgets'][0]['posts'], 'Precondition: the Panels floor leaves the query string alone (Steps 1–3).' );

		// Core kses: wp_filter_post_kses on content_save_pre → every block
		// attribute string is wp_kses()'d. The Panels floor already ran, so the
		// only change is the ampersand artifact.
		$blocks = parse_blocks( $prepared->post_content );
		$blocks[0]['attrs'] = self::core_kses_deep( $blocks[0]['attrs'] );
		$after_core = serialize_blocks( $blocks );
		$this->assertStringContainsString( '&amp;tax_query=', wp_json_encode( $blocks[0]['attrs']['panelsData'] ), 'Precondition: core kses emulation produced the artifact.' );

		// Hook 2: wp_insert_post_data, slashed, on the SAME instance.
		$validated = $compat->validate_post_data(
			array(
				'post_type'    => 'post',
				'post_content' => wp_slash( $after_core ),
			)
		);

		$stored = parse_blocks( wp_unslash( $validated['post_content'] ) )[0]['attrs']['panelsData'];
		$widget = $stored['widgets'][0];

		$this->assertSame( self::POSTS_QUERY, $widget['posts'], 'The stored posts query string has its & back.' );
		$this->assertSame( 'Jobs & Careers', $widget['title'] );
		$this->assertSame( '<b>x</b>', $widget['text'], 'The script sibling is stripped and, having a <, is left as core produced it.' );

		parse_str( $widget['posts'], $args );
		$this->assertSame( 'category:jobs', $args['tax_query'], 'The taxonomy filter parses out of the stored string.' );
		$this->assertArrayNotHasKey( 'amp;tax_query', $args );

		$this->assertSame(
			1,
			$stub->update_calls,
			'The repair runs before the memo check, so content that differs only by the core artifact hashes back to the REST output and update() is not re-run.'
		);
	}

	public function test_capable_user_post_save_is_not_repaired() {
		$stub = $this->use_counting_widget();
		Functions\when( 'current_user_can' )->justReturn( true );
		$compat = $this->layout_block();

		// A capable user's content arrives without core kses having run; an
		// &amp; in it is the author's own and must stay.
		$validated = $compat->validate_post_data(
			array(
				'post_type'    => 'post',
				'post_content' => wp_slash( serialize_blocks( array( $this->layout_block_fixture( self::POSTS_QUERY_KSESED, 'gbcap' ) ) ) ),
			)
		);

		$stored = parse_blocks( wp_unslash( $validated['post_content'] ) )[0]['attrs']['panelsData'];
		$this->assertSame( self::POSTS_QUERY_KSESED, $stored['widgets'][0]['posts'], 'No repair for a user with unfiltered_html.' );
		$this->assertSame( 1, $stub->update_calls, 'The safety net still sanitizes a direct write.' );
		$this->assertSame( array(), $this->kses_post_calls, 'No floor for a capable user.' );
	}

	// --- AC10: the block widget area path. ------------------------------------

	private function widget_block_option( $posts ) {
		$block = $this->layout_block_fixture( $posts, 'gbwidget1377' );

		return array(
			2              => array( 'content' => serialize_blocks( array( $block ) ) ),
			'_multiwidget' => 1,
		);
	}

	public function test_low_capability_widget_area_save_keeps_ampersands() {
		$stub = $this->use_counting_widget();

		// WP_Widget_Block::update() has already wp_kses_post()'d the content for
		// this user, so the option arrives carrying the artifact.
		$result = $this->layout_block()->validate_widget_block_option( $this->widget_block_option( self::POSTS_QUERY_KSESED ) );

		$stored = parse_blocks( $result[2]['content'] )[0]['attrs']['panelsData'];
		$this->assertSame( self::POSTS_QUERY, $stored['widgets'][0]['posts'] );
		$this->assertSame( 'Jobs & Careers', $stored['widgets'][0]['title'] );

		parse_str( $stored['widgets'][0]['posts'], $args );
		$this->assertSame( 'category:jobs', $args['tax_query'] );

		$this->assertSame( 1, $stub->update_calls );
		$this->assertContains( self::SCRIPT_SIBLING, $this->kses_post_calls, 'The floor still runs on the markup-shaped sibling.' );
		$this->assertSame( 1, $result['_multiwidget'] );
	}

	public function test_capable_user_widget_area_save_is_not_repaired() {
		$stub = $this->use_counting_widget();
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = $this->layout_block()->validate_widget_block_option( $this->widget_block_option( self::POSTS_QUERY_KSESED ) );

		$stored = parse_blocks( $result[2]['content'] )[0]['attrs']['panelsData'];
		$this->assertSame( self::POSTS_QUERY_KSESED, $stored['widgets'][0]['posts'], 'No repair for a user with unfiltered_html.' );
		$this->assertSame( 1, $stub->update_calls );
	}
}
