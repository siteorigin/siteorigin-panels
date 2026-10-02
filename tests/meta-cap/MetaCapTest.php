<?php

namespace SiteOrigin\Tests\MetaCap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The capability outcome of SiteOrigin_Panels::restrict_panels_data_meta_caps(),
 * the map_meta_cap callback for the panels_data post meta key.
 *
 * The add, edit and delete post meta capabilities on that key need
 * unfiltered_html. Every other capability and every other key passes through
 * with the mapped capabilities unchanged, and without a capability lookup.
 *
 * This suite loads the REAL siteorigin-panels.php, so it runs on its own via
 * phpunit-meta-cap.xml: the other suites define a SiteOrigin_Panels stand-in.
 *
 * Build-toolchain note: no arrow functions or anonymous classes, because the
 * i18n .pot extraction's bundled php-parser cannot parse them.
 */
class MetaCapTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const USER_ID = 7;
	const POST_ID = 42;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->load_plugin();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The main plugin file builds the plugin object as it loads, so the
	 * WordPress functions its constructor calls are supplied first. The stored
	 * settings keep that constructor on its shortest path: a fixed renderer and
	 * no bundled widgets.
	 */
	private function load_plugin() {
		if ( class_exists( 'SiteOrigin_Panels', false ) ) {
			return;
		}

		Functions\when( 'plugin_dir_path' )->alias(
			function ( $file ) {
				return dirname( $file ) . '/';
			}
		);
		Functions\when( 'register_activation_hook' )->justReturn( null );
		Functions\when( 'add_shortcode' )->justReturn( null );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_theme_support' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( $option === 'siteorigin_panels_settings' ) {
					return array(
						'legacy-layout'   => 'never',
						'bundled-widgets' => false,
					);
				}

				return $default;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults = array() ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		require_once dirname( __DIR__, 2 ) . '/siteorigin-panels.php';
	}

	private function restrict( $caps, $cap, $args ) {
		return \SiteOrigin_Panels::single()->restrict_panels_data_meta_caps( $caps, $cap, self::USER_ID, $args );
	}

	public static function meta_caps() {
		return array(
			'add'    => array( 'add_post_meta' ),
			'edit'   => array( 'edit_post_meta' ),
			'delete' => array( 'delete_post_meta' ),
		);
	}

	#[DataProvider( 'meta_caps' )]
	public function test_user_without_unfiltered_html_is_not_allowed( $cap ) {
		Functions\expect( 'user_can' )
			->once()
			->with( self::USER_ID, 'unfiltered_html' )
			->andReturn( false );

		$this->assertSame(
			array( 'do_not_allow' ),
			$this->restrict( array( 'edit_posts' ), $cap, array( self::POST_ID, 'panels_data' ) )
		);
	}

	#[DataProvider( 'meta_caps' )]
	public function test_user_with_unfiltered_html_keeps_the_mapped_caps( $cap ) {
		Functions\expect( 'user_can' )
			->once()
			->with( self::USER_ID, 'unfiltered_html' )
			->andReturn( true );

		$caps = array( 'edit_others_posts', 'edit_published_posts' );

		$this->assertSame(
			$caps,
			$this->restrict( $caps, $cap, array( self::POST_ID, 'panels_data' ) )
		);
	}

	public static function pass_through_cases() {
		$cases = array();

		foreach ( array( 'add_post_meta', 'edit_post_meta', 'delete_post_meta' ) as $cap ) {
			$cases[ $cap . ' on another key' ]        = array( $cap, array( self::POST_ID, '_thumbnail_id' ) );
			$cases[ $cap . ' on a longer key' ]       = array( $cap, array( self::POST_ID, 'panels_data_extra' ) );
			$cases[ $cap . ' with no key' ]           = array( $cap, array( self::POST_ID ) );
			$cases[ $cap . ' with no arguments' ]     = array( $cap, array() );
			$cases[ $cap . ' with a non-string key' ] = array( $cap, array( self::POST_ID, 0 ) );
		}

		$cases['edit_post on the key']      = array( 'edit_post', array( self::POST_ID, 'panels_data' ) );
		$cases['edit_post with no key']     = array( 'edit_post', array( self::POST_ID ) );
		$cases['unfiltered_html']           = array( 'unfiltered_html', array() );
		$cases['edit_term_meta on the key'] = array( 'edit_term_meta', array( self::POST_ID, 'panels_data' ) );
		$cases['edit_user_meta on the key'] = array( 'edit_user_meta', array( self::POST_ID, 'panels_data' ) );

		return $cases;
	}

	#[DataProvider( 'pass_through_cases' )]
	public function test_other_caps_and_keys_pass_through_without_a_capability_lookup( $cap, $args ) {
		Functions\expect( 'user_can' )->never();

		$caps = array( 'edit_others_posts', 'edit_published_posts' );

		$this->assertSame( $caps, $this->restrict( $caps, $cap, $args ) );
	}

	/**
	 * A mapping that already denies stays a denial for a user with
	 * unfiltered_html: the callback never widens what was mapped.
	 */
	public function test_an_existing_denial_is_kept_for_a_user_with_unfiltered_html() {
		Functions\when( 'user_can' )->justReturn( true );

		$this->assertSame(
			array( 'do_not_allow' ),
			$this->restrict( array( 'do_not_allow' ), 'edit_post_meta', array( self::POST_ID, 'panels_data' ) )
		);
	}
}
