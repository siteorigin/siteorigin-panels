<?php

class SiteOrigin_Panels_Styles_Admin {

	function __construct() {
		add_action( 'wp_ajax_so_panels_style_form', array( $this, 'action_style_form' ) );

		add_filter( 'siteorigin_panels_data', array( $this, 'convert_data' ) );
		add_filter( 'siteorigin_panels_prebuilt_layout', array( $this, 'convert_data' ) );

		add_filter( 'siteorigin_panels_general_current_styles', array( $this, 'style_migration' ), 10, 4 );
	}

	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Admin action for handling fetching the style fields
	 */
	function action_style_form() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die(
				__( 'The supplied nonce is invalid.', 'siteorigin-panels' ),
				__( 'Invalid nonce.', 'siteorigin-panels' ),
				403
			);
		}
		
		$type = $_REQUEST['type'];
		
		if ( ! in_array( $type, array( 'row', 'cell', 'widget' ) ) ) {
			wp_die(
				__( 'Please specify the type of style form to be rendered.', 'siteorigin-panels' ),
				__( 'Missing style form type.', 'siteorigin-panels' ),
				400
			);
		}

		$post_id = empty( $_REQUEST['postId'] ) ? 0 : $_REQUEST['postId'];
		$args = ! empty( $_POST['args'] ) ? json_decode( stripslashes( $_POST['args'] ), true ) : array();

		$current = apply_filters(
			'siteorigin_panels_general_current_styles', 
			isset( $_REQUEST['style'] ) ? $_REQUEST['style'] : array(),
			$post_id,
			$type,
			$args
		);

		$current = apply_filters(
			'siteorigin_panels_general_current_styles_' . $type, 
			$current,
			$post_id,
			$args
		);

		switch ( $type ) {
			case 'row':
				$this->render_styles_fields( 'row', '<h3>' . __( 'Row Styles', 'siteorigin-panels' ) . '</h3>', '', $current, $post_id, $args );
				break;

			case 'cell':
				$cell_number = isset( $args['index'] ) ? ' ' . ( (int) $args['index'] + 1 ) : '';
				$this->render_styles_fields( 'cell', '<h3>' . sprintf( __( 'Cell%s Styles', 'siteorigin-panels' ), $cell_number ) . '</h3>', '', $current, $post_id, $args );
				break;

			case 'widget':
				$this->render_styles_fields( 'widget', '<h3>' . __( 'Widget Styles', 'siteorigin-panels' ) . '</h3>', '', $current, $post_id, $args );
				break;
		}

		wp_die();
	}

	/**
	 * Render all the style fields
	 *
	 * @param $section
	 * @param string $before
	 * @param string $after
	 * @param array $current
	 * @param int $post_id
	 * @param array $args Arguments passed by the builder
	 *
	 * @return bool
	 */
	function render_styles_fields( $section, $before = '', $after = '', $current = array(), $post_id = 0, $args = array() ) {
        $fields = array();
        $fields = apply_filters( 'siteorigin_panels_' . $section . '_style_fields', $fields, $post_id, $args );
        $fields = apply_filters( 'siteorigin_panels_general_style_fields', $fields, $post_id, $args );
        if ( empty( $fields ) ) {
			return false;
		}

		$groups = array(
			'attributes' => array(
				'name'     => __( 'Attributes', 'siteorigin-panels' ),
				'priority' => 5
			),
			'layout'     => array(
				'name'     => __( 'Layout', 'siteorigin-panels' ),
				'priority' => 10
			),
			'tablet_layout'     => array(
				'name'     => __( 'Tablet Layout', 'siteorigin-panels' ),
				'priority' => 11
			),
			'mobile_layout'     => array(
				'name'     => __( 'Mobile Layout', 'siteorigin-panels' ),
				'priority' => 12
			),
			'design'     => array(
				'name'     => __( 'Design', 'siteorigin-panels' ),
				'priority' => 15
			),
		);

		if ( ! siteorigin_panels_setting( 'tablet-layout' ) ) {
			unset( $groups['tablet_layout'] );
		}

		// Check if we need a default group
		foreach ( $fields as $field_id => $field ) {
			if ( empty( $field['group'] ) || $field['group'] == 'theme' ) {
				if ( empty( $groups['theme'] ) ) {
					$groups['theme'] = array(
						'name'     => __( 'Theme', 'siteorigin-panels' ),
						'priority' => 10
					);
				}
				$fields[ $field_id ]['group'] = 'theme';
			}
		}
		$groups = apply_filters( 'siteorigin_panels_' . $section . '_style_groups', $groups, $post_id, $args );
		$groups = apply_filters( 'siteorigin_panels_general_style_groups', $groups, $post_id, $args );

		// Sort the style fields and groups by priority
		uasort( $fields, array( $this, 'sort_fields' ) );
		uasort( $groups, array( $this, 'sort_fields' ) );

		echo $before;

		$group_counts = array();
		foreach ( $fields as $field_id => $field ) {
			if ( empty( $group_counts[ $field['group'] ] ) ) {
				$group_counts[ $field['group'] ] = 0;
			}
			$group_counts[ $field['group'] ] ++;
		}

		foreach ( $groups as $group_id => $group ) {

			if ( empty( $group_counts[ $group_id ] ) ) {
				continue;
			}

			?>
			<div class="style-section-wrapper">
				<div class="style-section-head" tabindex="0">
					<h4><?php echo esc_html( $group['name'] ) ?></h4>
				</div>
				<div class="style-section-fields" style="display: none">
					<?php
					foreach ( $fields as $field_id => $field ) {
						$default = isset( $field[ 'default' ] ) ? $field[ 'default' ] : false;

						if ( $field['group'] == $group_id ) {
							?>
							<div class="style-field-wrapper so-field-<?php echo esc_attr( $field_id ); ?>">
								<?php if ( ! empty( $field['name'] ) ) : ?>
									<label><?php echo $field['name'] ?></label>
								<?php endif; ?>
								<div
									class="style-field style-field-<?php echo sanitize_html_class( $field['type'] ) ?>">
									<?php $this->render_style_field( $field, isset( $current[ $field_id ] ) ? $current[ $field_id ] : $default, $field_id, $current ) ?>
								</div>
							</div>
							<?php

						}

					}
					?>
				</div>
			</div>
			<?php
		}

		echo $after;
	}

	/**
	 * Generate the style field
	 *
	 * @param array $field Everything needed to display the field
	 * @param $current
	 * @param $field_id
	 * @param $current_styles
	 */
	function render_style_field( $field, $current, $field_id, $current_styles ) {
		$field_name = 'style[' . $field_id . ']';

		echo '<div class="style-input-wrapper">';
		switch ( $field['type'] ) {
			case 'measurement' :

				if ( ! empty( $field['multiple'] ) ) {
					?>
					<div class="measurement-inputs">
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-top"
							       placeholder="<?php _e( 'Top', 'siteorigin-panels' ) ?>"/>
						</div>
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-right"
							       placeholder="<?php _e( 'Right', 'siteorigin-panels' ) ?>"/>
						</div>
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-bottom"
							       placeholder="<?php _e( 'Bottom', 'siteorigin-panels' ) ?>"/>
						</div>
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-left"
							       placeholder="<?php _e( 'Left', 'siteorigin-panels' ) ?>"/>
						</div>
					</div>
					<?php
				} else {
					?><input type="text" class="measurement-value measurement-value-single"/><?php
				}

				?>
				<select
					class="measurement-unit measurement-unit-<?php echo ! empty( $field['multiple'] ) ? 'multiple' : 'single' ?>">
					<?php foreach ( $this->measurements_list() as $measurement ): ?>
						<option
							value="<?php echo esc_html( $measurement ) ?>"><?php echo esc_html( $measurement ) ?></option>
					<?php endforeach ?>
				</select>
				<input type="hidden" name="<?php echo esc_attr( $field_name ) ?>"
				       value="<?php echo esc_attr( $current ) ?>"/>
				<?php
				break;

			case 'color' :
				?>
				<input type="text" name="<?php echo esc_attr( $field_name ) ?>"
				       value="<?php echo esc_attr( $current ) ?>" class="so-wp-color-field"/>
				<?php
				break;

			case 'image' :
				$image = false;
				if ( ! empty( $current ) ) {
					$image = SiteOrigin_Panels_Styles::get_attachment_image_src( $current, 'thumbnail' );
				}
				
				$fallback_url = ( ! empty( $current_styles[ $field_id . '_fallback' ] ) &&  $current_styles[ $field_id . '_fallback' ] !== 'false' ? $current_styles[ $field_id . '_fallback' ] : '' );
				$fallback_field_name = 'style[' . $field_id . '_fallback]';

				?>
				<div class="so-image-selector" tabindex="0">
					<div class="current-image" <?php if ( ! empty( $image ) ) {
						echo 'style="background-image: url(' . esc_url( $image[0] ) . ');"';
					} ?>>
					</div>

					<div class="select-image">
						<?php _e( 'Select Image', 'siteorigin-panels' ) ?>
					</div>
					<input type="hidden" name="<?php echo esc_attr( $field_name ) ?>"
					       value="<?php echo (int) $current; ?>"/>
				</div>
				<a href="#" class="remove-image <?php if ( empty( (int) $current ) ) echo ' hidden' ?>"><?php _e( 'Remove', 'siteorigin-panels' ) ?></a>
				
				<input type="text" value="<?php echo esc_url( $fallback_url ) ?>"
					   placeholder="<?php esc_attr_e( 'External URL', 'siteorigin-panels' ) ?>"
					   name="<?php echo esc_attr( $fallback_field_name ) ?>"
					   class="image-fallback widefat" />
				<?php
				break;

			case 'image_size':
				$sizes = self::get_image_sizes();
				?>
				<select name="<?php echo esc_attr( $field_name ); ?>">
					<?php foreach ( $sizes as $size_name => $size_config ) : ?>
						<?php $sizing_label = ! empty( $size_config['width'] ) && is_numeric( $size_config['width'] ) ? ' (' . $size_config['width'] . 'x' . $size_config['height'] . ')' : ''; ?>
						<option
							value="<?php echo esc_attr( $size_name ); ?>"
							<?php selected( $current, $size_name ); ?>
						>
							<?php echo esc_html( ucwords( preg_replace( '/[-_]/', ' ', $size_name ) ) . $sizing_label ); ?>	
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				break;
			case 'url' :
			case 'text' :
				?><input type="text" name="<?php echo esc_attr( $field_name ); ?>"
				         value="<?php echo esc_attr( $current ); ?>" class="widefat" /><?php
				break;

			case 'number' :
				?><input type="number" name="<?php echo esc_attr( $field_name ); ?>"
				         value="<?php echo esc_attr( $current ); ?>" class="widefat" /><?php
				break;

			case 'checkbox' :
				$current = (bool) $current;
				?>
				<label class="so-checkbox-label">
					<input type="checkbox" name="<?php echo esc_attr( $field_name ) ?>" <?php checked( $current ) ?> />
					<?php echo esc_html( isset( $field['label'] ) ? $field['label'] : __( 'Enabled', 'siteorigin-panels' ) ) ?>
				</label>
				<?php
				break;

			case 'select' :
				?>
				<select name="<?php echo esc_attr( $field_name ) ?>">
					<?php foreach ( $field['options'] as $k => $v ) : ?>
						<option
							value="<?php echo esc_attr( $k ) ?>" <?php selected( $current, $k ) ?>><?php echo esc_html( $v ) ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'radio' :
				$radio_id = $field_name . '-' . uniqid();
				foreach ( $field['options'] as $k => $v ) :
					?>
					<label for="<?php echo esc_attr( $radio_id . '-' . $k ) ?>">
						<input type="radio" name="<?php echo esc_attr( $radio_id ) ?>"
					       id="<?php echo esc_attr( $radio_id . '-' . $k ) ?>"
					       value="<?php echo esc_attr( $k ) ?>" <?php checked( $k, $current ) ?>> <?php echo esc_html( $v ) ?>
					</label>
					<?php
				endforeach;
				break;

			case 'textarea' :
			case 'code' :
				?><textarea type="text" name="<?php echo esc_attr( $field_name ) ?>"
				            class="widefat <?php if ( $field['type'] == 'code' ) {
					            echo 'so-field-code';
				            } ?>" rows="4"><?php echo esc_textarea( stripslashes( $current ) ) ?></textarea><?php
				break;
			case 'toggle' :
				$current = (bool) $current;
				?>

				<?php echo esc_html( isset( $field['label'] ) ? $field['label'] : '' ); ?>
				<label class="so-toggle-switch">
					<input class="so-toggle-switch-input" type="checkbox" <?php checked( $current ); ?> name="<?php echo esc_attr( $field_name ); ?>">
					<span class="so-toggle-switch-label" data-on="<?php _e( 'On', 'siteorigin-panels' ); ?>" data-off="<?php _e( 'Off', 'siteorigin-panels' ); ?>"></span>
					<span class="so-toggle-switch-handle"></span>
				</label>

				<?php if ( ! empty( $field['fields'] ) ) : ?>
					<div class="so-toggle-fields">
						<?php foreach ( $field['fields'] as $sub_field_id => $sub_field ) : ?>
							<?php $sub_field_id = $field_id . '_' . $sub_field_id; ?>
							<div class="style-field-wrapper so-field-<?php echo esc_attr( $sub_field_id ); ?>">
								<?php if ( ! empty( $sub_field['name'] ) ) : ?>
									<label><?php echo $sub_field['name'] ?></label>
								<?php endif; ?>
								<div
									class="style-field style-field-<?php echo sanitize_html_class( $sub_field['type'] ) ?>">
									<?php
									$default = isset( $sub_field[ 'default' ] ) ? $sub_field[ 'default' ] : false;
									$this->render_style_field(
										$sub_field,
										isset( $current_styles[ $sub_field_id ] ) ? $current_styles[ $sub_field_id ] : $default,
										$sub_field_id,
										$current_styles
									);
									?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>


				<?php
				break;
		}

		echo '</div>';

		if ( ! empty( $field['description'] ) ) {
			?><p class="so-description"><?php echo wp_kses_post( $field['description'] ) ?></p><?php
		}
	}

	/**
	 * Sanitize the style fields in panels_data
	 *
	 * @param $panels_data
	 *
	 * @return mixed
	 */
	function sanitize_all( $panels_data ) {
		if ( ! empty( $panels_data['widgets'] ) ) {
			// Sanitize the widgets
			for ( $i = 0; $i < count( $panels_data['widgets'] ); $i ++ ) {
				if ( empty( $panels_data['widgets'][ $i ]['panels_info']['style'] ) ) {
					continue;
				}
				$panels_data['widgets'][ $i ]['panels_info']['style'] = $this->sanitize_style_fields( 'widget', $panels_data['widgets'][ $i ]['panels_info']['style'] );
			}
		}

		if ( ! empty( $panels_data['grids'] ) ) {
			// The rows
			for ( $i = 0; $i < count( $panels_data['grids'] ); $i ++ ) {
				if ( empty( $panels_data['grids'][ $i ]['style'] ) ) {
					continue;
				}
				$panels_data['grids'][ $i ]['style'] = $this->sanitize_style_fields( 'row', $panels_data['grids'][ $i ]['style'] );
			}
		}

		if ( ! empty( $panels_data['grid_cells'] ) ) {
			// And finally, the cells
			for ( $i = 0; $i < count( $panels_data['grid_cells'] ); $i ++ ) {
				if ( empty( $panels_data['grid_cells'][ $i ]['style'] ) ) {
					continue;
				}
				$panels_data['grid_cells'][ $i ]['style'] = $this->sanitize_style_fields( 'cell', $panels_data['grid_cells'][ $i ]['style'] );
			}
		}

		return $panels_data;
	}

	/**
	 * Sanitize style fields.
	 *
	 * @param $section
	 * @param $styles
	 *
	 * @return array Sanitized styles
	 */
	function sanitize_style_fields( $section, $styles, $sub_field = array() ) {
		// Use the filter to get the fields for this section.
		if ( empty( $sub_field ) ) {
			if ( empty( $fields_cache[ $section ] ) ) {
				// This filter doesn't pass in the arguments $post_id and $args
				// Plugins looking to extend fields, should always add their fields if these are empty
	            $fields_cache[ $section ] = array();
				$fields_cache[ $section ] = apply_filters( 'siteorigin_panels_' . $section . '_style_fields', $fields_cache[ $section ], false, false );
				$fields_cache[ $section ] = apply_filters( 'siteorigin_panels_general_style_fields', $fields_cache[ $section ], false, false );
			}
			$fields = $fields_cache[ $section ];
		} else {
			$fields = $sub_field;
		}
		
		if ( empty( $fields ) ) {
			return array();
		}

		$return = array();
		foreach ( $fields as $k => $field ) {
			// Skip this if no field type is set
			if ( empty( $field['type'] ) ) {
				continue;
			}

			// Sub fields prefix the parent field name.
			if ( ! empty( $sub_field ) ) {
				$k = $section . '_' . $k;
			}

			// Handle the special case of a checkbox
			if ( $field['type'] == 'checkbox' ) {
				$return[ $k ] = ! empty( $styles[ $k ] ) ? true : '';
				continue;
			}

			// Ignore this if we don't even have a value for the style, unless 'image' field which might have a fallback.
			if ( ! isset( $styles[ $k ] ) || ( $field['type'] != 'image' && $styles[ $k ] == '' ) ) {
				continue;
			}

			switch ( $field['type'] ) {
				case 'color' :
					$color = $styles[ $k ];
					if ( preg_match( '|^#([A-Fa-f0-9]{3,8})$|', $color ) ) {
						$return[ $k ] = $color;
					} else {
						$return[ $k ] = '';
					}
					break;
				case 'image' :
					$return[ $k ] = ! empty( $styles[ $k ] ) ? sanitize_text_field( $styles[ $k ] ) : false;
					$fallback_name = $k . '_fallback';
					if ( empty( $styles[ $k ] ) && empty( $styles[ $fallback_name ] ) ) {
						break;
					}
					$return[ $fallback_name ] = ! empty( $styles[ $fallback_name ] ) ? esc_url_raw( $styles[ $fallback_name ] ) : false;
					break;
				case 'url' :
					$return[ $k ] = esc_url_raw( $styles[ $k ] );
					break;
				case 'measurement' :
					$measurements = array_map( 'preg_quote', $this->measurements_list() );
					if ( ! empty( $field['multiple'] ) ) {
						if ( preg_match_all( '/(?:(-?[0-9\.,]+).*?(' . implode( '|', $measurements ) . ')+)/', $styles[ $k ], $match ) ) {
							$return[ $k ] = $styles[ $k ];
						} else {
							$return[ $k ] = '';
						}
					} else {
						if ( preg_match( '/([-?0-9\.,]+).*?(' . implode( '|', $measurements ) . ')/', $styles[ $k ], $match ) ) {
							$return[ $k ] = $match[1] . $match[2];
						} else {
							$return[ $k ] = '';
						}
					}
					break;
				case 'select' :
				case 'radio' :
					if ( ! empty( $styles[ $k ] ) && in_array( $styles[ $k ], array_keys( $field['options'] ) ) ) {
						$return[ $k ] = $styles[ $k ];
					}
					break;
				case 'toggle' :
					$return[ $k ] = $styles[ $k ];

					$return[ $k ] = ! empty( $styles[ $k ] ) ? true : '';
					if ( ! empty( $field['fields'] ) ) {
						$return = $return + $this->sanitize_style_fields( $k, $styles, $field['fields'] );
					}
				default:
					// Just pass the value through.
					$return[ $k ] = $styles[ $k ];
					break;

			}
		}

		return $return;
	}

	/**
	 * Convert the single string attribute of the grid style into an array.
	 *
	 * @param $panels_data
	 *
	 * @return mixed
	 */
	function convert_data( $panels_data ) {
		if ( empty( $panels_data ) || empty( $panels_data['grids'] ) || ! is_array( $panels_data['grids'] ) ) {
			return $panels_data;
		}

		foreach( $panels_data['grids'] as & $grid ) {
			if ( ! is_array( $grid ) || empty( $grid ) || empty( $grid['style'] ) ) {
				continue;
			}

			if ( is_string( $grid['style'] ) ) {
				$grid['style'] = array(
					$grid['style']
				);
			}
		}

		return $panels_data;
	}

	/**
	 * Migrate deprecated styles.
	 *
	 * @param $style array The currently selected styles.
	 * @param $post_id int The id of the current post.
	 * @param $args array An array containing builder Arguments.
	 *
	 * @return array
	 */
	function style_migration( $style, $post_id, $type, $args ) {
		if ( isset( $style['background_display'] ) && $style['background_display'] == 'parallax-original' ) {
			$style['background_display'] = 'parallax';
		}

		return $style;
	}

	/**
	 * Get list of supported mesurements
	 *
	 * @return array
	 */
	function measurements_list() {
		$measurements = array(
			'px',
			'%',
			'in',
			'cm',
			'mm',
			'em',
			'ex',
			'pt',
			'pc',
			'rem',
			'vw',
			'vh',
			'vmin',
			'vmax',
		);

		// Allow themes and plugins to trim or enhance the list.
		return apply_filters( 'siteorigin_panels_style_get_measurements_list', $measurements );
	}

	static public function get_image_sizes() {
		global $_wp_additional_image_sizes;

		$sizes = array(
			'full' => __( 'Full', 'siteorigin-panels' ),
			'thumb' => __( 'Thumbnail (Theme-defined)', 'siteorigin-panels' ),
		);

		foreach ( get_intermediate_image_sizes() as $size ) {
			if ( in_array( $size, array('thumbnail', 'medium', 'medium_large', 'large') ) ) {
				$sizes[ $size ]['width']  = get_option( "{$size}_size_w" );
				$sizes[ $size ]['height'] = get_option( "{$size}_size_h" );
				$sizes[ $size ]['crop']   = (bool) get_option( "{$size}_crop" );
			} elseif ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
				$sizes[ $size ] = array(
					'width' => $_wp_additional_image_sizes[ $size ]['width'],
					'height' => $_wp_additional_image_sizes[ $size ]['height'],
					'crop' => $_wp_additional_image_sizes[ $size ]['crop'],
				);
			}
		}

		return apply_filters( 'siteorigin_panels_image_sizes', $sizes );
	}

	/**
	 * User sort function to sort by the priority key value.
	 *
	 * @param $a
	 * @param $b
	 *
	 * @return int
	 */
	static function sort_fields( $a, $b ) {
		return ( ( isset( $a['priority'] ) ? $a['priority'] : 10 ) > ( isset( $b['priority'] ) ? $b['priority'] : 10 ) ) ? 1 : - 1;
	}
}

// Initialise all the default styling
SiteOrigin_Panels_Styles::single();
