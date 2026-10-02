<?php

namespace SiteOrigin\Tests\MetaCap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stand-in for $wpdb that answers the one lookup the callback makes: the
 * stored keys of a post's meta rows that the database matches to a given key.
 *
 * A named class rather than an anonymous one so this file stays parseable by
 * the build toolchain's bundled php-parser.
 */
class MetaCapFakeWpdb {
	public $postmeta = 'wp_postmeta';

	public $last_error = '';

	/**
	 * The stored keys the database returns, by "post ID|key".
	 */
	public $rows = array();

	/**
	 * The "post ID|key" lookups that end in a database error.
	 */
	public $failing = array();

	/**
	 * Each lookup made, as array( post ID, key ).
	 */
	public $lookups = array();

	public function prepare( $query, ...$args ) {
		return array( $query, $args );
	}

	public function get_col( $prepared ) {
		list( , $args ) = $prepared;

		$this->lookups[] = $args;
		$lookup          = $args[0] . '|' . $args[1];

		if ( in_array( $lookup, $this->failing, true ) ) {
			$this->last_error = 'The query failed.';

			return array();
		}

		$this->last_error = '';

		return isset( $this->rows[ $lookup ] ) ? $this->rows[ $lookup ] : array();
	}
}

/**
 * The capability outcome of SiteOrigin_Panels::restrict_panels_data_meta_caps(),
 * the map_meta_cap callback for the panels_data post meta key.
 *
 * The add, edit and delete post meta capabilities on that key need
 * unfiltered_html. The same holds for a key that the metadata functions or the
 * database resolve to that key: a key that is panels_data once its slashes are
 * removed, and a key that the database matches to the post's panels_data row.
 * Every other capability and every other key passes through with the mapped
 * capabilities unchanged.
 *
 * This suite loads the REAL siteorigin-panels.php, so it runs on its own via
 * phpunit-meta-cap.xml: the other suites define a SiteOrigin_Panels stand-in.
 *
 * Build-toolchain note: no arrow functions or anonymous classes, because the
 * i18n .pot extraction's bundled php-parser cannot parse them.
 */
class MetaCapTest extends TestCase {
	use MockeryPHPUnitIntegration;

	const USER_ID     = 7;
	const POST_ID     = 42;
	const REVISION_ID = 99;

	const META_CAPS   = array( 'add_post_meta', 'edit_post_meta', 'delete_post_meta' );
	const MAPPED_CAPS = array( 'edit_others_posts', 'edit_published_posts' );
	const DENIED      = array( 'do_not_allow' );

	/**
	 * A plugin object of its own for each test, so nothing a test looked up is
	 * remembered by the next one.
	 */
	private $panels;

	private $wpdb;

	private $previous_wpdb;

	/**
	 * Whether the acting user has unfiltered_html.
	 */
	private $has_unfiltered_html = false;

	/**
	 * The posts that have a panels_data row.
	 */
	private $posts_with_layout = array();

	private $blog_id = 1;

	/**
	 * The arguments of each call to a WordPress function, by function name.
	 */
	private $calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->load_plugin();

		$class        = new \ReflectionClass( 'SiteOrigin_Panels' );
		$this->panels = $class->newInstanceWithoutConstructor();

		$this->has_unfiltered_html = false;
		$this->posts_with_layout   = array();
		$this->blog_id             = 1;
		$this->calls               = array(
			'user_can'        => array(),
			'metadata_exists' => array(),
		);

		$this->previous_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$this->wpdb          = new MetaCapFakeWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;

		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'get_current_blog_id' )->alias( array( $this, 'blog_id_stub' ) );
		Functions\when( 'user_can' )->alias( array( $this, 'user_can_stub' ) );
		Functions\when( 'metadata_exists' )->alias( array( $this, 'metadata_exists_stub' ) );
		Functions\when( 'wp_is_post_revision' )->alias( array( $this, 'is_post_revision_stub' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;

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

	public function blog_id_stub() {
		return $this->blog_id;
	}

	public function user_can_stub( $user_id, $cap ) {
		$this->calls['user_can'][] = array( $user_id, $cap );

		return $this->has_unfiltered_html;
	}

	public function metadata_exists_stub( $meta_type, $object_id, $meta_key ) {
		$this->calls['metadata_exists'][] = array( $meta_type, $object_id, $meta_key );

		return in_array( $object_id, $this->posts_with_layout, true );
	}

	/**
	 * REVISION_ID is a revision of POST_ID. No other post is a revision.
	 */
	public function is_post_revision_stub( $post_id ) {
		return $post_id === self::REVISION_ID ? self::POST_ID : false;
	}

	private function restrict( $cap, $args, $caps = self::MAPPED_CAPS ) {
		return $this->panels->restrict_panels_data_meta_caps( $caps, $cap, self::USER_ID, $args );
	}

	/**
	 * The database matches $key to these stored keys on the post.
	 */
	private function database_matches( $key, $stored_keys, $post_id = self::POST_ID ) {
		$this->wpdb->rows[ $post_id . '|' . $key ] = $stored_keys;
	}

	private function assert_capability_was_checked_once() {
		$this->assertSame( array( array( self::USER_ID, 'unfiltered_html' ) ), $this->calls['user_can'] );
	}

	private function assert_no_lookup_of_any_kind() {
		$this->assertSame( array(), $this->calls['user_can'] );
		$this->assertSame( array(), $this->calls['metadata_exists'] );
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	public static function meta_caps() {
		return array(
			'add'    => array( 'add_post_meta' ),
			'edit'   => array( 'edit_post_meta' ),
			'delete' => array( 'delete_post_meta' ),
		);
	}

	/*
	 * The key itself.
	 */

	#[DataProvider( 'meta_caps' )]
	public function test_user_without_unfiltered_html_is_not_allowed( $cap ) {
		$this->assertSame( self::DENIED, $this->restrict( $cap, array( self::POST_ID, 'panels_data' ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->calls['metadata_exists'] );
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	#[DataProvider( 'meta_caps' )]
	public function test_user_with_unfiltered_html_keeps_the_mapped_caps( $cap ) {
		$this->has_unfiltered_html = true;

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, 'panels_data' ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	/**
	 * A mapping that already denies stays a denial for a user with
	 * unfiltered_html: the callback never widens what was mapped.
	 */
	public function test_an_existing_denial_is_kept_for_a_user_with_unfiltered_html() {
		$this->has_unfiltered_html = true;

		$this->assertSame(
			self::DENIED,
			$this->restrict( 'edit_post_meta', array( self::POST_ID, 'panels_data' ), self::DENIED )
		);
	}

	/*
	 * Other capabilities, and arguments that hold no key.
	 */

	public static function cases_with_nothing_to_look_up() {
		$cases = array();

		foreach ( self::META_CAPS as $cap ) {
			$cases[ $cap . ' with no key' ]           = array( $cap, array( self::POST_ID ) );
			$cases[ $cap . ' with no arguments' ]     = array( $cap, array() );
			$cases[ $cap . ' with a non-string key' ] = array( $cap, array( self::POST_ID, 0 ) );
			$cases[ $cap . ' with a false key' ]      = array( $cap, array( self::POST_ID, false ) );
		}

		$cases['edit_post on the key']      = array( 'edit_post', array( self::POST_ID, 'panels_data' ) );
		$cases['edit_post with no key']     = array( 'edit_post', array( self::POST_ID ) );
		$cases['unfiltered_html']           = array( 'unfiltered_html', array() );
		$cases['edit_term_meta on the key'] = array( 'edit_term_meta', array( self::POST_ID, 'panels_data' ) );
		$cases['edit_user_meta on the key'] = array( 'edit_user_meta', array( self::POST_ID, 'panels_data' ) );

		return $cases;
	}

	#[DataProvider( 'cases_with_nothing_to_look_up' )]
	public function test_other_caps_and_missing_keys_pass_through_without_a_lookup( $cap, $args ) {
		// A post with a layout and a database that matches everything: neither may be consulted.
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'panels_data', array( 'panels_data' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, $args ) );

		$this->assert_no_lookup_of_any_kind();
	}

	/*
	 * Another key.
	 */

	public static function other_keys() {
		$cases = array();

		foreach ( self::META_CAPS as $cap ) {
			$cases[ $cap . ' on another key' ]         = array( $cap, '_thumbnail_id' );
			$cases[ $cap . ' on a longer key' ]        = array( $cap, 'panels_data_extra' );
			$cases[ $cap . ' on another letter case' ] = array( $cap, 'Panels_Data' );
		}

		return $cases;
	}

	#[DataProvider( 'other_keys' )]
	public function test_another_key_on_a_post_with_no_layout_passes_through_without_a_database_lookup( $cap, $key ) {
		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assertSame( array( array( 'post', self::POST_ID, 'panels_data' ) ), $this->calls['metadata_exists'] );
		$this->assertSame( array(), $this->calls['user_can'] );
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	#[DataProvider( 'meta_caps' )]
	public function test_a_key_the_database_does_not_match_passes_through( $cap ) {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'my_field', array( 'my_field' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, 'my_field' ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array( array( self::POST_ID, 'my_field' ) ), $this->wpdb->lookups );
	}

	public function test_a_key_with_no_rows_passes_through() {
		$this->posts_with_layout = array( self::POST_ID );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'add_post_meta', array( self::POST_ID, 'my_field' ) ) );
	}

	/*
	 * A key the database matches to the post's panels_data row.
	 */

	public static function matched_keys() {
		$keys = array(
			'another letter case' => 'Panels_Data',
			'upper case'          => 'PANELS_DATA',
			'a trailing space'    => 'panels_data ',
			'an accented letter'  => "p\u{00e1}nels_data",
			'a full-width letter' => "\u{ff50}anels_data",
		);

		$cases = array();
		foreach ( self::META_CAPS as $cap ) {
			foreach ( $keys as $label => $key ) {
				$cases[ $cap . ' with ' . $label ] = array( $cap, $key );
			}
		}

		return $cases;
	}

	#[DataProvider( 'matched_keys' )]
	public function test_a_key_the_database_matches_to_the_layout_row_is_not_allowed( $cap, $key ) {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( $key, array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array( array( self::POST_ID, $key ) ), $this->wpdb->lookups );
	}

	#[DataProvider( 'matched_keys' )]
	public function test_a_matched_key_keeps_the_mapped_caps_for_a_user_with_unfiltered_html( $cap, $key ) {
		$this->has_unfiltered_html = true;
		$this->posts_with_layout   = array( self::POST_ID );
		$this->database_matches( $key, array( 'panels_data' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	/**
	 * The stored key must be panels_data exactly. A row stored under another
	 * form of the key is not the row the renderer reads.
	 */
	public function test_a_match_to_a_row_stored_under_another_form_passes_through() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Panels_Data', array( 'Panels_Data' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );
	}

	public function test_a_match_to_several_rows_that_include_the_layout_row_is_not_allowed() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Panels_Data', array( 'Panels_Data', 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );
	}

	/**
	 * A match on another post says nothing about this one.
	 */
	public function test_a_match_on_another_post_passes_through() {
		$this->posts_with_layout = array( self::POST_ID, 43 );
		$this->database_matches( 'Panels_Data', array( 'panels_data' ), 43 );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );
		$this->assertSame( array( array( self::POST_ID, 'Panels_Data' ) ), $this->wpdb->lookups );
	}

	/**
	 * The post meta functions write to the parent of a revision, so the
	 * parent's rows decide.
	 */
	public function test_a_revision_is_resolved_to_its_parent() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Panels_Data', array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::REVISION_ID, 'Panels_Data' ) ) );

		$this->assertSame( array( array( 'post', self::POST_ID, 'panels_data' ) ), $this->calls['metadata_exists'] );
		$this->assertSame( array( array( self::POST_ID, 'Panels_Data' ) ), $this->wpdb->lookups );
	}

	public function test_a_post_id_given_as_a_string_is_used_as_an_integer() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Panels_Data', array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( (string) self::POST_ID, 'Panels_Data' ) ) );
	}

	/*
	 * A key with slashes. The metadata functions remove them before they write.
	 */

	#[DataProvider( 'meta_caps' )]
	public function test_a_key_that_is_the_layout_key_without_its_slashes_is_not_allowed( $cap ) {
		// No layout row yet: the write would create one.
		$this->assertSame( self::DENIED, $this->restrict( $cap, array( self::POST_ID, 'panels\\_data' ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->calls['metadata_exists'] );
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	public function test_a_slashed_layout_key_keeps_the_mapped_caps_for_a_user_with_unfiltered_html() {
		$this->has_unfiltered_html = true;

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'panels\\_data' ) ) );
	}

	public function test_a_slashed_key_is_looked_up_with_and_without_its_slashes() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Panels_Data', array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels\\_Data' ) ) );

		$this->assertSame(
			array(
				array( self::POST_ID, 'Panels\\_Data' ),
				array( self::POST_ID, 'Panels_Data' ),
			),
			$this->wpdb->lookups
		);
	}

	/*
	 * A lookup that fails, and what is remembered.
	 */

	public function test_a_failed_lookup_is_not_allowed_and_is_not_remembered() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->wpdb->failing     = array( self::POST_ID . '|my_field' );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'my_field' ) ) );

		$this->wpdb->failing = array();

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'my_field' ) ) );
		$this->assertCount( 2, $this->wpdb->lookups );
	}

	public static function remembered_answers() {
		return array(
			'a match'  => array( array( 'panels_data' ), self::DENIED ),
			'no match' => array( array( 'my_field' ), self::MAPPED_CAPS ),
		);
	}

	#[DataProvider( 'remembered_answers' )]
	public function test_the_database_is_asked_once_for_a_post_and_key( $stored_keys, $expected ) {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Some_Key', $stored_keys );

		foreach ( self::META_CAPS as $cap ) {
			$this->assertSame( $expected, $this->restrict( $cap, array( self::POST_ID, 'Some_Key' ) ) );
		}

		$this->assertSame( array( array( self::POST_ID, 'Some_Key' ) ), $this->wpdb->lookups );
	}

	/**
	 * A remembered match must not outlive the layout row: once the row is gone
	 * there is nothing for the key to resolve to.
	 */
	public function test_a_remembered_match_does_not_apply_once_the_layout_row_is_gone() {
		$this->posts_with_layout = array( self::POST_ID );
		$this->database_matches( 'Panels_Data', array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );

		$this->posts_with_layout = array();

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );
	}

	/**
	 * A post with no layout row is checked again each time: a layout row
	 * created later in the request must be seen.
	 */
	public function test_a_post_with_no_layout_row_is_checked_again_each_time() {
		$this->database_matches( 'Panels_Data', array( 'panels_data' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );

		$this->posts_with_layout = array( self::POST_ID );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );
	}

	public function test_an_answer_is_remembered_for_one_post_of_one_site_only() {
		$this->posts_with_layout = array( self::POST_ID, 43 );
		$this->database_matches( 'Panels_Data', array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );
		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( 43, 'Panels_Data' ) ) );

		// The same post ID on another site of a network is another post.
		$this->blog_id = 2;
		$this->database_matches( 'Panels_Data', array() );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'Panels_Data' ) ) );

		$this->assertSame(
			array(
				array( self::POST_ID, 'Panels_Data' ),
				array( 43, 'Panels_Data' ),
				array( self::POST_ID, 'Panels_Data' ),
			),
			$this->wpdb->lookups
		);
	}
}
