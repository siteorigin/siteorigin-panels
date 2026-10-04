<?php
/**
 * Layout generator for the parity generator test. Plain PHP: no WordPress and no plugin code.
 *
 * parity_generate( $seed, $count ) returns $count cases. The same seed and count give the same cases:
 * the generator uses its own PRNG (xorshift32), so nothing else can change the sequence.
 * Each case is array( i, kind, note, data, json ):
 *   data = the PHP value of the layout
 *   json = the bytes a builder field would send
 *
 * Every string used as a row, cell or widget reference matches ^[\p{L}\p{N}_-]+$ (letters, digits,
 * underscore and hyphen; letters and digits of any script).
 */

if ( function_exists( 'parity_generate' ) ) {
	return;
}

function parity_gen_r32() {
	$x  = $GLOBALS['parity_gen_state'];
	$x ^= ( $x << 13 ) & 0xFFFFFFFF;
	$x ^= $x >> 17;
	$x ^= ( $x << 5 ) & 0xFFFFFFFF;

	$GLOBALS['parity_gen_state'] = $x & 0xFFFFFFFF;

	return $GLOBALS['parity_gen_state'];
}

function parity_gen_rn( $min, $max ) {
	return $min + ( parity_gen_r32() % ( $max - $min + 1 ) );
}

function parity_gen_pick( $list ) {
	return $list[ parity_gen_r32() % count( $list ) ];
}

function parity_gen_chance( $pct ) {
	return ( parity_gen_r32() % 100 ) < $pct;
}

/* ---------- value pools ---------- */

function parity_gen_pool_safe_strings() {
	return array( 'my-row', 'row_a', 'hero', 'Row-2', 'a', 'x9', '-lead', '_under', 'row-0', 'ABC' );
}

function parity_gen_pool_nonascii() {
	return array(
		"\u{00FC}ber-row",
		"\u{884C}-1",
		"\u{6771}\u{4EAC}",
		"\u{0441}\u{0442}\u{0440}\u{043E}\u{043A}\u{0430}",
		"\u{30ED}\u{30A6}",
		"r\u{03BF}w-\u{03B1}",
		"row-\u{0430}bc",
		"\u{FF10}",
		"\u{0660}",
		"\u{D55C}\u{AD6D}\u{C5B4}-row",
		"\u{05E9}\u{05D5}\u{05E8}\u{05D4}",
	);
}

function parity_gen_pool_numeric_strings() {
	return array( '0', '1', '2', '01', '00', '1e0', '0x1', '-0', '0b1', "\u{0661}" );
}

function parity_gen_pool_out_of_range() {
	return array( -1, '-1', 99, '99', 2147483647, PHP_INT_MAX, '999999999999999999999', -2147483648, 1000000, '1e9', 1.0e30, -0.5 );
}

function parity_gen_pool_mixed() {
	return array( true, false, null, array(), array( 0 ), array( 'a' => 1 ), 0.0, 1.0, 0.5, new stdClass(), (object) array( 'grid' => 0 ) );
}

function parity_gen_any_string_index() {
	switch ( parity_gen_rn( 0, 2 ) ) {
		case 0:
			return parity_gen_pick( parity_gen_pool_safe_strings() );
		case 1:
			return parity_gen_pick( parity_gen_pool_nonascii() );
		default:
			return str_repeat( parity_gen_pick( array( 'a', '9', '-', "\u{884C}" ) ), parity_gen_rn( 200, 1200 ) );
	}
}

function parity_gen_any_bad_ref() {
	switch ( parity_gen_rn( 0, 3 ) ) {
		case 0:
			return parity_gen_any_string_index();
		case 1:
			return parity_gen_pick( parity_gen_pool_out_of_range() );
		case 2:
			return parity_gen_pick( parity_gen_pool_numeric_strings() );
		default:
			return parity_gen_pick( parity_gen_pool_mixed() );
	}
}

/* ---------- valid layout ---------- */

function parity_gen_make_widget( $grid, $cell, $id, $depth, $max_depth ) {
	$type = parity_gen_rn( 0, 99 );
	$info = array(
		'grid'      => $grid,
		'cell'      => $cell,
		'id'        => $id,
		'widget_id' => sprintf( '%08x-%04x-4%03x-8%03x-%012x', parity_gen_r32(), parity_gen_r32() & 0xFFFF, parity_gen_r32() & 0xFFF, parity_gen_r32() & 0xFFF, parity_gen_r32() ),
		'style'     => array(),
	);

	if ( parity_gen_chance( 35 ) ) {
		$info['style'] = array_filter(
			array(
				'id'             => parity_gen_chance( 30 ) ? 'w' . parity_gen_rn( 1, 99 ) : '',
				'class'          => parity_gen_chance( 30 ) ? 'c' . parity_gen_rn( 1, 9 ) : '',
				'padding'        => parity_gen_chance( 50 ) ? parity_gen_rn( 0, 40 ) . 'px ' . parity_gen_rn( 0, 40 ) . 'px' : '',
				'mobile_padding' => parity_gen_chance( 30 ) ? parity_gen_rn( 0, 20 ) . 'px' : '',
				'margin'         => parity_gen_chance( 40 ) ? '0px 0px ' . parity_gen_rn( 0, 60 ) . 'px 0px' : '',
				'mobile_margin'  => parity_gen_chance( 20 ) ? parity_gen_rn( 0, 20 ) . 'px' : '',
				'background'     => parity_gen_chance( 30 ) ? sprintf( '#%06x', parity_gen_r32() & 0xFFFFFF ) : '',
				'font_color'     => parity_gen_chance( 20 ) ? sprintf( '#%06x', parity_gen_r32() & 0xFFFFFF ) : '',
				'widget_css'     => parity_gen_chance( 15 ) ? 'color: red;' : '',
			)
		);
	}

	if ( $type < 40 ) {
		$info['class'] = 'WP_Widget_Text';

		return array( 'title' => 'T' . parity_gen_rn( 1, 999 ), 'text' => '<p>Text ' . parity_gen_rn( 1, 9999 ) . ' &amp; more</p>', 'filter' => parity_gen_chance( 50 ), 'visual' => true, 'panels_info' => $info );
	}

	if ( $type < 60 ) {
		$info['class'] = 'WP_Widget_Custom_HTML';

		return array( 'title' => '', 'content' => '<div class="x">HTML ' . parity_gen_rn( 1, 9999 ) . '</div>', 'panels_info' => $info );
	}

	if ( $type < 75 ) {
		$info['class'] = 'SiteOrigin_Widget_Editor_Widget';

		return array( 'title' => 'E' . parity_gen_rn( 1, 99 ), 'text' => '<p>Editor ' . parity_gen_rn( 1, 9999 ) . '</p>', 'autop' => true, 'panels_info' => $info );
	}

	if ( $type < 82 ) {
		$info['class'] = 'SiteOrigin_Widget_Button_Widget';

		return array( 'text' => 'Go ' . parity_gen_rn( 1, 99 ), 'url' => 'https://example.com/' . parity_gen_rn( 1, 99 ), 'panels_info' => $info );
	}

	if ( $type < 88 ) {
		$info['class'] = 'Parity_Missing_Widget_' . parity_gen_rn( 1, 3 );

		return array( 'title' => 'missing', 'panels_info' => $info );
	}

	if ( $depth < $max_depth ) {
		$info['class'] = 'SiteOrigin_Panels_Widgets_Layout';

		return array( 'panels_data' => parity_gen_make_layout( $depth + 1, $max_depth ), 'builder_id' => sprintf( '%013x', parity_gen_r32() ), 'panels_info' => $info );
	}

	$info['class'] = 'WP_Widget_Text';

	return array( 'title' => 'leaf', 'text' => 'leaf', 'filter' => false, 'panels_info' => $info );
}

function parity_gen_make_layout( $depth = 0, $max_depth = 1 ) {
	$rows   = parity_gen_rn( 1, $depth ? 2 : 5 );
	$layout = array( 'widgets' => array(), 'grids' => array(), 'grid_cells' => array() );

	for ( $r = 0; $r < $rows; $r ++ ) {
		$cells = parity_gen_rn( 1, 4 );
		$grid  = array( 'cells' => $cells, 'style' => array() );

		if ( parity_gen_chance( 45 ) ) {
			$grid['style'] = array_filter(
				array(
					'id'                => parity_gen_chance( 25 ) ? 'row' . parity_gen_rn( 1, 99 ) : '',
					'class'             => parity_gen_chance( 25 ) ? 'rc' . parity_gen_rn( 1, 9 ) : '',
					'padding'           => parity_gen_chance( 40 ) ? parity_gen_rn( 0, 60 ) . 'px' : '',
					'mobile_padding'    => parity_gen_chance( 25 ) ? parity_gen_rn( 0, 30 ) . 'px' : '',
					'bottom_margin'     => parity_gen_chance( 40 ) ? parity_gen_rn( 0, 80 ) . 'px' : '',
					'mobile_bottom_margin' => parity_gen_chance( 20 ) ? parity_gen_rn( 0, 40 ) . 'px' : '',
					'gutter'            => parity_gen_chance( 40 ) ? parity_gen_rn( 0, 60 ) . 'px' : '',
					'row_stretch'       => parity_gen_chance( 30 ) ? parity_gen_pick( array( 'full', 'full-stretched', 'full-width-stretch' ) ) : '',
					'collapse_order'    => parity_gen_chance( 25 ) ? parity_gen_pick( array( 'left-top', 'right-top' ) ) : '',
					'collapse_behaviour' => parity_gen_chance( 15 ) ? parity_gen_pick( array( 'no_collapse', 'mobile_only' ) ) : '',
					'cell_alignment'    => parity_gen_chance( 30 ) ? parity_gen_pick( array( 'flex-start', 'center', 'flex-end', 'stretch' ) ) : '',
					'background'        => parity_gen_chance( 30 ) ? sprintf( '#%06x', parity_gen_r32() & 0xFFFFFF ) : '',
					'row_css'           => parity_gen_chance( 10 ) ? 'min-height: 10px;' : '',
				)
			);
		}

		if ( parity_gen_chance( 15 ) ) {
			$grid['ratio']           = 1;
			$grid['ratio_direction'] = 'right';
			$grid['label']           = 'Row ' . $r;
			$grid['color_label']     = parity_gen_rn( 1, 4 );
		}
		$layout['grids'][] = $grid;

		for ( $c = 0; $c < $cells; $c ++ ) {
			$cell = array( 'grid' => $r, 'index' => $c, 'weight' => round( 1 / $cells, 6 ), 'style' => array() );

			if ( parity_gen_chance( 25 ) ) {
				$cell['style'] = array_filter(
					array(
						'class'              => parity_gen_chance( 40 ) ? 'cc' . parity_gen_rn( 1, 9 ) : '',
						'padding'            => parity_gen_chance( 50 ) ? parity_gen_rn( 0, 40 ) . 'px' : '',
						'mobile_padding'     => parity_gen_chance( 30 ) ? parity_gen_rn( 0, 20 ) . 'px' : '',
						'background'         => parity_gen_chance( 40 ) ? sprintf( '#%06x', parity_gen_r32() & 0xFFFFFF ) : '',
						'vertical_alignment' => parity_gen_chance( 40 ) ? parity_gen_pick( array( 'auto', 'flex-start', 'center', 'flex-end', 'stretch' ) ) : '',
						'cell_css'           => parity_gen_chance( 10 ) ? 'opacity: 0.9;' : '',
					)
				);
			}
			$layout['grid_cells'][] = $cell;

			$n = parity_gen_rn( 0, 3 );

			for ( $w = 0; $w < $n; $w ++ ) {
				$layout['widgets'][] = parity_gen_make_widget( $r, $c, count( $layout['widgets'] ), $depth, $max_depth );
			}
		}
	}

	return $layout;
}

/* ---------- mutations ---------- */

function &parity_gen_deepest_layout( &$layout ) {
	if ( ! empty( $layout['widgets'] ) && is_array( $layout['widgets'] ) ) {
		foreach ( $layout['widgets'] as $k => &$w ) {
			if ( is_array( $w ) && isset( $w['panels_data'] ) && is_array( $w['panels_data'] ) ) {
				return parity_gen_deepest_layout( $w['panels_data'] );
			}
		}
	}

	return $layout;
}

function parity_gen_mut_legacy_info( &$l, &$note ) {
	$mode = parity_gen_rn( 0, 3 );

	foreach ( $l['widgets'] as $k => $w ) {
		if ( ! is_array( $w ) || ! isset( $w['panels_info'] ) || ! is_array( $w['panels_info'] ) || ! isset( $w['panels_info']['grid'], $w['panels_info']['cell'], $w['panels_info']['id'], $w['panels_info']['class'] ) ) {
			continue;
		}

		$info = $w['panels_info'];

		if ( $mode === 0 ) {
			// Pre-2.5 shape: only `info`, string references.
			$l['widgets'][ $k ]['info'] = array( 'grid' => (string) $info['grid'], 'cell' => (string) $info['cell'], 'id' => (string) $info['id'], 'class' => $info['class'] );
			unset( $l['widgets'][ $k ]['panels_info'] );
		} elseif ( $mode === 1 ) {
			$l['widgets'][ $k ]['info'] = $info;
			unset( $l['widgets'][ $k ]['panels_info'] );
		} elseif ( $mode === 2 ) {
			$l['widgets'][ $k ]['info'] = $info; // Both present.
		} else {
			$l['widgets'][ $k ]['info'] = array( 'grid' => $info['grid'], 'cell' => $info['cell'] );
			$l['widgets'][ $k ]['panels_info'] = array(); // Empty panels_info, info carries placement.
		}
	}
	$note[] = "legacy-info:$mode";
}

function parity_gen_rekey( $list, $mode ) {
	$out = array();
	$i   = 0;

	foreach ( $list as $v ) {
		switch ( $mode ) {
			case 0:
				$out[ $i + 1 ] = $v;
				break;
			case 1:
				$out[ $i * 2 ] = $v;
				break;
			case 2:
				$out[ 'k' . $i ] = $v;
				break;
			default:
				$out[ $i ] = $v;
		}
		$i ++;
	}

	if ( $mode === 3 ) {
		$out = array_reverse( $out, true );
	}

	return $out;
}

function parity_gen_mut_numeric_keyed( &$l, &$note ) {
	$which = parity_gen_pick( array( 'grids', 'grid_cells', 'widgets', 'all' ) );
	$mode  = parity_gen_rn( 0, 3 );

	foreach ( array( 'grids', 'grid_cells', 'widgets' ) as $k ) {
		if ( ( $which === 'all' || $which === $k ) && isset( $l[ $k ] ) && is_array( $l[ $k ] ) ) {
			$l[ $k ] = parity_gen_rekey( $l[ $k ], $mode );
		}
	}
	$note[] = "numeric-keyed:$which:$mode";
}

function parity_gen_mut_numeric_string_refs( &$l, &$note ) {
	$mode = parity_gen_rn( 0, 2 );

	foreach ( $l['grid_cells'] as $k => $c ) {
		if ( is_array( $c ) && isset( $c['grid'] ) && is_int( $c['grid'] ) ) {
			$l['grid_cells'][ $k ]['grid'] = $mode === 2 && parity_gen_chance( 30 ) ? parity_gen_pick( parity_gen_pool_numeric_strings() ) : (string) $c['grid'];
		}
	}

	foreach ( $l['widgets'] as $k => $w ) {
		if ( is_array( $w ) && isset( $w['panels_info'] ) && is_array( $w['panels_info'] ) && isset( $w['panels_info']['grid'] ) && is_int( $w['panels_info']['grid'] ) ) {
			foreach ( array( 'grid', 'cell', 'id' ) as $f ) {
				if ( isset( $w['panels_info'][ $f ] ) && ( $mode !== 1 || $f !== 'id' ) ) {
					$l['widgets'][ $k ]['panels_info'][ $f ] = $mode === 2 && parity_gen_chance( 20 ) ? parity_gen_pick( parity_gen_pool_numeric_strings() ) : (string) $w['panels_info'][ $f ];
				}
			}
		}
	}
	$note[] = "numeric-string-refs:$mode";
}

function parity_gen_mut_missing( &$l, &$note ) {
	$mode = parity_gen_rn( 0, 9 );

	switch ( $mode ) {
		case 0:
			unset( $l['widgets'] );
			break;
		case 1:
			$l['widgets'] = null;
			break;
		case 2:
			$l['widgets'] = array();
			break;
		case 3:
			unset( $l['grid_cells'] );
			break;
		case 4:
			unset( $l['grids'] );
			break;
		case 5:
			$l['grids'] = array();
			break;
		case 6:
			$l['grid_cells'] = array();
			break;
		case 7:
			if ( $l['widgets'] ) {
				$k = parity_gen_array_rand_det( $l['widgets'] );
				unset( $l['widgets'][ $k ]['panels_info'] );
			}
			break;
		case 8:
			if ( $l['widgets'] ) {
				$k = parity_gen_array_rand_det( $l['widgets'] );
				unset( $l['widgets'][ $k ]['panels_info'][ parity_gen_pick( array( 'grid', 'cell', 'id', 'class' ) ) ] );
			}
			break;
		default:
			if ( $l['grid_cells'] ) {
				$k = parity_gen_array_rand_det( $l['grid_cells'] );
				unset( $l['grid_cells'][ $k ][ parity_gen_pick( array( 'grid', 'weight' ) ) ] );
			}
	}
	$note[] = "missing:$mode";
}

function parity_gen_array_rand_det( $arr ) {
	$keys = array_keys( $arr );

	return $keys[ parity_gen_r32() % count( $keys ) ];
}

function parity_gen_mut_refs( &$l, &$note, $value = null ) {
	$target = parity_gen_rn( 0, 7 );
	$v      = $value === null ? parity_gen_any_bad_ref() : $value;
	$label  = is_string( $v ) ? 'str' : gettype( $v );

	switch ( $target ) {
		case 0: // One cell's row reference.
			if ( ! empty( $l['grid_cells'] ) ) {
				$k = parity_gen_array_rand_det( $l['grid_cells'] );

				if ( is_array( $l['grid_cells'][ $k ] ) ) {
					$l['grid_cells'][ $k ]['grid'] = $v;
				}
			}
			break;
		case 1: // Every cell of one row points at the same value (a "string row id").
			if ( ! empty( $l['grid_cells'] ) ) {
				$k   = parity_gen_array_rand_det( $l['grid_cells'] );
				$row = is_array( $l['grid_cells'][ $k ] ) && isset( $l['grid_cells'][ $k ]['grid'] ) ? $l['grid_cells'][ $k ]['grid'] : 0;

				foreach ( $l['grid_cells'] as $ck => $c ) {
					if ( is_array( $c ) && isset( $c['grid'] ) && $c['grid'] === $row ) {
						$l['grid_cells'][ $ck ]['grid'] = $v;
					}
				}

				if ( parity_gen_chance( 50 ) && ! empty( $l['widgets'] ) ) {
					foreach ( $l['widgets'] as $wk => $w ) {
						if ( is_array( $w ) && isset( $w['panels_info'] ) && is_array( $w['panels_info'] ) && isset( $w['panels_info']['grid'] ) && $w['panels_info']['grid'] === $row ) {
							$l['widgets'][ $wk ]['panels_info']['grid'] = $v;
						}
					}
				}
			}
			break;
		case 2:
		case 3:
		case 4:
		case 5: // One widget's grid / cell / id / widget_index.
			if ( ! empty( $l['widgets'] ) ) {
				$k      = parity_gen_array_rand_det( $l['widgets'] );
				$field  = array( 2 => 'grid', 3 => 'cell', 4 => 'id', 5 => 'widget_index' )[ $target ];
				$holder = is_array( $l['widgets'][ $k ] ) && isset( $l['widgets'][ $k ]['info'] ) && ! isset( $l['widgets'][ $k ]['panels_info'] ) ? 'info' : 'panels_info';

				if ( is_array( $l['widgets'][ $k ] ) ) {
					if ( ! isset( $l['widgets'][ $k ][ $holder ] ) || ! is_array( $l['widgets'][ $k ][ $holder ] ) ) {
						$l['widgets'][ $k ][ $holder ] = array();
					}
					$l['widgets'][ $k ][ $holder ][ $field ] = $v;
				}
			}
			break;
		case 6: // Every widget id (the legacy renderer's widget index).
			if ( ! empty( $l['widgets'] ) ) {
				foreach ( $l['widgets'] as $wk => $w ) {
					if ( is_array( $w ) && isset( $w['panels_info'] ) && is_array( $w['panels_info'] ) ) {
						$l['widgets'][ $wk ]['panels_info']['id'] = is_string( $v ) ? $v . ( parity_gen_chance( 50 ) ? '' : $wk ) : $v;
					}
				}
			}
			break;
		default: // Every cell reference.
			if ( ! empty( $l['grid_cells'] ) ) {
				foreach ( $l['grid_cells'] as $ck => $c ) {
					if ( is_array( $c ) ) {
						$l['grid_cells'][ $ck ]['grid'] = $v;
					}
				}
			}
	}
	$note[] = "ref:$target:$label";
}

function parity_gen_mut_mixed( &$l, &$note ) {
	$mode = parity_gen_rn( 0, 11 );
	$v    = parity_gen_pick( parity_gen_pool_mixed() );

	switch ( $mode ) {
		case 0:
			if ( ! empty( $l['grid_cells'] ) ) {
				$l['grid_cells'][ parity_gen_array_rand_det( $l['grid_cells'] ) ] = $v;
			}
			break;
		case 1:
			if ( ! empty( $l['grids'] ) ) {
				$l['grids'][ parity_gen_array_rand_det( $l['grids'] ) ] = parity_gen_pick( array( 'row', 1, null, true, new stdClass() ) );
			}
			break;
		case 2:
			if ( ! empty( $l['widgets'] ) ) {
				$l['widgets'][ parity_gen_array_rand_det( $l['widgets'] ) ] = parity_gen_pick( array( 'widget', 7, null, false ) );
			}
			break;
		case 3: // A widget entry as a stdClass object.
			if ( ! empty( $l['widgets'] ) ) {
				$k = parity_gen_array_rand_det( $l['widgets'] );

				if ( is_array( $l['widgets'][ $k ] ) ) {
					$l['widgets'][ $k ] = (object) $l['widgets'][ $k ];
				}
			}
			break;
		case 4:
			if ( ! empty( $l['widgets'] ) ) {
				$k = parity_gen_array_rand_det( $l['widgets'] );

				if ( is_array( $l['widgets'][ $k ] ) ) {
					$l['widgets'][ $k ]['panels_info'] = parity_gen_pick( array( 'info', 3, null, true, new stdClass() ) );
				}
			}
			break;
		case 5:
			if ( ! empty( $l['grid_cells'] ) ) {
				$k = parity_gen_array_rand_det( $l['grid_cells'] );

				if ( is_array( $l['grid_cells'][ $k ] ) ) {
					$l['grid_cells'][ $k ]['weight'] = parity_gen_pick( array( '0.5', 'abc', -1, null, array(), 0, 1e30, '50%' ) );
				}
			}
			break;
		case 6:
			if ( ! empty( $l['grids'] ) ) {
				$k = parity_gen_array_rand_det( $l['grids'] );

				if ( is_array( $l['grids'][ $k ] ) ) {
					$l['grids'][ $k ]['style'] = parity_gen_pick( array( 'style', 1, null, true, new stdClass() ) );
				}
			}
			break;
		case 7:
			$l[ parity_gen_pick( array( 'grids', 'grid_cells', 'widgets' ) ) ] = parity_gen_pick( array( 'x', 1, true, null, new stdClass(), 0.5 ) );
			break;
		case 8:
			$l['extra'] = array( 'a' => 1 );
			break;
		case 9:
			$l = array_values( $l ); // A list, not an object.
			break;
		case 10:
			if ( ! empty( $l['widgets'] ) ) {
				$k = parity_gen_array_rand_det( $l['widgets'] );

				if ( is_array( $l['widgets'][ $k ] ) ) {
					$l['widgets'][ $k ]['nested_object'] = (object) array( 'html' => '<b>x</b>' );
				}
			}
			break;
		default:
			if ( ! empty( $l['grid_cells'] ) ) {
				$k = parity_gen_array_rand_det( $l['grid_cells'] );

				if ( is_array( $l['grid_cells'][ $k ] ) ) {
					$l['grid_cells'][ $k ]['grid'] = $v;
				}
			}
	}
	$note[] = "mixed:$mode";
}

/* ---------- JSON with raw bytes ---------- */

function parity_gen_json_raw( $value ) {
	$json = json_encode( $value );

	return $json === false ? 'null' : $json;
}

/* ---------- case assembly ---------- */

/**
 * @return array List of array( i, kind, note, data, json ).
 */
function parity_generate( $seed, $count ) {
	$GLOBALS['parity_gen_state'] = ( (int) $seed & 0xFFFFFFFF ) ?: 1;

	// Weights: about two thirds of the cases are layouts the release stores on the classic path, so the
	// generator test checks real saves (the test fails below 60%).
	$kinds = array(
		'valid'               => 40,
		'legacy-info'         => 8,
		'numeric-keyed'       => 7,
		'numeric-string-refs' => 8,
		'missing'             => 7,
		'row-id-ascii'        => 8,
		'row-id-nonascii'     => 7,
		'ref-range'           => 6,
		'mixed-types'         => 8,
		'deep-nesting'        => 6,
		'combo'               => 8,
	);
	$wheel = array();

	foreach ( $kinds as $kind => $weight ) {
		for ( $i = 0; $i < $weight; $i ++ ) {
			$wheel[] = $kind;
		}
	}

	$cases = array();

	for ( $i = 0; $i < $count; $i ++ ) {
		$kind = parity_gen_pick( $wheel );
		$note = array();
		$data = parity_gen_make_layout( 0, $kind === 'deep-nesting' ? parity_gen_rn( 2, 6 ) : 1 );

		switch ( $kind ) {
			case 'legacy-info':
				parity_gen_mut_legacy_info( $data, $note );

				if ( parity_gen_chance( 30 ) ) {
					parity_gen_mut_numeric_string_refs( $data, $note );
				}
				break;
			case 'numeric-keyed':
				parity_gen_mut_numeric_keyed( $data, $note );
				break;
			case 'numeric-string-refs':
				parity_gen_mut_numeric_string_refs( $data, $note );
				break;
			case 'missing':
				parity_gen_mut_missing( $data, $note );
				break;
			case 'row-id-ascii':
				parity_gen_mut_refs( $data, $note, parity_gen_pick( parity_gen_pool_safe_strings() ) );
				break;
			case 'row-id-nonascii':
				parity_gen_mut_refs( $data, $note, parity_gen_pick( parity_gen_pool_nonascii() ) );
				break;
			case 'ref-range':
				parity_gen_mut_refs( $data, $note, parity_gen_pick( parity_gen_pool_out_of_range() ) );
				break;
			case 'mixed-types':
				parity_gen_mut_mixed( $data, $note );
				break;
			case 'deep-nesting':
				if ( parity_gen_chance( 70 ) ) {
					$deep = &parity_gen_deepest_layout( $data );

					if ( parity_gen_chance( 50 ) ) {
						parity_gen_mut_refs( $deep, $note );
					} else {
						parity_gen_mut_legacy_info( $deep, $note );
					}
					unset( $deep );
				}
				break;
			case 'combo':
				$n = parity_gen_rn( 2, 3 );

				for ( $m = 0; $m < $n; $m ++ ) {
					switch ( parity_gen_rn( 0, 5 ) ) {
						case 0:
							parity_gen_mut_legacy_info( $data, $note );
							break;
						case 1:
							if ( is_array( $data ) && isset( $data['grids'] ) ) {
								parity_gen_mut_numeric_keyed( $data, $note );
							}
							break;
						case 2:
							if ( isset( $data['grid_cells'], $data['widgets'] ) && is_array( $data['grid_cells'] ) && is_array( $data['widgets'] ) ) {
								parity_gen_mut_numeric_string_refs( $data, $note );
							}
							break;
						case 3:
							parity_gen_mut_refs( $data, $note );
							break;
						case 4:
							parity_gen_mut_mixed( $data, $note );
							break;
						default:
							parity_gen_mut_refs( $data, $note, parity_gen_any_string_index() );
					}

					if ( ! is_array( $data ) || ! isset( $data['widgets'] ) || ! is_array( $data['widgets'] ) || ! isset( $data['grid_cells'] ) || ! is_array( $data['grid_cells'] ) ) {
						break; // The shape no longer supports further list changes.
					}
				}
				break;
		}

		$cases[] = array(
			'i'    => $i,
			'kind' => $kind,
			'note' => implode( ',', $note ),
			'data' => $data,
			'json' => parity_gen_json_raw( $data ),
		);
	}

	return $cases;
}
