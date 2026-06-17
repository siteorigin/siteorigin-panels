<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

/*
 * Minimal, test-local class shims. Brain Monkey mocks functions, not classes,
 * so the WordPress classes the production code touches are stubbed here only if
 * the real ones are not already loaded. Keep these minimal — just enough for
 * SiteOrigin_Panels_AI_Exposure to run under unit tests.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE = 'GET';
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_AI_Exposure' ) ) {
	require __DIR__ . '/../inc/ai-exposure.php';
}

/**
 * Request stub providing ArrayAccess for the `id` parameter, matching how the
 * production code reads `$request['id']`.
 */
class AiExposure_RequestStub implements ArrayAccess {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ): bool {
		return isset( $this->data[ $offset ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->data[ $offset ] ?? null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ): void {
		$this->data[ $offset ] = $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ): void {
		unset( $this->data[ $offset ] );
	}
}

/**
 * Unit tests for SiteOrigin_Panels_AI_Exposure.
 *
 * Asserts the committed, read-only REST response contract:
 *   { post_id, source: "meta"|"block"|"mixed"|"none",
 *     layouts: [ { storage:"meta"|"block", block_index:int|null, panels_data:{...} } ] }
 * (Phase 2c revised each `layouts` entry from a bare panels_data array to a
 * labelled object carrying storage + block_index.) Also asserts permission/404
 * behaviour. These mirror inc/ai-exposure.php exactly; the production code is
 * frozen and must not change to fit a test.
 */
class AiExposureRestTest extends SiteOriginTests {
	private function request( int $id ): AiExposure_RequestStub {
		return new AiExposure_RequestStub( array( 'id' => $id ) );
	}

	/**
	 * apply_filters( 'siteorigin_panels_data', $data, $id ) returns $data
	 * unchanged for these read-path tests.
	 */
	private function stub_panels_data_filter() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
	}

	/**
	 * rest_ensure_response() returns its argument so tests can inspect the
	 * raw response array.
	 */
	private function stub_rest_ensure_response() {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );
	}

	public function test_permission_denied_without_edit_post() {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 403 );

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout_permissions_check( $this->request( 5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_read_layout', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_granted_with_edit_post() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout_permissions_check( $this->request( 5 ) );

		$this->assertTrue( $result );
	}

	public function test_missing_post_returns_404() {
		Functions\when( 'get_post' )->justReturn( null );

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout( $this->request( 99 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_layout_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_source_meta() {
		$meta = array( 'grids' => array( array() ), 'widgets' => array() );

		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 5, 'post_content' => '' ) );
		Functions\when( 'get_post_meta' )->justReturn( $meta );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		$this->stub_panels_data_filter();
		$this->stub_rest_ensure_response();

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout( $this->request( 5 ) );

		$this->assertSame( 5, $result['post_id'] );
		$this->assertSame( 'meta', $result['source'] );
		$this->assertCount( 1, $result['layouts'] );
		$this->assertSame(
			array( 'storage' => 'meta', 'block_index' => null, 'panels_data' => $meta ),
			$result['layouts'][0]
		);
	}

	public function test_source_block() {
		$block_layout = array( 'grids' => array( array() ), 'widgets' => array( 'block-widget' ) );

		Functions\when( 'get_post' )->justReturn(
			(object) array( 'ID' => 7, 'post_content' => '<!-- wp:siteorigin-panels/layout-block --><!-- /wp:siteorigin-panels/layout-block -->' )
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => $block_layout ),
				),
			)
		);
		$this->stub_panels_data_filter();
		$this->stub_rest_ensure_response();

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout( $this->request( 7 ) );

		$this->assertSame( 7, $result['post_id'] );
		$this->assertSame( 'block', $result['source'] );
		$this->assertCount( 1, $result['layouts'] );
		$this->assertSame(
			array( 'storage' => 'block', 'block_index' => 0, 'panels_data' => $block_layout ),
			$result['layouts'][0]
		);
	}

	public function test_source_mixed() {
		$meta         = array( 'grids' => array( array() ), 'widgets' => array( 'meta-widget' ) );
		$block_layout = array( 'grids' => array( array() ), 'widgets' => array( 'block-widget' ) );

		Functions\when( 'get_post' )->justReturn(
			(object) array( 'ID' => 9, 'post_content' => '<!-- wp:siteorigin-panels/layout-block /-->' )
		);
		Functions\when( 'get_post_meta' )->justReturn( $meta );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => $block_layout ),
				),
			)
		);
		$this->stub_panels_data_filter();
		$this->stub_rest_ensure_response();

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout( $this->request( 9 ) );

		$this->assertSame( 'mixed', $result['source'] );
		$this->assertCount( 2, $result['layouts'] );
		// Code order: meta first (block_index null), then block (block_index 0).
		$this->assertSame(
			array( 'storage' => 'meta', 'block_index' => null, 'panels_data' => $meta ),
			$result['layouts'][0]
		);
		$this->assertSame(
			array( 'storage' => 'block', 'block_index' => 0, 'panels_data' => $block_layout ),
			$result['layouts'][1]
		);
	}

	public function test_source_none() {
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 11, 'post_content' => '' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'parse_blocks' )->justReturn( array() );
		$this->stub_panels_data_filter();
		$this->stub_rest_ensure_response();

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout( $this->request( 11 ) );

		$this->assertSame( 11, $result['post_id'] );
		$this->assertSame( 'none', $result['source'] );
		$this->assertSame( array(), $result['layouts'] );
	}

	public function test_multiple_layout_blocks_all_collected() {
		$layout_a = array( 'grids' => array(), 'widgets' => array( 'a' ) );
		$layout_b = array( 'grids' => array(), 'widgets' => array( 'b' ) );

		Functions\when( 'get_post' )->justReturn(
			(object) array( 'ID' => 13, 'post_content' => 'two blocks' )
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => $layout_a ),
				),
				array(
					'blockName' => 'core/paragraph',
					'attrs'     => array(),
				),
				array(
					'blockName' => 'siteorigin-panels/layout-block',
					'attrs'     => array( 'panelsData' => $layout_b ),
				),
			)
		);
		$this->stub_panels_data_filter();
		$this->stub_rest_ensure_response();

		$exposure = new SiteOrigin_Panels_AI_Exposure();
		$result   = $exposure->get_layout( $this->request( 13 ) );

		$this->assertSame( 'block', $result['source'] );
		$this->assertCount( 2, $result['layouts'] );
		// The non-layout core/paragraph between them must NOT advance block_index:
		// indices are the 0-based ordinal among QUALIFYING layout blocks only.
		$this->assertSame(
			array( 'storage' => 'block', 'block_index' => 0, 'panels_data' => $layout_a ),
			$result['layouts'][0]
		);
		$this->assertSame(
			array( 'storage' => 'block', 'block_index' => 1, 'panels_data' => $layout_b ),
			$result['layouts'][1]
		);
	}
}
