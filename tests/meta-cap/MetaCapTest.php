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
 * unfiltered_html. The same holds for a key that resolves to that key: a key
 * that is panels_data once its slashes are removed or once sanitize_key() has
 * run on it, and a key that the database matches to the post's panels_data
 * row. Every other capability and every other key passes through with the
 * mapped capabilities unchanged.
 *
 * The database alone decides a match. metadata_exists() is stubbed to report
 * no panels_data value in every test, as a metadata filter or a meta cache
 * read before the row existed would, and must never be consulted.
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
		$this->calls               = array(
			'user_can'        => array(),
			'metadata_exists' => array(),
		);

		$this->previous_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$this->wpdb          = new MetaCapFakeWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;

		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'sanitize_key' )->alias( array( $this, 'sanitize_key_stub' ) );
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

	/**
	 * What sanitize_key() does: lower case, then only a-z, 0-9, _ and - are kept.
	 */
	public function sanitize_key_stub( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}

	public function user_can_stub( $user_id, $cap ) {
		$this->calls['user_can'][] = array( $user_id, $cap );

		return $this->has_unfiltered_html;
	}

	public function metadata_exists_stub( $meta_type, $object_id, $meta_key ) {
		$this->calls['metadata_exists'][] = array( $meta_type, $object_id, $meta_key );

		return false;
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
		// A database that matches everything: it may not be consulted.
		$this->database_matches( 'panels_data', array( 'panels_data' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, $args ) );

		$this->assert_no_lookup_of_any_kind();
	}

	/*
	 * A key that is the layout key once WordPress has cleaned it. No database
	 * lookup is needed, and the post does not need a layout row: a write with
	 * the cleaned key would create one.
	 */

	public static function keys_that_clean_to_the_layout_key() {
		$keys = array(
			'a slash that wp_unslash() removes'         => 'panels\\_data',
			'another letter case'                       => 'Panels_Data',
			'upper case'                                => 'PANELS_DATA',
			'a trailing space'                          => 'panels_data ',
			'an added character sanitize_key() removes' => 'panels_data!',
			'a slash and upper case'                    => 'PANELS\\_DATA',
		);

		$cases = array();
		foreach ( self::META_CAPS as $cap ) {
			foreach ( $keys as $label => $key ) {
				$cases[ $cap . ' with ' . $label ] = array( $cap, $key );
			}
		}

		return $cases;
	}

	#[DataProvider( 'keys_that_clean_to_the_layout_key' )]
	public function test_a_key_that_cleans_to_the_layout_key_is_not_allowed( $cap, $key ) {
		$this->assertSame( self::DENIED, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	#[DataProvider( 'keys_that_clean_to_the_layout_key' )]
	public function test_a_key_that_cleans_to_the_layout_key_keeps_the_mapped_caps_for_a_user_with_unfiltered_html( $cap, $key ) {
		$this->has_unfiltered_html = true;

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	/*
	 * Another key: the database decides.
	 */

	public static function other_keys() {
		$cases = array();

		foreach ( self::META_CAPS as $cap ) {
			$cases[ $cap . ' on another key' ]  = array( $cap, '_thumbnail_id' );
			$cases[ $cap . ' on a longer key' ] = array( $cap, 'panels_data_extra' );
			$cases[ $cap . ' on my_field' ]     = array( $cap, 'my_field' );
		}

		return $cases;
	}

	#[DataProvider( 'other_keys' )]
	public function test_a_key_the_database_does_not_match_passes_through( $cap, $key ) {
		$this->database_matches( $key, array( $key ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array( array( self::POST_ID, $key ) ), $this->wpdb->lookups );
	}

	public function test_a_key_with_no_rows_passes_through() {
		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'add_post_meta', array( self::POST_ID, 'my_field' ) ) );

		$this->assertSame( array( array( self::POST_ID, 'my_field' ) ), $this->wpdb->lookups );
	}

	#[DataProvider( 'other_keys' )]
	public function test_a_user_with_unfiltered_html_passes_through_without_a_database_lookup( $cap, $key ) {
		$this->has_unfiltered_html = true;

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array(), $this->wpdb->lookups );
	}

	/**
	 * Keys that sanitize_key() does not turn into the layout key, which a
	 * database can still match to the panels_data row.
	 */
	public static function matched_keys() {
		$keys = array(
			'an accented letter'  => "p\u{00e1}nels_data",
			'a full-width letter' => "\u{ff50}anels_data",
			'a different accent'  => "panels_dat\u{00e4}",
		);

		$cases = array();
		foreach ( self::META_CAPS as $cap ) {
			foreach ( $keys as $label => $key ) {
				$cases[ $cap . ' with ' . $label ] = array( $cap, $key );
			}
		}

		return $cases;
	}

	/**
	 * metadata_exists() reports no panels_data value here, as it does in every
	 * test. The match the database reports must still decide.
	 */
	#[DataProvider( 'matched_keys' )]
	public function test_a_key_the_database_matches_to_the_layout_row_is_not_allowed( $cap, $key ) {
		$this->database_matches( $key, array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( $cap, array( self::POST_ID, $key ) ) );

		$this->assert_capability_was_checked_once();
		$this->assertSame( array( array( self::POST_ID, $key ) ), $this->wpdb->lookups );
		$this->assertSame( array(), $this->calls['metadata_exists'] );
	}

	#[DataProvider( 'matched_keys' )]
	public function test_a_matched_key_keeps_the_mapped_caps_for_a_user_with_unfiltered_html( $cap, $key ) {
		$this->has_unfiltered_html = true;
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
		$key = "p\u{00e1}nels_data";
		$this->database_matches( $key, array( $key, 'Panels_Data' ) );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, $key ) ) );
	}

	public function test_a_match_to_several_rows_that_include_the_layout_row_is_not_allowed() {
		$key = "p\u{00e1}nels_data";
		$this->database_matches( $key, array( $key, 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, $key ) ) );
	}

	/**
	 * A match on another post says nothing about this one.
	 */
	public function test_a_match_on_another_post_passes_through() {
		$key = "p\u{00e1}nels_data";
		$this->database_matches( $key, array( 'panels_data' ), 43 );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, $key ) ) );
		$this->assertSame( array( array( self::POST_ID, $key ) ), $this->wpdb->lookups );
	}

	/**
	 * The post meta functions write to the parent of a revision, so the
	 * parent's rows decide.
	 */
	public function test_a_revision_is_resolved_to_its_parent() {
		$key = "p\u{00e1}nels_data";
		$this->database_matches( $key, array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::REVISION_ID, $key ) ) );

		$this->assertSame( array( array( self::POST_ID, $key ) ), $this->wpdb->lookups );
	}

	public function test_a_post_id_given_as_a_string_is_used_as_an_integer() {
		$key = "p\u{00e1}nels_data";
		$this->database_matches( $key, array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( (string) self::POST_ID, $key ) ) );
	}

	public static function arguments_with_no_post() {
		return array(
			'post ID zero'       => array( array( 0, 'my_field' ) ),
			'a negative post ID' => array( array( -42, 'my_field' ) ),
			'a null post ID'     => array( array( null, 'my_field' ) ),
		);
	}

	/**
	 * WordPress has already denied a meta capability that names no post, so
	 * there is no post whose rows could be compared.
	 */
	#[DataProvider( 'arguments_with_no_post' )]
	public function test_a_key_with_no_post_passes_through_without_a_database_lookup( $args ) {
		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', $args, self::DENIED ) );

		$this->assertSame( array(), $this->wpdb->lookups );
	}

	/**
	 * A slashed key is compared as given and as the metadata functions write it.
	 */
	public function test_a_slashed_key_is_looked_up_with_and_without_its_slashes() {
		$this->database_matches( "p\u{00e1}nels_data", array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, "p\u{00e1}nels\\_data" ) ) );

		$this->assertSame(
			array(
				array( self::POST_ID, "p\u{00e1}nels\\_data" ),
				array( self::POST_ID, "p\u{00e1}nels_data" ),
			),
			$this->wpdb->lookups
		);
	}

	/*
	 * A lookup that fails, and lookups that repeat.
	 */

	public function test_a_failed_lookup_is_not_allowed() {
		$this->wpdb->failing = array( self::POST_ID . '|my_field' );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'my_field' ) ) );

		$this->wpdb->failing = array();

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'my_field' ) ) );
	}

	/**
	 * No answer is remembered: a panels_data row created later in the request
	 * is seen by the next check.
	 */
	public function test_a_layout_row_created_after_a_check_is_seen_by_the_next_check() {
		$key = "p\u{00e1}nels_data";

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, $key ) ) );

		$this->database_matches( $key, array( 'panels_data' ) );

		$this->assertSame( self::DENIED, $this->restrict( 'edit_post_meta', array( self::POST_ID, $key ) ) );

		$this->database_matches( $key, array() );

		$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, $key ) ) );
		$this->assertCount( 3, $this->wpdb->lookups );
	}

	/**
	 * The cost for a user without unfiltered_html: one lookup for each check
	 * of a key that is not the layout key. Ten keys, ten lookups; the three
	 * capabilities of one key, three lookups.
	 */
	public function test_each_check_of_another_key_makes_one_database_lookup() {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'my_field_' . $i ) ) );
		}

		$this->assertCount( 10, $this->wpdb->lookups );

		foreach ( self::META_CAPS as $cap ) {
			$this->assertSame( self::MAPPED_CAPS, $this->restrict( $cap, array( self::POST_ID, 'my_field_1' ) ) );
		}

		$this->assertCount( 13, $this->wpdb->lookups );
		$this->assertSame( array(), $this->calls['metadata_exists'] );
	}

	public function test_ten_other_keys_make_no_database_lookup_for_a_user_with_unfiltered_html() {
		$this->has_unfiltered_html = true;

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( self::MAPPED_CAPS, $this->restrict( 'edit_post_meta', array( self::POST_ID, 'my_field_' . $i ) ) );
		}

		$this->assertSame( array(), $this->wpdb->lookups );
	}
}
