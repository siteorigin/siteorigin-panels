<?php


/**
 * Class SiteOrigin_Panels_Css_Builder
 *
 * Use for building CSS for a page.
 */
class SiteOrigin_Panels_Css_Builder {

	public $css;

	function __construct() {
		$this->css = array();
	}

	/**
	 * Add some general CSS.
	 *
	 * @param string $selector
	 * @param array $attributes
	 * @param int $resolution The pixel resolution that this applies to
	 */
	public function add_css( $selector, $attributes, $resolution = 1920 ) {
		$attribute_string = array();
		foreach ( $attributes as $k => $v ) {
			
			if( is_array( $v ) ) {
				for( $i = 0; $i < count( $v ); $i++ ) {
					if ( ! strlen( (string) $v[ $i ] ) ) continue;
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
	 * Add CSS that applies to a row or group of rows.
	 *
	 * @param int $li The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool|string $ri The row index. If false, then the CSS applies to all rows.
	 * @param string $sub_selector A sub selector if we need one.
	 * @param array $attributes An array of attributes.
	 * @param int $resolution The pixel resolution that this applies to
	 * @param bool $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	public function add_row_css( $li, $ri = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false ) {
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
	 * @param int $li The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool $ri The row index. If false, then the CSS applies to all rows.
	 * @param int|bool $ci The cell index. If false, then the CSS applies to all rows.
	 * @param string $sub_selector A sub selector if we need one.
	 * @param array $attributes An array of attributes.
	 * @param int $resolution The pixel resolution that this applies to
	 * @param bool $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	public function add_cell_css( $li, $ri = false, $ci = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false ) {
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
	 * @param int $li The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool $ri The row index. If false, then the CSS applies to all rows.
	 * @param int|bool $ci The cell index. If false, then the CSS applies to all rows.
	 * @param int|bool $wi The widget index. If false, then CSS applies to all widgets.
	 * @param string $sub_selector A sub selector if we need one.
	 * @param array $attributes An array of attributes.
	 * @param int $resolution The pixel resolution that this applies to
	 * @param bool $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	public function add_widget_css( $li, $ri = false, $ci = false, $wi = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false ) {
		$selector = array();

		if ( $ri === false && $ci === false && $wi === false ) {
			// This applies to all widgets in the layout
			$selector[] = '#pl-' . $li;
			$selector[] = '.so-panel';
		} else if ( $ri !== false && $ci === false && $wi === false ) {
			// This applies to all widgets in a row
			if ( $specify_layout ) {
				$selector[] = '#pl-' . $li;
			}
			$selector[] = is_string( $ri ) ? ( '#' . $ri ) : '#pg-' . $li . '-' . $ri;
			$selector[] = '.so-panel';
		} else if ( $ri !== false && $ci !== false && $wi === false ) {
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
	 * @param string $selector
	 * @param string|array $sub_selector
	 *
	 * @return string
	 */
	private function add_sub_selector( $selector, $sub_selector ){
		$return = array();

		if( ! empty( $sub_selector ) ) {
			if( ! is_array( $sub_selector ) ) $sub_selector = array( $sub_selector );

			foreach( $sub_selector as $sub ) {
				$return[] = $selector . $sub;
			}
		}
		else {
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
			if( strpos( $res, ':' ) !== false ) {
				list( $max_res, $min_res ) = explode( ':', $res, 2 );
			}
			else {
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

			if ( ( $max_res === '' && $min_res > 0 ) ||  $max_res < 1920 ) {
				$css_text .= ' } ';
			}
		}

		return $css_text;
	}
}
