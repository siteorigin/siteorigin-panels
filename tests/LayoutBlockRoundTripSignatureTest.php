<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
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
 * (appends '-SANITIZED') and counts invocations, so the test can prove whether
 * sanitization ran zero, one, or two times against the same data.
 */
class RoundTripMarkerWidgetStub {
	public $update_calls = 0;

	public function update( $new, $old ) {
		$this->update_calls++;
		$new['content'] = (string) $new['content'] . '-SANITIZED';

		return $new;
	}
}

/**
 * Renderer stub standing in for SiteOrigin_Panels::renderer()'s real renderer,
 * which render_layout_block() invokes (via render_and_restore_post_globals())
 * on the save path to produce the contentPreview attribute. Its output content
 * is irrelevant to the signature round trip under test.
 */
class RoundTripRendererStub {
	public $render_calls = 0;

	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		$this->render_calls++;

		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * Integration test: the trust signature must survive a full
 * parse_blocks() -> save-time sanitize/sign (real sanitize_blocks() path) ->
 * serialize_blocks() -> parse_blocks() -> verify round trip, i.e. the
 * wp_json_encode()/json_decode() comment-delimiter boundary that stored
 * Layout Block content actually crosses between save and render. This is the
 * canonicalization-drift gap flagged in PR #1341's review: the existing
 * LayoutBlockRenderTrustSignatureTest signs and verifies hand-built PHP
 * arrays directly and never proves the signature survives storage encoding.
 *
 * parse_blocks()/serialize_blocks() are reproduced as minimal Brain Monkey
 * stubs faithful to WP core's comment-delimiter format for the single-block,
 * no-inner-blocks shape this test needs (verified against wp-includes/blocks.php
 * during planning), keeping the suite's zero-WP-install invariant while still
 * exercising the REAL wp_json_encode()/json_decode() round trip — the actual
 * drift mechanism. See "Test design decision" in the plan.
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockRoundTripSignatureTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Minimal WP function stubs used by the code under test.
		Functions\when( '__' )->returnArg();

		// apply_filters: return the value being filtered unchanged. Covers
		// 'siteorigin_panels_widget_class', 'widget_update_callback', and
		// 'siteorigin_panels_sanitize_version' (passes through 'panels:1').
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		// wp_kses_post(): emulate the relevant behaviour — strip on* event
		// handler attributes. The save-time kses_deep() floor runs over the
		// marker content and must leave it untouched.
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $value );
			}
		);

		// wp_json_encode(): PHP's native json_encode is a faithful stand-in.
		// This is the REAL encode half of the round trip under test.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// wp_salt(): fixed test salt. hash_hmac()/hash_equals() are pure PHP
		// core functions and are deliberately NOT stubbed.
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );

		// render_layout_block() save-path plumbing.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );

		// parse_blocks(): minimal, faithful codec for the single top-level
		// self-closing block shape this test produces —
		// `<!-- wp:name {json-attrs} /-->` — matching the array shape
		// WP_Block_Parser_Block produces and sanitize_blocks()/
		// sanitize_block() consume. Intentionally NOT a general parser.
		// The json-attrs portion is first reversed through the same
		// \uXXXX unescaping serialize_block_attributes() (WP core,
		// wp-includes/blocks.php) applies on encode, so this stub's codec
		// round-trips the same '<', '&', '--' characters real stored
		// Layout Block content does. json_decode() handles \uXXXX escapes
		// natively regardless of who produced them, so no unescape step is
		// actually required here for correctness -- this pairs with the
		// matching strtr() escape step in the serialize_blocks() stub below
		// purely so the intermediate serialized string this test asserts on
		// looks like real WP core output, not because decoding needs it.
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

		// serialize_blocks(): the inverse of the parse_blocks() stub above —
		// re-encodes attrs through the REAL wp_json_encode()/json_encode()
		// (the decode half happens in parse_blocks() via json_decode()), so
		// the pair forms a genuine scoped codec, not a hardcoded round trip.
		// The strtr() step reproduces serialize_block_attributes()'s (WP
		// core, wp-includes/blocks.php) unicode escaping of '\\', '--',
		// '<', '>', '&', and '\"' to '\uXXXX' sequences, so this test's
		// round trip actually exercises that encoding layer instead of
		// relying on plain json_encode()/json_decode() alone.
		Functions\when( 'serialize_blocks' )->alias(
			function ( $blocks ) {
				$serialized = array();

				foreach ( $blocks as $block ) {
					$encoded_attributes = wp_json_encode( $block['attrs'] );
					$escaped_attributes = strtr(
						$encoded_attributes,
						array(
							'\\\\' => '\\u005c',
							'--'   => '\\u002d\\u002d',
							'<'    => '\\u003c',
							'>'    => '\\u003e',
							'&'    => '\\u0026',
							'\\"'  => '\\u0022',
						)
					);

					$serialized[] = '<!-- wp:' . $block['blockName'] . ' ' . $escaped_attributes . ' /-->';
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

	/**
	 * Load inc/admin.php and compat/layout-block.php plus the stub
	 * collaborators they depend on, once per process. The SiteOrigin_Panels
	 * facade itself is defined at file-load time above.
	 */
	private function require_classes() {
		// SiteOrigin_Panels_Admin::single() runs the real constructor, which
		// instantiates these collaborator singletons and includes the
		// installer when SiteOrigin_Installer is absent. Stub them so the
		// constructor can run without pulling in the whole admin stack.
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
			// Presence alone stops the admin constructor including the real
			// installer bootstrap file.
			eval( 'class SiteOrigin_Installer {}' );
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Styles_Admin', false ) ) {
			// sanitize_all() is out of scope here; identity passthrough lets
			// the test observe the widget-level round trip directly.
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

		// Stubs for the WP base bits the classes reference at include time.
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

	/**
	 * Build the Layout Block compat instance WITHOUT running its real
	 * constructor (which registers WP hooks out of scope for a unit test).
	 */
	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Invoke a private method on the Layout Block compat instance.
	 */
	private function invoke( $object, $method, array $args ) {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	public function test_signature_survives_serialize_parse_round_trip() {
		$stub = new RoundTripMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new RoundTripRendererStub();

		$block = $this->layout_block();

		// 1. One widget instance shaped like WP_Widget_Custom_HTML, class
		//    resolvable via the instance_resolver wiring above.
		$panels_data = array(
			'widgets' => array(
				array(
					// Includes '<', '&', and '--' so the round trip
					// exercises serialize_block_attributes()'s (WP core,
					// wp-includes/blocks.php) '\uXXXX' unicode escaping of
					// those characters, not just plain alphanumeric content.
					'content'     => 'round-trip <content> & more -- extra',
					'panels_info' => array( 'class' => 'RoundTripMarkerWidget' ),
				),
			),
		);

		// 2. Full block-comment string, exactly as the block editor would
		//    submit it in post_content. builder_id included so the save path
		//    reuses it deterministically.
		$comment_string = '<!-- wp:siteorigin-panels/layout-block '
			. json_encode( array( 'panelsData' => $panels_data, 'builder_id' => 'gbtest1' ) )
			. ' /-->';

		// 3. Parse, as server_side_validation() does with $prepared_post->post_content.
		$blocks = parse_blocks( $comment_string );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'siteorigin-panels/layout-block', $blocks[0]['blockName'] );

		// 4. Drive the REAL save path — mirroring server_side_validation()'s
		//    own `foreach ( $blocks as &$block ) { $block = $this->sanitize_blocks( $block, true ); }`
		//    loop body: sanitize_blocks() -> sanitize_block() ->
		//    render_layout_block() with $return_layout = false -> strict
		//    sanitize -> kses_deep() floor -> sign_panels_data().
		$blocks[0] = $block->sanitize_blocks( $blocks[0] );

		$saved_panels_data = $blocks[0]['attrs']['panelsData'];
		$saved_content = $saved_panels_data['widgets'][0]['content'];

		$this->assertSame(
			'round-trip <content> & more -- extra-SANITIZED',
			$saved_content,
			'The save path must have run the widget update() sanitizer.'
		);
		$this->assertSame( 1, $stub->update_calls, 'update() runs exactly once during save.' );
		$this->assertArrayHasKey( 'sanitize_signature', $saved_panels_data );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			$saved_panels_data['sanitize_signature'],
			'The save path must have produced an HMAC-SHA256 hex signature.'
		);

		// 5. Serialize back to post_content, as server_side_validation()'s
		//    `$prepared_post->post_content = serialize_blocks( $blocks );` does.
		$serialized = serialize_blocks( $blocks );

		// 6. Re-parse, simulating a later page load reading post_content out
		//    of storage for rendering.
		$reparsed = parse_blocks( $serialized );
		$this->assertCount( 1, $reparsed );
		$reparsed_panels_data = $reparsed[0]['attrs']['panelsData'];

		// 8. (Asserted before 7 so a verification failure is reported
		//    distinctly from its downstream effect.) The signature must
		//    survive the full encode/decode round trip.
		$this->assertTrue(
			$this->invoke( $block, 'verify_panels_data', array( $reparsed_panels_data ) ),
			'The signature must verify against the re-parsed panels data.'
		);

		// 7. Prepare for render, exactly as render_layout_block()'s render
		//    branch does with $attributes['panelsData'].
		$prepared = $this->invoke( $block, 'prepare_render_panels_data', array( $reparsed_panels_data ) );

		// 9. The trusted path must have been taken: no re-sanitization.
		$this->assertSame(
			1,
			$stub->update_calls,
			'update() must NOT run again when rendering round-tripped signed content.'
		);

		// 10. No silent content mutation anywhere in the
		//     parse -> sanitize -> sign -> serialize -> parse -> verify chain.
		$this->assertSame(
			$saved_content,
			$prepared['widgets'][0]['content'],
			'Widget content must be byte-identical to the save-time sanitized value.'
		);
	}
}
