<?php
/**
 * Self-test for the diff runner's regression gate.
 *
 * Standing rule: the gate must be proven to fire once per failure class before
 * it is trusted. An earlier gate passed three real regressions as "SAFE" because
 * its only control exercised the one branch it tested (tautological). This seeds
 * ONE control per class into a candidate snapshot and asserts the gate flags it,
 * plus a no-op control that must stay SAFE.
 *
 * Run: SOTRUST_MODE=baseline wp eval-file tests/harness/diff-runner.php   # once
 *      wp eval-file tests/harness/diff-runner-selftest.php
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "must run via `wp eval-file`\n" );
	exit( 1 );
}

// Load the class without triggering its auto-run.
define( 'SOTRUST_DIFF_NO_AUTORUN', true );
require __DIR__ . '/diff-runner.php';

$dir = __DIR__ . '/snapshots';
$base_path = $dir . '/baseline.json';
$cand_path = $dir . '/candidate.json';
if ( ! file_exists( $base_path ) ) {
	fwrite( STDERR, "run SOTRUST_MODE=baseline first\n" );
	exit( 1 );
}
$orig_base = file_get_contents( $base_path );
$base = json_decode( $orig_base, true );
$rows = $base['rows'];
$keys = array_keys( $rows );

$find = function ( $needle ) use ( $keys ) {
	foreach ( $keys as $k ) { if ( strpos( $k, $needle ) !== false ) { return $k; } }
	return null;
};
$admin_row  = $find( '|administrator|' );
$lowcap_row = $find( '|contributor|' );
$any_row    = $keys[0];

$runner = new SiteOrigin_Render_Trust_Diff();

/** Write a (baseline, candidate) pair, run diff, return the parsed _summary. */
$run_case = function ( $base_doc, $cand_doc ) use ( $runner, $base_path, $cand_path ) {
	file_put_contents( $base_path, wp_json_encode( $base_doc, JSON_PRETTY_PRINT ) );
	file_put_contents( $cand_path, wp_json_encode( $cand_doc, JSON_PRETTY_PRINT ) );
	ob_start();
	$runner->diff();
	$out = ob_get_clean();
	$summary = null;
	foreach ( explode( "\n", trim( $out ) ) as $l ) {
		$o = json_decode( $l, true );
		if ( is_array( $o ) && ! empty( $o['_summary'] ) ) { $summary = $o; }
	}
	return $summary;
};

$mk = function ( $mutator ) use ( $base, $rows ) {
	$doc = $base;
	$doc['rows'] = $mutator( json_decode( json_encode( $rows ), true ) );
	return $doc;
};

$results = array();

// 0. No-op: candidate == baseline → SAFE, zero regressions.
$s = $run_case( $base, $base );
$results['noop_stays_safe'] = ( $s && (int) $s['regressions'] === 0 );

// 1. containment_leak — low-cap payload becomes live.
$c = $mk( function ( $r ) use ( $lowcap_row ) { $r[ $lowcap_row ]['rendered_out_payload'] = true; return $r; } );
$s = $run_case( $base, $c );
$results['containment_leak'] = ( $s && ! empty( $s['by_class']['containment_leak'] ) );

// 2a. embed_loss_out — privileged embed stops rendering to logged-out visitors.
$b_embed = $mk( function ( $r ) use ( $admin_row ) { $r[ $admin_row ]['rendered_out_payload'] = true; $r[ $admin_row ]['can_unfiltered'] = true; return $r; } );
$c_embed = $mk( function ( $r ) use ( $admin_row ) { $r[ $admin_row ]['rendered_out_payload'] = false; $r[ $admin_row ]['can_unfiltered'] = true; return $r; } );
$s = $run_case( $b_embed, $c_embed );
$results['embed_loss_out'] = ( $s && ! empty( $s['by_class']['embed_loss_out'] ) );

// 2b. render_loss_in — content stops rendering to a logged-in viewer, fires even
//     without can_unfiltered (must cover the legacy population). This is the
//     control for the hole where a legacy row moved out but lost its in-render.
$in_row = null;
foreach ( array_keys( $rows ) as $k ) { if ( ( $rows[ $k ]['rendered_in_payload'] ?? null ) === true ) { $in_row = $k; break; } }
if ( $in_row ) {
	$b_in = $mk( function ( $r ) use ( $in_row ) { $r[ $in_row ]['rendered_in_payload'] = true; return $r; } );
	$c_in = $mk( function ( $r ) use ( $in_row ) { $r[ $in_row ]['rendered_in_payload'] = false; return $r; } );
	$s = $run_case( $b_in, $c_in );
	$results['render_loss_in'] = ( $s && ! empty( $s['by_class']['render_loss_in'] ) );
} else {
	$results['render_loss_in'] = false;
}

// 3. stored_loss — stored payload true → false.
$b_st = $mk( function ( $r ) use ( $admin_row ) { $r[ $admin_row ]['stored_has_payload'] = true; return $r; } );
$c_st = $mk( function ( $r ) use ( $admin_row ) { $r[ $admin_row ]['stored_has_payload'] = false; return $r; } );
$s = $run_case( $b_st, $c_st );
$results['stored_loss'] = ( $s && ! empty( $s['by_class']['stored_loss'] ) );

// 4. disappearance — key removed from candidate.
$c = $mk( function ( $r ) use ( $lowcap_row ) { unset( $r[ $lowcap_row ] ); return $r; } );
$s = $run_case( $base, $c );
$results['disappearance'] = ( $s && ! empty( $s['by_class']['disappearance'] ) );

// 5. appearance — unexpected new key in candidate.
$c = $mk( function ( $r ) { $r['ghost|role|Widget'] = array( 'rendered_out_payload' => false ); return $r; } );
$s = $run_case( $base, $c );
$results['appearance'] = ( $s && ! empty( $s['by_class']['appearance'] ) );

// 6. viewer_variance — false → true.
$b_vv = $mk( function ( $r ) use ( $any_row ) { $r[ $any_row ]['viewer_variant'] = false; return $r; } );
$c_vv = $mk( function ( $r ) use ( $any_row ) { $r[ $any_row ]['viewer_variant'] = true; return $r; } );
$s = $run_case( $b_vv, $c_vv );
$results['viewer_variance'] = ( $s && ! empty( $s['by_class']['viewer_variance'] ) );

// 7. expected_change HONORED — an unsigned row that moves contained→live is
//    benign (the intended effect), NOT a regression.
$unsigned_row = $find( '|unsigned_dbwrite|' );
if ( $unsigned_row ) {
	$b_ec = $mk( function ( $r ) use ( $unsigned_row ) { $r[ $unsigned_row ]['expect_change'] = 'contained_to_live'; $r[ $unsigned_row ]['rendered_out_payload'] = false; return $r; } );
	$c_ec = $mk( function ( $r ) use ( $unsigned_row ) { $r[ $unsigned_row ]['expect_change'] = 'contained_to_live'; $r[ $unsigned_row ]['rendered_out_payload'] = true; return $r; } );
	$s = $run_case( $b_ec, $c_ec );
	$results['expected_move_is_not_a_regression'] = ( $s && (int) $s['regressions'] === 0 );

	// 8. STALLED legacy row surfaces via the completeness signal, NOT as a
	//    regression, and must NOT wrongly fail a byte-identical (no-op) diff.
	//    "Did the change fire on every legacy row" is a candidate-side check, not
	//    a per-pair regression — so it never fails a diff where nothing moved.
	$b_st = $mk( function ( $r ) use ( $unsigned_row ) { $r[ $unsigned_row ]['expect_change'] = 'contained_to_live'; $r[ $unsigned_row ]['rendered_out_payload'] = false; return $r; } );
	$s = $run_case( $b_st, $b_st ); // identical candidate — nothing moved
	$results['stalled_legacy_is_incomplete_not_regression'] =
		( $s && (int) $s['regressions'] === 0 && $s['expected_effect_complete'] === false && (int) $s['legacy_still_contained'] >= 1 );

	// 9. expected move + collateral stored loss — the intended move is suppressed,
	//    but the stored_loss on the SAME row must still flag (fall-through works).
	$b_col = $mk( function ( $r ) use ( $unsigned_row ) { $r[ $unsigned_row ]['expect_change'] = 'contained_to_live'; $r[ $unsigned_row ]['rendered_out_payload'] = false; $r[ $unsigned_row ]['stored_has_payload'] = true; return $r; } );
	$c_col = $mk( function ( $r ) use ( $unsigned_row ) { $r[ $unsigned_row ]['expect_change'] = 'contained_to_live'; $r[ $unsigned_row ]['rendered_out_payload'] = true; $r[ $unsigned_row ]['stored_has_payload'] = false; return $r; } );
	$s = $run_case( $b_col, $c_col );
	$results['expected_move_with_collateral_stored_loss_flags'] = ( $s && ! empty( $s['by_class']['stored_loss'] ) );
} else {
	// A missing fixture must FAIL the suite loudly, not pass by default. false,
	// not a truthy string — a suite that green-lights on absent coverage is the
	// same failure class this whole gate exists to prevent.
	$results['expected_move_is_not_a_regression']                = false;
	$results['stalled_legacy_is_incomplete_not_regression']      = false;
	$results['expected_move_with_collateral_stored_loss_flags']  = false;
}

// 10. COVERAGE self-check — the systemic guard must itself be proven: a baseline
//     with a dead rule (no row that could trip it) must be flagged BROKEN, and
//     the real baseline must pass. This is what ends the "rule can't fire against
//     its population" class — assert it works before trusting it.
$runner_cov = new SiteOrigin_Render_Trust_Diff();
// (a) real baseline → COVERAGE OK.
file_put_contents( $base_path, $orig_base );
ob_start(); $runner_cov->coverage(); $cov_ok = ob_get_clean();
$co = null; foreach ( explode( "\n", trim( $cov_ok ) ) as $l ) { $o = json_decode( $l, true ); if ( ! empty( $o['_coverage'] ) ) { $co = $o; } }
$results['coverage_passes_real_baseline'] = ( $co && empty( $co['dead'] ) );
// (b) baseline stripped of every viewer_variant:false row → viewer_variance rule
//     becomes dead (exactly the byte-compare bug) → must be flagged.
$dead_doc = json_decode( $orig_base, true );
foreach ( $dead_doc['rows'] as $k => &$r ) { if ( array_key_exists( 'viewer_variant', $r ) ) { $r['viewer_variant'] = true; } }
unset( $r );
file_put_contents( $base_path, wp_json_encode( $dead_doc, JSON_PRETTY_PRINT ) );
ob_start(); $runner_cov->coverage(); $cov_dead = ob_get_clean();
$cd = null; foreach ( explode( "\n", trim( $cov_dead ) ) as $l ) { $o = json_decode( $l, true ); if ( ! empty( $o['_coverage'] ) ) { $cd = $o; } }
$results['coverage_flags_dead_rule'] = ( $cd && in_array( 'viewer_variance', $cd['dead'], true ) );

// Restore the clean baseline and remove the scratch candidate.
file_put_contents( $base_path, $orig_base );
@unlink( $cand_path );

// Pass requires EVERY result to be strictly true — any false, string, or other
// value fails. (in_array(false,...) alone would let a stray non-true string pass.)
$all = true;
foreach ( $results as $v ) {
	if ( $v !== true ) { $all = false; break; }
}
echo wp_json_encode( array(
	'selftest' => $results,
	'verdict'  => $all ? 'GATE TRUSTWORTHY — every class fires, no-op stays safe' : 'GATE BROKEN — a control did not behave',
) ) . "\n";
