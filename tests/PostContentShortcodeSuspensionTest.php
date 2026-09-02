<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

// Load the REAL classes under test. The copy-content parity suite uses a
// counting shim for SiteOrigin_Panels_Post_Content_Filters, which is why it
// runs in its own suite; this suite exercises the real implementation.
if ( ! class_exists( 'SiteOrigin_Panels_Widget_Shortcode', false ) ) {
	require __DIR__ . '/../inc/widget-shortcode.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Post_Content_Filters', false ) ) {
	require __DIR__ . '/../inc/post-content-filters.php';
}

/**
 * Tests for shortcode suspension during post content (copy-content) renders.
 *
 * The post content mirror must keep raw shortcodes intact rather than baking
 * their rendered output into post_content. Executed shortcode output in the
 * mirror both displays broken wherever the mirror is shown instead of a live
 * Page Builder render (e.g. a Ninja Forms placeholder stored without the
 * plugin's JS/CSS ever being enqueued), and defeats plugins that scan
 * post_content with has_shortcode() to decide whether to enqueue assets.
 *
 * SiteOrigin_Panels_Post_Content_Filters::add_filters() therefore empties the
 * global $shortcode_tags registry for the duration of the render — making
 * every do_shortcode() call a no-op that returns its input unchanged — and
 * remove_filters() restores the registry.
 */
class PostContentShortcodeSuspensionTest extends SiteOriginTests {
	protected function setUp(): void {
		parent::setUp();

		// The real Widget_Shortcode::add_filters()/remove_filters() call
		// add_filter()/remove_filter(); Brain Monkey stubs these.
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			fn( $tag, $value ) => $value
		);

		$this->reset_backup();
		$GLOBALS['shortcode_tags'] = array( 'ninja_forms' => 'nf_callback' );
	}

	protected function tearDown(): void {
		$this->reset_backup();
		unset( $GLOBALS['shortcode_tags'] );

		parent::tearDown();
	}

	/**
	 * Reset the private static state so it can't leak between tests.
	 */
	private function reset_backup() {
		$ref = new ReflectionProperty( SiteOrigin_Panels_Post_Content_Filters::class, 'shortcode_tags_backup' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$depth = new ReflectionProperty( SiteOrigin_Panels_Post_Content_Filters::class, 'suspend_depth' );
		$depth->setAccessible( true );
		$depth->setValue( null, 0 );
	}

	public function test_add_filters_suspends_shortcodes_and_remove_filters_restores() {
		SiteOrigin_Panels_Post_Content_Filters::add_filters();

		$this->assertSame(
			array(),
			$GLOBALS['shortcode_tags'],
			'Shortcode registry must be empty during a post content render so do_shortcode() leaves shortcodes intact.'
		);

		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame(
			array( 'ninja_forms' => 'nf_callback' ),
			$GLOBALS['shortcode_tags'],
			'Shortcode registry must be restored after the post content render.'
		);
	}

	public function test_block_editor_render_does_not_suspend_shortcodes() {
		SiteOrigin_Panels_Post_Content_Filters::add_filters( true );

		$this->assertSame(
			array( 'ninja_forms' => 'nf_callback' ),
			$GLOBALS['shortcode_tags'],
			'Block editor preview renders are live previews; shortcodes must still execute.'
		);

		SiteOrigin_Panels_Post_Content_Filters::remove_filters( true );

		$this->assertSame( array( 'ninja_forms' => 'nf_callback' ), $GLOBALS['shortcode_tags'] );
	}

	public function test_shortcodes_registered_during_render_survive_restore() {
		SiteOrigin_Panels_Post_Content_Filters::add_filters();

		// A widget lazily registers a shortcode while rendering, and also
		// tries to claim a tag that was registered before the render.
		$GLOBALS['shortcode_tags']['lazy'] = 'lazy_callback';
		$GLOBALS['shortcode_tags']['ninja_forms'] = 'usurper_callback';

		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame(
			'lazy_callback',
			$GLOBALS['shortcode_tags']['lazy'],
			'Shortcodes registered during the render must survive the restore.'
		);
		$this->assertSame(
			'nf_callback',
			$GLOBALS['shortcode_tags']['ninja_forms'],
			'Original registrations must win over re-registrations made during the render.'
		);
	}

	public function test_nested_scope_counts_even_when_the_opt_out_filter_flips_mid_render() {
		$calls = 0;
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( &$calls ) {
				if ( $tag !== 'siteorigin_panels_post_content_keep_shortcodes' ) {
					return $value;
				}
				$calls++;

				// True for the outer scope; false if consulted again.
				return $calls === 1;
			}
		);

		SiteOrigin_Panels_Post_Content_Filters::add_filters();
		// The nested scope must be counted without consulting the filter, so
		// a flipped filter result cannot desync the depth accounting.
		SiteOrigin_Panels_Post_Content_Filters::add_filters();

		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame(
			array(),
			$GLOBALS['shortcode_tags'],
			'The inner scope closing must not restore while the outer suspending scope is still open.'
		);

		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame( array( 'ninja_forms' => 'nf_callback' ), $GLOBALS['shortcode_tags'] );
	}

	public function test_keep_shortcodes_filter_opts_out_of_suspension() {
		Functions\when( 'apply_filters' )->alias(
			fn( $tag, $value ) => $tag === 'siteorigin_panels_post_content_keep_shortcodes' ? false : $value
		);

		SiteOrigin_Panels_Post_Content_Filters::add_filters();

		$this->assertSame(
			array( 'ninja_forms' => 'nf_callback' ),
			$GLOBALS['shortcode_tags'],
			'Sites can opt back into baking shortcode output via the filter.'
		);

		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame( array( 'ninja_forms' => 'nf_callback' ), $GLOBALS['shortcode_tags'] );
	}

	public function test_nested_scopes_stay_suspended_until_the_outermost_closes() {
		SiteOrigin_Panels_Post_Content_Filters::add_filters();
		SiteOrigin_Panels_Post_Content_Filters::add_filters();

		// Closing the inner scope must NOT restore; the outer render is
		// still in progress.
		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame(
			array(),
			$GLOBALS['shortcode_tags'],
			'Shortcodes must stay suspended while an outer render scope is still open.'
		);

		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame(
			array( 'ninja_forms' => 'nf_callback' ),
			$GLOBALS['shortcode_tags'],
			'The outermost scope closing must restore the original registry.'
		);
	}

	public function test_excess_remove_filters_is_a_no_op() {
		SiteOrigin_Panels_Post_Content_Filters::add_filters();
		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		// An unbalanced extra remove must not corrupt the registry or the
		// suspension state for a later render.
		SiteOrigin_Panels_Post_Content_Filters::remove_filters();

		$this->assertSame( array( 'ninja_forms' => 'nf_callback' ), $GLOBALS['shortcode_tags'] );

		SiteOrigin_Panels_Post_Content_Filters::add_filters();
		$this->assertSame( array(), $GLOBALS['shortcode_tags'] );
		SiteOrigin_Panels_Post_Content_Filters::remove_filters();
		$this->assertSame( array( 'ninja_forms' => 'nf_callback' ), $GLOBALS['shortcode_tags'] );
	}

	public function test_custom_html_widget_is_treated_as_a_text_widget() {
		$this->assertContains(
			'WP_Widget_Custom_HTML',
			SiteOrigin_Panels_Widget_Shortcode::$text_widgets,
			'The Custom HTML widget must keep its raw markup (and raw shortcodes) in the post content mirror rather than being replaced by a [siteorigin_widget] wrapper.'
		);
	}

	// --- widget_html(): the mirror's widget substitution layer ---------------

	public function test_widget_html_lets_the_custom_html_widget_render_its_own_markup() {
		$GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] = true;

		$widget = new WP_Widget_Custom_HTML();
		$html = SiteOrigin_Panels_Widget_Shortcode::widget_html(
			'',
			$widget,
			array(),
			array( 'content' => '<h2>Contact</h2>[ninja_forms id=1]' )
		);

		unset( $GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] );

		$this->assertSame(
			'',
			$html,
			'widget_html() must return empty for the Custom HTML widget so the renderer falls through to the real widget() output, keeping raw markup and shortcodes in the mirror.'
		);
	}

	public function test_widget_html_still_wraps_non_text_widgets_in_a_widget_shortcode() {
		$GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] = true;

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			fn( $tag, $value ) => $value
		);

		$widget = new PostContentSuspension_OtherWidgetDouble();
		$html = SiteOrigin_Panels_Widget_Shortcode::widget_html(
			'',
			$widget,
			array(),
			array( 'some_setting' => 'value' )
		);

		unset( $GLOBALS['SITEORIGIN_PANELS_POST_CONTENT_RENDER'] );

		$this->assertStringStartsWith(
			'[siteorigin_widget class="PostContentSuspension_OtherWidgetDouble"]',
			$html,
			'Non-text widgets must still be represented by a [siteorigin_widget] wrapper in the mirror.'
		);
		$this->assertStringEndsWith( '[/siteorigin_widget]', $html );
	}
}

/**
 * get_class() doubles for widget_html() tests. The real WP_Widget classes are
 * not available outside WordPress; widget_html() only inspects the class name,
 * so an empty class carrying core's exact name stands in for the real widget.
 */
if ( ! class_exists( 'WP_Widget_Custom_HTML', false ) ) {
	class WP_Widget_Custom_HTML {
	}
}

class PostContentSuspension_OtherWidgetDouble {
}
