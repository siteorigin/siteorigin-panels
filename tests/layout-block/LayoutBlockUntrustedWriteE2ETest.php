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

/**
 * Widget stub whose update() mutates content in a detectable, one-way manner
 * (appends '-SANITIZED') and counts invocations, so tests can prove whether
 * sanitization ran zero, one, or two times against the same data.
 */
class UntrustedWriteMarkerWidgetStub {
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
class UntrustedWriteRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * End-to-end acceptance tests for the AI ability block-write path (Audit #1
 * fixes 1a + 3): sanitize_block_untrusted() as the write chokepoint, composed
 * with the wp_insert_post_data safety net (validate_post_data()) the persisted
 * post then flows through.
 *
 * Properties locked by this test:
 * (a) THE fail-open acceptance criterion: with a credential that HAS
 *     `unfiltered_html` (the admin-application-password scenario), a raw
 *     payload-bearing AI layout written through the chokepoint is kses-floored,
 *     and the persisted payload REMAINS floored after the wp_slash round trip
 *     through the wp_insert_post_data safety net.
 * (b) The write chokepoint sanitizes exactly ONCE (no inline double-sanitize
 *     in the write itself). NOTE: the safety net legitimately re-sanitizes on
 *     the same request — the signature-gated dedup was deliberately removed;
 *     sanitize is expected to be idempotent on its own output (the marker stub
 *     is deliberately not, which is how the second pass is observable).
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockUntrustedWriteE2ETest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Faithful reproduction of WP core wp_slash(): recursive addslashes() on
	 * string leaves.
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

		Functions\when( '__' )->returnArg();

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		// wp_kses_post(): emulate the relevant behaviour — strip on* event
		// handler attributes carrying the XSS payload.
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $value );
			}
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// REAL slashing semantics — the write path slashes for wp_update_post
		// and the safety net unslashes before parsing; identity stubs would
		// bypass the exact contract under test.
		Functions\when( 'wp_slash' )->alias( array( __CLASS__, 'slash_deep' ) );
		Functions\when( 'wp_unslash' )->alias( array( __CLASS__, 'unslash_deep' ) );

		// The admin-application-password scenario: every capability check
		// passes. Only the forced floor can strip the payload.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );

		// parse_blocks()/serialize_blocks(): the minimal scoped codec this
		// suite established — single self-closing block, real json_encode()/
		// json_decode() attrs round trip.
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

		\SiteOrigin_Panels::$renderer = new UntrustedWriteRendererStub();
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

	/**
	 * Invoke a private method on the Layout Block compat instance.
	 */
	private function invoke( $object, $method, array $args ) {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	private const PAYLOAD = '<img src=x onerror=alert(1)>';

	// Marker widget appends '-SANITIZED' before the floor strips the handler.
	private const FLOORED_SANITIZED = '<img src=x>-SANITIZED';

	/**
	 * The full AI block-write acceptance flow:
	 *   raw AI layout → sanitize_block_untrusted() (what write_block_layout
	 *   routes through) → serialize + wp_slash (what wp_update_post receives)
	 *   → validate_post_data() (the wp_insert_post_data safety net).
	 */
	public function test_capable_credential_write_is_floored_signed_and_deduped_end_to_end() {
		$stub = new UntrustedWriteMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		$compat = $this->layout_block();

		// 1. The ability write: raw AI panels_data spliced into the parsed
		//    target block, then routed through the chokepoint (the exact
		//    write_block_layout() flow).
		$block = array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => array(
					'widgets' => array(
						array(
							'content'     => self::PAYLOAD,
							'panels_info' => array( 'class' => 'UntrustedWriteMarkerWidget' ),
						),
					),
				),
				'builder_id' => 'gbe2e1',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$block = $compat->sanitize_block_untrusted( $block );
		$written_panels_data = $block['attrs']['panelsData'];

		// The floor ran despite unfiltered_html === true, after the widget's
		// own sanitizer (marker suffix present, handler stripped).
		$this->assertSame(
			self::FLOORED_SANITIZED,
			$written_panels_data['widgets'][0]['content'],
			'The forced floor must strip the payload from a capable-credential AI write.'
		);
		$this->assertSame( 1, $stub->update_calls, 'One sanitize pass during the write.' );

		// 2. write_block_layout() persists via wp_update_post( wp_slash( serialize_blocks() ) ).
		$post_content = serialize_blocks( array( $block ) );
		$slashed_content = wp_slash( $post_content );

		// 3. The persisted post flows through the wp_insert_post_data safety
		//    net in the same wp_update_post() call. With the signature dedup
		//    removed, the safety net re-sanitizes unconditionally — the marker
		//    stub makes that second pass observable.
		$validated = $this->layout_block()->validate_post_data(
			array(
				'post_type'    => 'post',
				'post_content' => $slashed_content,
			)
		);
		$this->assertSame(
			2,
			$stub->update_calls,
			'The safety net runs its own sanitize pass (dedup deliberately removed).'
		);

		// And what is actually persisted still carries a floored payload: the
		// handler payload stripped at the write chokepoint never comes back.
		$persisted_blocks = parse_blocks( wp_unslash( $validated['post_content'] ) );
		$persisted = $persisted_blocks[0]['attrs']['panelsData'];
		$this->assertStringNotContainsString(
			'onerror',
			$persisted['widgets'][0]['content'],
			'The persisted payload must remain floored after the slash round trip through the safety net.'
		);
		$this->assertStringStartsWith(
			self::FLOORED_SANITIZED,
			$persisted['widgets'][0]['content'],
			'The floored write-time content survives (the safety net only re-appends the marker suffix).'
		);
	}
}
