<?php

namespace SiteOrigin\Tests\Renderer;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The wp_footer hook as the renderer reads it: the priority running now.
 */
class FooterLayoutCssFakeHook {
	public $priority = false;

	public function current_priority() {
		return $this->priority;
	}
}

/**
 * wp_styles() for these tests: do_item() prints the stylesheet without marking it as printed,
 * as WP_Styles::do_item() does.
 */
class FooterLayoutCssFakeStyles {
	public function do_item( $handle ) {
		echo "<link id='" . $handle . "-css'>";

		return true;
	}
}

/**
 * Layout CSS for layouts first rendered from wp_footer (#1434).
 *
 * Drives the real SiteOrigin_Panels_Renderer. Brain Monkey runs each step inside
 * do_action( 'wp_footer' ), so doing_action() and current_filter() answer as they do in
 * WordPress; the fake hook gives the wp_footer priority that step runs at. Each step makes
 * at most one print, because Brain Monkey's running hook stack only grows within a call.
 * No arrow functions or anonymous classes (build-toolchain parser compatibility).
 */
class FooterLayoutCssTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * @var FooterLayoutCssFakeHook
	 */
	private $hook;

	/**
	 * The front stylesheet's state: enqueued, and printed (done).
	 *
	 * @var array
	 */
	private $front = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->front = array( 'enqueued' => false, 'done' => false );
		$front       = &$this->front;

		Functions\when( 'siteorigin_panels_setting' )->alias(
			function ( $key = '' ) {
				return $key === 'output-css-header' ? 'auto' : false;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'wp_enqueue_style' )->alias(
			function () use ( &$front ) {
				$front['enqueued'] = true;
			}
		);
		Functions\when( 'wp_style_is' )->alias(
			function ( $handle, $list ) use ( &$front ) {
				return $front[ $list ];
			}
		);
		Functions\when( 'wp_print_styles' )->alias(
			function ( $handle ) use ( &$front ) {
				if ( ! $front['done'] ) {
					echo "<link id='" . $handle . "-css'>";
					$front['done'] = true;
				}
			}
		);
		Functions\when( 'wp_styles' )->justReturn( new FooterLayoutCssFakeStyles() );

		$this->hook                       = new FooterLayoutCssFakeHook();
		$GLOBALS['wp_filter']['wp_footer'] = $this->hook;

		$root = dirname( dirname( __DIR__ ) );

		if ( ! class_exists( 'SiteOrigin_Panels_Renderer', false ) ) {
			require_once $root . '/inc/renderer.php';
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_filter']['wp_footer'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A renderer built without its constructor, which adds a WordPress hook.
	 */
	private function renderer() {
		$reflection = new \ReflectionClass( 'SiteOrigin_Panels_Renderer' );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Run a step inside wp_footer at a priority, and return what it printed.
	 */
	private function in_footer( $priority, $step ) {
		$this->hook->priority = $priority;
		Actions\expectDone( 'wp_footer' )->once()->whenHappen( $step );

		ob_start();
		do_action( 'wp_footer' );

		return ob_get_clean();
	}

	/**
	 * The CSS of each layout style element in the output, in order, keyed by element ID.
	 */
	private function styles( $output ) {
		preg_match_all( '#<style media="all" id="([^"]+)">(.*?)</style>#s', $output, $matches );

		return array_combine( $matches[1], $matches[2] );
	}

	public function test_identical_css_after_a_footer_print_is_not_printed_again() {
		$renderer = $this->renderer();

		$this->in_footer( 30, function () use ( $renderer ) {
			$renderer->add_inline_css( 'L', 'a' );
		} );
		$first = $this->in_footer( 31, function () use ( $renderer ) {
			$renderer->print_inline_css();
		} );
		$this->in_footer( 40, function () use ( $renderer ) {
			$renderer->add_inline_css( 'L', 'a' );
		} );
		$second = $this->in_footer( 41, function () use ( $renderer ) {
			$renderer->print_inline_css();
		} );

		$this->assertSame( array( 'siteorigin-panels-layouts-footer' => '/* Layout L */ a' ), $this->styles( $first ) );
		$this->assertSame( '', $second );
	}

	public function test_the_latest_css_for_a_layout_prints_last() {
		$renderer = $this->renderer();

		$this->in_footer( 30, function () use ( $renderer ) {
			$renderer->add_inline_css( 'L', 'a' );
		} );
		$first = $this->in_footer( 31, function () use ( $renderer ) {
			$renderer->print_inline_css();
		} );

		// Changed CSS is queued, then the first CSS is asked for again before the print.
		$this->in_footer( 40, function () use ( $renderer ) {
			$renderer->add_inline_css( 'L', 'b' );
			$renderer->add_inline_css( 'L', 'a' );
		} );
		$second = $this->in_footer( 41, function () use ( $renderer ) {
			$renderer->print_inline_css();
		} );

		$this->assertSame(
			array(
				'siteorigin-panels-layouts-footer'   => '/* Layout L */ a',
				'siteorigin-panels-layouts-footer-2' => '/* Layout L */ a',
			),
			$this->styles( $first . $second )
		);
	}

	public function test_returned_css_does_not_mark_the_front_stylesheet_as_printed() {
		$renderer = $this->renderer();
		do_action( 'wp_print_footer_scripts' );

		// A print that returns its CSS, which the caller may throw away.
		$returned = '';
		$this->in_footer( 30, function () use ( $renderer, &$returned ) {
			$renderer->add_inline_css( 'D', 'd' );
			$returned = $renderer->print_inline_css( true );
		} );
		$this->in_footer( 40, function () use ( $renderer ) {
			$renderer->add_inline_css( 'V', 'v' );
		} );
		$printed = $this->in_footer( 41, function () use ( $renderer ) {
			$renderer->print_inline_css();
		} );

		$this->assertStringContainsString( "<link id='siteorigin-panels-front-css'>", $returned );
		$this->assertStringContainsString( "<link id='siteorigin-panels-front-css'>", $printed );
		$this->assertStringContainsString( '/* Layout V */ v', $printed );
		$this->assertTrue( $this->front['done'] );
	}

	/**
	 * A renderer whose render() runs here: generate_css() gives each layout its own rule, and no
	 * row is rendered.
	 */
	private function rendering_renderer() {
		$renderer = Mockery::mock( 'SiteOrigin_Panels_Renderer' )->makePartial();
		$renderer->shouldReceive( 'generate_css' )->andReturnUsing(
			function ( $post_id ) {
				return '#pl-' . $post_id . ' { color:red }';
			}
		);

		$container = new \ReflectionProperty( 'SiteOrigin_Panels_Renderer', 'container' );
		$container->setAccessible( true );
		$container->setValue( $renderer, array( 'selector' => '', 'width' => '', 'full_width' => false, 'css_override' => false ) );

		Functions\when( 'is_rtl' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Filters\expectApplied( 'siteorigin_panels_output_row' )->andReturn( false );

		return $renderer;
	}

	/**
	 * Render a one-row layout and return the HTML render() returns.
	 */
	private function render_layout( $renderer, $post_id, $enqueue_css = true ) {
		$panels_data = array(
			'widgets'    => array(),
			'grids'      => array( array( 'cells' => 1 ) ),
			'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1 ) ),
		);
		$layout_data = array( array( 'cells' => array() ) );

		return $renderer->render( $post_id, $enqueue_css, $panels_data, $layout_data );
	}

	public function test_at_the_last_priority_the_css_is_returned_with_the_layout() {
		$renderer = $this->rendering_renderer();
		do_action( 'wp_print_footer_scripts' );
		$returned = '';

		$echoed = $this->in_footer( PHP_INT_MAX, function () use ( $renderer, &$returned ) {
			$returned = $this->render_layout( $renderer, 'M' );
		} );

		// Nothing escapes the caller: the CSS and the stylesheet come back with the layout.
		$this->assertSame( '', $echoed );
		$this->assertMatchesRegularExpression(
			"#^<link id='siteorigin-panels-front-css'><style media=\"all\" id=\"siteorigin-panels-layouts-footer\">/\* Layout M \*/ \#pl-M \{ color:red \}</style><div id=\"pl-M\" #",
			$returned
		);
		$this->assertFalse( $this->front['done'] );
	}

	public function test_a_render_at_the_last_priority_returns_only_its_own_css() {
		$renderer = $this->rendering_renderer();
		do_action( 'wp_print_footer_scripts' );

		// A visible layout at PHP_INT_MAX - 1 queues its CSS for the print at PHP_INT_MAX.
		$this->in_footer( PHP_INT_MAX - 1, function () use ( $renderer ) {
			echo $this->render_layout( $renderer, 'V' );
		} );
		$this->assertTrue( has_action( 'wp_footer', array( $renderer, 'print_inline_css' ) ) !== false );

		// A max-priority callback that runs before that print renders a layout and throws it away.
		$discarded = '';
		$this->in_footer( PHP_INT_MAX, function () use ( $renderer, &$discarded ) {
			$discarded = $this->render_layout( $renderer, 'D' );
		} );
		$printed = $this->in_footer( PHP_INT_MAX, function () use ( $renderer ) {
			$renderer->print_inline_css();
		} );

		$this->assertStringContainsString( '/* Layout D */', $discarded );
		$this->assertStringNotContainsString( '/* Layout V */', $discarded );
		$this->assertStringContainsString( "<link id='siteorigin-panels-front-css'>", $printed );
		$this->assertStringContainsString( '/* Layout V */ #pl-V { color:red }', $printed );
		$this->assertStringNotContainsString( '/* Layout D */', $printed );
		$this->assertTrue( $this->front['done'] );
	}

	public function test_a_render_without_css_inside_a_render_filter_leaves_the_outer_css() {
		$renderer = $this->rendering_renderer();
		do_action( 'wp_print_footer_scripts' );

		// A siteorigin_panels_render filter on the outer layout renders another layout with its
		// CSS turned off, and throws it away.
		Filters\expectApplied( 'siteorigin_panels_render' )->andReturnUsing(
			function ( $html, $post_id ) use ( $renderer ) {
				if ( $post_id === 'O' ) {
					$this->render_layout( $renderer, 'I', false );
				}

				return $html;
			}
		);

		$returned = '';
		$this->in_footer( PHP_INT_MAX, function () use ( $renderer, &$returned ) {
			$returned = $this->render_layout( $renderer, 'O' );
		} );

		$this->assertMatchesRegularExpression(
			"#^<link id='siteorigin-panels-front-css'><style media=\"all\" id=\"siteorigin-panels-layouts-footer\">/\* Layout O \*/ \#pl-O \{ color:red \}</style><div id=\"pl-O\" #",
			$returned
		);
	}
}
