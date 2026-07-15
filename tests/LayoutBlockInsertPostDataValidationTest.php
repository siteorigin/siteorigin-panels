<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/*
 * Define the shared global `SiteOrigin_Panels` facade stub at FILE LOAD time.
 * PHPUnit loads every test file while building the suite, BEFORE any test
 * executes, whereas tests/LayoutBlockRenderTrustSignatureTest.php only evals
 * its (smaller) facade inside require_classes() at setUp() time. Defining the
 * superset here therefore wins deterministically in combined runs regardless
 * of execution order, and the class_exists guard keeps coexistence a no-op
 * when several files carrying this identical definition are loaded together.
 * The superset adds static renderer() support, required by tests that drive
 * the real save path through render_and_restore_post_globals().
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
 * Widget stub whose update() mutates content in a detectable, one-way manner
 * (appends '-SANITIZED') and counts invocations, so tests can prove whether
 * sanitization ran zero, one, or two times against the same data.
 */
class InsertPostDataMarkerWidgetStub {
	public $update_calls = 0;

	public function update( $new, $old ) {
		$this->update_calls++;
		$new['content'] = (string) $new['content'] . '-SANITIZED';

		return $new;
	}
}

/**
 * Renderer stub standing in for SiteOrigin_Panels::renderer()'s real renderer,
 * invoked by render_layout_block()'s save branch to produce contentPreview.
 */
class InsertPostDataRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * Regression tests for the wp_insert_post_data safety-net validation
 * (SiteOrigin_Panels_Compat_Layout_Block::validate_post_data()).
 *
 * Properties locked by this test:
 * (a) The filter is registered exactly once in __construct(), supplementing
 *     (not replacing) the rest_pre_insert_* hooks.
 * (b) post_type 'revision' rows (covers plain revisions AND autosaves) are
 *     returned unchanged with zero processing.
 * (c) An UNSIGNED Layout Block arriving via this hook is sanitized-and-signed.
 * (d) A Layout Block that ALREADY carries a valid signature passes through
 *     BYTE-IDENTICAL — sanitize/update() provably does NOT run a second time.
 *     This locks the double-sanitization-avoidance dedup this hook depends on.
 * (e) The hook's REAL slashed-input contract: content slashed with the
 *     genuine addslashes()-based wp_slash() semantics survives the round trip
 *     with panelsData intact and verifying — the case that catches the
 *     unslash/reslash bug found in plan review (case (d) alone would pass
 *     with that bug present).
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockInsertPostDataValidationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Spy counter: number of parse_blocks() invocations in the current test.
	 *
	 * @var int
	 */
	private $parse_blocks_calls = 0;

	/**
	 * Faithful reproduction of WP core wp_slash(): recursive addslashes() on
	 * string leaves. Public static so it is callable from the Brain Monkey
	 * stub container.
	 */
	public static function slash_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'slash_deep' ), $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	/**
	 * Faithful reproduction of WP core wp_unslash()/stripslashes_deep().
	 */
	public static function unslash_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'unslash_deep' ), $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->parse_blocks_calls = 0;

		// Minimal WP function stubs used by the code under test.
		Functions\when( '__' )->returnArg();

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $value );
			}
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );

		// REAL slashing semantics — NOT identity stubs. Case (e) depends on
		// these being the genuine addslashes()/stripslashes() behavior so the
		// handler's unslash-before-parse / reslash-after-serialize contract is
		// actually exercised, not bypassed.
		Functions\when( 'wp_slash' )->alias( array( __CLASS__, 'slash_deep' ) );
		Functions\when( 'wp_unslash' )->alias( array( __CLASS__, 'unslash_deep' ) );

		// render_layout_block() save-path plumbing.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );

		// parse_blocks()/serialize_blocks(): the same minimal scoped codec the
		// round-trip test established — single self-closing block, real
		// json_encode()/json_decode() attrs round trip. parse_blocks() also
		// counts invocations so the revision early-exit can be proven to skip
		// ALL processing.
		$test = $this;
		Functions\when( 'parse_blocks' )->alias(
			function ( $content ) use ( $test ) {
				$test->count_parse_blocks_call();
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
	}

	protected function tearDown(): void {
		\SiteOrigin_Panels::$instance_resolver = null;
		\SiteOrigin_Panels::$renderer = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function count_parse_blocks_call() {
		$this->parse_blocks_calls++;
	}

	/**
	 * Load inc/admin.php and compat/layout-block.php plus the stub
	 * collaborators they depend on, once per process. The SiteOrigin_Panels
	 * facade itself is defined at file-load time above.
	 */
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
			require_once dirname( __DIR__ ) . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once dirname( __DIR__ ) . '/compat/layout-block.php';
		}
	}

	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function construct_layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );
		$instance = $reflection->newInstanceWithoutConstructor();
		$reflection->getConstructor()->invoke( $instance );

		return $instance;
	}

	/**
	 * Invoke a private method on the Layout Block compat instance.
	 */
	private function invoke( $object, $method, array $args ) {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	/**
	 * Build an UNSLASHED single-Layout-Block content string via the codec.
	 */
	private function build_block_content( array $panels_data ) {
		return '<!-- wp:siteorigin-panels/layout-block '
			. json_encode( array( 'panelsData' => $panels_data, 'builder_id' => 'gbpost1' ) )
			. ' /-->';
	}

	private function marker_panels_data() {
		return array(
			'widgets' => array(
				array(
					'content'     => 'post-data-content',
					'panels_info' => array( 'class' => 'InsertPostDataMarkerWidget' ),
				),
			),
		);
	}

	// --- (a) Hook registration. ----------------------------------------------

	public function test_insert_post_data_filter_registered_exactly_once() {
		Functions\when( 'siteorigin_panels_setting' )->justReturn( array( 'post', 'page' ) );
		Filters\expectAdded( 'wp_insert_post_data' )
			->once()
			->with( Mockery::type( 'array' ), 10, 1 );

		$instance = $this->construct_layout_block();

		$this->assertNotFalse(
			has_filter( 'wp_insert_post_data', array( $instance, 'validate_post_data' ) ),
			'validate_post_data must be the registered callback.'
		);
	}

	// --- (b) Revision rows skip ALL processing. -------------------------------

	public function test_revision_post_type_returned_unchanged_with_no_processing() {
		$data = array(
			'post_type'    => 'revision',
			'post_content' => self::slash_deep( $this->build_block_content( $this->marker_panels_data() ) ),
		);

		$result = $this->layout_block()->validate_post_data( $data );

		$this->assertSame( $data, $result, 'Revision rows must pass through byte-identical.' );
		$this->assertSame(
			0,
			$this->parse_blocks_calls,
			'parse_blocks() (and therefore verify/sanitize) must never run for revisions.'
		);
	}

	// --- (c) Unsigned Layout Block gets sanitized-and-signed. -----------------

	public function test_unsigned_layout_block_is_sanitized_and_signed() {
		$stub = new InsertPostDataMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new InsertPostDataRendererStub();

		$data = array(
			'post_type'    => 'post',
			'post_content' => wp_slash( $this->build_block_content( $this->marker_panels_data() ) ),
		);

		$result = $this->layout_block()->validate_post_data( $data );

		$reparsed = parse_blocks( wp_unslash( $result['post_content'] ) );
		$this->assertCount( 1, $reparsed );
		$panels_data = $reparsed[0]['attrs']['panelsData'];

		$this->assertSame(
			'post-data-content-SANITIZED',
			$panels_data['widgets'][0]['content'],
			'The strict sanitize path must have run for unsigned content.'
		);
		$this->assertSame( 1, $stub->update_calls );
		$this->assertArrayHasKey( 'sanitize_signature', $panels_data );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $panels_data['sanitize_signature'] );
	}

	// --- (d) Validly-signed Layout Block passes through byte-identical. -------

	public function test_signed_layout_block_passes_through_byte_identical() {
		$stub = new InsertPostDataMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new InsertPostDataRendererStub();

		$block = $this->layout_block();

		// Produce validly-signed panelsData exactly as a real save would.
		$panels_data = $this->marker_panels_data();
		$panels_data['sanitize_signature'] = $this->invoke( $block, 'sign_panels_data', array( $panels_data ) );

		// Build the content through the SAME serialize codec the handler uses
		// so byte-identity is a meaningful assertion.
		$blocks = array(
			array(
				'blockName'    => 'siteorigin-panels/layout-block',
				'attrs'        => array( 'panelsData' => $panels_data, 'builder_id' => 'gbpost1' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
		);
		$content = serialize_blocks( $blocks );

		$data = array(
			'post_type'    => 'post',
			'post_content' => wp_slash( $content ),
		);

		$result = $this->layout_block()->validate_post_data( $data );

		$this->assertSame(
			$data['post_content'],
			$result['post_content'],
			'Already-signed content must pass through BYTE-IDENTICAL (verify-first dedup).'
		);
		$this->assertSame(
			0,
			$stub->update_calls,
			'sanitize_block()/update() must provably NOT run for already-verified content.'
		);
	}

	// --- (e) REAL slashed-input contract: no data loss through the hook. ------

	public function test_slashed_content_survives_round_trip_with_verifying_signature() {
		$stub = new InsertPostDataMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new InsertPostDataRendererStub();

		$block = $this->layout_block();

		// Genuine block-comment string with a realistic JSON payload, slashed
		// with the REAL addslashes()-based wp_slash() semantics — exactly the
		// shape wp_insert_post_data receives on every call path. Nothing is
		// stubbed past the slashing layer for this case: the handler must
		// unslash before parsing and re-slash after serializing, or the
		// panelsData JSON becomes undecodable and gets silently wiped.
		$raw_content = $this->build_block_content( $this->marker_panels_data() );
		$slashed_content = wp_slash( $raw_content );
		$this->assertNotSame( $raw_content, $slashed_content, 'Precondition: the payload must actually contain slashed characters.' );

		$data = array(
			'post_type'    => 'post',
			'post_content' => $slashed_content,
		);

		$result = $block->validate_post_data( $data );

		// The returned content is slashed (matching its $data siblings);
		// unslash it as wp_insert_post() itself will, then re-parse.
		$reparsed = parse_blocks( wp_unslash( $result['post_content'] ) );

		$this->assertCount( 1, $reparsed, 'The Layout Block must survive the hook.' );
		$this->assertIsArray( $reparsed[0]['attrs'], 'Block attrs must be decodable after the round trip.' );
		$this->assertArrayHasKey( 'panelsData', $reparsed[0]['attrs'], 'panelsData must NOT be silently deleted.' );
		$this->assertNotEmpty( $reparsed[0]['attrs']['panelsData']['widgets'], 'panelsData must survive non-empty.' );
		$this->assertTrue(
			$this->invoke( $block, 'verify_panels_data', array( $reparsed[0]['attrs']['panelsData'] ) ),
			'The re-parsed panelsData must carry a VALID signature — content survived the slashed-input contract.'
		);
	}
}
