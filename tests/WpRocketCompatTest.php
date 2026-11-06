<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins which requests lose WP Rocket's widget purge.
 *
 * Page Builder requests that prepare a layout lose it, and every other request
 * keeps it. The front-end Live Editor preview waits for template_redirect, so a
 * save carrying the Live Editor query arg can't lose the purge.
 *
 * Build-toolchain note: this file avoids arrow functions and anonymous classes
 * because the i18n .pot extraction's bundled php-parser cannot parse them. The
 * `: void` return types on setUp()/tearDown() are required by PHPUnit 12.
 */
class WpRocketCompatTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const CALLBACK = 'rocket_widget_update_callback';
	const REMOVE = 'siteorigin_panels_wp_rocket_remove_widget_purge';

	private $server = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->server = array( $_GET, $_POST, $_REQUEST );
		$_GET = array();
		$_POST = array();
		$_REQUEST = array();

		if ( ! class_exists( 'SiteOrigin_Panels', false ) ) {
			eval(
				'class SiteOrigin_Panels {'
				. ' public static function is_live_editor() { return ! empty( $_GET["siteorigin_panels_live_editor"] ); }'
				. '}'
			);
		}

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		require_once dirname( __DIR__ ) . '/compat/wp-rocket.php';

		add_filter( 'widget_update_callback', self::CALLBACK );
	}

	protected function tearDown(): void {
		list( $_GET, $_POST, $_REQUEST ) = $this->server;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function run_compat( $ajax ) {
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		siteorigin_panels_wp_rocket_skip_preview_purge();
	}

	private function callback_attached() {
		return false !== has_filter( 'widget_update_callback', self::CALLBACK );
	}

	private function waits_for_template_redirect() {
		return false !== has_action( 'template_redirect', self::REMOVE );
	}

	public static function preview_actions() {
		return array(
			'builder content'      => array( 'so_panels_builder_content' ),
			'builder content json' => array( 'so_panels_builder_content_json' ),
			'get layout'           => array( 'so_panels_get_layout' ),
			'import layout'        => array( 'so_panels_import_layout' ),
			'layout block preview' => array( 'so_panels_layout_block_preview' ),
			'live editor preview'  => array( 'so_panels_live_editor_preview' ),
		);
	}

	#[DataProvider( 'preview_actions' )]
	public function test_preview_action_removes_the_callback( $action ) {
		$_REQUEST['action'] = $action;

		$this->run_compat( true );

		$this->assertFalse( $this->callback_attached() );
	}

	public static function other_actions() {
		return array(
			'widget save'     => array( 'save-widget' ),
			'customizer save' => array( 'customize_save' ),
			'heartbeat'       => array( 'heartbeat' ),
			'widget form'     => array( 'so_panels_widget_form' ),
			'near match'      => array( 'so_panels_builder_content_json_x' ),
			'array'           => array( array( 'so_panels_builder_content_json' ) ),
		);
	}

	#[DataProvider( 'other_actions' )]
	public function test_other_action_keeps_the_callback( $action ) {
		$_REQUEST['action'] = $action;

		$this->run_compat( true );

		$this->assertTrue( $this->callback_attached() );
		$this->assertFalse( $this->waits_for_template_redirect() );
	}

	public function test_ajax_request_without_an_action_keeps_the_callback() {
		$this->run_compat( true );

		$this->assertTrue( $this->callback_attached() );
	}

	public function test_preview_action_outside_ajax_keeps_the_callback() {
		$_REQUEST['action'] = 'so_panels_builder_content_json';

		$this->run_compat( false );

		$this->assertTrue( $this->callback_attached() );
		$this->assertFalse( $this->waits_for_template_redirect() );
	}

	public function test_front_end_preview_waits_for_template_redirect() {
		$_GET['siteorigin_panels_live_editor'] = 'true';
		$_POST['live_editor_panels_data'] = '{}';

		$this->run_compat( false );

		$this->assertTrue( $this->callback_attached() );
		$this->assertTrue( $this->waits_for_template_redirect() );

		siteorigin_panels_wp_rocket_remove_widget_purge();

		$this->assertFalse( $this->callback_attached() );
	}

	public function test_live_editor_arg_without_preview_data_keeps_the_callback() {
		$_GET['siteorigin_panels_live_editor'] = 'true';

		$this->run_compat( false );

		$this->assertTrue( $this->callback_attached() );
		$this->assertFalse( $this->waits_for_template_redirect() );
	}

	public function test_preview_data_without_the_live_editor_arg_keeps_the_callback() {
		$_POST['live_editor_panels_data'] = '{}';

		$this->run_compat( false );

		$this->assertTrue( $this->callback_attached() );
		$this->assertFalse( $this->waits_for_template_redirect() );
	}

	public function test_live_editor_arg_on_a_widget_save_keeps_the_callback() {
		$_GET['siteorigin_panels_live_editor'] = 'true';
		$_POST['live_editor_panels_data'] = '{}';
		$_REQUEST['action'] = 'save-widget';

		$this->run_compat( true );

		$this->assertTrue( $this->callback_attached() );
		$this->assertFalse( $this->waits_for_template_redirect() );
	}

	public function test_live_editor_arg_on_a_preview_action_removes_the_callback() {
		$_GET['siteorigin_panels_live_editor'] = 'true';
		$_REQUEST['action'] = 'so_panels_live_editor_preview';

		$this->run_compat( true );

		$this->assertFalse( $this->callback_attached() );
	}
}
