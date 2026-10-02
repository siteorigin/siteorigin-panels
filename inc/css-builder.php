<?php

/**
 * Class SiteOrigin_Panels_Css_Builder
 *
 * Use for building CSS for a page.
 */
class SiteOrigin_Panels_Css_Builder {
	public $css;

	/**
	 * String indexes that safe_selector_index() has checked and returned
	 * unchanged, as keys. A layout uses the same few indexes in every selector,
	 * so each method looks here before it calls the check again.
	 *
	 * @var array
	 */
	private $safe_indexes = array();

	public function __construct() {
		$this->css = array();
	}

	/**
	 * Add some general CSS.
	 *
	 * @param string $selector
	 * @param array  $attributes
	 * @param int    $resolution The pixel resolution that this applies to
	 */
	public function add_css( $selector, $attributes, $resolution = 1920 ) {
		$attribute_string = array();

		foreach ( $attributes as $k => $v ) {
			if ( is_array( $v ) ) {
				for ( $i = 0; $i < count( $v ); $i++ ) {
					if ( ! strlen( (string) $v[ $i ] ) ) {
						continue;
					}
					$attribute_string[] = wp_strip_all_tags( $k ) . ':' . wp_strip_all_tags( $v[ $i ] );
				}
			} elseif ( ! strlen( (string) $v ) || $v === 'px' ) {
				continue;
			} else {
				$attribute_string[] = wp_strip_all_tags( $k ) . ':' . wp_strip_all_tags( $v );
			}
		}
		$attribute_string = implode( ';', $attribute_string );

		if ( ! empty( $attribute_string ) ) {
			// Add everything we need to the CSS selector
			if ( empty( $this->css[ $resolution ] ) ) {
				$this->css[ $resolution ] = array();
			}

			if ( empty( $this->css[ $resolution ][ $attribute_string ] ) ) {
				$this->css[ $resolution ][ $attribute_string ] = array();
			}

			$this->css[ $resolution ][ $attribute_string ][] = $selector;
		}
	}

	/**
	 * Make a layout, row, cell or widget index safe to place in a selector.
	 *
	 * The index is concatenated into a selector as it is, so it may hold only
	 * the characters an unescaped identifier can carry. A value that is not a
	 * string (an integer index, or false for "all") is returned unchanged, so
	 * each method still branches on its type. A string keeps every non-ASCII
	 * character and the ASCII characters A-Z, a-z, 0-9, `_` and `-`; any other
	 * ASCII character is removed. A string that is not valid UTF-8 keeps only
	 * those ASCII characters.
	 *
	 * This is needed for every index of every selector, so the common cases are
	 * kept cheap, with the same result for every input:
	 * - The callers test is_string() themselves, so an integer or false index
	 *   costs no call.
	 * - A string made only of the safe ASCII characters (a Layout Block id, a
	 *   numeric string, the empty string) returns before any UTF-8 work. The
	 *   checks after it would return such a string unchanged.
	 * - That string is recorded in $safe_indexes, and the callers skip the call
	 *   for a recorded string. Only a string returned unchanged is recorded.
	 *
	 * @param mixed $index The index as the caller supplied it.
	 *
	 * @return mixed
	 */
	private function safe_selector_index( $index ) {
		if ( ! is_string( $index ) ) {
			return $index;
		}

		// rtrim() with a character list removes every listed character from
		// the end, so nothing is left only when every character is listed.
		if ( rtrim( $index, 'A..Za..z0..9_-' ) === '' ) {
			$this->safe_indexes[ $index ] = true;

			return $index;
		}

		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $index, 'UTF-8' ) ) {
			return preg_replace( '/[^A-Za-z0-9_-]/', '', $index );
		}

		if ( preg_match( '/^[\x{0080}-\x{10FFFF}A-Za-z0-9_-]+\z/u', $index ) ) {
			return $index;
		}

		return preg_replace( '/[^\x{0080}-\x{10FFFF}A-Za-z0-9_-]/u', '', $index );
	}

	/**
	 * Add CSS that applies to a row or group of rows.
	 *
	 * @param int             $li             The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool|string $ri             The row index. If false, then the CSS applies to all rows.
	 * @param string          $sub_selector   A sub selector if we need one.
	 * @param array           $attributes     An array of attributes.
	 * @param int             $resolution     The pixel resolution that this applies to
	 * @param bool            $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	public function add_row_css( $li, $ri = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false ) {
		if ( is_string( $li ) && ! isset( $this->safe_indexes[ $li ] ) ) {
			$li = $this->safe_selector_index( $li );
		}
		if ( is_string( $ri ) && ! isset( $this->safe_indexes[ $ri ] ) ) {
			$ri = $this->safe_selector_index( $ri );
		}

		$selector = array();

		// Special case of `> .panel-row-style` sub_selector
		if ( $ri === false ) {
			// This applies to all rows
			$selector[] = '#pl-' . $li;
			$selector[] = '.panel-grid';
		} else {
			// This applies to a specific row
			if ( $specify_layout ) {
				$selector[] = '#pl-' . $li;
			}

			if ( is_string( $ri ) ) {
				$selector[] = '#' . $ri;
			} else {
				$selector[] = '#pg-' . $li . '-' . $ri;
			}
		}

		$selector = implode( ' ', $selector );
		$selector = $this->add_sub_selector( $selector, $sub_selector );

		// Add this to the CSS array
		$this->add_css( $selector, $attributes, $resolution );
	}

	/**
	 * Add cell specific CSS
	 *
	 * @param int      $li             The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool $ri             The row index. If false, then the CSS applies to all rows.
	 * @param int|bool $ci             The cell index. If false, then the CSS applies to all rows.
	 * @param string   $sub_selector   A sub selector if we need one.
	 * @param array    $attributes     An array of attributes.
	 * @param int      $resolution     The pixel resolution that this applies to
	 * @param bool     $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	public function add_cell_css( $li, $ri = false, $ci = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false ) {
		if ( is_string( $li ) && ! isset( $this->safe_indexes[ $li ] ) ) {
			$li = $this->safe_selector_index( $li );
		}
		if ( is_string( $ri ) && ! isset( $this->safe_indexes[ $ri ] ) ) {
			$ri = $this->safe_selector_index( $ri );
		}
		if ( is_string( $ci ) && ! isset( $this->safe_indexes[ $ci ] ) ) {
			$ci = $this->safe_selector_index( $ci );
		}

		$selector_parts = array();

		if ( $ri === false && $ci === false ) {
			// This applies to all cells in the layout
			$selector_parts[] = '#pl-' . $li;
			$selector_parts[] = '.panel-grid-cell';
		} elseif ( $ri !== false && $ci === false ) {
			// This applies to all cells in a row
			$sel = '';

			if ( $specify_layout ) {
				$sel = '#pl-' . $li . ' ';
			}
			$sel .= is_string( $ri ) ? ( '#' . $ri ) : '#pg-' . $li . '-' . $ri;

			// If row styles are set, there's a row style wrapper between the row and the cell, so we need to include
			// the selector for both. This is a somewhat hacky fix, but trying to prevent further breakage in existing
			// layouts.
			$sel_with_style = ', ' . $sel . ' > .panel-row-style';

			$sel .= ' > .panel-grid-cell';
			$sel_with_style .= ' > .panel-grid-cell';

			$selector_parts[] = $sel;
			$selector_parts[] = $sel_with_style;
		} elseif ( $ri !== false && $ci !== false ) {
			// This applies to a specific cell
			if ( $specify_layout ) {
				$selector_parts[] = '#pl-' . $li;
			}
			$selector_parts[] = '#pgc-' . $li . '-' . $ri . '-' . $ci;
		}

		$selector = implode( ' ', $selector_parts );

		if ( ! empty( $sub_selector ) ) {
			$selector = $this->add_sub_selector( $selector, $sub_selector );
		}

		// Add this to the CSS array
		$this->add_css( $selector, $attributes, $resolution );
	}

	/**
	 * Add widget specific CSS
	 *
	 * @param int      $li             The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool $ri             The row index. If false, then the CSS applies to all rows.
	 * @param int|bool $ci             The cell index. If false, then the CSS applies to all rows.
	 * @param int|bool $wi             The widget index. If false, then CSS applies to all widgets.
	 * @param string   $sub_selector   A sub selector if we need one.
	 * @param array    $attributes     An array of attributes.
	 * @param int      $resolution     The pixel resolution that this applies to
	 * @param bool     $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	public function add_widget_css( $li, $ri = false, $ci = false, $wi = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false ) {
		if ( is_string( $li ) && ! isset( $this->safe_indexes[ $li ] ) ) {
			$li = $this->safe_selector_index( $li );
		}
		if ( is_string( $ri ) && ! isset( $this->safe_indexes[ $ri ] ) ) {
			$ri = $this->safe_selector_index( $ri );
		}
		if ( is_string( $ci ) && ! isset( $this->safe_indexes[ $ci ] ) ) {
			$ci = $this->safe_selector_index( $ci );
		}
		if ( is_string( $wi ) && ! isset( $this->safe_indexes[ $wi ] ) ) {
			$wi = $this->safe_selector_index( $wi );
		}

		$selector = array();

		if ( $ri === false && $ci === false && $wi === false ) {
			// This applies to all widgets in the layout
			$selector[] = '#pl-' . $li;
			$selector[] = '.so-panel';
		} elseif ( $ri !== false && $ci === false && $wi === false ) {
			// This applies to all widgets in a row
			if ( $specify_layout ) {
				$selector[] = '#pl-' . $li;
			}
			$selector[] = is_string( $ri ) ? ( '#' . $ri ) : '#pg-' . $li . '-' . $ri;
			$selector[] = '.so-panel';
		} elseif ( $ri !== false && $ci !== false && $wi === false ) {
			if ( $specify_layout ) {
				$selector[] = '#pl-' . $li;
			}
			$selector[] = '#pgc-' . $li . '-' . $ri . '-' . $ci;
			$selector[] = '.so-panel';
		} else {
			// This applies to a specific widget
			if ( $specify_layout ) {
				$selector[] = '#pl-' . $li;
			}
			$selector[] = '#panel-' . $li . '-' . $ri . '-' . $ci . '-' . $wi;
		}

		$selector = implode( ' ', $selector );
		$selector = $this->add_sub_selector( $selector, $sub_selector );

		// Add this to the CSS array
		$this->add_css( $selector, $attributes, $resolution );
	}

	/**
	 * Add a sub selector to the main selector
	 *
	 * @param string       $selector
	 * @param string|array $sub_selector
	 *
	 * @return string
	 */
	private function add_sub_selector( $selector, $sub_selector ) {
		$return = array();

		if ( ! empty( $sub_selector ) ) {
			if ( ! is_array( $sub_selector ) ) {
				$sub_selector = array( $sub_selector );
			}

			foreach ( $sub_selector as $sub ) {
				$return[] = $selector . $sub;
			}
		} else {
			$return = array( $selector );
		}

		return implode( ', ', $return );
	}

	/**
	 * Gets the CSS for this particular layout.
	 */
	public function get_css() {
		// Build actual CSS from the array
		$css_text = '';
		krsort( $this->css );

		foreach ( $this->css as $res => $def ) {
			if ( strpos( $res, ':' ) !== false ) {
				list( $max_res, $min_res ) = explode( ':', $res, 2 );
			} else {
				$min_res = false;
				$max_res = $res;
			}

			if ( empty( $def ) ) {
				continue;
			}

			if ( $max_res === '' && $min_res > 0 ) {
				$css_text .= '@media (min-width:' . (int) $min_res . 'px) {';
			} elseif ( $max_res < 1920 ) {
				$css_text .= '@media (max-width:' . (int) $max_res . 'px)';

				if ( ! empty( $min_res ) ) {
					$css_text .= ' and (min-width:' . (int) $min_res . 'px) ';
				}
				$css_text .= '{ ';
			}

			foreach ( $def as $property => $selector ) {
				$selector = array_unique( $selector );
				$css_text .= implode( ' , ', $selector ) . ' { ' . $property . ' } ';
			}

			if ( ( $max_res === '' && $min_res > 0 ) || $max_res < 1920 ) {
				$css_text .= ' } ';
			}
		}

		return $css_text;
	}
}
