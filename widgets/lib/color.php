<?php
/**
 * Some color classes to help the widgets class
 *
 * @license GPL 2.0
 * @author Greg Priday
 */


/**
 * This is a very simple color conversion class. It just offers some static function for conversions.
 */
class SiteOrigin_Color {
	/**
	 * @param mixed $input A color representation.
	 *
	 * @return array An RGB array.
	 */
	public static function rgb($input){
		if(is_array($input)) return $input;
		elseif(is_float($input)) $input = 255*$input;

		return array($input,$input,$input);
	}

	public static function hex2rgb($hex) {
		$hex = (string) $hex;
		if(!is_string($hex) || $hex[0] != '#') throw new Exception('Invalid hex color ['.$hex.']');
		$hex = preg_replace("/[^0-9A-Fa-f]/", '', $hex); // Gets a proper hex string
		$rgb = array();

		if (strlen($hex) == 6) { //If a proper hex code, convert using bitwise operation. No overhead... faster
			$color_val = hexdec($hex);
			$rgb[0] = 0xFF & ($color_val >> 0x10);
			$rgb[1] = 0xFF & ($color_val >> 0x8);
			$rgb[2] = 0xFF & $color_val;
		}
		elseif (strlen($hex) == 3) { //if shorthand notation, need some string manipulations
			$rgb[0] = hexdec(str_repeat(substr($hex, 0, 1), 2));
			$rgb[1] = hexdec(str_repeat(substr($hex, 1, 1), 2));
			$rgb[2] = hexdec(str_repeat(substr($hex, 2, 1), 2));
		}
		else {
			throw new Exception('Invalid hex color');
		}

		foreach($rgb as $i => $p) $rgb[$i] = self::maxmin(round($p),0,255);
		return $rgb;
	}

	/**
	 * Convert RGB to HEX
	 */
	public static function rgb2hex($rgb){
		$hex = '#';
		foreach($rgb as $p){
			$p = base_convert($p,10,16);
			$p = str_pad($p,2,'0',STR_PAD_LEFT);
			$hex .= $p;
		}
		return strtoupper($hex);
	}

	/**
	 * Convert a HSV color to an RGB color.
	 *
	 * @param array $hsv HSV array with values 0-1
	 */
	public static function hsv2rgb ($hsv)
	{
		// The return RGB value
		$rgb = array();

		if($hsv[1] == 0){
			$rgb = array_fill(0,3,$hsv[2] * 255);
		}
		else{
			// Break hue into 6 possible segments
			$hue = $hsv[0] * 6;
			$hue_range = floor( $hue );

			$v = array(
				$hsv[2] * ( 1 - $hsv[1] ),
				$hsv[2] * ( 1 - $hsv[1] * ( $hue - $hue_range ) ),
				$hsv[2] * (1 - $hsv[1] * (1 - ($hue-$hue_range)))
			);

			switch($hue_range){
				case 0:
					$rgb[0] = $hsv[2]; $rgb[1] = $v[2]; $rgb[2] = $v[0];
					break;
				case 1:
					$rgb[0] = $v[1]; $rgb[1] = $hsv[2]; $rgb[2] = $v[0];
					break;
				case 2:
					$rgb[0] = $v[0]; $rgb[1] = $hsv[2]; $rgb[2] = $v[2];
					break;
				case 3:
					$rgb[0] = $v[0]; $rgb[1] = $v[1]; $rgb[2] = $hsv[2];
					break;
				case 4:
					$rgb[0] = $v[2]; $rgb[1] = $v[0]; $rgb[2] = $hsv[2];
					break;
				default :
					$rgb[0] = $hsv[2]; $rgb[1] = $v[0]; $rgb[2] = $v[1];
					break;
			}

			$rgb[0] = round($rgb[0] * 255);
			$rgb[1] = round($rgb[1] * 255);
			$rgb[2] = round($rgb[2] * 255);
		}

		// Make sure the parts are in the proper range
		foreach($rgb as $i => $p) $rgb[$i] = self::maxmin(round($p),0,255);
		return $rgb;
	}

	/**
	 * Converts an RGB color to an XYZ color.
	 *
	 * @param array $color The input color. Values from 0-255.
	 */
	public static function rgb2xyz(array $rgb)
	{
		foreach($rgb as $i => $c) $rgb[$i] /= 255;

		foreach($rgb as $i => $c){
			if ($c > 0.04045){ $rgb[$i] = pow(($c + 0.055) / 1.055, 2.4); }
			else { $rgb[$i] = $c / 12.92; }

			$rgb[$i] = $rgb[$i] * 100;
		}

		//Observer. = 2¡, Illuminant = D65
		$xyz = array(0,0,0);
		$xyz[0] = $rgb[0] * 0.4124 + $rgb[1] * 0.3576 + $rgb[2] * 0.1805;
		$xyz[1] = $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
		$xyz[2] = $rgb[0] * 0.0193 + $rgb[1] * 0.1192 + $rgb[2] * 0.9505;

		return $xyz;
	}

	/**
	 * Convert a RGB color to a HSV color
	 *
	 * @param array $rgb RGB array with values 0-255
	 */
	public static function rgb2hsv ($rgb)
	{
		$rgb = self::rgb($rgb);

		$rgb[0] = ($rgb[0] / 255);
		$rgb[1] = ($rgb[1] / 255);
		$rgb[2] = ($rgb[2] / 255);

		$min = min($rgb[0], $rgb[1], $rgb[2]);
		$max = max($rgb[0], $rgb[1], $rgb[2]);
		$del_max = $max - $min;

		$hsv = array(0,0,$max);

		if ($del_max != 0){
			$hsv[1] = $del_max / $max;

			$del_r = ( ( ( $del_max - $rgb[0] ) / 6 ) + ( $del_max / 2 ) ) / $del_max;
			$del_g = ( ( ( $del_max - $rgb[1] ) / 6 ) + ( $del_max / 2 ) ) / $del_max;
			$del_b = ( ( ( $del_max - $rgb[2] ) / 6 ) + ( $del_max / 2 ) ) / $del_max;

			if ($rgb[0] == $max) $hsv[0] = $del_b - $del_g;
			else if ($rgb[1] == $max) $hsv[0] = ( 1 / 3 ) + $del_r - $del_b;
			else if ($rgb[2] == $max) $hsv[0] = ( 2 / 3 ) + $del_g - $del_r;

			if ($hsv[0] < 0) $hsv[0]++;
			if ($hsv[0] > 1) $hsv[0]--;
		}

		return $hsv;
	}

	/**
	 * Converts a LAB color into RGB
	 */
	public static function lab2xyz(array $lab)
	{
		foreach($lab as $i => $c) $lab[$i] *= 100;

		// Observer= 2¡, Illuminant= D65
		$REF_X = 95.047;
		$REF_Y = 100.000;
		$REF_Z = 108.883;

		$xyz = array();

		$xyz[1] = ($lab[0] + 16) / 116;
		$xyz[0] = $lab[1] / 500 + $xyz[1];
		$xyz[2] = $xyz[1] - $lab[2] / 200;

		foreach($xyz as $i => $c){
			if ( pow( $c , 3 ) > 0.008856 ) { $xyz[$i] = pow( $c , 3 ); }
			else { $xyz[$i] = ( $c - 16 / 116 ) / 7.787; }
		}

		$xyz[0] *= $REF_X;
		$xyz[1] *= $REF_Y;
		$xyz[2] *= $REF_Z;

		return $xyz;
	}


	/**
	 * Convert XYZ color to a LAB color
	 */
	public static function xyz2lab(array $xyz)
	{
		// Observer= 2¡, Illuminant= D65
		$REF_X = 95.047;
		$REF_Y = 100.000;
		$REF_Z = 108.883;

		$xyz[0] = $xyz[0] / $REF_X;
		$xyz[1] = $xyz[1] / $REF_Y;
		$xyz[2] = $xyz[2] / $REF_Z;

		foreach($xyz as $i => $c){
			if ($c > 0.008856 ) { $xyz[$i] = pow( $c , 1/3 ); }
			else { $xyz[$i] = ( 7.787 * $c ) + ( 16/116 ); }
		}

		$lab = array();
		$lab[0] = ( 116 * $xyz[1] ) - 16;
		$lab[1] = 500 * ( $xyz[0] - $xyz[1] );
		$lab[2] = 200 * ( $xyz[1] - $xyz[2] );

		foreach($lab as $i => $c) $lab[$i] /= 100;

		return $lab;
	}

	/**
	 * Convert an XYZ color to an RGB color
	 */
	public static function xyz2rgb($xyz)
	{
		// (Observer = 2¡, Illuminant = D65)
		$xyz[0] /= 100; //X from 0 to  95.047
		$xyz[1] /= 100; //Y from 0 to 100.000
		$xyz[2] /= 100; //Z from 0 to 108.883

		$rgb = array();

		$rgb[0] = $xyz[0] * 3.2406 + $xyz[1] * -1.5372 + $xyz[2] * -0.4986;
		$rgb[1] = $xyz[0] * -0.9689 + $xyz[1] * 1.8758 + $xyz[2] * 0.0415;
		$rgb[2] = $xyz[0] * 0.0557 + $xyz[1] * -0.2040 + $xyz[2] * 1.0570;

		foreach($rgb as $i => $c){
			if ( $c > 0.0031308 ) { $rgb[$i] = 1.055 * pow( $c , ( 1 / 2.4 ) ) - 0.055; }
			else { $rgb[$i] = 12.92 * $c; }
		}

		$rgb[0] = round(min(max($rgb[0],0),1) * 255);
		$rgb[1] = round(min(max($rgb[1],0),1) * 255);
		$rgb[2] = round(min(max($rgb[2],0),1) * 255);

		return $rgb;
	}

	// Combine the primary functions to create all 6 conversion functions

	/**
	 * Convert an RGB color to a LAB color.
	 */
	public static function rgb2lab($rgb)
	{
		$xyx = self::rgb2xyz(self::rgb($rgb));
		return self::xyz2lab($xyx);
	}

	/**
	 * Convert a LAB color to a
	 */
	public static function lab2rgb($lab)
	{
		$xyx = self::lab2xyz($lab);
		return self::xyz2rgb($xyx);
	}

	/**
	 * Convert a LAB color to HSV
	 */
	public static function lab2hsv($lab)
	{
		$rgb = self::lab2rgb($lab);
		return self::rgb2hsv($rgb);
	}

	/**
	 * Convert an HSV color to LAB
	 */
	public static function hsv2lab($hsv)
	{
		$rgb = self::hsv2rgb($hsv);
		return self::rgb2lab($rgb);
	}

	/**
	 * Makes sure that the given value falls inside a range.
	 */
	public static function maxmin($i, $min, $max){
		return min(max($i,$min),$max);
	}

	public static function float2hex($float){
		$hsv = array(
			0,
			0,
			$float
		);

		return self::rgb2hex(self::hsv2rgb($hsv));
	}
}

/**
 * A color conversions class. Of course, you really spell it colour. Color conversion based on algorithms form EasyRGB <http://www.easyrgb.com/>.
 *
 * @author Greg Priday <greg@siteorigin.com>
 * @copyright Copyright (c) 2011, Greg Priday
 * @license GPL <http://www.gnu.org/copyleft/gpl.html>
 */
class SiteOrigin_Color_Object extends SiteOrigin_Color{
	private $changed;

	/**
	 * The hex value of this color before it was varied.
	 */
	private $color;
	private $type;

	const COLOR_HSV = 'hsv';
	const COLOR_RGB = 'rgb';
	const COLOR_LAB = 'lab';

	const COLOR_GREY = 'grey';
	const COLOR_HEX = 'hex';

	const COLOR_RGB_R = 'red';
	const COLOR_RGB_G = 'green';
	const COLOR_RGB_B = 'blue';

	const COLOR_LAB_L = 'lum';
	const COLOR_LAB_A = 'a';
	const COLOR_LAB_B = 'b';

	const COLOR_HSV_H = 'hue';
	const COLOR_HSV_S = 'sat';
	const COLOR_HSV_V = 'val';

	function __construct($color, $type = self::COLOR_HEX){
		if($type == self::COLOR_HEX){
			$this->type = self::COLOR_RGB;
			$this->color = self::hex2rgb($color);
		}
		elseif(is_numeric($color) && $type == self::COLOR_GREY){
			// We're going to assume this is a greyscale color
			$this->type = self::COLOR_HSV;
			$this->color = array(1,0,min(max($color,0),1));
		}
		elseif($type == self::COLOR_GREY){
			if(!is_int($color)) throw Exception('Invalid color');
			$this->type = self::COLOR_RGB;
			$this->color = array($color,$color,$color);
		}
		else{
			$this->color = $color;
			$this->type = $type;
		}

		$this->changed = array();
	}

	/**
	 * Get a color or color part
	 */
	public function __get($name)
	{
		$colors = array(
			self::COLOR_HSV => array(self::COLOR_HSV_H, self::COLOR_HSV_S, self::COLOR_HSV_V),
			self::COLOR_RGB => array(self::COLOR_RGB_R, self::COLOR_RGB_G, self::COLOR_RGB_B),
			self::COLOR_LAB => array(self::COLOR_LAB_L, self::COLOR_LAB_A, self::COLOR_LAB_B)
		);

		if($name == 'hex') {
			return self::rgb2hex($this->rgb);
		}
		elseif(in_array($name, array_keys($colors))){
			// We need a color array
			if($name == $this->type) return $this->color;
			else{
				$func = $this->type.'2'.$name;
				return call_user_func(array($this,$func), $this->color);
			}
		}
		else{
			// We need an individual color element
			foreach($colors as $type => $parts){
				if(in_array($name, $parts)){
					$color = $this->{$type};
					$i = array_search($name, $parts);
					return $color[$i];
				}
			}
		}

	}

	/**
	 * Set a color or color part.
	 */
	public function __set($name, $value)
	{
		$this->changed[] = $name;

		$colors = array(
			self::COLOR_HSV => array(self::COLOR_HSV_H, self::COLOR_HSV_S, self::COLOR_HSV_V),
			self::COLOR_RGB => array(self::COLOR_RGB_R, self::COLOR_RGB_G, self::COLOR_RGB_B),
			self::COLOR_LAB => array(self::COLOR_LAB_L, self::COLOR_LAB_A, self::COLOR_LAB_B)
		);

		if($name == 'hex'){
			$this->type = 'rgb';
			$this->color = self::hex2rgb($value);
		}
		elseif(in_array($name, array_keys($colors))){
			$this->type = $name;
			$this->color = $value;
		}
		else{
			foreach($colors as $type => $parts){
				if(in_array($name, $parts)){
					$color = $this->{$type};
					$i = array_search($name, $parts);
					$color[$i] = $value;

					$this->type = $type;
					$this->color = $color;
				}
			}
		}
	}

	/**
	 * @return array
	 */
	public function get_changed(){
		return $this->changed;
	}

	public function __toString() {
		return $this->hex;
	}

	/**
	 * Calculates the percieved difference between 2 colors.
	 */
	public static function distance(SiteOrigin_Color_Object $c1, SiteOrigin_Color_Object $c2){
		return sqrt(
			pow($c1->lab[0]-$c2->lab[0],2) +
			pow($c1->lab[1]-$c2->lab[1],2) +
			pow($c1->lab[2]-$c2->lab[2],2)
		);
	}
}