<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
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
 * Regression tests for the reusable-block (wp_block) save-validation hook
 * registration in SiteOrigin_Panels_Compat_Layout_Block::__construct().
 *
 * Properties locked by this test:
 * (a) `rest_pre_insert_wp_block` is registered exactly once, UNCONDITIONALLY —
 *     independent of the configurable 'post-types' setting, which only
 *     controls editor availability, never the save-validation boundary.
 * (b) The wp_block registration uses the SAME `server_side_validation()`
 *     callback (same priority, same arg count) as the configurable
 *     `rest_pre_insert_post`/`rest_pre_insert_page` registrations — a new
 *     invocation surface for existing logic, not a parallel code path.
 *
 * NOTE: This test is intentionally self-contained, following the conventions
 * of tests/LayoutBlockRenderTrustSignatureTest.php: no shared base class,
 * phpunit.xml, or composer autoload exists on this branch. To keep the file
 * parseable by the build toolchain's bundled php-parser it avoids arrow
 * functions and anonymous classes; the `: void` return types on
 * setUp()/tearDown() are required by PHPUnit 12.
 */
class LayoutBlockWpBlockValidationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

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
			// sanitize_all() is out of scope here; identity passthrough.
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
	 * Build the Layout Block compat instance without letting `new` run the
	 * constructor implicitly, then invoke __construct() explicitly via
	 * Reflection so each test controls exactly the stub environment
	 * (siteorigin_panels_setting, Brain Monkey hook recording) it runs under.
	 */
	private function construct_layout_block() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );
		$instance = $reflection->newInstanceWithoutConstructor();
		$reflection->getConstructor()->invoke( $instance );

		return $instance;
	}

	// --- (a) wp_block registration is present and unconditional. -------------

	public function test_wp_block_hook_registered_with_default_post_types() {
		Functions\when( 'siteorigin_panels_setting' )->justReturn( array( 'post', 'page' ) );
		Actions\expectAdded( 'rest_pre_insert_wp_block' )
			->once()
			->with( Mockery::type( 'array' ), 10, 2 );

		$instance = $this->construct_layout_block();
		$callback = array( $instance, 'server_side_validation' );

		$this->assertNotFalse(
			has_action( 'rest_pre_insert_post', $callback ),
			'The default configurable post loop must still register rest_pre_insert_post.'
		);
		$this->assertNotFalse(
			has_action( 'rest_pre_insert_page', $callback ),
			'The default configurable post loop must still register rest_pre_insert_page.'
		);
		$this->assertNotFalse(
			has_action( 'rest_pre_insert_wp_block', $callback ),
			'wp_block saves must be validated via the same server_side_validation callback.'
		);
	}

	public function test_wp_block_hook_registered_independent_of_post_types_setting() {
		// Simulate a site configured for a custom post type only — no
		// post/page. The wp_block registration must be unaffected.
		Functions\when( 'siteorigin_panels_setting' )->justReturn( array( 'product' ) );

		$instance = $this->construct_layout_block();
		$callback = array( $instance, 'server_side_validation' );

		$this->assertNotFalse(
			has_action( 'rest_pre_insert_product', $callback ),
			'The configurable loop must follow the post-types setting.'
		);
		$this->assertFalse(
			has_action( 'rest_pre_insert_post', $callback ),
			'post must NOT be registered when absent from the post-types setting.'
		);
		$this->assertFalse(
			has_action( 'rest_pre_insert_page', $callback ),
			'page must NOT be registered when absent from the post-types setting.'
		);
		$this->assertNotFalse(
			has_action( 'rest_pre_insert_wp_block', $callback ),
			'wp_block registration must be unconditional — independent of the post-types setting.'
		);
	}

	// --- (b) Identical callback across every rest_pre_insert_* registration. -

	public function test_all_rest_pre_insert_hooks_share_the_same_callback() {
		Functions\when( 'siteorigin_panels_setting' )->justReturn( array( 'post', 'page' ) );

		$is_server_side_validation_callback = function ( $callback ) {
			return is_array( $callback )
				&& isset( $callback[0], $callback[1] )
				&& $callback[0] instanceof \SiteOrigin_Panels_Compat_Layout_Block
				&& $callback[1] === 'server_side_validation';
		};

		Actions\expectAdded( 'rest_pre_insert_post' )
			->once()
			->with( Mockery::on( $is_server_side_validation_callback ), 10, 2 );
		Actions\expectAdded( 'rest_pre_insert_page' )
			->once()
			->with( Mockery::on( $is_server_side_validation_callback ), 10, 2 );
		Actions\expectAdded( 'rest_pre_insert_wp_block' )
			->once()
			->with( Mockery::on( $is_server_side_validation_callback ), 10, 2 );

		$this->construct_layout_block();
	}
}
