<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/*
 * The default bootstrap loads neither WP_Widget nor WP_Post. Minimal stand-ins let
 * the widget class load and let viewer_can_read() see real WP_Post instances.
 */
if ( ! class_exists( 'WP_Widget' ) ) {
	class WP_Widget {}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_status;
		public $post_parent = 0;
	}
}

require_once __DIR__ . '/../inc/widgets/post-loop.php';

/**
 * SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read() decides which loop rows
 * the current viewer sees: public statuses pass without a capability check,
 * everything else follows core's read_post.
 */
class PostLoopViewerCanReadTest extends SiteOriginTests {

	private function make_post( $id, $status, $parent = 0 ) {
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_status = $status;
		$post->post_parent = $parent;

		return $post;
	}

	private function mock_status( $status, $public ) {
		Functions\when( 'get_post_status' )->justReturn( $status );
		Functions\expect( 'get_post_status_object' )
			->once()
			->with( $status )
			->andReturn( (object) array( 'name' => $status, 'public' => $public ) );
	}

	public static function read_post_answers() {
		return array(
			'readable'   => array( true ),
			'unreadable' => array( false ),
		);
	}

	public function test_public_status_skips_the_capability_check() {
		$this->mock_status( 'publish', true );
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'current_user_can' )->never();

		$this->assertTrue( SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( $this->make_post( 7, 'publish' ) ) );
	}

	#[DataProvider( 'read_post_answers' )]
	public function test_non_public_status_returns_read_post( $answer ) {
		$this->mock_status( 'draft', false );
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'current_user_can' )->once()->with( 'read_post', 7 )->andReturn( $answer );

		$this->assertSame( $answer, SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( $this->make_post( 7, 'draft' ) ) );
	}

	#[DataProvider( 'read_post_answers' )]
	public function test_int_row_is_loaded_then_checked( $answer ) {
		Functions\expect( 'get_post' )->once()->with( 7 )->andReturn( $this->make_post( 7, 'private' ) );
		$this->mock_status( 'private', false );
		Functions\expect( 'current_user_can' )->once()->with( 'read_post', 7 )->andReturn( $answer );

		$this->assertSame( $answer, SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( 7 ) );
	}

	#[DataProvider( 'read_post_answers' )]
	public function test_partial_object_row_is_loaded_by_id( $answer ) {
		$row = (object) array( 'ID' => '7', 'post_parent' => 0 );
		Functions\expect( 'get_post' )->once()->with( 7 )->andReturn( $this->make_post( 7, 'pending' ) );
		$this->mock_status( 'pending', false );
		Functions\expect( 'current_user_can' )->once()->with( 'read_post', 7 )->andReturn( $answer );

		$this->assertSame( $answer, SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( $row ) );
	}

	public function test_partial_object_row_with_a_public_status_skips_the_capability_check() {
		Functions\expect( 'get_post' )->once()->with( 7 )->andReturn( $this->make_post( 7, 'publish' ) );
		$this->mock_status( 'publish', true );
		Functions\expect( 'current_user_can' )->never();

		$this->assertTrue( SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( (object) array( 'ID' => 7 ) ) );
	}

	public function test_unloadable_row_is_dropped() {
		Functions\expect( 'get_post' )->once()->with( 7 )->andReturn( null );
		Functions\expect( 'get_post_status' )->never();
		Functions\expect( 'current_user_can' )->never();

		$this->assertFalse( SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( 7 ) );
	}

	#[DataProvider( 'read_post_answers' )]
	public function test_attachment_status_comes_from_get_post_status( $answer ) {
		$attachment = $this->make_post( 9, 'inherit', 5 );
		// get_post_status() resolves an attachment's inherit status to its parent's.
		Functions\expect( 'get_post_status' )->once()->with( $attachment )->andReturn( 'private' );
		Functions\expect( 'get_post_status_object' )
			->once()
			->with( 'private' )
			->andReturn( (object) array( 'name' => 'private', 'public' => false ) );
		Functions\expect( 'current_user_can' )->once()->with( 'read_post', 9 )->andReturn( $answer );

		$this->assertSame( $answer, SiteOrigin_Panels_Widgets_PostLoop::viewer_can_read( $attachment ) );
	}
}
