<?php


/**
 * Class SiteOrigin_Panels_Css_Builder
 *
 * Use for building CSS for a page.
 */
class SiteOrigin_Panels_Css_Builder {

	private $css;

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
	function add_css($selector, $attributes, $resolution = 1920) {
		$attribute_string = array();
		foreach( $attributes as $k => $v ) {
			if( empty( $v ) ) continue;
			$attribute_string[] = $k.':'.$v;
		}
		$attribute_string = implode(';', $attribute_string);

		// Add everything we need to the CSS selector
		if( empty( $this->css[$resolution] ) ) $this->css[$resolution] = array();
		if( empty( $this->css[$resolution][$attribute_string] ) ) $this->css[$resolution][$attribute_string] = array();
		$this->css[$resolution][$attribute_string][] = $selector;
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
	function add_row_css($li, $ri = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false) {
		$selector = array();

		if( $ri === false ) {
			// This applies to all rows
			$selector[] = '#pl-'.$li;
			$selector[] = '.panel-grid';
		}
		else {
			// This applies to a specific row
			if( $specify_layout ) $selector[] = '#pl-'.$li;
			if( is_string($ri) ) {
				$selector[] = '#' . $ri;
			}
			else {
				$selector[] = '#pg-'.$li.'-'.$ri;
			}

		}

		// Add in the sub selector
		if( !empty($sub_selector) ) $selector[] = $sub_selector;

		// Add this to the CSS array
		$this->add_css( implode(' ', $selector), $attributes, $resolution );
	}

	/**
	 * @param int $li The layout ID. If false, then the CSS applies to all layouts.
	 * @param int|bool $ri The row index. If false, then the CSS applies to all rows.
	 * @param int|bool $ci The cell index. If false, then the CSS applies to all rows.
	 * @param string $sub_selector A sub selector if we need one.
	 * @param array $attributes An array of attributes.
	 * @param int $resolution The pixel resolution that this applies to
	 * @param bool $specify_layout Sometimes for CSS specificity, we need to include the layout ID.
	 */
	function add_cell_css( $li, $ri = false, $ci = false, $sub_selector = '', $attributes = array(), $resolution = 1920, $specify_layout = false) {
		$selector = array();

		if( $ri === false && $ci === false ) {
			// This applies to all cells in the layout
			$selector[] = '#pl-'.$li;
			$selector[] = '.panel-grid-cell';
		}
		elseif( $ri !== false && $ci === false ) {
			// This applies to all cells in a row
			if( $specify_layout ) $selector[] = '#pl-'.$li;
			$selector[] = is_string( $ri ) ? ( '#' . $ri ) : '#pg-'.$li.'-'.$ri;
			$selector[] = '.panel-grid-cell';
		}
		elseif( $ri !== false && $ci !== false ) {
			// This applies to a specific cell
			if( $specify_layout ) $selector[] = '#pl-'.$li;
			$selector[] = '#pgc-' . $li . '-' . $ri . '-' . $ci;
		}

		// Add in the sub selector
		if( !empty($sub_selector) ) $selector[] = $sub_selector;

		// Add this to the CSS array
		$this->add_css( implode(' ', $selector), $attributes, $resolution );
	}

	/**
	 * Gets the CSS for this particular layout.
	 */
	function get_css(){
		// Build actual CSS from the array
		$css_text = '';
		krsort( $this->css );
		foreach ( $this->css as $res => $def ) {
			if ( empty( $def ) ) continue;

			if ( $res < 1920 ) {
				$css_text .= '@media (max-width:' . $res . 'px)';
				$css_text .= '{ ';
			}

			foreach ( $def as $property => $selector ) {
				$selector = array_unique( $selector );
				$css_text .= implode( ' , ', $selector ) . ' { ' . $property . ' } ';
			}

			if ( $res < 1920 ) $css_text .= ' } ';
		}

		return $css_text;
	}
}