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
			'integer post id'        => array( 42, '42' ),
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
	 * A value wearing the reserved `gb_c` prefix that is NOT an exact canonical
	 * token must be forced through the hash, not passed through — so a caller
	 * cannot hand in a forged token and have it survive verbatim.
	 */
	public function test_reserved_prefix_is_not_a_passthrough() {
		$forged = 'gb_cNOTAREALHASHVALUE';
		$out = $this->canon( $forged );
		$this->assertNotSame( $forged, $out, 'a forged gb_c-prefixed value must not pass through' );
		$this->assertMatchesRegularExpression( '/^gb_c[0-9a-f]{20}$/', $out );
	}

	/**
	 * A genuine canonical token (this method's own output) IS a fixed point —
	 * required for idempotency. This is not a forgery gap: two different inputs
	 * mapping to the same token would need a sha256 preimage collision.
	 */
	public function test_canonical_token_is_a_fixed_point() {
		$token = $this->canon( 'gb1}} body{x:1}/*' );
		$this->assertMatchesRegularExpression( '/^gb_c[0-9a-f]{20}$/', $token );
		$this->assertSame( $token, $this->canon( $token ) );
	}
}
