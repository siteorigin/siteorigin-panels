<?php
/**
 * Batch endpoints for the parity generator test. Loaded by the parity mu-plugin, only when the
 * Playground blueprint defines PANELS_PARITY_HARNESS.
 *
 * GET  /?parity=generate              the generated cases (i, kind, note, json) for seed, count, from, to.
 * POST wp-admin/admin-ajax.php?action=panels_parity_batch_save
 *      Saves each case through the plugin's own save handlers, as the X-Parity-User user:
 *      classic = the builder field on a post save (the plugin's save_post hook);
 *      block   = a Layout Block in the post content (wp_update_post).
 *      The save handler is wired only in an admin request, so this is an admin-ajax request.
 * POST /?parity=batch_render
 *      Renders each value on the renderer the X-Parity-Renderer header pins. No database write.
 *      The renderer is chosen once per request (SiteOrigin_Panels::renderer() keeps it), so the
 *      harness sends one request per renderer.
 */

if ( ! defined( 'PANELS_PARITY_HARNESS' ) || ! PANELS_PARITY_HARNESS ) {
	return;
}

require_once __DIR__ . '/generator.php';

/**
 * The cases a request asks for: generated from a seed, or a private corpus sent in the body
 * ({ corpus: [ { key, storage: meta|block|widget, serialized_b64 | post_content_b64 } ] }).
 */
function panels_parity_batch_cases( $body ) {
	if ( ! empty( $body['corpus'] ) && is_array( $body['corpus'] ) ) {
		$cases = array();

		foreach ( $body['corpus'] as $n => $item ) {
			$case = array(
				'i'       => isset( $item['i'] ) ? $item['i'] : $n,
				'kind'    => $item['storage'],
				'note'    => isset( $item['key'] ) ? $item['key'] : '',
				'storage' => $item['storage'],
				'json'    => null,
			);

			if ( $item['storage'] === 'meta' ) {
				// A re-save of the stored value: the builder field carries it as JSON.
				$value        = @unserialize( base64_decode( $item['serialized_b64'] ) );
				$case['json'] = is_array( $value ) ? wp_json_encode( $value ) : null;
			}
			$cases[] = $case;
		}

		return $cases;
	}

	$seed  = isset( $body['seed'] ) ? (int) $body['seed'] : 20261002;
	$count = isset( $body['count'] ) ? (int) $body['count'] : 1000;
	$from  = isset( $body['from'] ) ? (int) $body['from'] : 0;
	$to    = isset( $body['to'] ) ? (int) $body['to'] : $count;

	return array_slice( parity_generate( $seed, $count ), $from, max( 0, $to - $from ) );
}

/**
 * Two scratch posts (classic, block) owned by the current user. Made once per user and site.
 */
function panels_parity_scratch_posts() {
	$uid  = get_current_user_id();
	$all  = get_option( 'panels_parity_scratch', array() );
	$all  = is_array( $all ) ? $all : array();
	$mine = isset( $all[ $uid ] ) ? $all[ $uid ] : array();

	foreach ( array( 'classic', 'block' ) as $path ) {
		if ( empty( $mine[ $path ] ) || ! get_post( $mine[ $path ] ) ) {
			$mine[ $path ] = wp_insert_post(
				array(
					'post_title'  => "Parity scratch $path",
					'post_status' => 'publish',
					'post_type'   => 'post',
					'post_author' => $uid,
					'post_date'   => '2026-01-15 10:00:00',
				)
			);
		}
	}
	$all[ $uid ] = $mine;
	update_option( 'panels_parity_scratch', $all, false );

	return $mine;
}

/**
 * A save handler that throws part-way can leave the plugin's re-entry guard set. Clear it, so the
 * next case is saved as in a new request.
 */
function panels_parity_reset_save_guard() {
	if ( class_exists( 'SiteOrigin_Panels_Admin' ) && property_exists( 'SiteOrigin_Panels_Admin', 'in_save_post' ) ) {
		$prop = new ReflectionProperty( 'SiteOrigin_Panels_Admin', 'in_save_post' );
		$prop->setAccessible( true );
		$prop->setValue( SiteOrigin_Panels_Admin::single(), false );
	}
}

function panels_parity_batch_send( $data ) {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	panels_parity_send( $data );
}

add_action(
	'wp_ajax_panels_parity_batch_save',
	function () {
		global $wpdb;
		@ini_set( 'display_errors', '0' );
		ob_start();

		$body    = json_decode( file_get_contents( 'php://input' ), true );
		$cases   = panels_parity_batch_cases( $body );
		$scratch = panels_parity_scratch_posts();
		$out     = array();

		// No revision rows for the scratch posts: they are not compared and slow every save.
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		foreach ( $cases as $case ) {
			$rec = array( 'i' => $case['i'] );

			// classic: the builder field on a post save.
			$json = $case['json'];

			if ( $json !== null ) {
				$id = (int) $scratch['classic'];
				$wpdb->delete(
					$wpdb->postmeta,
					array(
						'post_id'  => $id,
						'meta_key' => 'panels_data',
					)
				);
				clean_post_cache( $id );
				$exception = null;

				try {
					$_POST['panels_data']     = wp_slash( $json );
					$_POST['_sopanels_nonce'] = wp_create_nonce( 'save' );
					wp_update_post( array( 'ID' => $id ) );
				} catch ( \Throwable $e ) {
					$exception = get_class( $e );
					panels_parity_reset_save_guard();
				}
				unset( $_POST['panels_data'], $_POST['_sopanels_nonce'] );

				$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'panels_data'", $id ) );
				$value = count( $rows ) === 1 ? @unserialize( $rows[0] ) : false;

				$rec['classic'] = array(
					'accepted'  => count( $rows ) === 1 && is_array( $value ),
					'bytes'     => count( $rows ) === 1 ? base64_encode( $rows[0] ) : null,
					'rows'      => count( $rows ),
					'exception' => $exception,
				);
			}

			// block: a Layout Block in the post content.
			if ( isset( $case['data'] ) && empty( $case['storage'] ) ) {
				$id      = (int) $scratch['block'];
				$content = '<!-- wp:siteorigin-panels/layout-block ' . serialize_block_attributes( array( 'panelsData' => $case['data'] ) ) . ' /-->';
				$wpdb->update( $wpdb->posts, array( 'post_content' => '' ), array( 'ID' => $id ) );
				clean_post_cache( $id );
				$exception = null;
				$result    = null;

				try {
					$result = wp_update_post(
						array(
							'ID'           => $id,
							'post_content' => wp_slash( $content ),
						),
						true
					);
				} catch ( \Throwable $e ) {
					$exception = get_class( $e );
				}

				$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );

				$rec['block'] = array(
					'accepted'  => $result === $id && strpos( $stored, 'wp:siteorigin-panels/layout-block' ) !== false,
					'bytes'     => base64_encode( $stored ),
					'error'     => is_wp_error( $result ) ? $result->get_error_code() : null,
					'exception' => $exception,
				);
			}

			$out[] = $rec;
		}

		panels_parity_batch_send(
			array(
				'user'    => get_current_user_id(),
				'scratch' => $scratch,
				'cases'   => $out,
			)
		);
	}
);

add_action(
	'wp_loaded',
	function () {
		if ( empty( $_GET['parity'] ) || ! in_array( $_GET['parity'], array( 'generate', 'batch_render' ), true ) ) {
			return;
		}
		@ini_set( 'display_errors', '0' );
		ob_start();

		$body = json_decode( file_get_contents( 'php://input' ), true );

		if ( $_GET['parity'] === 'generate' ) {
			$cases = panels_parity_batch_cases( array_map( 'intval', wp_unslash( $_GET ) ) );
			$out   = array();

			foreach ( $cases as $case ) {
				$out[] = array(
					'i'    => $case['i'],
					'kind' => $case['kind'],
					'note' => $case['note'],
					'json' => $case['json'],
				);
			}
			panels_parity_batch_send( $out );
		}

		// batch_render: { items: [ { key, kind: raw|classic|block|widget, bytes_b64, post_id } ] }
		// post_id is the scratch post the value belongs to (the render id of a classic value, the global
		// post of a block value).
		$out = array();

		foreach ( (array) $body['items'] as $item ) {
			$bytes = base64_decode( $item['bytes_b64'] );
			$rec   = array(
				'key'       => $item['key'],
				'html'      => null,
				'css'       => null,
				'exception' => null,
			);

			// Each value is rendered as if it were the only layout of the request.
			$GLOBALS['siteorigin_panels_cache']        = array();
			$GLOBALS['siteorigin_panels_current_post'] = null;

			try {
				if ( $item['kind'] === 'widget' ) {
					// A stored Layout Builder widget instance, rendered by the widget itself.
					ob_start();
					the_widget(
						'SiteOrigin_Panels_Widgets_Layout',
						@unserialize( $bytes ),
						array(
							'before_widget' => '<div class="parity-widget">',
							'after_widget'  => '</div>',
						)
					);
					$rec['html'] = ob_get_clean();
				} elseif ( $item['kind'] === 'block' ) {
					$GLOBALS['post'] = get_post( (int) $item['post_id'] );
					setup_postdata( $GLOBALS['post'] );
					$rec['html'] = apply_filters( 'the_content', $bytes );
					wp_reset_postdata();
				} else {
					$id          = (int) $item['post_id'];
					$panels_data = $item['kind'] === 'raw' ? json_decode( $bytes, true ) : @unserialize( $bytes );
					$rec['html'] = SiteOrigin_Panels::renderer()->render( $id, true, $panels_data );
					$rec['css']  = SiteOrigin_Panels::renderer()->generate_css( $id, $panels_data );
				}
			} catch ( \Throwable $e ) {
				$rec['exception'] = get_class( $e );
			}
			$out[] = $rec;
		}

		panels_parity_batch_send(
			array(
				'renderer' => get_class( SiteOrigin_Panels::renderer() ),
				'items'    => $out,
			)
		);
	},
	9997
);
