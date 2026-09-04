<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

/*
 * Test-local shims for the static collaborators copy_content_to_post() calls.
 * Brain Monkey mocks functions, not classes, so these stand in for the real
 * Page Builder classes only if not already loaded. Kept minimal.
 *
 * The renderer is spyable so tests can assert it is rendered with the right
 * layout id and that its output flows into post_content.
 */
class CopyContent_RendererSpy {
	public static $instance;
	public $render_args = array();
	public $css_args    = array();
	public $render_return = '<div class="rendered">HTML</div>';
	public $css_return    = '.panel { color: red; }';
	public $render_throws = null;

	public function render( $layout_id, $enqueue_css = false, $panels_data = false ) {
		$this->render_args = array( $layout_id, $enqueue_css, $panels_data );

		if ( $this->render_throws !== null ) {
			throw $this->render_throws;
		}

		return $this->render_return;
	}

	public function generate_css( $layout_id, $panels_data = false ) {
		$this->css_args = array( $layout_id, $panels_data );

		return $this->css_return;
	}
}

if ( ! class_exists( 'SiteOrigin_Panels' ) ) {
	class SiteOrigin_Panels {
		public static function renderer() {
			return CopyContent_RendererSpy::$instance;
		}

		public static function front_css_url() {
			return 'https://example.test/front.css';
		}
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Post_Content_Filters' ) ) {
	class SiteOrigin_Panels_Post_Content_Filters {
		public static $added   = 0;
		public static $removed = 0;

		public static function add_filters() {
			self::$added++;
		}

		public static function remove_filters() {
			self::$removed++;
		}
	}
}

// The real class is normally loaded by this suite's bootstrap (bootstrap-admin.php);
// this require is a fallback if run another way.
if ( ! class_exists( 'SiteOrigin_Panels_Admin' ) ) {
	require __DIR__ . '/../../inc/admin.php';
}

/**
 * Regression tests locking the behavior of the copy-content block extracted from
 * save_post() into SiteOrigin_Panels_Admin::copy_content_to_post(). These assert
 * the extraction is behavior-preserving (same render id, same persisted content,
 * copy-styles append, revision-parent id, the update-method filter, and the
 * direct_db path) BEFORE a second caller (the layout-update ability) is added.
 *
 * The class is instantiated WITHOUT its constructor (which wires WP admin hooks)
 * so we exercise copy_content_to_post() in isolation.
 *
 * Runs in its OWN suite (phpunit-copy-content.xml, bootstrap-admin.php loads the
 * real inc/admin.php first). The default suite's AbilitiesTest defines a minimal
 * SiteOrigin_Panels_Admin shim for its own spying, so the two suites are kept
 * separate to avoid a class-name clash.
 */
class CopyContentParityTest extends SiteOriginTests {
	private function admin(): SiteOrigin_Panels_Admin {
		$ref = new ReflectionClass( SiteOrigin_Panels_Admin::class );

		return $ref->newInstanceWithoutConstructor();
	}

	protected function setUp(): void {
		parent::setUp();

		CopyContent_RendererSpy::$instance = new CopyContent_RendererSpy();
		SiteOrigin_Panels_Post_Content_Filters::$added   = 0;
		SiteOrigin_Panels_Post_Content_Filters::$removed = 0;

		// Defaults the method touches. Individual tests override as needed.
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'esc_url' )->returnArg();
		// Default copy-content ON, copy-styles OFF, update method default.
		Functions\when( 'siteorigin_panels_setting' )->alias(
			fn( $key ) => $key === 'copy-content' ? true : false
		);
		Functions\when( 'apply_filters' )->alias(
			fn( $tag, $value ) => $value
		);
	}

	private function post( int $id = 10 ): object {
		return (object) array( 'ID' => $id, 'post_content' => 'OLD CONTENT' );
	}

	// --- copy-content OFF => no-op -------------------------------------------

	public function test_no_op_when_copy_content_setting_off() {
		Functions\when( 'siteorigin_panels_setting' )->justReturn( false );
		Functions\expect( 'wp_update_post' )->never();

		$post = $this->post();
		$this->admin()->copy_content_to_post( $post, 10, array( 'widgets' => array() ) );

		$this->assertSame( 'OLD CONTENT', $post->post_content, 'post_content must be untouched when copy-content is off.' );
		$this->assertSame( array(), CopyContent_RendererSpy::$instance->render_args, 'renderer must not run when copy-content is off.' );
	}

	// --- default path: renders canonical data, persists via wp_update_post ----

	public function test_renders_canonical_data_and_persists_via_wp_update_post() {
		$captured = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$captured ) {
				$captured = $args;

				return $args['ID'];
			}
		);

		$panels_data = array( 'widgets' => array( 'w' ), 'grids' => array( 'g' ) );
		$post = $this->post( 10 );

		$this->admin()->copy_content_to_post( $post, 10, $panels_data );

		// Rendered from the canonical $panels_data, with the post's own id (no revision).
		$this->assertSame( array( 10, false, $panels_data ), CopyContent_RendererSpy::$instance->render_args );

		// post_content becomes the rendered HTML, and that exact content is persisted.
		$this->assertSame( '<div class="rendered">HTML</div>', $post->post_content );
		$this->assertSame( array( 'ID' => 10, 'post_content' => '<div class="rendered">HTML</div>' ), $captured );

		// Render filters added and removed (balanced).
		$this->assertSame( 1, SiteOrigin_Panels_Post_Content_Filters::$added );
		$this->assertSame( 1, SiteOrigin_Panels_Post_Content_Filters::$removed );
	}

	// --- copy-styles append ---------------------------------------------------

	public function test_copy_styles_appends_style_block() {
		Functions\when( 'siteorigin_panels_setting' )->alias(
			fn( $key ) => in_array( $key, array( 'copy-content', 'copy-styles' ), true )
		);
		$captured = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$captured ) {
				$captured = $args;

				return $args['ID'];
			}
		);

		$post = $this->post( 10 );
		$this->admin()->copy_content_to_post( $post, 10, array( 'widgets' => array( 'w' ) ) );

		$this->assertStringContainsString( '<div class="rendered">HTML</div>', $captured['post_content'] );
		$this->assertStringContainsString( '<style type="text/css" class="panels-style" data-panels-style-for-post="10">', $captured['post_content'] );
		$this->assertStringContainsString( '.panel { color: red; }', $captured['post_content'] );
		$this->assertStringContainsString( '@import url(https://example.test/front.css);', $captured['post_content'] );
	}

	// --- revisions: render with PARENT id, update the original post -----------

	public function test_revision_renders_with_parent_id_but_updates_original() {
		Functions\when( 'wp_is_post_revision' )->justReturn( 42 ); // parent id
		$captured = null;
		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$captured ) {
				$captured = $args;

				return $args['ID'];
			}
		);

		$post = $this->post( 99 ); // the (revision) post passed in
		$this->admin()->copy_content_to_post( $post, 99, array( 'widgets' => array( 'w' ) ) );

		// Rendered against the PARENT layout id 42.
		$this->assertSame( 42, CopyContent_RendererSpy::$instance->render_args[0] );
		$this->assertSame( 42, CopyContent_RendererSpy::$instance->css_args[0] );
		// But the post object that gets persisted is the one passed in (ID 99).
		$this->assertSame( 99, $captured['ID'] );
	}

	// --- update-method filter receives mutated post/id/panels_data ------------

	public function test_update_method_filter_receives_context() {
		$filter_args = null;
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, ...$rest ) use ( &$filter_args ) {
				if ( $tag === 'siteorigin_panels_copy_content_update_method' ) {
					$filter_args = array( $value, $rest );
				}

				return $value;
			}
		);
		Functions\when( 'wp_update_post' )->justReturn( 10 );

		$panels_data = array( 'widgets' => array( 'w' ) );
		$post = $this->post( 10 );
		$this->admin()->copy_content_to_post( $post, 10, $panels_data );

		$this->assertNotNull( $filter_args, 'copy-content update-method filter must run.' );
		$this->assertSame( 'wp_update_post', $filter_args[0] );
		// rest = [ $post, $post_id, $panels_data ]
		$this->assertSame( 10, $filter_args[1][1] );
		$this->assertSame( $panels_data, $filter_args[1][2] );
		// post passed to the filter carries the rendered content.
		$this->assertSame( '<div class="rendered">HTML</div>', $filter_args[1][0]->post_content );
	}

	// --- direct_db method bypasses wp_update_post -----------------------------

	public function test_direct_db_method_uses_direct_update_not_wp_update_post() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $tag === 'siteorigin_panels_copy_content_update_method' ? 'direct_db' : $value;
			}
		);
		// wp_update_post must NOT be called on the direct_db path.
		Functions\expect( 'wp_update_post' )->never();

		// Stub the global $wpdb the direct-db helper uses.
		global $wpdb;
		$wpdb = new class {
			public $posts = 'wp_posts';
			public $updated_with = null;
			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->updated_with = array( $table, $data, $where );

				return 1;
			}
		};
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'clean_post_cache' )->justReturn( null );

		$post = $this->post( 10 );
		$this->admin()->copy_content_to_post( $post, 10, array( 'widgets' => array( 'w' ) ) );

		$this->assertNotNull( $wpdb->updated_with, 'direct_db path must write via $wpdb->update().' );
		$this->assertSame( '<div class="rendered">HTML</div>', $wpdb->updated_with[1]['post_content'] );
	}

	// --- render filters and globals are cleaned up when a widget throws -------

	public function test_render_exception_still_removes_filters_and_render_global() {
		CopyContent_RendererSpy::$instance->render_throws = new RuntimeException( 'widget exploded' );
		Functions\expect( 'wp_update_post' )->never();

		$post = $this->post( 10 );
		$thrown = null;

		try {
			$this->admin()->copy_content_to_post( $post, 10, array( 'widgets' => array( 'w' ) ) );
		} catch ( RuntimeException $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'The render exception must propagate, not be swallowed.' );
		$this->assertSame( 1, SiteOrigin_Panels_Post_Content_Filters::$added );
		$this->assertSame(
			1,
			SiteOrigin_Panels_Post_Content_Filters::$removed,
			'remove_filters() must run even when the render throws, so the shortcode registry and content filters cannot leak into the rest of the request.'
		);
		$this->assertArrayNotHasKey(
			'SITEORIGIN_PANELS_POST_CONTENT_RENDER',
			$GLOBALS,
			'The content render global must not leak when the render throws.'
		);
	}
}
