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
 * - which Page Builder checkout ran: its directory, and its git commit and uncommitted changes;
 * - every widget factory entry: its key, the key's type, its class and id_base;
 * - the widget list the editor receives, every field of every entry (SiteOrigin_Panels_Admin::get_widgets(),
 *   with its filters);
 * - every widget class stored in panels_data meta, in Layout Blocks (in posts and in widget areas)
 *   and in Layout Builder widgets, nested layouts included: whether it resolves, whether it is in
 *   the list, and whether its form says the widget is missing;
 * - a normalised hash of the render of every post with panels_data or a Layout Block, and of every
 *   widget-area Layout Block and Layout Builder widget. The byte and widget counts beside each hash
 *   are there to read a reported change; only the hash is compared.
 *
 * Use a copy, never a live or shared site: the render runs plugin and theme code, and the snapshot
 * clears the widget-list transient. Block outbound HTTP and mail on the copy first, for example with
 * a must-use plugin returning a WP_Error from pre_http_request and false from pre_wp_mail.
 *
 * Before trusting a comparison, see it fail: register a widget with register_widget( new X() ) or
 * add a siteorigin_panels_render filter for one snapshot only, and check the comparison reports it
 * (pass --same-checkout to the compare for such a control).
 *
 * @package siteorigin-panels
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "must run via `wp eval-file`\n" );
	exit( 1 );
}

if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! method_exists( 'WP_HTML_Tag_Processor', 'has_class' ) ) {
	fwrite( STDERR, "needs WordPress 6.4 or later, for WP_HTML_Tag_Processor::has_class()\n" );
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
	 * When the snapshot started, to tell uniqid() stamps from other hex.
	 *
	 * @var int
	 */
	private $now;

	/**
	 * The snapshot.
	 *
	 * @return array
	 */
	public function take() {
		global $wp_widget_factory;

		$this->now  = time();
		$panels_dir = class_exists( 'SiteOrigin_Panels' ) ? dirname( ( new ReflectionClass( 'SiteOrigin_Panels' ) )->getFileName() ) : null;
		$snapshot   = array(
			'site'       => home_url(),
			'panels_dir' => $panels_dir,
			'panels_rev' => $this->revision( $panels_dir ),
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
				'key'        => $key,
				'key_type'   => gettype( $key ),
				// Whether WordPress made the key from this object, so it changes between requests.
				'object_key' => $this->is_object_key( $key, $widget ),
				'class'      => get_class( $widget ),
				'id_base'    => $widget->id_base,
			);
		}

		// The singleton the plugin itself uses, so the widget dialog's filters (recommended widgets)
		// shape the list as they do in the editor.
		delete_transient( 'siteorigin_panels_widgets' );
		$admin  = SiteOrigin_Panels_Admin::single();
		$listed = $admin->get_widgets();
		foreach ( $listed as $key => $entry ) {
			$snapshot['listed'][ (string) $key ] = $entry;
		}

		$classes = array();
		foreach ( $this->post_ids() as $post_id ) {
			$this->snapshot_post( $post_id, $snapshot, $classes );
		}
		$this->snapshot_widget_areas( $snapshot, $classes );

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
	 * Whether a factory key is one WordPress makes from the widget object itself: spl_object_hash()
	 * before 7.1, the integer spl_object_id() in 7.1.0, 'spl_object_id:' . spl_object_id() from 7.1.1.
	 *
	 * @param int|string $key    The factory key.
	 * @param object     $widget The widget.
	 *
	 * @return bool
	 */
	private function is_object_key( $key, $widget ) {
		if ( $key === spl_object_hash( $widget ) ) {
			return true;
		}

		return function_exists( 'spl_object_id' ) && in_array( $key, array( spl_object_id( $widget ), 'spl_object_id:' . spl_object_id( $widget ) ), true );
	}

	/**
	 * The checkout's git commit and a hash of its uncommitted changes, so two snapshots of one
	 * directory on different branches still differ.
	 *
	 * @param string|null $dir The plugin directory.
	 *
	 * @return string|null
	 */
	private function revision( $dir ) {
		if ( ! $dir || ! function_exists( 'shell_exec' ) ) {
			return null;
		}

		$git  = 'git -C ' . escapeshellarg( $dir ) . ' ';
		$head = trim( (string) shell_exec( $git . 'rev-parse HEAD 2>/dev/null' ) );
		if ( $head === '' ) {
			return null;
		}

		$dirty = (string) shell_exec( $git . 'diff HEAD 2>/dev/null' );

		return $dirty === '' ? $head : $head . '+' . md5( $dirty );
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
		$this->collect_block_classes( $post->post_content, $classes );

		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$this->record_render(
			(string) $post_id,
			$post->post_type,
			function () use ( $post_id, $post, $meta ) {
				if ( is_array( $meta ) && ! empty( $meta ) ) {
					echo SiteOrigin_Panels::renderer()->render( $post_id );
				} else {
					echo apply_filters( 'the_content', $post->post_content );
				}
			},
			$snapshot
		);
		wp_reset_postdata();
	}

	/**
	 * Record Layout Blocks in block widgets and Layout Builder widgets, which live in options rather
	 * than posts.
	 *
	 * @param array $snapshot The snapshot.
	 * @param array $classes  Stored widget classes, as keys.
	 */
	private function snapshot_widget_areas( array &$snapshot, array &$classes ) {
		foreach ( (array) get_option( 'widget_block', array() ) as $number => $instance ) {
			if ( ! is_array( $instance ) || empty( $instance['content'] ) || strpos( $instance['content'], 'wp:siteorigin-panels/layout-block' ) === false ) {
				continue;
			}

			$this->collect_block_classes( $instance['content'], $classes );
			$this->record_render(
				"widget_block-$number",
				'widget_block',
				function () use ( $instance ) {
					echo do_blocks( $instance['content'] );
				},
				$snapshot
			);
		}

		foreach ( (array) get_option( 'widget_siteorigin-panels-builder', array() ) as $number => $instance ) {
			if ( ! is_array( $instance ) || empty( $instance['panels_data'] ) ) {
				continue;
			}

			$this->collect_classes( $instance['panels_data'], $classes );
			$this->record_render(
				"widget_siteorigin-panels-builder-$number",
				'siteorigin-panels-builder',
				function () use ( $instance ) {
					the_widget( 'SiteOrigin_Panels_Widgets_Layout', $instance );
				},
				$snapshot
			);
		}
	}

	/**
	 * Render into the snapshot, recording a throw as an error.
	 *
	 * @param string   $id       The render's id in the snapshot.
	 * @param string   $type     What was rendered.
	 * @param callable $render   Echoes the render.
	 * @param array    $snapshot The snapshot.
	 */
	private function record_render( $id, $type, $render, array &$snapshot ) {
		// Every render keeps a hash: what it printed, plus the error if it threw. A render that can't
		// be masked keeps its unmasked hash, and real-data-compare.php refuses the comparison.
		//
		// The capture buffer's handler keeps every chunk it is given, so output the render flushes
		// with ob_flush() is captured too, and returns nothing, so none of it reaches the page. A
		// chunk handed over to be cleaned is output the render threw away. Once the render is
		// abandoned, the handler passes output on, so the error that stops the snapshot is seen.
		$level     = ob_get_level();
		$error     = '';
		$html      = '';
		$closed    = false;
		$capturing = true;
		ob_start(
			function ( $chunk, $phase ) use ( &$html, &$closed, &$capturing ) {
				if ( ! $capturing ) {
					return $chunk;
				}
				if ( ! ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
					$html .= $chunk;
				}
				if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
					$closed = true;
				}
				return '';
			}
		);
		try {
			$render();
		} catch ( Throwable $e ) {
			$error                = get_class( $e ) . ': ' . $e->getMessage();
			$snapshot['errors'][] = "render $id: $error";
		}
		if ( $closed ) {
			$capturing = false;
			throw new RuntimeException( "render $id closed the buffer capturing it, so later output escaped" );
		}
		// Flush every buffer the render left open into the capture buffer, then the capture buffer
		// into its handler. A buffer that can't be removed would leave the level unchanged, so stop
		// rather than loop.
		while ( ob_get_level() > $level ) {
			$before = ob_get_level();
			ob_end_flush();
			if ( ob_get_level() >= $before ) {
				$capturing = false;
				throw new RuntimeException( "render $id left an output buffer that can't be removed" );
			}
		}

		try {
			$normalised = $this->normalise( $html );
		} catch ( Throwable $e ) {
			// real-data-compare.php refuses a comparison with this error, as an unmasked render can
			// differ between base snapshots and drop out as noise.
			$normalised           = $html;
			$snapshot['errors'][] = "normalise $id: " . $e->getMessage();
		}

		$snapshot['renders'][ $id ] = array(
			'type'    => $type,
			'bytes'   => strlen( $html ),
			'hash'    => md5( $normalised . ( $error === '' ? '' : "\n<!-- render error: $error -->" ) ),
			'widgets' => substr_count( $html, 'class="so-panel widget' ),
		);

		// Keep the normalised HTML when asked, to read what a flagged render changed.
		if ( getenv( 'SO_RD_HTML' ) && is_dir( getenv( 'SO_RD_HTML' ) ) ) {
			file_put_contents( getenv( 'SO_RD_HTML' ) . "/$id.html", $normalised );
		}
	}


	/**
	 * Collect the classes of every Layout Block in some block content.
	 *
	 * @param string $content Block content.
	 * @param array  $classes Stored widget classes, as keys.
	 */
	private function collect_block_classes( $content, array &$classes ) {
		if ( ! has_blocks( $content ) ) {
			return;
		}

		$stack = parse_blocks( $content );
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

	/**
	 * Collect the widget classes of a layout, and of every layout nested in its widgets.
	 *
	 * @param mixed $panels_data A layout, as an array or JSON.
	 * @param array $classes     Stored widget classes, as keys.
	 * @param int   $depth       How deep the layout is nested.
	 */
	private function collect_classes( $panels_data, array &$classes, $depth = 0 ) {
		if ( is_string( $panels_data ) ) {
			$panels_data = json_decode( $panels_data, true );
		}
		if ( $depth > 20 || empty( $panels_data['widgets'] ) || ! is_array( $panels_data['widgets'] ) ) {
			return;
		}

		foreach ( $panels_data['widgets'] as $widget ) {
			if ( ! is_array( $widget ) ) {
				continue;
			}
			// Older layouts keep the class under info; it is read when panels_info is empty, as
			// SiteOrigin_Panels::process_panels_data() does before rendering.
			$info = empty( $widget['panels_info'] ) && ! empty( $widget['info'] ) ? $widget['info'] : ( isset( $widget['panels_info'] ) ? $widget['panels_info'] : array() );
			if ( isset( $info['class'] ) && is_string( $info['class'] ) ) {
				$classes[ $info['class'] ] = true;
			}
			if ( ! empty( $widget['panels_data'] ) ) {
				$this->collect_classes( $widget['panels_data'], $classes, $depth + 1 );
			}
		}
	}

	/**
	 * Whether the tag processor is on a contact form's hidden instance_hash input holding an
	 * 8-character hash.
	 *
	 * @param WP_HTML_Tag_Processor $tags The processor, on a tag.
	 *
	 * @return bool
	 */
	private function is_contact_hash_input( WP_HTML_Tag_Processor $tags ) {
		$type  = $tags->get_attribute( 'type' );
		$name  = $tags->get_attribute( 'name' );
		$value = $tags->get_attribute( 'value' );

		return $tags->get_tag() === 'INPUT' &&
			is_string( $type ) && strtolower( $type ) === 'hidden' &&
			is_string( $name ) && preg_match( '/^instance_hash(?:-\d+)?$/', $name ) &&
			is_string( $value ) && preg_match( '/^[0-9a-f]{8}$/', $value );
	}

	/**
	 * Mask the Widgets Bundle's per-render values, read from tags by WordPress's own tag processor,
	 * so quoting, spacing and attribute order are read as a browser reads them and the text of
	 * script and style elements is never taken for tags. A contact form's id comes before the field
	 * holding its hash, so the hashes are collected first.
	 *
	 * @param string $html Rendered HTML.
	 * @param int    $now  The snapshot time.
	 *
	 * @return string
	 *
	 */
	private function normalise_tags( $html, $now ) {
		$short = array();
		$tags  = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'INPUT' ) ) {
			if ( $this->is_contact_hash_input( $tags ) ) {
				$short[ substr( $tags->get_attribute( 'value' ), 0, 4 ) ] = true;
			}
		}

		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( $tags->has_class( 'sow-google-map-canvas' ) ) {
				$options = $tags->get_attribute( 'data-options' );
				if ( is_string( $options ) ) {
					$masked = $this->checked( preg_replace_callback(
						'/("id":")([0-9a-f]{6})(")/',
						function ( $id ) use ( $now ) {
							return abs( hexdec( $id[2] ) * 256 - $now ) < 2 * DAY_IN_SECONDS ? $id[1] . 'U' . $id[3] : $id[0];
						},
						$options
					) );
					if ( $masked !== $options ) {
						$tags->set_attribute( 'data-options', $masked );
					}
				}
			}

			$hash = $tags->get_attribute( 'data-hash' );
			if ( $tags->has_class( 'sow-blog' ) && is_string( $hash ) && preg_match( '/^[0-9a-f]{8}$/', $hash ) ) {
				$tags->set_attribute( 'data-hash', 'H' );
			}

			if ( $this->is_contact_hash_input( $tags ) ) {
				$tags->set_attribute( 'value', 'H' );
			}

			$id = $tags->get_attribute( 'id' );
			if ( is_string( $id ) && preg_match( '/^contact-form-([0-9a-f]{4})$/', $id, $short_hash ) && isset( $short[ $short_hash[1] ] ) ) {
				$tags->set_attribute( 'id', 'contact-form-H' );
			}
		}

		// At an unfinished tag (a trailing <!-- or an unclosed <script>) the processor stops; the HTML
		// before it is masked and the rest is kept as written, so it is still compared.
		return $tags->get_updated_html();
	}

	/**
	 * Mask the values seen to change between two renders of the same post, and nothing else, so any
	 * other difference in a render is compared:
	 * - uniqid() stamps in layout, row, cell and widget ids and selectors: 13 hex digits whose first
	 *   8 are the current Unix time, with any more-entropy digits after them, and a Google Map
	 *   widget's generated map id in its canvas's data-options, the first 6 of those digits
	 *   (substr( uniqid(), 0, 6 )), which changes every 256 seconds;
	 * - the Widgets Bundle's per-render storage hash, only where it is written: a contact form's
	 *   instance_hash field and its contact-form-xxxx id (the hash's first 4 characters), and a blog
	 *   widget's data-hash on its sow-blog element;
	 * - nonces in fields named for them.
	 *
	 * @param string $html Rendered HTML.
	 *
	 * @return string
	 */
	private function normalise( $html ) {
		$now  = $this->now;
		$html = $this->checked( preg_replace_callback(
			'/[0-9a-f]{13}(?:\.?\d{1,10})?/',
			function ( $stamp ) use ( $now ) {
				return abs( hexdec( substr( $stamp[0], 0, 8 ) ) - $now ) < 2 * DAY_IN_SECONDS ? 'U' : $stamp[0];
			},
			$html
		) );
		$html = $this->normalise_tags( $html, $now );

		return $this->checked( preg_replace( '/(nonce|_wpnonce|data-nonce)(["\'=:\s]+(?:value=["\'])?)[0-9a-zA-Z]{8,12}/', '$1$2N', $html ) );
	}

	/**
	 * Stop on a failed regex, so the render is hashed unmasked and recorded as an error rather than
	 * hashed as empty.
	 *
	 * @param mixed $result A preg function's result.
	 *
	 * @return mixed The result.
	 *
	 * @throws RuntimeException When the regex failed.
	 */
	private function checked( $result ) {
		if ( $result === null || $result === false ) {
			throw new RuntimeException( 'normalise failed, preg error ' . preg_last_error() );
		}

		return $result;
	}
}

$siteorigin_rd_snapshot = ( new SiteOrigin_Panels_Real_Data_Snapshot() )->take();
if ( file_put_contents( $siteorigin_rd_out, wp_json_encode( $siteorigin_rd_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) === false ) {
	fwrite( STDERR, "could not write $siteorigin_rd_out\n" );
	exit( 1 );
}
printf(
	"%s: %d factory entries, %d listed, %d stored classes, %d renders, %d errors\n",
	$siteorigin_rd_snapshot['site'],
	count( $siteorigin_rd_snapshot['factory'] ),
	count( $siteorigin_rd_snapshot['listed'] ),
	count( $siteorigin_rd_snapshot['stored'] ),
	count( $siteorigin_rd_snapshot['renders'] ),
	count( $siteorigin_rd_snapshot['errors'] )
);
