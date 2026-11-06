<?php
/**
 * Detect and report HTML markup that a Layout Block save removed.
 *
 * Report only: nothing here changes what is filtered or stored. The save
 * code in compat/layout-block.php takes a copy of each widget before a
 * filtering stage and compares it with the widget after that stage. This
 * class does the comparison and fires the action.
 *
 * See https://github.com/siteorigin/siteorigin-panels/issues/1454.
 */
class SiteOrigin_Panels_Markup_Removal {

	/**
	 * The action fired when a Layout Block save removed markup.
	 */
	const ACTION = 'siteorigin_panels_layout_block_markup_removed';

	/**
	 * Elements whose content the HTML Standard parses as text (RAWTEXT and
	 * RCDATA), so tag-shaped text inside them is not markup.
	 */
	const RAW_TEXT_ELEMENTS = array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes' );

	/**
	 * An opening or self-closing tag at the scan position. Quoted attribute
	 * values are data, so a `>` inside quotes does not end the tag.
	 */
	const TAG_PATTERN = '/\G<([A-Za-z][^\s\/>]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/';

	/**
	 * One attribute: its name, then an optional value consumed whole.
	 */
	const ATTRIBUTE_PATTERN = '/([^\s"\'=<>\/`]+)(?:\s*=\s*(?:"[^"]*+"|\'[^\']*+\'|[^\s>]++))?/';

	/**
	 * Count the elements and attributes in a value.
	 *
	 * Walks arrays and skips every `panels_info` key at any depth. Each
	 * string that contains `<` is scanned left to right: comments and CDATA
	 * sections are skipped whole, the content of a raw-text or RCDATA element
	 * is skipped up to its end tag, and every opening or self-closing tag adds
	 * `t:<name>` and one `a:<name>@<attribute>` per attribute, all lowercased.
	 * Closing tags and text are not counted.
	 *
	 * @param mixed $value  A widget, a list of widgets, or any part of one.
	 * @param array $counts Counts to add to.
	 *
	 * @return array<string,int> Token => count.
	 */
	public static function inventory( $value, $counts = array() ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( $key === 'panels_info' ) {
					continue;
				}
				$counts = self::inventory( $item, $counts );
			}

			return $counts;
		}

		if ( ! is_string( $value ) || strpos( $value, '<' ) === false ) {
			return $counts;
		}

		$length = strlen( $value );
		$offset = 0;
		while ( $offset < $length ) {
			$open = strpos( $value, '<', $offset );
			if ( $open === false ) {
				break;
			}

			if ( substr( $value, $open, 4 ) === '<!--' ) {
				$end    = strpos( $value, '-->', $open + 4 );
				$offset = $end === false ? $length : $end + 3;
				continue;
			}

			if ( substr( $value, $open, 9 ) === '<![CDATA[' ) {
				$end    = strpos( $value, ']]>', $open + 9 );
				$offset = $end === false ? $length : $end + 3;
				continue;
			}

			if ( ! preg_match( self::TAG_PATTERN, $value, $tag, 0, $open ) ) {
				$offset = $open + 1;
				continue;
			}

			$name = strtolower( $tag[1] );
			$counts = self::add( $counts, 't:' . $name );
			if ( preg_match_all( self::ATTRIBUTE_PATTERN, $tag[2], $attributes ) ) {
				foreach ( $attributes[1] as $attribute ) {
					$counts = self::add( $counts, 'a:' . $name . '@' . strtolower( $attribute ) );
				}
			}

			$offset = $open + strlen( $tag[0] );

			if ( in_array( $name, self::RAW_TEXT_ELEMENTS, true ) ) {
				// The content ends at the first appropriate end tag: `</name`
				// followed by whitespace, `/` or `>`. Without one, the rest is text.
				if ( preg_match( '/<\/' . preg_quote( $name, '/' ) . '(?=[\s\/>])/i', $value, $end, PREG_OFFSET_CAPTURE, $offset ) ) {
					$offset = $end[0][1];
				} else {
					$offset = $length;
				}
			}
		}

		return $counts;
	}

	/**
	 * The tokens whose count fell, and by how much.
	 *
	 * @param array<string,int> $before Counts before a filtering stage.
	 * @param array<string,int> $after  Counts after it.
	 *
	 * @return array<string,int> Token => number lost. Gains never appear.
	 */
	public static function losses( $before, $after ) {
		$losses = array();
		foreach ( $before as $token => $count ) {
			$remaining = isset( $after[ $token ] ) ? $after[ $token ] : 0;
			if ( $remaining < $count ) {
				$losses[ $token ] = $count - $remaining;
			}
		}

		return $losses;
	}

	/**
	 * Whether any token's count fell.
	 *
	 * @param array<string,int> $before Counts before a filtering stage.
	 * @param array<string,int> $after  Counts after it.
	 *
	 * @return bool
	 */
	public static function lost( $before, $after ) {
		return ! empty( self::losses( $before, $after ) );
	}

	/**
	 * Turn one comparison into findings.
	 *
	 * 1. Pool first: one inventory over every widget on each side. When no
	 *    token's count fell, there are no findings, so markup that moved
	 *    between widgets or blocks is never reported.
	 * 2. A widget's identity is its `panels_info.widget_id`, and only when that
	 *    is a non-empty string found exactly once on each side. Keys, block
	 *    positions and classes never decide identity.
	 * 3. A widget is named for a lost token when it has identity, holds fewer
	 *    of that token after than before, and no other after-side widget that
	 *    changed, or that has no identity, still holds the token. A move always
	 *    leaves the token in its destination, so a possible move is never
	 *    named.
	 * 4. Losses no named widget accounts for, by count, add at most one
	 *    finding with null identifiers.
	 *
	 * @param array      $before_blocks  List of widget maps before the stage.
	 * @param array      $after_blocks   List of widget maps after the stage.
	 * @param string     $source         'page_builder' or 'post_save_filters'.
	 * @param array      $block_ordinals After-side list index => the `block` value to report.
	 * @param int|null   $block_index    The ability's `layout-get` block_index, or null.
	 *
	 * @return array List of findings: block, block_index, widget, widget_id, class, source.
	 */
	public static function attribute( $before_blocks, $after_blocks, $source, $block_ordinals = array(), $block_index = null ) {
		$before = self::flatten( $before_blocks );
		$after  = self::flatten( $after_blocks );

		$pool_before = array();
		foreach ( $before as $entry ) {
			$pool_before = self::inventory( $entry['widget'], $pool_before );
		}
		$pool_after = array();
		foreach ( $after as $entry ) {
			$pool_after = self::inventory( $entry['widget'], $pool_after );
		}

		$losses = self::losses( $pool_before, $pool_after );
		if ( empty( $losses ) ) {
			return array();
		}

		$before_ids = self::identities( $before );
		$after_ids  = self::identities( $after );

		$before_by_id = array();
		foreach ( $before as $entry ) {
			if ( $entry['id'] !== null && isset( $before_ids[ $entry['id'] ] ) && $before_ids[ $entry['id'] ] === 1 ) {
				$before_by_id[ $entry['id'] ] = $entry;
			}
		}

		// After-side widgets with their counts, identity and whether they changed.
		$candidates = array();
		foreach ( $after as $position => $entry ) {
			$id         = $entry['id'];
			$identified = $id !== null
				&& isset( $after_ids[ $id ] ) && $after_ids[ $id ] === 1
				&& isset( $before_by_id[ $id ] );

			$candidates[ $position ] = array(
				'entry'      => $entry,
				'identified' => $identified,
				'changed'    => ! $identified || $before_by_id[ $id ]['widget'] !== $entry['widget'],
				'before'     => $identified ? self::inventory( $before_by_id[ $id ]['widget'] ) : array(),
				'after'      => self::inventory( $entry['widget'] ),
			);
		}

		$named     = array();
		$accounted = array();
		foreach ( $losses as $token => $lost ) {
			foreach ( $candidates as $position => $candidate ) {
				if ( ! $candidate['identified'] ) {
					continue;
				}

				$had  = isset( $candidate['before'][ $token ] ) ? $candidate['before'][ $token ] : 0;
				$kept = isset( $candidate['after'][ $token ] ) ? $candidate['after'][ $token ] : 0;
				if ( $kept >= $had ) {
					continue;
				}

				$possible_move = false;
				foreach ( $candidates as $other_position => $other ) {
					if (
						$other_position !== $position &&
						( $other['changed'] || ! $other['identified'] ) &&
						! empty( $other['after'][ $token ] )
					) {
						$possible_move = true;
						break;
					}
				}
				if ( $possible_move ) {
					continue;
				}

				$named[ $position ] = true;
				$accounted[ $token ] = ( isset( $accounted[ $token ] ) ? $accounted[ $token ] : 0 ) + ( $had - $kept );
			}
		}

		$findings = array();
		foreach ( array_keys( $named ) as $position ) {
			$entry = $candidates[ $position ]['entry'];
			$block = $entry['block'];
			$findings[] = array(
				'block'       => array_key_exists( $block, $block_ordinals ) ? $block_ordinals[ $block ] : $block,
				'block_index' => $block_index,
				'widget'      => self::widget_key( $entry['key'] ),
				'widget_id'   => self::widget_id( $entry['id'] ),
				'class'       => self::widget_class( $entry['widget'] ),
				'source'      => $source,
			);
		}

		foreach ( $losses as $token => $lost ) {
			$covered = isset( $accounted[ $token ] ) ? $accounted[ $token ] : 0;
			if ( $lost > $covered ) {
				$findings[] = array(
					'block'       => null,
					'block_index' => $block_index,
					'widget'      => null,
					'widget_id'   => null,
					'class'       => null,
					'source'      => $source,
				);
				break;
			}
		}

		return $findings;
	}

	/**
	 * Fire the action for one detecting hook's findings.
	 *
	 * Fires `siteorigin_panels_layout_block_markup_removed` during a Layout Block
	 * save, before the database write, when the save removed HTML markup from a
	 * widget. It reports; it never changes what is filtered or stored.
	 *
	 * What counts as removed: an element name, or an element and attribute name
	 * pair, occurs fewer times after a filtering stage than before it. Comments,
	 * CDATA and the content of raw-text elements (script, style, textarea,
	 * title, xmp, iframe, noembed, noframes) are not markup, and `panels_info`
	 * is skipped. Known limits: a value-only change, such as a stripped
	 * `javascript:` protocol, is not reported; a removal offset by an addition
	 * of the same name in the same comparison is not reported, which includes
	 * markup moved between widgets; an unquoted value containing quotes, or
	 * other malformed markup, can miscount.
	 *
	 * Sources. `page_builder`: removed by Page Builder's own save filtering
	 * (widget update() with its widget_update_callback chain, the data
	 * migration filter, the kses floor). `post_save_filters`: present in the
	 * content passed to wp_insert_post() and missing when it reaches Page
	 * Builder's wp_insert_post_data check, or removed by the same save filters
	 * when a layout-update ability write settles its stored form. That is core
	 * kses or any other content filter at that point, named for the boundary,
	 * not for a cause.
	 *
	 * Each detecting hook fires at most once per call, and `context` names it:
	 * `rest_pre_insert`, `wp_insert_post_data` or `ability`. A REST save normally
	 * fires once; it fires again from `wp_insert_post_data` only when a save
	 * filter later removed markup Page Builder kept. A save that fails after
	 * filtering still reports, because the filtering happened.
	 *
	 * Not covered: block widget areas, classic (meta) layouts, attachment posts,
	 * and writes that bypass wp_insert_post(). Identifiers only: no markup and
	 * no field values are passed.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param array $report {
	 *     @type int    $post_id The post's ID, or 0 for a post that does not exist yet.
	 *     @type string $context The hook that detected the loss.
	 *     @type array  $widgets List of findings, each with: `block` (0-based
	 *                           document-order ordinal of the Layout Block, nested
	 *                           and empty blocks included, or null), `block_index`
	 *                           (the layout-get block_index for `ability`, or null),
	 *                           `widget` (the widget's key, or null), `widget_id`,
	 *                           `class` and `source`.
	 * }
	 *
	 * @param int    $post_id The post's ID, or 0.
	 * @param string $context The detecting hook.
	 * @param array  $widgets Findings from attribute().
	 */
	public static function fire( $post_id, $context, $widgets ) {
		if ( empty( $widgets ) ) {
			return;
		}

		try {
			do_action(
				self::ACTION,
				array(
					'post_id' => (int) $post_id,
					'context' => (string) $context,
					'widgets' => array_values( $widgets ),
				)
			);
		} catch ( Throwable $e ) {
			// A listener cannot break the save. No WordPress hook or notice runs
			// here: one could throw again and replace an exception the save is
			// already carrying.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'SiteOrigin Page Builder: a ' . self::ACTION . ' callback threw ' . get_class( $e ) . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Add one to a token's count.
	 *
	 * @param array  $counts Token => count.
	 * @param string $token  The token.
	 *
	 * @return array
	 */
	private static function add( $counts, $token ) {
		$counts[ $token ] = ( isset( $counts[ $token ] ) ? $counts[ $token ] : 0 ) + 1;

		return $counts;
	}

	/**
	 * Flatten a list of widget maps into entries.
	 *
	 * @param array $blocks List of widget maps.
	 *
	 * @return array List of { block, key, id, widget }.
	 */
	private static function flatten( $blocks ) {
		$entries = array();
		if ( ! is_array( $blocks ) ) {
			return $entries;
		}

		foreach ( array_values( $blocks ) as $block => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $key => $widget ) {
				if ( ! is_array( $widget ) ) {
					continue;
				}
				$id = isset( $widget['panels_info']['widget_id'] ) && is_string( $widget['panels_info']['widget_id'] ) && $widget['panels_info']['widget_id'] !== ''
					? $widget['panels_info']['widget_id']
					: null;

				$entries[] = array(
					'block'  => $block,
					'key'    => $key,
					'id'     => $id,
					'widget' => $widget,
				);
			}
		}

		return $entries;
	}

	/**
	 * How often each widget_id occurs on one side.
	 *
	 * @param array $entries Entries from flatten().
	 *
	 * @return array<string,int>
	 */
	private static function identities( $entries ) {
		$counts = array();
		foreach ( $entries as $entry ) {
			if ( $entry['id'] !== null ) {
				$counts[ $entry['id'] ] = ( isset( $counts[ $entry['id'] ] ) ? $counts[ $entry['id'] ] : 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * A widget key safe to pass on: an int as is, a short plain string, or null.
	 *
	 * @param mixed $key The widgets array key.
	 *
	 * @return int|string|null
	 */
	private static function widget_key( $key ) {
		if ( is_int( $key ) ) {
			return $key;
		}

		return is_string( $key ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $key ) ? $key : null;
	}

	/**
	 * A widget_id reduced to `[A-Za-z0-9_-]`, or null.
	 *
	 * @param mixed $id The widget_id.
	 *
	 * @return string|null
	 */
	private static function widget_id( $id ) {
		if ( ! is_string( $id ) ) {
			return null;
		}
		$id = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $id ), 0, 64 );

		return $id === '' ? null : $id;
	}

	/**
	 * A widget's class reduced to `[A-Za-z0-9_\\]`, or null.
	 *
	 * @param array $widget The widget.
	 *
	 * @return string|null
	 */
	private static function widget_class( $widget ) {
		if ( ! isset( $widget['panels_info']['class'] ) || ! is_string( $widget['panels_info']['class'] ) ) {
			return null;
		}
		$class = substr( preg_replace( '/[^A-Za-z0-9_\\\\]/', '', $widget['panels_info']['class'] ), 0, 128 );

		return $class === '' ? null : $class;
	}
}
