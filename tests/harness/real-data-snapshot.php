<?php
/**
 * Real-data snapshot: what Page Builder lists, resolves and renders on a copy of a real site.
 *
 * Run it on a throwaway copy of a production database, once per plugin checkout, then compare the
 * snapshots with real-data-compare.php. Take two snapshots of the base checkout so the comparison
 * can tell render noise (captchas, nonces, random ids) from a real change.
 *
 * Usage, from the copy's site root (add --url=<site url> for each site of a multisite):
 *   SO_RD_OUT=/tmp/rd/base-1.json wp eval-file <plugin>/tests/harness/real-data-snapshot.php
 *   SO_RD_OUT=/tmp/rd/base-2.json wp eval-file <plugin>/tests/harness/real-data-snapshot.php
 *   SO_RD_OUT=/tmp/rd/change.json SO_PANELS_DIR=siteorigin-panels-wt \
 *     wp --require=<plugin>/tests/harness/with-plugin-dir.php eval-file <plugin>/tests/harness/real-data-snapshot.php
 *   php <plugin>/tests/harness/real-data-compare.php /tmp/rd/base-1.json /tmp/rd/base-2.json /tmp/rd/change.json
 *
 * The snapshot records, for the site:
 * - every widget factory entry: its key, the key's type, its class and id_base;
 * - the widget list the editor receives (SiteOrigin_Panels_Admin::get_widgets());
 * - every widget class stored in panels_data meta or in Layout Blocks, whether it resolves, whether
 *   it is in the list, and whether its form says the widget is missing;
 * - a normalised hash of the render of every post with panels_data or a Layout Block.
 *
 * Use a copy, never a live or shared site: the render runs plugin and theme code, and the snapshot
 * clears the widget-list transient. Block outbound HTTP and mail on the copy first, for example with
 * a must-use plugin returning a WP_Error from pre_http_request and false from pre_wp_mail.
 *
 * Before trusting a comparison, see it fail: register a widget with register_widget( new X() ) or
 * add a siteorigin_panels_render filter for one snapshot only, and check the comparison reports it.
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "must run via `wp eval-file`\n" );
	exit( 1 );
}

$siteorigin_rd_out = getenv( 'SO_RD_OUT' );
if ( empty( $siteorigin_rd_out ) ) {
	fwrite( STDERR, "set SO_RD_OUT to the snapshot file to write\n" );
	exit( 1 );
}

/**
 * Builds one snapshot.
 */
class SiteOrigin_Panels_Real_Data_Snapshot {

	/**
	 * The snapshot.
	 *
	 * @return array
	 */
	public function take() {
		global $wp_widget_factory;

		$snapshot = array(
			'site'       => home_url(),
			'panels_dir' => class_exists( 'SiteOrigin_Panels' ) ? dirname( ( new ReflectionClass( 'SiteOrigin_Panels' ) )->getFileName() ) : null,
			'factory'    => array(),
			'listed'     => array(),
			'stored'     => array(),
			'renders'    => array(),
			'errors'     => array(),
		);

		if ( ! class_exists( 'SiteOrigin_Panels' ) ) {
			return $snapshot;
		}

		foreach ( $wp_widget_factory->widgets as $key => $widget ) {
			$snapshot['factory'][] = array(
				'key'      => $key,
				'key_type' => gettype( $key ),
				'class'    => get_class( $widget ),
				'id_base'  => $widget->id_base,
			);
		}

		delete_transient( 'siteorigin_panels_widgets' );
		$admin  = ( new ReflectionClass( 'SiteOrigin_Panels_Admin' ) )->newInstanceWithoutConstructor();
		$listed = $admin->get_widgets();
		foreach ( $listed as $key => $entry ) {
			$snapshot['listed'][ (string) $key ] = array(
				'class'     => isset( $entry['class'] ) ? (string) $entry['class'] : null,
				'title'     => isset( $entry['title'] ) ? $entry['title'] : null,
				'installed' => ! empty( $entry['installed'] ),
			);
		}

		$classes = array();
		foreach ( $this->post_ids() as $post_id ) {
			$this->snapshot_post( $post_id, $snapshot, $classes );
		}

		ksort( $classes );
		foreach ( array_keys( $classes ) as $class ) {
			$filtered = apply_filters( 'siteorigin_panels_widget_class', $class );
			$widget   = SiteOrigin_Panels::get_widget_instance( $filtered );
			$entry    = array(
				'resolves' => $widget ? get_class( $widget ) : null,
				'listed'   => isset( $listed[ $filtered ] ),
				'missing'  => null,
			);

			try {
				$form = $admin->render_form( $filtered, array() );
				$entry['missing'] = strpos( $form, 'panels-missing-widget-form' ) !== false;
			} catch ( Throwable $e ) {
				$snapshot['errors'][] = "form $class: " . get_class( $e ) . ': ' . $e->getMessage();
			}

			$snapshot['stored'][ $class ] = $entry;
		}

		return $snapshot;
	}

	/**
	 * Every post with Page Builder meta or a Layout Block, revisions included.
	 *
	 * @return int[]
	 */
	private function post_ids() {
		global $wpdb;

		$ids = array_merge(
			$wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'panels_data'" ),
			$wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%wp:siteorigin-panels/layout-block%'" )
		);
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Record the stored widget classes and the render of one post.
	 *
	 * @param int   $post_id  The post.
	 * @param array $snapshot The snapshot.
	 * @param array $classes  Stored widget classes, as keys.
	 */
	private function snapshot_post( $post_id, array &$snapshot, array &$classes ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$meta = get_post_meta( $post_id, 'panels_data', true );
		if ( is_array( $meta ) ) {
			$this->collect_classes( $meta, $classes );
		}

		if ( has_blocks( $post->post_content ) ) {
			$stack = parse_blocks( $post->post_content );
			while ( $stack ) {
				$block = array_pop( $stack );
				if ( $block['blockName'] === 'siteorigin-panels/layout-block' && ! empty( $block['attrs']['panelsData'] ) ) {
					$this->collect_classes( $block['attrs']['panelsData'], $classes );
				}
				foreach ( $block['innerBlocks'] as $inner ) {
					$stack[] = $inner;
				}
			}
		}

		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$level = ob_get_level();
		try {
			ob_start();
			if ( is_array( $meta ) && ! empty( $meta ) ) {
				echo SiteOrigin_Panels::renderer()->render( $post_id );
			} else {
				echo apply_filters( 'the_content', $post->post_content );
			}
			$html       = ob_get_clean();
			$normalised = $this->normalise( $html );

			$snapshot['renders'][ $post_id ] = array(
				'type'    => $post->post_type,
				'bytes'   => strlen( $html ),
				'hash'    => md5( $normalised ),
				'widgets' => substr_count( $html, 'class="so-panel widget' ),
			);

			// Keep the normalised HTML when asked, to read what a flagged render changed.
			if ( getenv( 'SO_RD_HTML' ) && is_dir( getenv( 'SO_RD_HTML' ) ) ) {
				file_put_contents( getenv( 'SO_RD_HTML' ) . "/$post_id.html", $normalised );
			}
		} catch ( Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$snapshot['errors'][] = "render $post_id: " . get_class( $e ) . ': ' . $e->getMessage();
		}
		wp_reset_postdata();
	}

	/**
	 * @param mixed $panels_data A layout.
	 * @param array $classes     Stored widget classes, as keys.
	 */
	private function collect_classes( $panels_data, array &$classes ) {
		if ( empty( $panels_data['widgets'] ) || ! is_array( $panels_data['widgets'] ) ) {
			return;
		}

		foreach ( $panels_data['widgets'] as $widget ) {
			if ( isset( $widget['panels_info']['class'] ) && is_string( $widget['panels_info']['class'] ) ) {
				$classes[ $widget['panels_info']['class'] ] = true;
			}
		}
	}

	/**
	 * Mask values that change on every render: long hex ids, short hex hashes in attributes (such as a
	 * widget's instance_hash and data-hash), nonces, timestamps and numeric ids.
	 *
	 * @param string $html Rendered HTML.
	 *
	 * @return string
	 */
	private function normalise( $html ) {
		$html = preg_replace( '/[0-9a-f]{13,}/', 'X', $html );
		$html = preg_replace( '/="[0-9a-f]{8}"/', '="H"', $html );
		$html = preg_replace( '/(nonce|_wpnonce|data-nonce)(["\'=:\s]+)[0-9a-zA-Z]{8,12}/', '$1$2N', $html );
		$html = preg_replace( '/\b\d{9,}\b/', 'T', $html );

		return preg_replace( '/(id|for|aria-[a-z]+|data-[a-z-]+)="([a-z_-]*?)-?\d{3,}([^"]*)"/i', '$1="$2-#$3"', $html );
	}
}

$siteorigin_rd_snapshot = ( new SiteOrigin_Panels_Real_Data_Snapshot() )->take();
file_put_contents( $siteorigin_rd_out, wp_json_encode( $siteorigin_rd_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
printf(
	"%s: %d factory entries, %d listed, %d stored classes, %d renders, %d errors\n",
	$siteorigin_rd_snapshot['site'],
	count( $siteorigin_rd_snapshot['factory'] ),
	count( $siteorigin_rd_snapshot['listed'] ),
	count( $siteorigin_rd_snapshot['stored'] ),
	count( $siteorigin_rd_snapshot['renders'] ),
	count( $siteorigin_rd_snapshot['errors'] )
);
