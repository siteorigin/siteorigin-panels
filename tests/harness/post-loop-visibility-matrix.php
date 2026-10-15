<?php
/**
 * Post Loop viewer visibility harness.
 *
 * Renders the Post Loop widget for a matrix of viewers, contexts and query arguments on a live
 * local site, and reports any fixture the viewer cannot read_post that still appears in the output.
 *
 * Usage, from the site root (the active theme needs a content.php loop template, e.g. Vantage):
 *   wp eval-file <plugin>/tests/harness/post-loop-visibility-matrix.php
 * To run another checkout of the plugin (see with-plugin-dir.php):
 *   SO_PANELS_DIR=siteorigin-panels-wt wp --require=<plugin>/tests/harness/with-plugin-dir.php eval-file <plugin>/tests/harness/post-loop-visibility-matrix.php
 *
 * Prints one line per viewer x context x case and a final `leaks=<n>` line. Exits 1 on any leak.
 * Every fixture carries a unique run token and is removed at the end, even on failure.
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "must run via `wp eval-file`\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

class SiteOrigin_Panels_Post_Loop_Visibility_Harness {

	/** Unique per-run token, letters and digits only so titles render unchanged. */
	private $token;

	/** Fixture post IDs keyed by marker. */
	private $fixtures = array();

	/** Created user IDs. */
	private $users = array();

	private $admin_id = 0;
	private $contributor_id = 0;

	/** Synthetic capability mode for the Contributor, or '' for none. */
	private $cap_mode = '';

	private $leaks = 0;
	private $cleaned = false;

	public function __construct() {
		$this->token = 'ZZPLV' . strtoupper( substr( md5( uniqid( 'plv', true ) ), 0, 10 ) );
	}

	public function run() {
		$this->setup();

		add_filter( 'user_has_cap', array( $this, 'synthetic_caps' ), 10, 4 );
		$_POST['action'] = 'so_panels_layout_block_preview';

		$viewers = array(
			array( 'contributor', $this->contributor_id, '' ),
			array( 'anonymous', 0, '' ),
			array( 'administrator', $this->admin_id, '' ),
			array( 'contributor+edit_others_posts', $this->contributor_id, 'others-no-published' ),
			array( 'contributor+edit_others_posts+edit_published_posts', $this->contributor_id, 'others-published-no-private' ),
			array( 'contributor+read_private_posts', $this->contributor_id, 'private-only' ),
		);

		foreach ( array( 'front', 'admin' ) as $context ) {
			if ( $context === 'admin' && ! defined( 'WP_ADMIN' ) ) {
				define( 'WP_ADMIN', true );
			}
			foreach ( $viewers as $viewer ) {
				list( $label, $user_id, $mode ) = $viewer;
				if ( $context === 'admin' && $user_id === 0 ) {
					continue;
				}
				$this->cap_mode = $mode;
				wp_set_current_user( $user_id );
				echo "--- as $label, $context context\n";
				foreach ( $this->cases() as $case ) {
					$this->render_case( $context, $case );
				}
			}
		}

		$this->cap_mode = '';
		remove_filter( 'user_has_cap', array( $this, 'synthetic_caps' ), 10 );
		echo 'leaks=' . $this->leaks . "\n";

		return $this->leaks;
	}

	private function cases() {
		return array(
			'',
			'post_status=any',
			'post_status=draft,pending,future,private&post_type=any',
			'post_status=private',
			'post_status=future',
			'post_status=future&post_type=post',
			'post_type=any',
			'post_type=page',
			'post_type=attachment',
			'post_type=attachment&post_status=inherit',
			'post_type=attachment&post_status=inherit&suppress_filters=1',
			'post_status=draft&p=' . $this->fixtures['DRAFT'],
			'post_status=draft&posts_per_page=3',
		);
	}

	private function render_case( $context, $additional ) {
		$widget = new SiteOrigin_Panels_Widgets_PostLoop();
		ob_start();
		$widget->widget(
			array( 'before_widget' => '', 'after_widget' => '', 'before_title' => '', 'after_title' => '' ),
			array( 'title' => '', 'template' => 'content.php', 'additional' => $additional, 'posts_per_page' => 500 )
		);
		$html = ob_get_clean();

		$markers = array();
		$leaked = array();
		foreach ( $this->fixtures as $marker => $post_id ) {
			if ( $marker === 'ATT-PRIV-PARENT' ) {
				$present = strpos( $html, 'id="post-' . $post_id . '"' ) !== false;
			} else {
				$present = strpos( $html, $this->token . 'K' . $marker . 'Z' ) !== false;
			}
			if ( ! $present ) {
				continue;
			}
			$markers[] = $marker;
			if ( ! current_user_can( 'read_post', $post_id ) ) {
				$leaked[] = $marker;
			}
		}
		$rows = preg_match_all( '/post-\d+ /', $html, $m ) ? count( $m[0] ) : 0;

		$this->leaks += count( $leaked );
		$case_label = $additional === '' ? '(absent)' : preg_replace( '/&p=\d+/', '&p=<other draft>', $additional );
		printf(
			"%s %-5s %-66s => %s rows=%d%s\n",
			$leaked ? 'FAIL' : 'PASS',
			$context,
			$case_label,
			$markers ? implode( ' ', $markers ) : '-',
			$rows,
			$leaked ? ' LEAK: ' . implode( ' ', $leaked ) : ''
		);
	}

	public function synthetic_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $this->cap_mode || (int) $user->ID !== $this->contributor_id ) {
			return $allcaps;
		}
		if ( $this->cap_mode === 'others-no-published' ) {
			$allcaps['edit_others_posts'] = true;
			unset( $allcaps['edit_published_posts'], $allcaps['read_private_posts'] );
		} elseif ( $this->cap_mode === 'others-published-no-private' ) {
			$allcaps['edit_others_posts'] = true;
			$allcaps['edit_published_posts'] = true;
			unset( $allcaps['read_private_posts'] );
		} elseif ( $this->cap_mode === 'private-only' ) {
			$allcaps['read_private_posts'] = true;
			unset( $allcaps['edit_others_posts'] );
		}
		return $allcaps;
	}

	private function title( $marker ) {
		return $this->token . 'K' . $marker . 'Z';
	}

	private function setup() {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
		if ( empty( $admins ) ) {
			throw new RuntimeException( 'No administrator user on this site.' );
		}
		$this->admin_id = (int) $admins[0];
		wp_set_current_user( $this->admin_id );

		$contributor = wp_insert_user( array(
			'user_login' => strtolower( $this->token ) . 'contrib',
			'user_email' => strtolower( $this->token ) . '@example.invalid',
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'contributor',
		) );
		if ( is_wp_error( $contributor ) ) {
			throw new RuntimeException( 'Could not create the Contributor: ' . $contributor->get_error_message() );
		}
		$this->contributor_id = (int) $contributor;
		$this->users[] = $this->contributor_id;

		$future_date = ( (int) gmdate( 'Y' ) + 1 ) . '-06-15 12:00:00';
		$this->fixtures['DRAFT'] = $this->insert_post( 'DRAFT', 'draft', $this->admin_id );
		$this->fixtures['PENDING'] = $this->insert_post( 'PENDING', 'pending', $this->admin_id );
		$this->fixtures['FUTURE'] = $this->insert_post( 'FUTURE', 'future', $this->admin_id, $future_date );
		$this->fixtures['PRIVATE'] = $this->insert_post( 'PRIVATE', 'private', $this->admin_id );
		$this->fixtures['OWNDRAFT'] = $this->insert_post( 'OWNDRAFT', 'draft', $this->contributor_id );

		$attachment = wp_insert_attachment( array(
			'post_title'     => $this->title( 'ATT-PRIV-PARENT' ),
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_author'    => $this->admin_id,
			'guid'           => 'https://example.invalid/' . strtolower( $this->token ) . '.png',
		), false, $this->fixtures['PRIVATE'], true );
		if ( is_wp_error( $attachment ) || ! $attachment ) {
			throw new RuntimeException( 'Could not create the attachment.' );
		}
		$this->fixtures['ATT-PRIV-PARENT'] = (int) $attachment;

		if ( get_post_status( $this->fixtures['FUTURE'] ) !== 'future' ) {
			throw new RuntimeException( 'The scheduled fixture is not in the future status.' );
		}
		echo "token={$this->token} fixtures=" . wp_json_encode( $this->fixtures ) . "\n";
	}

	private function insert_post( $marker, $status, $author, $date = '' ) {
		$post = array(
			'post_title'   => $this->title( $marker ),
			'post_content' => 'Post Loop visibility fixture.',
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => $author,
		);
		if ( $date ) {
			$post['post_date'] = $date;
			$post['post_date_gmt'] = get_gmt_from_date( $date );
		}
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) || ! $id ) {
			throw new RuntimeException( "Could not create the $marker fixture." );
		}
		return (int) $id;
	}

	public function cleanup() {
		if ( $this->cleaned ) {
			return;
		}
		$this->cleaned = true;
		wp_set_current_user( $this->admin_id );
		if ( isset( $this->fixtures['ATT-PRIV-PARENT'] ) ) {
			wp_delete_attachment( $this->fixtures['ATT-PRIV-PARENT'], true );
			unset( $this->fixtures['ATT-PRIV-PARENT'] );
		}
		foreach ( $this->fixtures as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->fixtures = array();
		$this->users = array();
		echo "cleanup done\n";
	}
}

$so_plv_harness = new SiteOrigin_Panels_Post_Loop_Visibility_Harness();
// Backstop for fatals: the shutdown handler removes fixtures if the finally block never ran.
register_shutdown_function( array( $so_plv_harness, 'cleanup' ) );
$so_plv_leaks = 1;
try {
	$so_plv_leaks = $so_plv_harness->run();
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Harness error: ' . $e->getMessage() . "\n" );
	$so_plv_leaks = 1;
} finally {
	$so_plv_harness->cleanup();
}
exit( $so_plv_leaks > 0 ? 1 : 0 );
