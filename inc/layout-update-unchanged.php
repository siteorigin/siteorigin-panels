<?php
/**
 * Widgets a layout-update caller sent back unchanged.
 *
 * Both layout-update write paths (the classic meta write in inc/abilities.php
 * and the Layout Block save in compat/layout-block.php) use this class to find
 * the incoming widgets that equal a stored widget, keep those widgets' stored
 * values, and filter every other widget. "Unchanged" is decided only by
 * comparing with the stored layout; nothing the caller sends can claim it.
 *
 * See https://github.com/siteorigin/siteorigin-panels/issues/1409.
 */
class SiteOrigin_Panels_Layout_Update_Unchanged {

	/**
	 * Top-level keys the sidebars emulator writes on every widget.
	 */
	const EMULATOR_KEYS = array( 'so_sidebar_emulator_id', 'option_name' );

	/**
	 * panels_info keys that place a widget. cell_index is derived from them
	 * and added by the siteorigin_panels_data read filter.
	 */
	const POSITION_KEYS = array( 'grid', 'cell', 'id', 'cell_index' );

	/**
	 * The panels_info keys that decide whether a widget moved.
	 */
	const PLACEMENT_KEYS = array( 'grid', 'cell', 'id' );

	/**
	 * Whether a value can be walked without looping: it holds no cycle.
	 *
	 * Cycles among arrays exist only through PHP references, and PHP copies a
	 * reference with a single holder as a plain value, so a userland walker
	 * cannot always see one. count() with COUNT_RECURSIVE uses PHP's own
	 * recursion guard and warns when it re-enters an array it is inside; that
	 * warning marks the value as cyclic. Cycles through plain objects are found
	 * by tracking the objects on the current path. Objects of other classes
	 * are not descended. There is no depth limit.
	 *
	 * @param mixed $value The value to check.
	 *
	 * @return bool
	 */
	public static function walkable( $value ) {
		$objects = array();

		return self::walk( $value, $objects );
	}

	/**
	 * Stop a layout-update write whose layout holds a cycle.
	 *
	 * The save pipeline and kses_deep() recurse without a cycle check, so a
	 * cyclic value would loop. The write is declined with the code the
	 * pre-write copy uses for values it cannot copy; nothing is stored.
	 *
	 * @param mixed $value The layout.
	 *
	 * @throws SiteOrigin_Panels_Layout_Update_Aborted Code siteorigin_panels_layout_update_unsupported_value.
	 */
	public static function assert_walkable( $value ) {
		if ( ! self::walkable( $value ) ) {
			throw new SiteOrigin_Panels_Layout_Update_Aborted(
				new WP_Error(
					'siteorigin_panels_layout_update_unsupported_value',
					__( 'The layout contains a value that cannot be saved by a layout update.', 'siteorigin-panels' )
				)
			);
		}
	}

	/**
	 * Whether a stored or incoming widget can be compared and copied.
	 *
	 * @param mixed $entry A widget entry.
	 *
	 * @return bool True for an array that is walkable and holds no object
	 *              other than stdClass.
	 */
	public static function comparable( $entry ) {
		return is_array( $entry ) && self::walkable( $entry ) && self::plain( $entry );
	}

	/**
	 * Whether two values are equal by value.
	 *
	 * Lists compare in order; other arrays compare with their keys in any
	 * order; a stdClass compares by its properties and never equals an array;
	 * scalars and null compare strictly. An object of any other class is
	 * unequal to everything. Both values must be walkable.
	 *
	 * @param mixed $a A value.
	 * @param mixed $b A value.
	 *
	 * @return bool
	 */
	public static function same( $a, $b ) {
		$a_ok = true;
		$b_ok = true;
		$a    = self::canonical( $a, $a_ok );
		$b    = self::canonical( $b, $b_ok );

		return $a_ok && $b_ok && $a === $b;
	}

	/**
	 * Match incoming widgets to stored widgets.
	 *
	 * Pass A matches a widget left at its stored placement; pass B matches a
	 * widget the caller moved. A group must hold exactly one incoming and one
	 * stored widget to match, so an ambiguous group matches nothing, and a
	 * stored widget is matched at most once. Member-only entries are counted
	 * in their groups but never matched. The result does not depend on list
	 * order.
	 *
	 * @param array $incoming    Comparable incoming widgets, keyed as in the live list.
	 * @param array $stored      Comparable stored widgets, keyed as in the stored list.
	 * @param array $member_only Incoming keys that can never match.
	 *
	 * @return array Incoming key => array( 'stored' => stored key, 'moved' => bool ).
	 */
	public static function match( array $incoming, array $stored, array $member_only = array() ) {
		$member_only = array_flip( $member_only );
		$forms       = array();
		$places      = array();
		foreach ( $incoming as $key => $widget ) {
			$forms['i'][ $key ]  = self::form_key( $widget );
			$places['i'][ $key ] = self::placement_key( $widget );
		}
		foreach ( $stored as $key => $widget ) {
			$forms['s'][ $key ]  = self::form_key( $widget );
			$places['s'][ $key ] = self::placement_key( $widget );
		}

		$matches  = array();
		$consumed = array();
		$moving   = array();

		// Pass A: group by form and placement.
		$groups = array();
		foreach ( $incoming as $key => $widget ) {
			$groups[ $forms['i'][ $key ] . "\0" . $places['i'][ $key ] ]['i'][] = $key;
		}
		foreach ( $stored as $key => $widget ) {
			$groups[ $forms['s'][ $key ] . "\0" . $places['s'][ $key ] ]['s'][] = $key;
		}
		foreach ( $groups as $group ) {
			$in  = isset( $group['i'] ) ? $group['i'] : array();
			$out = isset( $group['s'] ) ? $group['s'] : array();

			if ( empty( $in ) ) {
				continue;
			}

			if ( empty( $out ) ) {
				$moving = array_merge( $moving, $in );
				continue;
			}

			foreach ( $out as $stored_key ) {
				$consumed[ $stored_key ] = true;
			}

			if ( count( $in ) === 1 && count( $out ) === 1 && ! isset( $member_only[ $in[0] ] ) ) {
				$matches[ $in[0] ] = array( 'stored' => $out[0], 'moved' => false );
			}
		}

		// Pass B: group the remaining widgets by form only.
		$groups = array();
		foreach ( $moving as $key ) {
			$groups[ $forms['i'][ $key ] ]['i'][] = $key;
		}
		foreach ( $stored as $key => $widget ) {
			if ( ! isset( $consumed[ $key ] ) ) {
				$groups[ $forms['s'][ $key ] ]['s'][] = $key;
			}
		}
		foreach ( $groups as $group ) {
			if (
				isset( $group['i'], $group['s'] ) &&
				count( $group['i'] ) === 1 &&
				count( $group['s'] ) === 1 &&
				! isset( $member_only[ $group['i'][0] ] )
			) {
				$matches[ $group['i'][0] ] = array( 'stored' => $group['s'][0], 'moved' => true );
			}
		}

		return $matches;
	}

	/**
	 * The widgets to store for matched keys.
	 *
	 * Each is a copy of its stored widget with every stored key kept. A widget
	 * that did not move keeps its stored position fields; a moved widget takes
	 * the caller's grid, cell, id and cell_index, filtered.
	 *
	 * @param array $incoming Comparable incoming widgets.
	 * @param array $stored   Comparable stored widgets.
	 * @param array $matches  The result of match().
	 *
	 * @return array Incoming key => restored widget.
	 */
	public static function restore( array $incoming, array $stored, array $matches ) {
		$restored = array();
		foreach ( $matches as $key => $match ) {
			$widget = SiteOrigin_Panels_Layout_Update_Pre_Write::detach( $stored[ $match['stored'] ] );

			if (
				$match['moved'] &&
				isset( $widget['panels_info'], $incoming[ $key ]['panels_info'] ) &&
				is_array( $widget['panels_info'] ) &&
				is_array( $incoming[ $key ]['panels_info'] )
			) {
				foreach ( self::POSITION_KEYS as $position ) {
					if ( array_key_exists( $position, $incoming[ $key ]['panels_info'] ) ) {
						$widget['panels_info'][ $position ] = self::floor_value( $incoming[ $key ]['panels_info'][ $position ] );
					} else {
						unset( $widget['panels_info'][ $position ] );
					}
				}
			}

			$restored[ $key ] = $widget;
		}

		return $restored;
	}

	/**
	 * The emulator keys present on a widget, with their values.
	 *
	 * @param mixed $widget A widget.
	 *
	 * @return array
	 */
	public static function emulator_values( $widget ) {
		$values = array();
		if ( is_array( $widget ) ) {
			foreach ( self::EMULATOR_KEYS as $name ) {
				if ( array_key_exists( $name, $widget ) ) {
					$values[ $name ] = $widget[ $name ];
				}
			}
		}

		return $values;
	}

	/**
	 * The kses floor for a layout-update write.
	 *
	 * A widget keeps its snapshot when the output still equals it, ignoring
	 * the emulator keys, and its emulator values are the ones this write's
	 * emulator call produced, or with none recorded, the snapshot's own. A kept
	 * widget is stored as a copy of its snapshot, never the output. Every
	 * other widget is filtered.
	 *
	 * @param mixed $widgets   The widget list after the save pipeline.
	 * @param array $snapshots Key => copy of the restored widget.
	 * @param array $emulator  Key => emulator values recorded after the emulator call.
	 * @param array $kept      Receives the keys of the widgets kept.
	 *
	 * @return mixed
	 */
	public static function floor( $widgets, array $snapshots, array $emulator = array(), &$kept = null ) {
		$kept = array();

		if ( ! is_array( $widgets ) ) {
			return SiteOrigin_Panels_Admin::kses_deep( $widgets );
		}

		foreach ( $widgets as $key => $widget ) {
			if ( isset( $snapshots[ $key ] ) && self::kept( $widget, $snapshots[ $key ], $key, $emulator ) ) {
				$widgets[ $key ] = self::keep( $snapshots[ $key ], $key, $emulator );
				$kept[]          = $key;
			} else {
				$widgets[ $key ] = self::floor_value( $widget );
			}
		}

		return $widgets;
	}

	/**
	 * Filter a value: every string, including strings inside stdClass values,
	 * goes through kses_deep(). The result is a reference-free copy. A value
	 * that holds another kind of object gets kses_deep() alone; the pre-write
	 * copy then stops the write.
	 *
	 * @param mixed $value The value to filter.
	 *
	 * @return mixed
	 */
	public static function floor_value( $value ) {
		if ( self::walkable( $value ) && self::plain( $value ) ) {
			return map_deep( SiteOrigin_Panels_Layout_Update_Pre_Write::detach( $value ), array( 'SiteOrigin_Panels_Admin', 'kses_deep' ) );
		}

		return SiteOrigin_Panels_Admin::kses_deep( $value );
	}

	private static function kept( $widget, $snapshot, $key, array $emulator ) {
		if ( ! is_array( $widget ) || ! self::comparable( $widget ) ) {
			return false;
		}

		$expected = array_key_exists( $key, $emulator ) ? $emulator[ $key ] : self::emulator_values( $snapshot );

		return self::same( self::without_emulator( $widget ), self::without_emulator( $snapshot ) ) &&
			self::same( self::emulator_values( $widget ), $expected );
	}

	private static function keep( $snapshot, $key, array $emulator ) {
		$widget = SiteOrigin_Panels_Layout_Update_Pre_Write::detach( $snapshot );

		if ( array_key_exists( $key, $emulator ) ) {
			foreach ( self::EMULATOR_KEYS as $name ) {
				if ( array_key_exists( $name, $emulator[ $key ] ) ) {
					$widget[ $name ] = $emulator[ $key ][ $name ];
				} else {
					unset( $widget[ $name ] );
				}
			}
		}

		return $widget;
	}

	private static function without_emulator( array $widget ) {
		foreach ( self::EMULATOR_KEYS as $name ) {
			unset( $widget[ $name ] );
		}

		return $widget;
	}

	private static function form_key( array $widget ) {
		if ( isset( $widget['panels_info'] ) && is_array( $widget['panels_info'] ) ) {
			// Work on a copy, and drop the slot before writing it back: a
			// panels_info held by reference must not change for the caller.
			$info = $widget['panels_info'];
			unset( $widget['panels_info'] );
			foreach ( self::POSITION_KEYS as $position ) {
				unset( $info[ $position ] );
			}
			$widget['panels_info'] = $info;
		}

		return self::key( $widget );
	}

	private static function placement_key( array $widget ) {
		$placement = array();
		if ( isset( $widget['panels_info'] ) && is_array( $widget['panels_info'] ) ) {
			foreach ( self::PLACEMENT_KEYS as $position ) {
				if ( array_key_exists( $position, $widget['panels_info'] ) ) {
					$placement[ $position ] = $widget['panels_info'][ $position ];
				}
			}
		}

		return self::key( $placement );
	}

	private static function key( $value ) {
		$ok = true;

		return serialize( self::canonical( $value, $ok ) );
	}

	private static function canonical( $value, &$ok ) {
		if ( is_array( $value ) ) {
			$list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $list ) {
				ksort( $value, SORT_STRING );
			}

			$items = array();
			foreach ( $value as $key => $item ) {
				$items[ $key ] = self::canonical( $item, $ok );
			}

			return array( $list ? 'list' : 'map', $items );
		}

		if ( is_object( $value ) ) {
			if ( get_class( $value ) !== 'stdClass' ) {
				$ok = false;

				return null;
			}

			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );

			$items = array();
			foreach ( $properties as $key => $item ) {
				$items[ $key ] = self::canonical( $item, $ok );
			}

			return array( 'object', $items );
		}

		return $value;
	}

	private static function plain( $value ) {
		if ( is_object( $value ) ) {
			if ( get_class( $value ) !== 'stdClass' ) {
				return false;
			}

			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::plain( $item ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function walk( $value, array &$objects ) {
		if ( is_object( $value ) ) {
			if ( get_class( $value ) !== 'stdClass' ) {
				return true;
			}

			$hash = spl_object_hash( $value );
			if ( isset( $objects[ $hash ] ) ) {
				return false;
			}

			$objects[ $hash ] = true;
			try {
				foreach ( get_object_vars( $value ) as $item ) {
					if ( ! self::walk( $item, $objects ) ) {
						return false;
					}
				}

				return true;
			} finally {
				unset( $objects[ $hash ] );
			}
		}

		if ( ! is_array( $value ) ) {
			return true;
		}

		return self::arrays_acyclic( $value ) && self::walk_array( $value, $objects );
	}

	// Descends an array already known to hold no array cycle, checking only
	// the plain objects inside it.
	private static function walk_array( array $value, array &$objects ) {
		foreach ( $value as $item ) {
			if ( is_object( $item ) ) {
				if ( ! self::walk( $item, $objects ) ) {
					return false;
				}
			} elseif ( is_array( $item ) && ! self::walk_array( $item, $objects ) ) {
				return false;
			}
		}

		return true;
	}

	private static function arrays_acyclic( array $value ) {
		$cyclic = false;
		set_error_handler(
			function ( $number, $message ) use ( &$cyclic ) {
				if ( stripos( $message, 'recursion' ) !== false ) {
					$cyclic = true;

					return true;
				}

				return false;
			},
			E_WARNING
		);

		try {
			count( $value, COUNT_RECURSIVE );
		} finally {
			restore_error_handler();
		}

		return ! $cyclic;
	}
}
