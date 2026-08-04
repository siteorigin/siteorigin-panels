<?php
/**
 * Render-trust integration harness.
 *
 * Run against a REAL, live WordPress install (not a mocked WP_UnitTestCase),
 * because the behaviour under test lives in WordPress core — core's kses floor
 * on block attributes, the real widget update() sanitizers, the real Panels
 * chokepoint. A mock cannot see any of that; that blind spot is exactly what
 * produced several rounds of contradictory conclusions in the research phase.
 *
 * Contract: this file characterises OBSERVED behaviour. It plants a payload,
 * saves it as a given role, renders it as a logged-out visitor, and records
 * what bytes are served. It asserts nothing about right vs wrong on its own —
 * the runner compares two code states (baseline vs a proposed change) and
 * reports the diff. Ground truth is core's actual output, never the harness's
 * expectation.
 *
 * Usage:
 *   wp eval-file tests/harness/render-trust-harness.php
 * Emits one JSON object per line (JSONL) to stdout: the full observation matrix.
 * All probe posts/users are created under a unique run prefix; a normal run
 * deletes them, and every run first sweeps any leftovers from a prior fatal
 * (see sweep_leftovers()), so the harness is self-cleaning.
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "must run via `wp eval-file`\n" );
	exit( 1 );
}

class SiteOrigin_Render_Trust_Harness {

	/** Stable prefix shared by every run, so a fatal run's fixtures are sweepable. */
	const PREFIX = 'sotrust_';

	/** Unique per-run tag so concurrent/serial runs never collide. */
	private $run_tag;

	/** Collected observations, one row per probe. */
	private $rows = array();

	/** Cached probe user IDs by role. */
	private $users = array();

	public function __construct() {
		$this->run_tag = self::PREFIX . substr( md5( uniqid( 'h', true ) ), 0, 10 );
	}

	/**
	 * Payload matrix. `class` is for human-readable reporting only, never to
	 * gate an assertion — ground truth is what core actually serves.
	 */
	private function payloads() {
		return array(
			'script'        => array( 'markup' => '<script>alert(1)</script>',                     'class' => 'dangerous' ),
			'iframe'        => array( 'markup' => '<iframe src="https://embed.example/f"></iframe>', 'class' => 'embed' ),
			'img_onerror'   => array( 'markup' => '<img src=x onerror=alert(2)>',                   'class' => 'dangerous' ),
			'svg_onload'    => array( 'markup' => '<svg onload=alert(3)></svg>',                    'class' => 'dangerous' ),
			'form'          => array( 'markup' => '<form action="//e"><input name=a></form>',       'class' => 'embed' ),
			'a_js'          => array( 'markup' => '<a href="javascript:alert(4)">x</a>',             'class' => 'dangerous' ),
			'benign_markup' => array( 'markup' => '<b>keep</b> <em>me</em>',                         'class' => 'benign' ),
		);
	}

	private function roles() {
		return array( 'administrator', 'editor', 'author', 'contributor', 'user0' );
	}

	/**
	 * Widget classes to plant the payload in. WP_Widget_Custom_HTML alone is
	 * unrepresentative: its update() IS the viewer-caps kses call (the 2.34.4
	 * bug), so a matrix built only on it overstates how much the strict render
	 * path protects. The SOWB Editor Widget is the customer-visible symptom
	 * (blank JotForm/iframe embeds) and carries a real logged-in-vs-out
	 * viewer-variance that core-only coverage cannot see.
	 */
	private function widget_classes() {
		$w = array( 'WP_Widget_Custom_HTML' );
		if ( class_exists( 'SiteOrigin_Widget_Editor_Widget' ) ) {
			$w[] = 'SiteOrigin_Widget_Editor_Widget';
		}
		return $w;
	}

	private function ensure_user( $role ) {
		if ( 'user0' === $role ) {
			return 0;
		}
		if ( isset( $this->users[ $role ] ) ) {
			return $this->users[ $role ];
		}
		$uid = wp_insert_user( array(
			'user_login' => $this->run_tag . '_' . $role,
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => $role,
		) );
		return is_wp_error( $uid ) ? 0 : ( $this->users[ $role ] = $uid );
	}

	/**
	 * Build a Layout Block carrying $markup in one widget of class $widget_class.
	 * panels_info mirrors the real stored shape (class,grid,cell,id,widget_id) so
	 * the renderer emits no undefined-index warnings — a malformed fixture both
	 * pollutes the JSONL and characterises a widget shape that never occurs in
	 * real data. Built via serialize_block() so the delimiter is canonical.
	 */
	private function build_block( $markup, $widget_class ) {
		$wid = $this->run_tag . '-w';
		$widget = array(
			'title'   => '',
			'text'    => $markup,   // WP_Widget_Custom_HTML content field
			'content' => $markup,   // SOWB Editor Widget content field
			'panels_info' => array(
				'class'     => $widget_class,
				'grid'      => 0,
				'cell'      => 0,
				'id'        => 0,
				'widget_id' => $wid,
				'style'     => array(),
			),
		);
		$attrs = array(
			'panelsData' => array(
				'widgets'    => array( $widget ),
				'grids'      => array( array( 'cells' => 1, 'style' => array() ) ),
				'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
			),
		);

		return serialize_block( array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		) );
	}

	private function stored_panels_data( $post_id ) {
		foreach ( parse_blocks( get_post_field( 'post_content', $post_id ) ) as $b ) {
			if ( ! empty( $b['blockName'] ) && $b['blockName'] === 'siteorigin-panels/layout-block' ) {
				return isset( $b['attrs']['panelsData'] ) ? $b['attrs']['panelsData'] : null;
			}
		}
		return null;
	}

	private function widget_text( $pd ) {
		if ( isset( $pd['widgets'][0]['text'] ) ) {
			return $pd['widgets'][0]['text'];
		}
		if ( isset( $pd['widgets'][0]['content'] ) ) {
			return $pd['widgets'][0]['content'];
		}
		return null;
	}

	/**
	 * Render as a given viewer and return served HTML. Note: we do NOT re-arm
	 * kses here (asymmetric with probe()) and that is deliberate — kses_init_filters()
	 * attaches SAVE-time hooks only; no render-time filter is capability-armed,
	 * so re-arming would change nothing. Do not "fix" this asymmetry by adding a
	 * render-time kses filter.
	 */
	private function render_as( $post_id, $viewer_uid ) {
		$prev = get_current_user_id();
		wp_set_current_user( $viewer_uid );
		$html = do_blocks( get_post_field( 'post_content', $post_id ) );
		wp_set_current_user( $prev );
		return $html;
	}

	private function danger_needle( $payload_key ) {
		switch ( $payload_key ) {
			case 'script':      return '<script>alert(1)';
			case 'iframe':      return '<iframe';
			case 'img_onerror': return 'onerror';
			case 'svg_onload':  return 'onload';
			case 'form':        return '<form';
			case 'a_js':        return 'javascript:';
			default:            return null;
		}
	}

	/** Save one payload/widget as one role, render as logged-out + logged-in. */
	private function probe( $pk, $payload, $role, $widget_class ) {
		$uid  = $this->ensure_user( $role );
		$prev = get_current_user_id();
		wp_set_current_user( $uid );
		// LOAD-BEARING — do NOT delete as redundant. WP-CLI (`wp eval-file`, the
		// only way to run this harness) leaves core's kses UNARMED at init:
		// measured on this install, `content_save_pre` has no `wp_filter_post_kses`
		// under WP-CLI for user 0, whereas plain PHP (`require wp-load.php`) arms
		// it TRUE. Web requests and real WP-Cron (both HTTP) arm it correctly.
		// Without this manual arm, the harness would run in a fantasy state where
		// core never floors markup, silently inverting every result. This line
		// restores the real-world (HTTP) save path. Verified 2026-07-28.
		// (It is NOT simulating something unrealistic — it repairs a WP-CLI-only
		// defect. See drop-signing-fanout-prompts.md "WP-CLI kses arming".)
		if ( function_exists( 'kses_init' ) ) {
			kses_init();
		}

		$row = array(
			'payload'        => $pk,
			'class'          => $payload['class'],
			'role'           => $role,
			'widget'         => $widget_class,
			'can_unfiltered' => (bool) current_user_can( 'unfiltered_html' ),
			'kses_armed'     => (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
		);

		$post_id = wp_insert_post( array(
			'post_title'   => $this->run_tag . '_' . $pk . '_' . $role . '_' . $widget_class,
			'post_content' => wp_slash( $this->build_block( $payload['markup'], $widget_class ) ),
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );
		$row['saved'] = ( $post_id && ! is_wp_error( $post_id ) );

		if ( $row['saved'] ) {
			$needle = $this->danger_needle( $pk );
			$pd     = $this->stored_panels_data( $post_id );
			$text   = $this->widget_text( $pd );
			$out_o  = $this->render_as( $post_id, 0 );          // logged-out visitor
			$out_i  = $this->render_as( $post_id, $this->admin_viewer() ); // logged-in admin viewer

			$row['stored_text']          = $text;
			$row['stored_has_payload']   = ( $needle && $text !== null && strpos( $text, $needle ) !== false );
			$row['rendered_out_payload'] = ( $needle && strpos( $out_o, $needle ) !== false );
			$row['rendered_in_payload']  = ( $needle && strpos( $out_i, $needle ) !== false );
			// Viewer variance, corrected (Session 9): whether the PAYLOAD's fate
			// differs by viewer login state — NOT a raw byte compare of the two
			// renders, which is unsatisfiable noise (uniqid()/nonce differ almost
			// always, so md5($out_o)!==md5($out_i) is ~true everywhere and can
			// never discriminate). This is computed from the two payload booleans
			// already recorded, and is 0 on chokepointed content by design.
			$row['viewer_variant']       = ( $row['rendered_out_payload'] !== $row['rendered_in_payload'] );

			wp_delete_post( $post_id, true );
		}

		wp_set_current_user( $prev );
		return $row;
	}

	/**
	 * The user-0 re-save probe — the hard gate. Plant iframe as admin (stored
	 * raw), then a user-0 wp_update_post() title tweak (cron/importer model),
	 * and record the MECHANISM, not just before/after bytes: the mechanism is
	 * the finding. Adjudicates Session 8 R4, which claimed this is byte-identical
	 * today (it is NOT — core kses strips the iframe before the chokepoint runs).
	 */
	private function probe_user0_resave( $widget_class ) {
		$admin = $this->admin_viewer();
		$prev  = get_current_user_id();

		wp_set_current_user( $admin );
		if ( function_exists( 'kses_init' ) ) { kses_init(); }
		$post_id = wp_insert_post( array(
			'post_title'   => $this->run_tag . '_resave_' . $widget_class,
			'post_content' => wp_slash( $this->build_block( '<iframe src="https://embed.example/f"></iframe>', $widget_class ) ),
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		$before      = get_post_field( 'post_content', $post_id );
		$pd_before   = $this->stored_panels_data( $post_id );
		$iframe_before = ( strpos( wp_json_encode( $pd_before ), '<iframe' ) !== false );
		$sig_before  = ! empty( $pd_before['sanitize_signature'] );

		// user-0 title tweak, kses correctly re-armed (as real cron/init does).
		wp_set_current_user( 0 );
		if ( function_exists( 'kses_init' ) ) { kses_init(); }
		$kses_armed = (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $this->run_tag . '_resave_tweaked' ) );

		$after       = get_post_field( 'post_content', $post_id );
		$pd_after    = $this->stored_panels_data( $post_id );
		$iframe_after = ( strpos( wp_json_encode( $pd_after ), '<iframe' ) !== false );

		wp_delete_post( $post_id, true );
		wp_set_current_user( $prev );

		return array(
			'probe'             => 'user0_resave',
			'widget'            => $widget_class,
			'kses_armed'        => $kses_armed,
			'iframe_before'     => $iframe_before,
			'sig_before'        => $sig_before,
			'iframe_after'      => $iframe_after,
			'iframe_survived'   => $iframe_after,
			'bytes_identical'   => ( $before === $after ),
			// The R4 adjudication: if the iframe did NOT survive on today's code
			// with the signature present, then user-0 loss is the STATUS QUO,
			// not a regression the signature removal introduces.
			'r4_verdict'        => $iframe_after ? 'R4-HOLDS (iframe survived)' : 'R4-REFUTED (loss is status quo, sig did not prevent it)',
		);
	}

	private function admin_viewer() {
		static $a;
		if ( $a === null ) {
			$ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$a = ! empty( $ids ) ? (int) $ids[0] : 0;
		}
		return $a;
	}

	/** Delete any fixtures left by a prior fatal run (stable PREFIX). */
	private function sweep_leftovers() {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			's'              => self::PREFIX,
		) );
		$swept_posts = 0;
		foreach ( $posts as $pid ) {
			if ( strpos( get_the_title( $pid ), self::PREFIX ) === 0 ) {
				wp_delete_post( $pid, true );
				$swept_posts++;
			}
		}
		$users = get_users( array( 'search' => self::PREFIX . '*', 'fields' => 'ID' ) );
		$swept_users = 0;
		foreach ( $users as $uid ) {
			wp_delete_user( $uid );
			$swept_users++;
		}
		return array( $swept_posts, $swept_users );
	}

	private function cleanup() {
		foreach ( $this->users as $uid ) {
			if ( $uid ) {
				wp_delete_user( $uid );
			}
		}
	}

	/**
	 * Plant UNSIGNED / legacy / chokepoint-bypass content directly via $wpdb, so
	 * it never passes through the save chokepoint and carries no signature — the
	 * E1/E2 class that is the ONLY content the signature-removal change affects.
	 * Without these rows the diff runs entirely on signed content, which already
	 * takes the trusted structural path today (Session 9 Q6: TODAY == PROPOSED on
	 * chokepointed content), so a signed-only matrix would report SAFE while
	 * proving nothing about the change.
	 *
	 * These rows are tagged `expect_change => 'contained_to_live'`: on the CURRENT
	 * code the payload is stripped at render (fail-closed strict sanitize of
	 * unsigned content); on the PROPOSED code render is unconditionally structural
	 * so the stored payload is served. That contained→live move is the intended
	 * effect of the change on this class, asserted up front, not an override.
	 */
	private function probe_unsigned( $pk, $payload, $widget_class ) {
		global $wpdb;
		$needle = $this->danger_needle( $pk );
		// Build a block whose stored panelsData carries the payload raw and has NO
		// signature (direct DB write bypasses sanitize + sign entirely).
		$content = $this->build_block( $payload['markup'], $widget_class );
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->posts, array(
			'post_author'       => $this->admin_viewer(),
			'post_date'         => $now,
			'post_date_gmt'     => get_gmt_from_date( $now ),
			'post_content'      => $content, // RAW, unslashed, unsigned — as a legacy/SQLi row would be
			'post_title'        => $this->run_tag . '_unsigned_' . $pk . '_' . $widget_class,
			'post_status'       => 'publish',
			'post_name'         => $this->run_tag . '-unsigned-' . $pk,
			'post_type'         => 'post',
			'post_modified'     => $now,
			'post_modified_gmt' => get_gmt_from_date( $now ),
		) );
		$post_id = (int) $wpdb->insert_id;

		$row = array(
			'payload'       => $pk,
			'class'         => $payload['class'],
			'role'          => 'unsigned_dbwrite',
			'widget'        => $widget_class,
			'signed'        => false,
			'expect_change' => 'contained_to_live',
		);

		if ( $post_id ) {
			clean_post_cache( $post_id );
			$pd    = $this->stored_panels_data( $post_id );
			$text  = $this->widget_text( $pd );
			$out_o = $this->render_as( $post_id, 0 );
			$out_i = $this->render_as( $post_id, $this->admin_viewer() );

			$row['saved']                = true;
			$row['sig_present']          = ! empty( $pd['sanitize_signature'] );
			$row['stored_has_payload']   = ( $needle && $text !== null && strpos( $text, $needle ) !== false );
			$row['rendered_out_payload'] = ( $needle && strpos( $out_o, $needle ) !== false );
			$row['rendered_in_payload']  = ( $needle && strpos( $out_i, $needle ) !== false );
			$row['viewer_variant']       = ( $row['rendered_out_payload'] !== $row['rendered_in_payload'] );

			wp_delete_post( $post_id, true );
		} else {
			$row['saved'] = false;
		}
		return $row;
	}

	public function run() {
		$this->swept = $this->sweep_leftovers();
		foreach ( $this->widget_classes() as $wc ) {
			foreach ( $this->payloads() as $pk => $p ) {
				foreach ( $this->roles() as $role ) {
					$this->rows[] = $this->probe( $pk, $p, $role, $wc );
				}
				// The E1/E2 class: unsigned content the change actually affects.
				$this->rows[] = $this->probe_unsigned( $pk, $p, $wc );
			}
			$this->rows[] = $this->probe_user0_resave( $wc );
		}
		$this->cleanup();
		return $this->rows;
	}

	public function emit() {
		echo wp_json_encode( array(
			'_meta'   => true,
			'run_tag' => $this->run_tag,
			'wp'      => get_bloginfo( 'version' ),
			'panels'  => defined( 'SITEORIGIN_PANELS_VERSION' ) ? SITEORIGIN_PANELS_VERSION : '?',
			'sowb'    => class_exists( 'SiteOrigin_Widgets_Bundle' ),
			'widgets' => $this->widget_classes(),
			'swept'   => isset( $this->swept ) ? $this->swept : array( 0, 0 ),
			'rows'    => count( $this->rows ),
		) ) . "\n";
		foreach ( $this->rows as $r ) {
			echo wp_json_encode( $r ) . "\n";
		}
	}

	private $swept;
}

$h = new SiteOrigin_Render_Trust_Harness();
$h->run();
$h->emit();
