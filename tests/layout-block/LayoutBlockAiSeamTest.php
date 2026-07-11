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
 * Widget stub whose update() passes content through UNCHANGED while counting
 * invocations. Sanitization is deliberately a no-op so these tests isolate the
 * kses floor: any content stripping observed after a save came from the floor,
 * never from the widget's own sanitizer.
 */
class AiSeamIdentityWidgetStub {
	public $update_calls = 0;

	public function update( $new, $old ) {
		$this->update_calls++;

		return $new;
	}
}

/**
 * Widget stub whose update() throws, for proving state restoration on the
 * exception path.
 */
class AiSeamThrowingWidgetStub {
	public function update( $new, $old ) {
		throw new \RuntimeException( 'widget update exploded' );
	}
}

/**
 * Renderer stub standing in for SiteOrigin_Panels::renderer()'s real renderer,
 * invoked by render_layout_block()'s save branch to produce contentPreview.
 */
class AiSeamRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * Regression tests for the relocated `siteorigin_panels_ai_block_layout_pre_save`
 * seam (SiteOrigin_Panels_Compat_Layout_Block::render_layout_block() save branch).
 *
 * Properties locked by this test:
 * (a) The filter fires exactly ONCE per block on the save path (sanitize_block()).
 * (b) The filter NEVER fires on the render path — neither the trusted
 *     (signature-verified) short-circuit nor the unsigned strict fallback.
 * (c) A layout CHANGED by the filter is kses-floored BEFORE signing even when
 *     the request's author has `unfiltered_html` — proven by the persisted
 *     signature verifying against the FLOORED payload.
 * (d) A no-op filter with a capable author is NOT floored (the raw-embed path
 *     preserved by the capability gate must not regress).
 * (e) A filter returning a non-array is ignored: the original layout proceeds,
 *     unfloored for a capable author, and still signs.
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockAiSeamTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Number of times the AI block pre-save filter tag was applied in the
	 * current test.
	 *
	 * @var int
	 */
	private $ai_filter_calls = 0;

	/**
	 * Optional callback standing in for a premium-addon consumer hooked to the
	 * AI block pre-save filter. Null means "no consumer hooked" — the tag
	 * passes its value through unchanged, exactly like real apply_filters()
	 * with no registered callbacks.
	 *
	 * @var callable|null
	 */
	private $ai_filter_callback = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->ai_filter_calls = 0;
		$this->ai_filter_callback = null;

		Functions\when( '__' )->returnArg();

		// apply_filters: pass every tag's value through unchanged EXCEPT the AI
		// block pre-save tag, which dispatches to the test's consumer callback
		// (when set) and counts every application. The
		// 'siteorigin_panels_sanitize_version' tag passes through its
		// 'panels:1' default — a stable version string for signing.
		$test = $this;
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) use ( $test ) {
				return $test->dispatch_filter( $tag, $value );
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
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );

		// render_layout_block() save-path plumbing.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );

		$this->require_classes();

		\SiteOrigin_Panels::$renderer = new AiSeamRendererStub();
	}

	protected function tearDown(): void {
		\SiteOrigin_Panels::$instance_resolver = null;
		\SiteOrigin_Panels::$renderer = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The apply_filters() stand-in. Public so the Brain Monkey alias closure
	 * can reach it.
	 */
	public function dispatch_filter( $tag, $value ) {
		if ( $tag === 'siteorigin_panels_ai_block_layout_pre_save' ) {
			$this->ai_filter_calls++;

			if ( $this->ai_filter_callback !== null ) {
				return call_user_func( $this->ai_filter_callback, $value );
			}
		}

		return $value;
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

	private function widget( $content ) {
		return array(
			'content'     => $content,
			'panels_info' => array( 'class' => 'AiSeamIdentityWidget' ),
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

	private const PAYLOAD = '<img src=x onerror=alert(1)>';
	private const CLEANED = '<img src=x>';

	// --- (a) Save path: fires exactly once per block. ------------------------

	public function test_filter_fires_exactly_once_on_save() {
		$stub = new AiSeamIdentityWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( false );

		$block = $this->block_for( array( 'widgets' => array( $this->widget( 'hello' ) ) ) );

		$result = $this->layout_block()->sanitize_block( $block );

		$this->assertSame(
			1,
			$this->ai_filter_calls,
			'The AI block pre-save filter must fire exactly once per block save.'
		);
		$this->assertNotEmpty(
			$result['attrs']['panelsData']['sanitize_signature'],
			'The save path must still sign the block.'
		);
	}

	// --- (b) Render paths: never fires. ---------------------------------------

	public function test_filter_does_not_fire_on_render_paths() {
		$stub = new AiSeamIdentityWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( false );

		$panels_data = array( 'widgets' => array( $this->widget( 'hello' ) ) );
		$block = $this->layout_block();

		// Trusted render: sign first (via the save path), reset the counter,
		// then render-prepare the signed payload.
		$saved = $block->sanitize_block( $this->block_for( $panels_data ) );
		$this->ai_filter_calls = 0;
		$this->invoke( $block, 'prepare_render_panels_data', array( $saved['attrs']['panelsData'] ) );
		$this->assertSame(
			0,
			$this->ai_filter_calls,
			'The AI filter must not fire on the trusted (signed) render path.'
		);

		// Unsigned strict fallback: render-prepare an unsigned payload.
		$this->invoke( $block, 'prepare_render_panels_data', array( $panels_data ) );
		$this->assertSame(
			0,
			$this->ai_filter_calls,
			'The AI filter must not fire on the unsigned strict-fallback render path.'
		);
	}

	// --- (c) Changed layout floored before signing, capability-independent. ---

	public function test_changed_layout_is_floored_before_signing_for_capable_user() {
		$stub = new AiSeamIdentityWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		// The admin-app-password scenario: the author's capability check
		// PASSES, so only the changed-layout floor can strip the payload.
		Functions\when( 'current_user_can' )->justReturn( true );

		$test = $this;
		$this->ai_filter_callback = function ( $panels_data ) use ( $test ) {
			// An AI consumer swaps in transformed content carrying a payload.
			$panels_data['widgets'][0]['content'] = LayoutBlockAiSeamTest::PAYLOAD_VALUE();

			return $panels_data;
		};

		$block = $this->layout_block();
		$result = $block->sanitize_block(
			$this->block_for( array( 'widgets' => array( $this->widget( 'original' ) ) ) )
		);
		$persisted = $result['attrs']['panelsData'];

		$this->assertSame(
			self::CLEANED,
			$persisted['widgets'][0]['content'],
			'An AI-changed layout must be kses-floored even when the author has unfiltered_html.'
		);
		$this->assertTrue(
			$this->invoke( $block, 'verify_panels_data', array( $persisted ) ),
			'The signature must verify against the FLOORED payload — proving the floor ran before signing.'
		);
	}

	// --- (d) No-op filter + capable author: not floored. ----------------------

	public function test_noop_filter_does_not_floor_capable_user() {
		$stub = new AiSeamIdentityWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( true );

		// No consumer hooked: apply_filters passes the layout through unchanged.
		$block = $this->layout_block();
		$result = $block->sanitize_block(
			$this->block_for( array( 'widgets' => array( $this->widget( self::PAYLOAD ) ) ) )
		);
		$persisted = $result['attrs']['panelsData'];

		$this->assertSame(
			1,
			$this->ai_filter_calls,
			'The filter tag still fires (pass-through) on every save.'
		);
		$this->assertSame(
			self::PAYLOAD,
			$persisted['widgets'][0]['content'],
			'With no AI change, a capable author keeps raw content — the #1341 raw-embed path must not regress.'
		);
		$this->assertTrue(
			$this->invoke( $block, 'verify_panels_data', array( $persisted ) ),
			'The unfloored payload is what gets signed for a capable author.'
		);
	}

	// --- (e) Non-array filter output is ignored. -------------------------------

	public function test_non_array_filter_output_is_ignored() {
		$stub = new AiSeamIdentityWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->ai_filter_callback = function ( $panels_data ) {
			return 'garbage-not-a-layout';
		};

		$block = $this->layout_block();
		$result = $block->sanitize_block(
			$this->block_for( array( 'widgets' => array( $this->widget( self::PAYLOAD ) ) ) )
		);
		$persisted = $result['attrs']['panelsData'];

		$this->assertSame(
			self::PAYLOAD,
			$persisted['widgets'][0]['content'],
			'A non-array filter return must be discarded: the original layout proceeds, unfloored for a capable author.'
		);
		$this->assertTrue(
			$this->invoke( $block, 'verify_panels_data', array( $persisted ) ),
			'The original layout must still be signed when garbage filter output is discarded.'
		);
		$this->assertSame( 1, $stub->update_calls, 'Sanitize still runs exactly once.' );
	}

	/**
	 * Closure-safe accessor for the PAYLOAD constant (PHP closures bound in
	 * properties cannot reference self::).
	 */
	public static function PAYLOAD_VALUE() {
		return self::PAYLOAD;
	}
}
