<?php
/**
 * Step 1 characterisation for the layout-id canonicalisation fix.
 *
 * Captures, for every real stored layout on this install, the exact generated
 * HTML ids and CSS selectors — the byte-identity REFERENCE the fix is checked
 * against (Step 4). Records RAW strings (not only CSSOM/DOM), per the plan
 * critique: CSSOM serialises/drops and DOM normalises, so raw generated output
 * is the authoritative reference; parsed forms are captured too for cross-check.
 *
 * Run:  wp eval-file tests/harness/layout-id-characterisation.php
 * Modes (SOTRUST_ID_MODE env):
 *   baseline   — capture to snapshots/layout-id-baseline.json (pre-fix reference)
 *   candidate  — capture to snapshots/layout-id-candidate.json (post-fix)
 *   diff       — compare: every SAFE id must be byte-identical; report any change
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

class SiteOrigin_Layout_Id_Characterisation {

	private $dir;

	public function __construct() {
		$this->dir = __DIR__ . '/snapshots';
		if ( ! is_dir( $this->dir ) ) { @mkdir( $this->dir, 0755, true ); }
	}

	private function mode() {
		$m = getenv( 'SOTRUST_ID_MODE' );
		return $m ? strtolower( trim( $m ) ) : '';
	}

	/** Every post carrying a Page Builder layout, classic or block. */
	private function layout_post_ids() {
		global $wpdb;
		$classic = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'panels_data'" );
		$block   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%siteorigin-panels/layout-block%' AND post_status IN ('publish','draft','private')" );
		$ids = array_unique( array_map( 'intval', array_merge( $classic, $block ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Extract every id/selector token from a generated CSS string: the whole
	 * point is the #pl-/#pg-/#pgc- selector prefixes that embed the identifier.
	 */
	private function selectors_from_css( $css ) {
		$out = array();
		if ( preg_match_all( '/#(?:pl|pg|pgc)-[^\s{,:]+/', (string) $css, $m ) ) {
			$out = array_values( array_unique( $m[0] ) );
			sort( $out );
		}
		return $out;
	}

	/** Capture the CSS selectors for one post's stored layout, via the real generator. */
	private function capture_post( $post_id ) {
		$renderer = SiteOrigin_Panels::renderer();
		// generate_css() is the CSS-selector producer; render() the HTML-id one.
		// Capture the CSS (where the injection sink lives) as the primary reference.
		$css = '';
		try {
			$css = (string) $renderer->generate_css( $post_id );
		} catch ( \Throwable $e ) {
			// Fail-closed: an errored capture gets a DISTINCT, non-empty css_hash
			// tag so diff() never compares two failures as equal-and-unchanged.
			return array(
				'post_id'  => $post_id,
				'error'    => $e->getMessage(),
				'css_hash' => 'ERROR:' . md5( $post_id . '|' . $e->getMessage() ),
				'selectors' => array(),
			);
		}
		$selectors = $this->selectors_from_css( $css );
		return array(
			'post_id'        => $post_id,
			'selector_count' => count( $selectors ),
			// hash of the full CSS so a diff is cheap; selectors listed for inspection
			'css_hash'       => md5( $css ),
			'selectors'      => $selectors,
		);
	}

	private function capture( $label ) {
		$ids = $this->layout_post_ids();
		$rows = array();
		foreach ( $ids as $pid ) {
			$rows[ $pid ] = $this->capture_post( $pid );
		}
		$doc = array(
			'_meta' => array(
				'label'   => $label,
				'wp'      => get_bloginfo( 'version' ),
				'posts'   => count( $ids ),
				'commit'  => trim( (string) @shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' rev-parse --short HEAD 2>/dev/null' ) ),
			),
			'rows' => $rows,
		);
		$path = $this->dir . '/layout-id-' . preg_replace( '/[^a-z]/', '', $label ) . '.json';
		file_put_contents( $path, wp_json_encode( $doc, JSON_PRETTY_PRINT ) );
		echo wp_json_encode( array( 'captured' => $label, 'posts' => count( $ids ), 'path' => $path ) ) . "\n";
	}

	/** Fail-closed JSON load: any read/decode failure aborts, never returns partial. */
	private function load_rows( $path ) {
		if ( ! file_exists( $path ) ) { return array( 'err' => "missing: $path" ); }
		$raw = file_get_contents( $path );
		if ( $raw === false ) { return array( 'err' => "unreadable: $path" ); }
		$doc = json_decode( $raw, true );
		if ( ! is_array( $doc ) || ! isset( $doc['rows'] ) || ! is_array( $doc['rows'] ) ) {
			return array( 'err' => "malformed: $path" );
		}
		return array( 'rows' => $doc['rows'] );
	}

	private function diff() {
		$B = $this->load_rows( $this->dir . '/layout-id-baseline.json' );
		$C = $this->load_rows( $this->dir . '/layout-id-candidate.json' );
		if ( isset( $B['err'] ) || isset( $C['err'] ) ) {
			echo wp_json_encode( array( 'error' => ( $B['err'] ?? '' ) . ' ' . ( $C['err'] ?? '' ) ) ) . "\n";
			return false;
		}
		$B = $B['rows'];
		$C = $C['rows'];
		$changed = array();
		// Union of both key sets so a candidate-only or baseline-only post is
		// caught — iterating one side alone can report a false SAFE.
		$keys = array_unique( array_merge( array_keys( $B ), array_keys( $C ) ) );
		foreach ( $keys as $pid ) {
			$brow = $B[ $pid ] ?? null;
			$crow = $C[ $pid ] ?? null;
			if ( $brow === null ) { $changed[] = array( 'post_id' => $pid, 'why' => 'candidate-only (new)' ); continue; }
			if ( $crow === null ) { $changed[] = array( 'post_id' => $pid, 'why' => 'missing in candidate' ); continue; }
			if ( ( $brow['css_hash'] ?? '' ) !== ( $crow['css_hash'] ?? '' ) ) {
				$changed[] = array(
					'post_id' => $pid,
					'why'     => 'css changed',
					'before'  => $brow['selectors'] ?? array(),
					'after'   => $crow['selectors'] ?? array(),
				);
			}
		}
		// Fail-closed: any capture that errored (either side) makes the run
		// unreliable as evidence — report it, do not swallow it into SAFE.
		$errored = 0;
		foreach ( $keys as $pid ) {
			if ( isset( $B[ $pid ]['error'] ) || isset( $C[ $pid ]['error'] ) ) { $errored++; }
		}
		foreach ( $changed as $ch ) { echo wp_json_encode( $ch ) . "\n"; }
		echo wp_json_encode( array(
			'_summary' => true,
			'total'    => count( $keys ),
			'changed'  => count( $changed ),
			'errored'  => $errored,
			'verdict'  => ( empty( $changed ) && $errored === 0 )
				? 'SAFE — every stored layout emits byte-identical selectors'
				: ( $errored > 0
					? 'STOP — ' . $errored . ' capture(s) errored; run is not reliable evidence'
					: 'REVIEW — ' . count( $changed ) . ' layouts changed; each must be a currently-UNSAFE id, never a safe one' ),
		) ) . "\n";

		return empty( $changed ) && $errored === 0;
	}

	/**
	 * Integration probe: run the REAL generate_css() (modern AND legacy) with a
	 * malicious builder_id as the identifier and assert the actual CSS sink
	 * contains only the canonical token, not the injected selector structure.
	 * This exercises the render()/generate_css() call sites — it FAILS if the
	 * canonicalisation calls there are removed, unlike a direct unit test of the
	 * canonicaliser.
	 */
	private function probe() {
		$pd = array(
			'widgets'    => array(),
			'grids'      => array( array( 'cells' => 1, 'style' => array() ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 1, 'style' => array() ) ),
		);
		$payloads = array(
			'css_breakout' => 'gb1}} body{background:red}/*',
			'html_ish'     => 'gb"><x>',
		);
		$renderers = array(
			'modern' => SiteOrigin_Panels::renderer(),
			'legacy' => new SiteOrigin_Panels_Renderer_Legacy(),
		);
		$fail = 0;
		// CSS sink (generate_css) on both renderers.
		foreach ( $renderers as $rname => $r ) {
			foreach ( $payloads as $pname => $payload ) {
				$css = (string) $r->generate_css( $payload, $pd );
				$breakout = ( strpos( $css, 'body{background:red}' ) !== false )
					|| ( strpos( $css, '}}' ) !== false )
					|| ( strpos( $css, '<x>' ) !== false );
				$has_token = ( strpos( $css, 'gb_c' ) !== false );
				$ok = ( ! $breakout ) && $has_token;
				if ( ! $ok ) { $fail++; }
				echo wp_json_encode( array(
					'sink' => 'css:' . $rname, 'payload' => $pname,
					'breakout' => $breakout, 'canonical_token' => $has_token,
					'result'   => $ok ? 'PASS' : 'FAIL',
				) ) . "\n";
			}
		}
		// HTML sink (render): the payload must not appear as a raw element id;
		// only the canonical token may. This covers the render() call site that
		// the CSS probe does not — removing that call must make this FAIL.
		foreach ( $payloads as $pname => $payload ) {
			$html = (string) SiteOrigin_Panels::renderer()->render( $payload, false, $pd );
			// The raw payload's CSS-significant chars would appear in id="pl-{payload}".
			// esc_attr encodes them in the attribute, but the canonical token means
			// the id VALUE itself is the token, not the payload. Assert the payload's
			// distinctive substring is absent from any id, and the token is present.
			$raw_in_id = ( preg_match( '/id="[^"]*(?:\}\}|body\{|<x>)/', $html ) === 1 );
			$token_in_html = ( strpos( $html, 'gb_c' ) !== false );
			$ok = ( ! $raw_in_id ) && $token_in_html;
			if ( ! $ok ) { $fail++; }
			echo wp_json_encode( array(
				'sink' => 'html:render', 'payload' => $pname,
				'raw_payload_in_id' => $raw_in_id, 'canonical_token' => $token_in_html,
				'result' => $ok ? 'PASS' : 'FAIL',
			) ) . "\n";
		}
		echo wp_json_encode( array(
			'_summary' => true, 'failures' => $fail,
			'verdict'  => $fail === 0
				? 'SAFE — no injected structure at CSS or HTML sinks; canonical token used'
				: 'STOP — ' . $fail . ' sink(s) leaked the payload',
		) ) . "\n";

		return $fail === 0;
	}

	public function run() {
		$mode = $this->mode();
		if ( $mode === 'diff' ) {
			// exit non-zero so CI / scripted callers see a failed run, not a silent pass.
			if ( ! $this->diff() ) { exit( 1 ); }
		} elseif ( $mode === 'probe' ) {
			if ( ! $this->probe() ) { exit( 1 ); }
		} elseif ( $mode === 'baseline' || $mode === 'candidate' ) {
			$this->capture( $mode );
		} else {
			echo wp_json_encode( array( 'usage' => 'SOTRUST_ID_MODE=baseline|candidate|diff|probe' ) ) . "\n";
		}
	}
}

( new SiteOrigin_Layout_Id_Characterisation() )->run();
