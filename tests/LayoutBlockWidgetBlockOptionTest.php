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
 * sanitization ran against the widget-area content.
 */
class WidgetBlockOptionMarkerWidgetStub {
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
 * on the save path to produce the contentPreview attribute.
 */
class WidgetBlockOptionRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div class="so-panels-rendered">rendered</div>';
	}
}

/**
 * Regression tests for the `widget_block` option save-validation hook
 * (SiteOrigin_Panels_Compat_Layout_Block::validate_widget_block_option()).
 *
 * Block-based widget areas store Block-widget content in the widget_block
 * OPTION (via WP_Widget::save_settings()), not via wp_insert_post()/REST-post
 * hooks. This hook sanitizes-and-signs any embedded Layout Block on that
 * surface so it renders via the trusted path (fixing the blank-embed
 * regression there) and gets the save-time kses floor for admins lacking
 * unfiltered_html (e.g. multisite). NOTE: this is a render-consistency fix,
 * not a Contributor-XSS fix — editing widget areas requires
 * edit_theme_options (admin-only by default).
 *
 * NOTE: Self-contained per this suite's conventions; avoids arrow functions
 * and anonymous classes (build-toolchain parser compatibility); `: void`
 * return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockWidgetBlockOptionTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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

		// render_layout_block() save-path plumbing.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );

		// parse_blocks()/serialize_blocks(): the same minimal scoped codec the
		// round-trip test established — single self-closing block, real
		// json_encode()/json_decode() attrs round trip.
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
			require_once dirname( __DIR__ ) . '/inc/admin.php';
		}

		if ( ! class_exists( 'SiteOrigin_Panels_Compat_Layout_Block', false ) ) {
			require_once dirname( __DIR__ ) . '/compat/layout-block.php';
		}
	}

	/**
	 * Build the Layout Block compat instance WITHOUT running its real
	 * constructor.
	 */
	private function layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Build the instance and invoke __construct() explicitly via Reflection so
	 * the test controls the stub environment the constructor runs under.
	 */
	private function construct_layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );
		$instance = $reflection->newInstanceWithoutConstructor();
		$reflection->getConstructor()->invoke( $instance );

		return $instance;
	}

	// --- (a) Hook registration. ----------------------------------------------

	public function test_widget_block_option_filter_registered_exactly_once() {
		Functions\when( 'siteorigin_panels_setting' )->justReturn( array( 'post', 'page' ) );
		Filters\expectAdded( 'pre_update_option_widget_block' )
			->once()
			->with( Mockery::type( 'array' ), 10, 1 );

		$instance = $this->construct_layout_block();

		$this->assertNotFalse(
			has_filter( 'pre_update_option_widget_block', array( $instance, 'validate_widget_block_option' ) ),
			'validate_widget_block_option must be the registered callback.'
		);
	}

	// --- (b) Unsigned Layout Block in a widget instance gets sanitized+signed.

	public function test_unsigned_layout_block_in_widget_content_is_sanitized_and_signed() {
		$stub = new WidgetBlockOptionMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new WidgetBlockOptionRendererStub();

		$panels_data = array(
			'widgets' => array(
				array(
					'content'     => 'widget-area-content',
					'panels_info' => array( 'class' => 'WidgetAreaMarkerWidget' ),
				),
			),
		);

		$value = array(
			2              => array(
				'content' => '<!-- wp:siteorigin-panels/layout-block '
					. json_encode( array( 'panelsData' => $panels_data, 'builder_id' => 'gbwidget1' ) )
					. ' /-->',
			),
			'_multiwidget' => 1,
		);

		// validate_widget_block_option() is the registered callback itself
		// (public), so it is invoked directly — equivalent to the
		// ReflectionMethod pattern used for private methods elsewhere.
		$result = $this->layout_block()->validate_widget_block_option( $value );

		$reparsed = parse_blocks( $result[2]['content'] );
		$this->assertCount( 1, $reparsed );
		$reparsed_panels_data = $reparsed[0]['attrs']['panelsData'];

		$this->assertSame(
			'widget-area-content-SANITIZED',
			$reparsed_panels_data['widgets'][0]['content'],
			'The widget update() sanitizer must have run against the embedded Layout Block.'
		);
		$this->assertSame( 1, $stub->update_calls );
		$this->assertArrayHasKey(
			'sanitize_signature',
			$reparsed_panels_data,
			'Sanitize-then-sign must have run: the signature was NOT present in the input.'
		);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			$reparsed_panels_data['sanitize_signature']
		);
	}

	// --- (c) Non-instance keys and non-Block-shaped instances pass through. --

	public function test_multiwidget_flag_and_non_block_instances_untouched() {
		$stub = new WidgetBlockOptionMarkerWidgetStub();
		\SiteOrigin_Panels::$instance_resolver = function () use ( $stub ) {
			return $stub;
		};
		\SiteOrigin_Panels::$renderer = new WidgetBlockOptionRendererStub();

		$value = array(
			2              => array( 'title' => 'no content key here' ),
			3              => 'not-an-array-instance',
			4              => array( 'content' => '<p>plain html, no blocks</p>' ),
			'_multiwidget' => 1,
		);

		$result = $this->layout_block()->validate_widget_block_option( $value );

		$this->assertSame( $value, $result, 'Instances with nothing to sanitize must pass through untouched.' );
		$this->assertSame( 0, $stub->update_calls, 'No sanitization may run when no Layout Block is present.' );
	}

	// --- (d) Empty/non-array option values are returned unchanged. -----------

	public function test_empty_or_non_array_value_returned_unchanged() {
		$block = $this->layout_block();

		$this->assertFalse( $block->validate_widget_block_option( false ) );
		$this->assertSame( array(), $block->validate_widget_block_option( array() ) );
		$this->assertSame( 'nonsense', $block->validate_widget_block_option( 'nonsense' ) );
	}
}
