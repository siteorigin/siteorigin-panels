<?php
/**
 * Render-trust diff runner.
 *
 * Proves the IMPLEMENTATION of the signature removal by execution, not argument:
 * it snapshots the render-trust harness matrix, and on a second run (after the
 * structural-render source change) diffs the two, so exactly which observable
 * outcomes moved is a fact, not a claim.
 *
 * Workflow (mode is passed via the SOTRUST_MODE env var — WP-CLI intercepts
 * `--` flags for itself, so an env var is the reliable channel):
 *   1. On UNMODIFIED code (baseline):
 *        SOTRUST_MODE=baseline wp eval-file tests/harness/diff-runner.php
 *   2. Apply the structural-render change (KEYLESS-FINAL-SPEC.md).
 *   3. On MODIFIED code:
 *        SOTRUST_MODE=candidate wp eval-file tests/harness/diff-runner.php
 *   4. Diff:
 *        SOTRUST_MODE=diff wp eval-file tests/harness/diff-runner.php
 *      Emits, per matrix row, UNCHANGED / CHANGED with the before→after fields.
 *
 * SEEDED-FLOOR GATE — expected-RED owners (recorded here because the plan file
 * that held this list is gone; this is its only home). The gate proves the
 * AI-write forced-floor coverage is real by deleting one term of the floor
 * condition in compat/layout-block.php and confirming the suite goes RED. Seed
 * patterns (delete exactly these substrings, one at a time, then restore):
 *   Gate A — delete  `$this->force_kses_floor || `
 *     expected RED owners:
 *       tests/layout-block/LayoutBlockAiSeamTest.php
 *         test_untrusted_chokepoint_floors_before_signing_for_capable_user
 *       tests/layout-block/LayoutBlockAiSeamTest.php
 *         test_reentrant_untrusted_call_preserves_outer_floor
 *       tests/layout-block/LayoutBlockUntrustedWriteE2ETest.php
 *         test_capable_credential_write_is_floored_signed_and_deduped_end_to_end
 *   Gate B — delete  `$ai_changed_layout || `
 *     expected RED owner:
 *       tests/layout-block/LayoutBlockAiSeamTest.php
 *         test_changed_layout_is_floored_before_signing_for_capable_user
 * Run `vendor/bin/phpunit -c phpunit-layout-block.xml` after each seed; RED via
 * the named owner = coverage intact, GREEN = coverage lost (STOP). Restore the
 * file byte-identically (cp backup, verify with shasum) between seeds. These
 * four method names must NOT be renamed without updating this list, or the gate
 * false-STOPs on absence rather than on lost coverage.
 *
 * PREDICATE (stated first, per standing rule): the gate fails when any
 * observable moves in a direction that is bad for security OR function. That is
 * a SET of directional rules, one per failure class — not the single
 * "false→true containment" test an earlier version had, which passed three real
 * regressions (embed loss, stored loss, disappearance) as "SAFE". Each rule has
 * a seeded control proving it fires before the gate is trusted (see
 * diff-runner-selftest.php).
 *
 * Regression classes (each → STOP):
 *   1. containment leak   rendered_out_payload  false→true (any row)
 *   2. embed loss         rendered_out_payload  true→false where can_unfiltered
 *                         (the blank-embed bug returning — the reason this exists)
 *   3. stored loss        stored_has_payload    true→false
 *   4. disappearance      key in baseline, absent in candidate (fatal/skip)
 *   5. appearance         key in candidate, absent in baseline (unexpected new row)
 *   6. viewer variance    viewer_variant        false→true
 *
 * The snapshot excludes volatile fields (run_tag, per-render hashes that embed a
 * fresh builder_id/uniqid) and keys on the stable (payload, role, widget) tuple,
 * so two runs are comparable. Semantic booleans are the ground truth.
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "must run via `wp eval-file`\n" );
	exit( 1 );
}

if ( ! class_exists( 'SiteOrigin_Render_Trust_Diff' ) ) :
class SiteOrigin_Render_Trust_Diff {

	private $dir;

	public function __construct() {
		$this->dir = __DIR__ . '/snapshots';
		if ( ! is_dir( $this->dir ) ) {
			@mkdir( $this->dir, 0755, true );
		}
	}

	/** Mode from the SOTRUST_MODE env var (WP-CLI eats `--` flags). */
	private function mode() {
		$m = getenv( 'SOTRUST_MODE' );
		return $m ? strtolower( trim( $m ) ) : '';
	}

	/** Run the harness in-process; return [meta, rows]. */
	private function collect() {
		// The harness emits JSONL to stdout; capture and parse.
		ob_start();
		require_once __DIR__ . '/render-trust-harness.php';
		$raw = ob_get_clean();

		$meta = array();
		$rows = array();
		foreach ( explode( "\n", trim( $raw ) ) as $line ) {
			if ( $line === '' ) {
				continue;
			}
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			if ( ! empty( $obj['_meta'] ) ) {
				$meta = $obj;
				continue;
			}
			$rows[] = $obj;
		}
		return array( $meta, $rows );
	}

	/** Environment provenance, so a baseline+candidate from different worlds refuse to diff. */
	private function provenance() {
		$branch = trim( (string) @shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' rev-parse --abbrev-ref HEAD 2>/dev/null' ) );
		$commit = trim( (string) @shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' rev-parse --short HEAD 2>/dev/null' ) );
		return array(
			'wp'     => get_bloginfo( 'version' ),
			'panels' => defined( 'SITEORIGIN_PANELS_VERSION' ) ? SITEORIGIN_PANELS_VERSION : '?',
			'sowb'   => class_exists( 'SiteOrigin_Widgets_Bundle' ),
			'branch' => $branch !== '' ? $branch : '?',
			'commit' => $commit !== '' ? $commit : '?',
		);
	}

	/** Reduce a row to its stable key and the semantic (non-volatile) observations. */
	private function normalize( $row ) {
		// user0_resave probe rows use a different shape; key them separately.
		if ( ! empty( $row['probe'] ) && $row['probe'] === 'user0_resave' ) {
			return array(
				'key'    => 'resave|' . $row['widget'],
				'fields' => array(
					'iframe_survived' => $row['iframe_survived'] ?? null,
					'bytes_identical' => $row['bytes_identical'] ?? null,
				),
			);
		}
		return array(
			'key'    => implode( '|', array( $row['payload'] ?? '?', $row['role'] ?? '?', $row['widget'] ?? '?' ) ),
			'fields' => array(
				'can_unfiltered'       => $row['can_unfiltered'] ?? null,
				'kses_armed'           => $row['kses_armed'] ?? null,
				'stored_has_payload'   => $row['stored_has_payload'] ?? null,
				'rendered_out_payload' => $row['rendered_out_payload'] ?? null,
				'rendered_in_payload'  => $row['rendered_in_payload'] ?? null,
				'viewer_variant'       => $row['viewer_variant'] ?? null,
				// expect_change is an up-front ASSERTION of the intended movement
				// for this row's class, not an override applied after the fact.
				// Unsigned E1/E2 rows carry 'contained_to_live'; chokepointed rows
				// carry null (expected not to move).
				'expect_change'        => $row['expect_change'] ?? null,
			),
		);
	}

	private function snapshot( $label ) {
		list( , $rows ) = $this->collect();
		$snap = array();
		foreach ( $rows as $r ) {
			$n = $this->normalize( $r );
			$snap[ $n['key'] ] = $n['fields'];
		}
		ksort( $snap );
		$doc = array( '_provenance' => $this->provenance(), 'rows' => $snap );
		$path = $this->dir . '/' . preg_replace( '/[^a-z0-9_-]/i', '', $label ) . '.json';
		file_put_contents( $path, wp_json_encode( $doc, JSON_PRETTY_PRINT ) );
		echo wp_json_encode( array( 'snapshot' => $label, 'rows' => count( $snap ), 'provenance' => $doc['_provenance'], 'path' => $path ) ) . "\n";
	}

	/**
	 * Classify a single row's before/after into regression classes.
	 * Returns array of class-slugs that fired (empty = benign change).
	 * $bf/$cf are field arrays; either may be null (row missing on that side).
	 */
	private function classify( $bf, $cf ) {
		$hits = array();
		// 4. disappearance — was in baseline, gone from candidate.
		if ( $bf !== null && $cf === null ) {
			return array( 'disappearance' );
		}
		// 5. appearance — new in candidate, absent from baseline.
		if ( $bf === null && $cf !== null ) {
			return array( 'appearance' );
		}
		$get = function ( $a, $k ) { return array_key_exists( $k, $a ) ? $a[ $k ] : null; };

		// EXPECTED-CHANGE assertion (predicate stated up front, not an override):
		// a row tagged 'contained_to_live' (unsigned E1/E2) MUST move payload
		// contained→live; that move is the intended effect and is NOT a
		// regression for this row. But it must actually happen — a legacy row
		// that does NOT move is 'expected_change_missing' (the change failed to
		// do its job on the class it targets). Chokepointed rows carry no
		// expectation and must not move at all.
		$expect = $get( $bf, 'expect_change' );
		$out_before = $get( $bf, 'rendered_out_payload' );
		$out_after  = $get( $cf, 'rendered_out_payload' );

		// Whether THIS row's containment move is the asserted, intended one. When
		// true we suppress ONLY the containment_leak rule below (rules 2/3/6 still
		// run, so a legacy row that moves as expected AND loses stored data is
		// still flagged). We do NOT flag "row failed to move" here — whether the
		// change fired on every legacy row is a CANDIDATE-side completeness
		// assertion (see assert_expected_changes_fired()), not a per-pair
		// regression: a differ comparing baseline-vs-baseline has no way to know
		// the candidate was meant to be the post-change state, so demanding the
		// move inside classify() wrongly fails a no-op diff.
		$expected_move_ok = ( $expect === 'contained_to_live' && $out_before === false && $out_after === true );

		// 1. containment leak — payload became live to a logged-out viewer
		//    (on a row that did NOT expect it).
		if ( ! $expected_move_ok && $out_before === false && $out_after === true ) {
			$hits[] = 'containment_leak';
		}
		// 2a. embed loss (logged-out) — a privileged author's embed stopped
		//     rendering to visitors. Gated on can_unfiltered because for a
		//     LOW-cap saver a logged-out true→false is core kses doing its job,
		//     not loss. Does not apply to legacy rows (can_unfiltered null).
		if ( $out_before === true && $out_after === false && $get( $bf, 'can_unfiltered' ) === true ) {
			$hits[] = 'embed_loss_out';
		}
		// 2b. render loss (logged-in) — content that rendered for a logged-in
		//     viewer stopped rendering. Independent of can_unfiltered: this is
		//     content DISAPPEARING at render, never a sanitizer doing its job, so
		//     it must fire on legacy rows too (all can_unfiltered null). Without
		//     this, a legacy row that moves out but loses its logged-in render
		//     reads SAFE — content loss on the exact population the change targets.
		if ( $get( $bf, 'rendered_in_payload' ) === true && $get( $cf, 'rendered_in_payload' ) === false ) {
			$hits[] = 'render_loss_in';
		}
		// 3. stored loss — content lost its payload in the DB at save.
		if ( $get( $bf, 'stored_has_payload' ) === true && $get( $cf, 'stored_has_payload' ) === false ) {
			$hits[] = 'stored_loss';
		}
		// 6. viewer variance — the PAYLOAD's fate now differs by viewer login
		//    state (corrected def: rendered_out_payload != rendered_in_payload,
		//    already computed into the viewer_variant field). A row going from
		//    invariant to variant means the change made login state affect what
		//    a payload does — a real problem.
		if ( $get( $bf, 'viewer_variant' ) === false && $get( $cf, 'viewer_variant' ) === true ) {
			$hits[] = 'viewer_variance';
		}
		return $hits;
	}

	public function diff() {
		$b = $this->dir . '/baseline.json';
		$c = $this->dir . '/candidate.json';
		if ( ! file_exists( $b ) || ! file_exists( $c ) ) {
			echo wp_json_encode( array( 'error' => 'need both baseline.json and candidate.json', 'have' => array( 'baseline' => file_exists( $b ), 'candidate' => file_exists( $c ) ) ) ) . "\n";
			return;
		}
		$Bdoc = json_decode( file_get_contents( $b ), true );
		$Cdoc = json_decode( file_get_contents( $c ), true );

		// Provenance guard: refuse to diff snapshots from different worlds — except
		// the commit, which is EXPECTED to differ (baseline vs the change under test).
		$bp = $Bdoc['_provenance'] ?? array();
		$cp = $Cdoc['_provenance'] ?? array();
		foreach ( array( 'wp', 'panels', 'sowb' ) as $k ) {
			if ( ( $bp[ $k ] ?? null ) !== ( $cp[ $k ] ?? null ) ) {
				echo wp_json_encode( array( 'error' => 'provenance mismatch — refusing to diff', 'field' => $k, 'baseline' => $bp[ $k ] ?? null, 'candidate' => $cp[ $k ] ?? null ) ) . "\n";
				return;
			}
		}

		$B = $Bdoc['rows'] ?? array();
		$C = $Cdoc['rows'] ?? array();
		$keys = array_unique( array_merge( array_keys( $B ), array_keys( $C ) ) );
		sort( $keys );

		// Candidate-side completeness: how many legacy (expect_change) rows are
		// still contained-at-render in the candidate. Reported separately from the
		// regression scan. In a no-op diff (candidate == baseline) this is just the
		// pre-change count and is informational; when the candidate IS the
		// post-change snapshot, any row still contained means the change did not
		// fire on it. The OPERATOR asserts on this against a real candidate — the
		// differ reports it, it does not guess intent.
		// The intended effect on a legacy row is TWO things: the payload becomes
		// live to a logged-out viewer (rendered_out_payload true), AND viewer
		// variance resolves (viewer_variant false — the headline benefit). A row
		// that moves its payload but stays viewer_variant is NOT complete. Rows
		// with no dangerous payload (benign_markup — needle null, out stays false)
		// are excluded: they can never move and must not count as incomplete.
		$legacy_total = 0; $legacy_still_contained = array(); $legacy_variant_unresolved = array();
		foreach ( $C as $key => $cf ) {
			if ( ( $cf['expect_change'] ?? null ) !== 'contained_to_live' ) {
				continue;
			}
			// Only rows that CARRY a detectable payload participate in "moved".
			$has_payload_capacity = strpos( $key, 'benign_markup|' ) !== 0;
			if ( ! $has_payload_capacity ) {
				continue;
			}
			$legacy_total++;
			if ( ( $cf['rendered_out_payload'] ?? null ) !== true ) {
				$legacy_still_contained[] = $key;
			}
			if ( ( $cf['viewer_variant'] ?? null ) !== false ) {
				$legacy_variant_unresolved[] = $key;
			}
		}

		$unchanged = 0; $benign = 0; $regressions = array(); $by_class = array();
		foreach ( $keys as $key ) {
			$bf = $B[ $key ] ?? null;
			$cf = $C[ $key ] ?? null;
			if ( $bf !== null && $cf !== null && wp_json_encode( $bf ) === wp_json_encode( $cf ) ) {
				$unchanged++;
				continue;
			}
			$classes = $this->classify( $bf, $cf );
			$delta = array();
			if ( $bf !== null && $cf !== null ) {
				foreach ( array_unique( array_merge( array_keys( $bf ), array_keys( $cf ) ) ) as $f ) {
					if ( ( $bf[ $f ] ?? null ) !== ( $cf[ $f ] ?? null ) ) {
						$delta[ $f ] = array( 'before' => $bf[ $f ] ?? null, 'after' => $cf[ $f ] ?? null );
					}
				}
			}
			if ( empty( $classes ) ) {
				$benign++;
				echo wp_json_encode( array( 'CHANGED' => $key, 'delta' => $delta ) ) . "\n";
				continue;
			}
			$regressions[] = $key;
			foreach ( $classes as $cl ) {
				$by_class[ $cl ] = ( $by_class[ $cl ] ?? 0 ) + 1;
			}
			echo wp_json_encode( array( 'REGRESSION' => $key, 'classes' => $classes, 'delta' => $delta ) ) . "\n";
		}
		$complete = empty( $legacy_still_contained ) && empty( $legacy_variant_unresolved );
		// THREE-STATE verdict. A green line that means "nothing happened" is the
		// single output this whole effort can least afford, so SAFE-but-incomplete
		// is visibly distinct from SAFE-and-complete, and both from STOP.
		if ( ! empty( $regressions ) ) {
			$verdict = 'STOP — ' . count( $regressions ) . ' regression(s): ' . implode( ', ', array_keys( $by_class ) );
		} elseif ( ! $complete ) {
			$verdict = 'SAFE BUT INCOMPLETE — no regression, but the intended effect did not fully land ('
				. count( $legacy_still_contained ) . ' legacy rows still contained, '
				. count( $legacy_variant_unresolved ) . ' with unresolved viewer variance)';
		} else {
			$verdict = 'SAFE AND COMPLETE — no regression; intended effect landed on every legacy row';
		}
		echo wp_json_encode( array(
			'_summary'                   => true,
			'unchanged'                  => $unchanged,
			'benign_changed'             => $benign,
			'regressions'                => count( $regressions ),
			'by_class'                   => $by_class,
			'legacy_rows'                => $legacy_total,
			'legacy_still_contained'     => count( $legacy_still_contained ),
			'legacy_variant_unresolved'  => count( $legacy_variant_unresolved ),
			'expected_effect_complete'   => $complete,
			'verdict'                    => $verdict,
		) ) . "\n";
	}

	/**
	 * SYSTEMIC COVERAGE CHECK — the fix that ends the "rule can't fire against its
	 * population" failure mode instead of patching each instance. For every gate
	 * rule, assert baseline.json contains at least one row whose fields could
	 * TRIP that rule under some candidate mutation. A rule with an empty trippable
	 * population is dead by construction (byte-compare viewer_variant with no
	 * false rows; embed_loss on legacy with no can_unfiltered:true rows) and is
	 * reported BROKEN here — mechanically, without anyone imagining the scenario.
	 *
	 * "Could trip" = the baseline-side precondition each rule tests for is
	 * satisfiable by ≥1 row, so a candidate mutation of that row would fire it.
	 */
	public function coverage() {
		$b = $this->dir . '/baseline.json';
		if ( ! file_exists( $b ) ) {
			echo wp_json_encode( array( 'error' => 'need baseline.json — run SOTRUST_MODE=baseline first' ) ) . "\n";
			return;
		}
		$rows = ( json_decode( file_get_contents( $b ), true )['rows'] ?? array() );
		$any = function ( $pred ) use ( $rows ) {
			foreach ( $rows as $r ) { if ( $pred( $r ) ) { return true; } }
			return false;
		};
		// Each rule → the baseline precondition that makes it trippable.
		$rules = array(
			// containment_leak: a row currently contained (out=false) that could go true.
			'containment_leak'        => $any( function ( $r ) { return ( $r['rendered_out_payload'] ?? null ) === false; } ),
			// embed_loss_out: a privileged-saver row currently serving out=true.
			'embed_loss_out'          => $any( function ( $r ) { return ( $r['rendered_out_payload'] ?? null ) === true && ( $r['can_unfiltered'] ?? null ) === true; } ),
			// render_loss_in: any row currently rendering in=true (incl. legacy).
			'render_loss_in'          => $any( function ( $r ) { return ( $r['rendered_in_payload'] ?? null ) === true; } ),
			// stored_loss: a row currently storing a payload.
			'stored_loss'             => $any( function ( $r ) { return ( $r['stored_has_payload'] ?? null ) === true; } ),
			// viewer_variance: a row currently invariant (false) that could go variant.
			'viewer_variance'         => $any( function ( $r ) { return ( $r['viewer_variant'] ?? null ) === false; } ),
			// disappearance / appearance: any row exists (removable / addable).
			'disappearance'           => ! empty( $rows ),
			'appearance'              => true,
			// completeness (contained): a legacy row that carries a payload.
			'completeness_contained'  => $any( function ( $r ) { return ( $r['expect_change'] ?? null ) === 'contained_to_live'; } ),
			// completeness (variance): a legacy row currently variant, so resolution is assertable.
			'completeness_variance'   => $any( function ( $r ) { return ( $r['expect_change'] ?? null ) === 'contained_to_live' && ( $r['viewer_variant'] ?? null ) === true; } ),
		);
		$dead = array();
		foreach ( $rules as $name => $ok ) { if ( ! $ok ) { $dead[] = $name; } }
		echo wp_json_encode( array(
			'_coverage' => true,
			'rules'     => $rules,
			'dead'      => $dead,
			'verdict'   => empty( $dead )
				? 'COVERAGE OK — every gate rule has a baseline row that could trip it'
				: 'COVERAGE BROKEN — rules with no trippable baseline population: ' . implode( ', ', $dead ),
		) ) . "\n";
	}

	public function run() {
		$mode = $this->mode();
		if ( $mode === 'diff' ) {
			$this->diff();
		} elseif ( $mode === 'coverage' ) {
			$this->coverage();
		} elseif ( $mode === 'baseline' || $mode === 'candidate' ) {
			$this->snapshot( $mode );
		} else {
			echo wp_json_encode( array( 'usage' => 'SOTRUST_MODE=baseline|candidate|diff|coverage wp eval-file …' ) ) . "\n";
		}
	}
}

endif;

// Auto-run only when invoked directly (not when required by the self-test).
if ( ! defined( 'SOTRUST_DIFF_NO_AUTORUN' ) ) {
	( new SiteOrigin_Render_Trust_Diff() )->run();
}
