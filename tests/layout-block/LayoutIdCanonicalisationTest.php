<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the layout render-identifier canonicalisation
 * (SiteOrigin_Panels_Renderer::canonicalize_layout_id()).
 *
 * The identifier is concatenated raw into `#pl-`/`#pg-`/`#pgc-` CSS selectors and
 * element ids, so a Layout Block builder_id containing CSS syntax injects rules.
 * canonicalize_layout_id() neutralises that at the single render/CSS entry point.
 *
 * The method uses only PHP builtins, so these run without a WordPress bootstrap.
 * Self-contained per this suite's conventions: no arrow functions or anonymous
 * classes (build-toolchain parser compatibility); `: void` on setUp/tearDown.
 */
class LayoutIdCanonicalisationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private static $renderer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( 'SiteOrigin_Panels_Renderer', false ) ) {
			require_once dirname( dirname( __DIR__ ) ) . '/inc/renderer.php';
		}
		// newInstanceWithoutConstructor: the real constructor adds a WP hook.
		if ( self::$renderer === null ) {
			$ref = new \ReflectionClass( \SiteOrigin_Panels_Renderer::class );
			self::$renderer = $ref->newInstanceWithoutConstructor();
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function canon( $id ) {
		return self::$renderer->canonicalize_layout_id( $id );
	}

	/**
	 * Every id shape a real producer emits is in the safe grammar and MUST come
	 * out byte-identical — the constraint that keeps customer stylesheets and
	 * Live Editor selectors targeting `#pl-…` working.
	 */
	public static function safe_ids() {
		return array(
			'integer post id'        => array( 42, 42 ),
			'numeric string post id' => array( '42', '42' ),
			'uniqid gb.ID.- '        => array( 'gb42-6a6f097d9d775', 'gb42-6a6f097d9d775' ),
			'md5 fallback'           => array( 'gb42-9dd4e461268c8034f5c8564e155c67a6-', 'gb42-9dd4e461268c8034f5c8564e155c67a6-' ),
			'nested w+builder'       => array( 'wgb6a6f097d9d777', 'wgb6a6f097d9d777' ),
			'prebuilt gbp+uniqid'    => array( 'gbp6a6f097d9d778', 'gbp6a6f097d9d778' ),
			'plain underscores'      => array( 'a_b_c', 'a_b_c' ),
		);
	}

	#[DataProvider( 'safe_ids' )]
	public function test_safe_ids_are_byte_identical( $input, $expected ) {
		$this->assertSame( $expected, $this->canon( $input ) );
	}

	/**
	 * THE injection regression: a builder_id carrying CSS selector structure must
	 * NOT survive into the id (which would reach `#pl-{id}` raw). It becomes the
	 * reserved token; the CSS-significant characters are gone.
	 */
	public function test_css_injection_payload_is_neutralised() {
		$out = $this->canon( 'gb1}} body{background:red}/*' );

		$this->assertStringNotContainsString( '}', $out );
		$this->assertStringNotContainsString( '{', $out );
		$this->assertStringNotContainsString( ' ', $out );
		$this->assertStringStartsWith( 'gb_c', $out );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $out );
	}

	/**
	 * Unsafe/edge inputs all yield a safe reserved token, never empty.
	 */
	public static function unsafe_ids() {
		return array(
			'css breakout'  => array( 'gb1}} body{display:none}/*' ),
			'html-ish'      => array( 'gb"><script>x</script>' ),
			'dot slash'     => array( 'acme.widget/1' ),
			'space'         => array( 'a b' ),
			'colon'         => array( 'a:b' ),
			'hash'          => array( '#deadbeef' ),
			'empty string'  => array( '' ),
			'overlong'      => array( str_repeat( 'a', 500 ) ),
			'unicode'       => array( 'gb–dash' ),
		);
	}

	#[DataProvider( 'unsafe_ids' )]
	public function test_unsafe_ids_become_safe_reserved_token( $input ) {
		$out = $this->canon( $input );
		$this->assertNotSame( '', $out, 'never returns empty' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $out );
		$this->assertStringStartsWith( 'gb_c', $out );
	}

	/**
	 * Non-scalar inputs are not legitimate ids and must not fatal or serialize.
	 */
	public static function non_scalars() {
		return array(
			'null'   => array( null ),
			'false'  => array( false ),
			'true'   => array( true ),
			'float'  => array( 1.5 ),
			'array'  => array( array( 'x' ) ),
		);
	}

	#[DataProvider( 'non_scalars' )]
	public function test_non_scalar_inputs_yield_a_safe_token( $input ) {
		$out = $this->canon( $input );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $out );
		$this->assertStringStartsWith( 'gb_c', $out );
	}

	/**
	 * Idempotent: canonicalising a canonicalised value returns it unchanged (the
	 * `gb_c…` token is itself in the safe grammar).
	 */
	public function test_idempotent() {
		$once = $this->canon( 'gb1}} body{display:none}/*' );
		$this->assertSame( $once, $this->canon( $once ) );
	}

	/**
	 * Deterministic: same input → same token every call (not uniqid()), so output
	 * does not vary per render.
	 */
	public function test_deterministic() {
		$a = $this->canon( 'acme.widget/1' );
		$b = $this->canon( 'acme.widget/1' );
		$this->assertSame( $a, $b );
	}

	/**
	 * Reserved-namespace non-collision: distinct unsafe values yield distinct
	 * tokens (typed hash), and `gb_c` cannot be produced by a real generator —
	 * no generator emits an underscore in its token, so a canonicalised unsafe
	 * value can never collide with a real safe id.
	 */
	public function test_distinct_unsafe_values_do_not_collide() {
		$this->assertNotSame( $this->canon( 'a.b' ), $this->canon( 'a/b' ) );
		$this->assertNotSame( $this->canon( 'gb1}}x' ), $this->canon( 'gb1}}y' ) );
		// Different non-scalars must not collide: they are distinguished by a
		// stable type+JSON representation, not reduced to one constant token.
		$this->assertNotSame( $this->canon( null ), $this->canon( false ) );
		$this->assertNotSame( $this->canon( array( 'x' ) ), $this->canon( array( 'y' ) ) );
	}

	/**
	 * A legitimate safe-grammar id starting with the reserved prefix (e.g.
	 * `gb_cfoo`) is STILL byte-identical — the reserved namespace is not enforced
	 * by rewriting safe values (that would break real ids), only by the fact that
	 * no generator emits `gb_c`. A canonical token is a fixed point (idempotency).
	 */
	public function test_gb_c_prefixed_safe_ids_are_byte_identical() {
		$this->assertSame( 'gb_cfoo', $this->canon( 'gb_cfoo' ) );
		$this->assertSame( 'gb_c123', $this->canon( 'gb_c123' ) );
		// The method's own token round-trips unchanged.
		$token = $this->canon( 'gb1}} body{x:1}/*' );
		$this->assertMatchesRegularExpression( '/^gb_c[0-9a-f]{20}$/', $token );
		$this->assertSame( $token, $this->canon( $token ) );
	}

	/**
	 * An integer post id keeps its integer type (not stringified), so the
	 * renderer's application state, cache keys and filter args are unchanged.
	 */
	public function test_integer_post_id_keeps_int_type() {
		$this->assertSame( 42, $this->canon( 42 ) );
		$this->assertIsInt( $this->canon( 42 ) );
	}

	/**
	 * Cache/filter consistency for the ONE path where canonicalisation changes the
	 * value (unsafe input): a downstream consumer that keys off the identifier
	 * (the inline_css cache uses `$post_id` as its key; filters receive it) must
	 * see the SAME canonical value the sinks use — otherwise a request could write
	 * under one key and read under another. For an unsafe id, canonicalize is
	 * deterministic, so every consumer of the post-canonicalisation value agrees.
	 */
	public function test_unsafe_id_is_consistent_across_consumers() {
		$unsafe = 'gb1}} body{background:red}/*';
		$a = $this->canon( $unsafe );
		$b = $this->canon( $unsafe );
		// Deterministic: the cache key and every filter arg derived from it are the
		// same string on every call within and across requests.
		$this->assertSame( $a, $b );
		// And it is the safe token, so the cache key itself is selector-safe.
		$this->assertMatchesRegularExpression( '/^gb_c[0-9a-f]{20}$/', $a );
		// A safe id is unchanged, so its consumers are byte-identical to pre-fix.
		$this->assertSame( 42, $this->canon( 42 ) );
	}

	/**
	 * Call-site guard, wired into the phpunit suite: the canonicalise call must be
	 * present at BOTH render sinks (render() and generate_css() in the modern
	 * renderer, and generate_css() in the legacy renderer). Deleting a call site —
	 * which the unit tests above would not otherwise catch, since they call the
	 * canonicaliser directly — fails here. This is a structural guard; the
	 * BEHAVIOURAL end-to-end proof (that generate_css()/render() actually emit only
	 * the canonical token, and fail if a call site is removed) is the harness probe
	 * `SOTRUST_ID_MODE=probe`, which drives the real renderers and exits non-zero on
	 * a leak. Run both; this one is the cheap in-suite tripwire.
	 */
	public function test_canonicalise_is_called_at_every_render_sink() {
		$modern = file_get_contents( dirname( dirname( __DIR__ ) ) . '/inc/renderer.php' );
		$legacy = file_get_contents( dirname( dirname( __DIR__ ) ) . '/inc/renderer-legacy.php' );

		// Count the canonicalise application in each entry method's body.
		$this->assertSame(
			2,
			substr_count( $modern, '$post_id = $this->canonicalize_layout_id( $post_id );' ),
			'render() and generate_css() must each canonicalise the identifier.'
		);
		$this->assertSame(
			1,
			substr_count( $legacy, '$post_id = $this->canonicalize_layout_id( $post_id );' ),
			'the legacy renderer generate_css() override must canonicalise too.'
		);
	}
}
