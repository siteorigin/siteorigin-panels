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
 * Widget stub whose update() is a genuine no-op — returns content byte-identical.
 * Models a capable author's own raw markup surviving their sanitize unchanged, so
 * the same-request memo records a hash of the RAW content. That is the setup that
 * lets a later untrusted write of identical content hit the memo (input hash ==
 * output hash) — the exact condition of the floor-bypass defect.
 */
class UntrustedWriteNoopWidgetStub {
	public function update( $new, $old ) {
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
		// handler attributes and <iframe> elements carrying an XSS/embed payload
		// (real wp_kses_post() removes both for a non-unfiltered_html context).
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				$value = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', (string) $value );
				$value = preg_replace( '#<iframe\b[^>]*/?>#i', '', (string) $value );
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
		//    net in the same wp_update_post() call. The same-request memo means
		//    the safety net recognizes this block as already sanitized earlier
		//    this request (by the chokepoint above) and skips the second pass —
		//    the marker stub makes the ABSENCE of a second update() observable.
		//    Reuse the SAME instance ($compat) both hooks run on in production
		//    (both register on the single() instance), so the request-local memo
		//    is shared across them.
		$validated = $compat->validate_post_data(
			array(
				'post_type'    => 'post',
				'post_content' => $slashed_content,
			)
		);
		$this->assertSame(
			1,
			$stub->update_calls,
			'update() runs exactly once per block across both save hooks in one request (same-request dedup).'
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

	/**
	 * Ordinary editor REST save (not the AI path): the block flows through
	 * server_side_validation() (rest_pre_insert_*) then validate_post_data()
	 * (wp_insert_post_data) in the same request, on the same instance. The
	 * same-request memo means update() runs exactly ONCE across both hooks.
	 */
	public function test_editor_rest_save_runs_update_once_across_both_hooks() {
		$stub = new UntrustedWriteMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		$compat = $this->layout_block();

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
				'builder_id' => 'gbeditor1',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		// Hook 1: rest_pre_insert_* — server_side_validation sanitizes the block.
		$prepared = new \stdClass();
		$prepared->post_content = serialize_blocks( array( $block ) );
		$prepared = $compat->server_side_validation( $prepared, null );

		$this->assertSame( 1, $stub->update_calls, 'First hook sanitizes once.' );

		// Hook 2: wp_insert_post_data — validate_post_data on the SAME instance,
		// receiving the slashed content the first hook produced. The memo skips
		// the second sanitize pass.
		$compat->validate_post_data(
			array(
				'post_type'    => 'post',
				'post_content' => wp_slash( $prepared->post_content ),
			)
		);

		$this->assertSame(
			1,
			$stub->update_calls,
			'update() runs exactly once per block across both REST save hooks in one request.'
		);
	}

	/**
	 * Safety-net integrity: a block that did NOT pass through
	 * server_side_validation this request (a direct wp_insert_post, an importer,
	 * cron — no rest_pre_insert_* hook) is still sanitized by validate_post_data.
	 * The memo must not create a hole in the safety net.
	 */
	public function test_direct_write_block_is_still_sanitized_by_safety_net() {
		$stub = new UntrustedWriteMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		$compat = $this->layout_block();

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
				'builder_id' => 'gbdirect1',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		// No server_side_validation() this request — memo is empty. The safety
		// net must sanitize, exactly once (not skip via a false hit).
		$validated = $compat->validate_post_data(
			array(
				'post_type'    => 'post',
				'post_content' => wp_slash( serialize_blocks( array( $block ) ) ),
			)
		);

		$this->assertSame(
			1,
			$stub->update_calls,
			'A direct-write block with no prior chokepoint pass is sanitized by the safety net.'
		);

		$persisted = parse_blocks( wp_unslash( $validated['post_content'] ) )[0]['attrs']['panelsData'];
		$this->assertStringEndsWith(
			'-SANITIZED',
			$persisted['widgets'][0]['content'],
			'The safety net actually ran the widget update() on the un-chokepointed block.'
		);
	}

	/**
	 * Build a Layout Block whose panelsData is well-formed but fails
	 * wp_json_encode() — a value nested past json's default depth of 512, the
	 * shape reachable via nested Layout widgets or an imported layout. The
	 * $marker distinguishes the two blocks so a memo false-hit on the shared
	 * empty-string digest is observable.
	 */
	private function unencodable_block( $marker ) {
		$deep = self::PAYLOAD;
		for ( $i = 0; $i < 600; $i++ ) {
			$deep = array( $deep );
		}

		return array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => array(
					'widgets' => array(
						array(
							'content'     => self::PAYLOAD,
							'marker'      => $marker,
							'too_deep'    => $deep,
							'panels_info' => array( 'class' => 'UntrustedWriteMarkerWidget' ),
						),
					),
				),
				'builder_id' => 'gbdeep' . $marker,
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * False-hit guard: two DIFFERENT Layout Blocks in one request whose
	 * panelsData both fail wp_json_encode() must NOT collide in the memo.
	 * hash( 'sha256', false ) is the digest of '' — so without the false-encode
	 * guard both blocks share one memo key and the second is wrongly skipped.
	 * The second block must still be sanitized (update() runs on it).
	 */
	public function test_two_unencodable_blocks_do_not_false_hit_the_memo() {
		$stub = new UntrustedWriteMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		$compat = $this->layout_block();

		// Sanity: these blocks genuinely fail to encode (else the test proves
		// nothing about the false-encode path).
		$this->assertFalse(
			wp_json_encode( $this->unencodable_block( 'a' )['attrs']['panelsData'] ),
			'Fixture precondition: panelsData must fail wp_json_encode() (depth > 512).'
		);

		// Both blocks sanitized in the same request on the same instance. Without
		// the guard, block B false-hits A's empty-string digest and is skipped,
		// leaving update_calls at 1.
		$compat->sanitize_block( $this->unencodable_block( 'a' ) );
		$compat->sanitize_block( $this->unencodable_block( 'b' ) );

		$this->assertSame(
			2,
			$stub->update_calls,
			'Two distinct unencodable blocks must each be sanitized — a false hit on the empty-string digest would skip the second.'
		);
	}

	// Content whose iframe survives a capable-author sanitize (no floor) but must
	// be stripped by the forced floor of an untrusted write.
	private const EMBED_PAYLOAD = '<iframe src="https://evil.example/x"></iframe><b>keep</b>';

	private function embed_block() {
		return array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => array(
					'widgets' => array(
						array(
							'content'     => self::EMBED_PAYLOAD,
							'panels_info' => array( 'class' => 'UntrustedWriteMarkerWidget' ),
						),
					),
				),
				'builder_id' => 'gbfloor1',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * SECURITY: a capable author's sanitize_block() must not seed the same-request
	 * memo in a way that lets a LATER untrusted write skip the forced kses floor.
	 * A capable author's sanitize is a no-op on their own raw markup, so the memo
	 * records a hash of that raw content; if the untrusted write of identical
	 * content then hits the memo and returns early, the floor never runs and the
	 * embed is stored — breaking the Audit #1 contract (AI content is floored
	 * regardless of the credential's unfiltered_html capability).
	 */
	public function test_capable_sanitize_does_not_let_a_later_untrusted_write_skip_the_floor() {
		$stub = new UntrustedWriteNoopWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		$compat = $this->layout_block();

		// 1. Capable author (current_user_can → true this suite) sanitizes their
		//    own raw markup. The iframe SURVIVES — correct, they hold the
		//    capability — and this seeds the memo with the raw content's hash.
		$capable = $compat->sanitize_block( $this->embed_block() );
		$this->assertStringContainsString(
			'<iframe',
			$capable['attrs']['panelsData']['widgets'][0]['content'],
			'A capable author keeps their own iframe (no floor) — this is what seeds the memo.'
		);

		// 2. An untrusted write of FRESH, identical content. The forced floor MUST
		//    strip the iframe regardless of the memo hit.
		$untrusted = $compat->sanitize_block_untrusted( $this->embed_block() );
		$content = $untrusted['attrs']['panelsData']['widgets'][0]['content'];

		$this->assertStringNotContainsString(
			'<iframe',
			$content,
			'The forced floor must strip the iframe on an untrusted write even when the same content was already sanitized by a capable author this request.'
		);
		$this->assertStringContainsString(
			'keep',
			$content,
			'The floor strips only the disallowed markup; safe content survives.'
		);
	}

	/**
	 * Normal path guard: an untrusted write as the FIRST operation (empty memo)
	 * floors as it always has. Pairs with the test above to document both
	 * orderings and to catch a fix that silently regresses the normal path.
	 */
	public function test_untrusted_write_still_floors_when_memo_is_empty() {
		$stub = new UntrustedWriteNoopWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};

		$compat = $this->layout_block();

		$untrusted = $compat->sanitize_block_untrusted( $this->embed_block() );
		$content = $untrusted['attrs']['panelsData']['widgets'][0]['content'];

		$this->assertStringNotContainsString(
			'<iframe',
			$content,
			'An untrusted write with an empty memo floors the iframe (the normal AI path).'
		);
		$this->assertStringContainsString( 'keep', $content, 'Safe content survives the floor.' );
	}
}
