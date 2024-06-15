<?php

class SiteOrigin_Panels_Styles_Admin {
	public function __construct() {
		add_action( 'wp_ajax_so_panels_style_form', array( $this, 'action_style_form' ) );

		add_filter( 'siteorigin_panels_data', array( $this, 'convert_data' ) );
		add_filter( 'siteorigin_panels_prebuilt_layout', array( $this, 'convert_data' ) );

		add_filter( 'siteorigin_panels_general_current_styles', array( $this, 'style_migration' ), 10, 4 );

		add_filter( 'siteorigin_panels_data_migration', array( $this, 'panels_data_migrations' ) );
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Admin action for handling fetching the style fields
	 */
	public function action_style_form() {
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
				$this->render_styles_fields( 'cell', '<h3>' . sprintf( __( 'Column%s Styles', 'siteorigin-panels' ), $cell_number ) . '</h3>', '', $current, $post_id, $args );
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
	 * @param string $before
	 * @param string $after
	 * @param array  $current
	 * @param int    $post_id
	 * @param array  $args    Arguments passed by the builder
	 *
	 * @return bool
	 */
	public function render_styles_fields( $section, $before = '', $after = '', $current = array(), $post_id = 0, $args = array() ) {
		$fields = array();
		$fields = apply_filters( 'siteorigin_panels_' . $section . '_style_fields', $fields, $post_id, $args );
		$fields = apply_filters( 'siteorigin_panels_general_style_fields', $fields, $post_id, $args );

		if ( empty( $fields ) ) {
			return false;
		}

		$groups = array(
			'attributes' => array(
				'name'     => __( 'Attributes', 'siteorigin-panels' ),
				'priority' => 5,
			),
			'layout'     => array(
				'name'     => __( 'Layout', 'siteorigin-panels' ),
				'priority' => 10,
			),
			'tablet_layout'     => array(
				'name'     => __( 'Tablet Layout', 'siteorigin-panels' ),
				'priority' => 11,
			),
			'mobile_layout'     => array(
				'name'     => __( 'Mobile Layout', 'siteorigin-panels' ),
				'priority' => 12,
			),
			'design'     => array(
				'name'     => __( 'Design', 'siteorigin-panels' ),
				'priority' => 15,
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
						'priority' => 10,
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
					<h4><?php esc_html_e( $group['name'] ); ?></h4>
				</div>
				<div class="style-section-fields" style="display: none">
					<?php
					foreach ( $fields as $field_id => $field ) {
						$default = isset( $field[ 'default' ] ) ? $field[ 'default' ] : false;

						if ( $field['group'] == $group_id ) {
							?>
							<div class="style-field-wrapper so-field-<?php echo esc_attr( $field_id ); ?>">
								<?php if ( ! empty( $field['name'] ) ) { ?>
									<label>
										<?php esc_html_e( $field['name'] ); ?>
									</label>
								<?php } ?>
								<div
									class="style-field style-field-<?php echo sanitize_html_class( $field['type'] ); ?>">
									<?php $this->render_style_field( $field, isset( $current[ $field_id ] ) ? $current[ $field_id ] : $default, $field_id, $current ); ?>
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
	 */
	public function render_style_field( $field, $current, $field_id, $current_styles ) {
		$field_name = 'style[' . $field_id . ']';

		echo '<div class="style-input-wrapper">';
		switch ( $field['type'] ) {
			case 'measurement' :

				if ( ! empty( $field['multiple'] ) ) {
					?>
					<div class="measurement-inputs">
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-top"
							       placeholder="<?php esc_html_e( 'Top', 'siteorigin-panels' ); ?>"/>
						</div>
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-right"
							       placeholder="<?php esc_html_e( 'Right', 'siteorigin-panels' ); ?>"/>
						</div>
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-bottom"
							       placeholder="<?php esc_html_e( 'Bottom', 'siteorigin-panels' ); ?>"/>
						</div>
						<div class="measurement-wrapper">
							<input type="text" class="measurement-value measurement-left"
							       placeholder="<?php esc_html_e( 'Left', 'siteorigin-panels' ); ?>"/>
						</div>
					</div>
					<?php
				} else {
					?><input type="text" class="measurement-value measurement-value-single"/><?php
				}

				?>
				<select
					class="measurement-unit measurement-unit-<?php echo ! empty( $field['multiple'] ) ? 'multiple' : 'single'; ?>">
					<?php foreach ( $this->measurements_list() as $measurement ) { ?>
						<option
							value="<?php echo esc_attr( $measurement ); ?>"><?php esc_html_e( $measurement ); ?></option>
					<?php } ?>
				</select>
				<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>"
				       value="<?php echo esc_attr( $current ); ?>"/>
				<?php
				break;

			case 'color' :
				?>
				<input
					type="text"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="<?php echo esc_attr( $current ); ?>"
					class="so-wp-color-field"
					<?php if ( ! empty( $field['alpha'] ) ) { ?>
						data-alpha-enabled="true"
						data-alpha-color-type="hex"
					<?php } ?>
				/>
				<?php
				break;
			case 'slider' :
				?>
				<div class="so-wp-slider-value">
					<?php echo ! empty( $current ) ? esc_html( $current ) : 100; ?>
				</div>
				<div class="so-wp-slider-wrapper">
					<div class="so-wp-value-slider"></div>
				</div>
				<input
					type="number"
					class="so-wp-input-slider"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="<?php echo ! empty( $current ) ? esc_attr( ( float ) $current ) : 100; ?>"
					min="<?php echo isset( $field['min'] ) ? (float) $field['min'] : 0; ?>"
					max="<?php echo isset( $field['max'] ) ? (float) $field['max'] : 100; ?>"
					step="<?php echo isset( $field['step'] ) ? (float) $field['step'] : 1; ?>"
				/>
				<?php
				break;

			case 'image' :
				$image = false;

				if ( ! empty( $current ) ) {
					$image = SiteOrigin_Panels_Styles::get_attachment_image_src( $current, 'thumbnail' );
				}

				$fallback_url = ( ! empty( $current_styles[ $field_id . '_fallback' ] ) && $current_styles[ $field_id . '_fallback' ] !== 'false' ? $current_styles[ $field_id . '_fallback' ] : '' );
				$fallback_field_name = 'style[' . $field_id . '_fallback]';

				?>
				<div class="so-image-selector" tabindex="0">
					<div class="current-image" <?php if ( ! empty( $image ) ) {
						echo 'style="background-image: url(' . esc_url( $image[0] ) . ');"';
					} ?>>
					</div>

					<div class="select-image">
						<?php esc_html_e( 'Select Image', 'siteorigin-panels' ); ?>
					</div>
					<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>"
					       value="<?php echo (int) $current; ?>"/>
				</div>
				<a href="#" class="remove-image <?php if ( empty( (int) $current ) ) {
					echo ' hidden';
				} ?>"><?php esc_html_e( 'Remove', 'siteorigin-panels' ); ?></a>

				<input type="text" value="<?php echo esc_url( $fallback_url ); ?>"
					   placeholder="<?php esc_attr_e( 'External URL', 'siteorigin-panels' ); ?>"
					   name="<?php echo esc_attr( $fallback_field_name ); ?>"
					   class="image-fallback widefat" />
				<?php
				break;

			case 'image_size':
				$sizes = self::get_image_sizes();
				?>
				<select name="<?php echo esc_attr( $field_name ); ?>">
					<?php foreach ( $sizes as $size_name => $size_config ) { ?>
						<?php $sizing_label = ! empty( $size_config['width'] ) && is_numeric( $size_config['width'] ) ? ' (' . $size_config['width'] . 'x' . $size_config['height'] . ')' : ''; ?>
						<option
							value="<?php echo esc_attr( $size_name ); ?>"
							<?php selected( $current, $size_name ); ?>
						>
							<?php esc_html_e( ucwords( preg_replace( '/[-_]/', ' ', $size_name ) ) . $sizing_label ); ?>
						</option>
					<?php } ?>
				</select>
				<?php
				break;

			case 'url' :
			case 'text' :
				?>
				<input
					type="text"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="<?php echo esc_attr( $current ); ?>" class="widefat"
				/>
				<?php
				break;

			case 'number' :
				?><input
					type="number"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="<?php echo esc_attr( $current ); ?>" class="widefat"
					<?php
					if ( isset( $field['min'] ) ) {
						echo ' min="' . esc_attr( (int) $field['min'] ) . '"';
					}

					if ( isset( $field['max'] ) ) {
						echo ' max="' . esc_attr( (int) $field['max'] ) . '"';
					}
					?>
				/>
				<?php
				break;

			case 'checkbox' :
				$current = (bool) $current;
				?>
				<label class="so-checkbox-label">
					<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" <?php checked( $current ); ?> />
					<?php esc_html_e( isset( $field['label'] ) ? $field['label'] : __( 'Enabled', 'siteorigin-panels' ) ); ?>
				</label>
				<?php
				break;

			case 'select' :
				?>
				<select name="<?php echo esc_attr( $field_name ); ?>">
					<?php foreach ( $field['options'] as $k => $v ) { ?>
						<option
							value="<?php echo esc_attr( $k ); ?>" <?php selected( $current, $k ); ?>><?php esc_html_e( $v ); ?></option>
					<?php } ?>
				</select>
				<?php
				break;

			case 'radio' :
				$radio_id = $field_name . '-' . uniqid();

				foreach ( $field['options'] as $k => $v ) {
					?>
					<label for="<?php echo esc_attr( $radio_id . '-' . $k ); ?>">
						<input type="radio" name="<?php echo esc_attr( $radio_id ); ?>"
					       id="<?php echo esc_attr( $radio_id . '-' . $k ); ?>"
					       value="<?php echo esc_attr( $k ); ?>" <?php checked( $k, $current ); ?>> <?php esc_html_e( $v ); ?>
					</label>
					<?php
				}
				break;

			case 'textarea' :
			case 'code' :
				?><textarea type="text" name="<?php echo esc_attr( $field_name ); ?>"
				            class="widefat <?php if ( $field['type'] == 'code' ) {
				            	echo 'so-field-code';
				            } ?>" rows="4"><?php echo esc_textarea( stripslashes( $current ) ); ?></textarea><?php
				break;

			case 'toggle' :
				$current = (bool) $current;
				?>

				<?php esc_html_e( isset( $field['label'] ) ? $field['label'] : '' ); ?>
				<label class="so-toggle-switch">
					<input class="so-toggle-switch-input" type="checkbox" <?php checked( $current ); ?> name="<?php echo esc_attr( $field_name ); ?>">
					<span class="so-toggle-switch-label" data-on="<?php esc_attr_e( 'On', 'siteorigin-panels' ); ?>" data-off="<?php esc_attr_e( 'Off', 'siteorigin-panels' ); ?>"></span>
					<span class="so-toggle-switch-handle"></span>
				</label>

				<?php if ( ! empty( $field['fields'] ) ) { ?>
					<div class="so-toggle-fields">
						<?php foreach ( $field['fields'] as $sub_field_id => $sub_field ) { ?>
							<?php $sub_field_id = $field_id . '_' . $sub_field_id; ?>
							<div class="style-field-wrapper so-field-<?php echo esc_attr( $sub_field_id ); ?>">
								<?php if ( ! empty( $sub_field['name'] ) ) { ?>
									<label>
										<?php esc_html_e( $sub_field['name'] ); ?>
									</label>
								<?php } ?>
								<div
									class="style-field style-field-<?php echo sanitize_html_class( $sub_field['type'] ); ?>">
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
						<?php } ?>
					</div>
				<?php } ?>


				<?php
				break;
			default:
				// No standard style fields used. See if there's a custom one set.
				$custom_style_field = apply_filters(
					'siteorigin_panels_style_field_' . $field['type'],
					$field,
					$field_name,
					$current,
					$field_id,
					$current_styles
				);

				if ( ! empty( $custom_style_field ) ) {
					echo $custom_style_field;
				}

				break;
		}

		echo '</div>';

		if ( ! empty( $field['description'] ) ) {
			?><p class="so-description"><?php echo wp_kses_post( $field['description'] ); ?></p><?php
		}
	}

	public function panels_data_migrations( $panels_data ) {
		// Full Width Stretch Migrations.
		if ( empty( $panels_data['grids'] ) ) {
			return $panels_data;
		}

		foreach ( $panels_data['grids'] as $grid_id => $row ) {
			if ( empty( $row ) || empty( $row['style'] ) ) {
				continue;
			}

			// Check if the row has the old Row Stretch values.
			if (
				! isset( $row['style']['row_stretch'] ) ||
				(
					$row['style']['row_stretch'] !== 'full-stretched' &&
					$row['style']['row_stretch'] !== 'full-stretched-padded'
				)
			) {
				// It doesn't. Skip it.
				continue;
			}

			if ( $row['style']['row_stretch'] == 'full-stretched' ) {
				if ( ! empty( $row['style']['padding'] ) ) {
					SiteOrigin_Panels_Styles::single()->full_width_stretched_legacy_padding(
						$row['style'],
						'padding'
					);
				}

				if ( ! empty( $row['style']['mobile_padding'] ) ) {
					SiteOrigin_Panels_Styles::single()->full_width_stretched_legacy_padding(
						$row['style'],
						'mobile_padding'
					);
				}

				if ( ! empty( $row['style']['tablet_padding'] ) ) {
					SiteOrigin_Panels_Styles::single()->full_width_stretched_legacy_padding(
						$row['style'],
						'tablet_padding'
					);
				}
			}

			$row['style']['row_stretch'] = 'full-width-stretch';

			$panels_data['grids'][ $grid_id ] = $row;
		}

		return $panels_data;
	}

	/**
	 * Sanitize the style fields in panels_data
	 *
	 * @return mixed
	 */
	public function sanitize_all( $panels_data ) {
		$panels_data = apply_filters( 'siteorigin_panels_data_migration', $panels_data );
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
	 * @return array Sanitized styles
	 */
	public function sanitize_style_fields( $section, $styles, $sub_field = array() ) {
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
					if ( ! empty( $field['alpha'] ) && strpos( $color, 'rgba' ) !== false ) {
						sscanf( $color, 'rgba(%d,%d,%d,%f)', $r, $g, $b, $a );
						if (
							isset( $r ) && isset( $g ) && isset( $b ) && isset( $a )
							&& is_numeric( $r ) && is_numeric( $g ) && is_numeric( $b ) && is_numeric( $a )
						) {
							$return[ $k ] = "rgba($r,$g,$b,$a)";
						} else {
							$return[ $k ] = '';
						}
					} else {
						$return[ $k ] = sanitize_hex_color( $color );
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

				case 'number' :
					$return[ $k ] = (float) $styles[ $k ];

					if ( isset( $field['min'] ) && $return[ $k ] < $field['min'] ) {
						$return[ $k ] = (float) $field['min'];
					}

					if ( isset( $field['max'] ) && $return[ $k ] > $field['max'] ) {
						$return[ $k ] = (float) $field['max'];
					}

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
					break;
				default:
					// No standard style fields used. See if there's a custom one set.
					$custom_style_sanitized_data = apply_filters(
						'siteorigin_panels_style_field_sanitize_' . $field['type'],
						$styles[ $k ],
						$k,
						$field,
						$styles,
						$sub_field
					);

					if ( ! empty( $custom_style_sanitized_data ) ) {
						$return[ $k ] = $custom_style_sanitized_data;
					} else {
						// Just pass the value through.
						$return[ $k ] = $styles[ $k ];
					}

					// Allow field to modify other values.
					$return = apply_filters(
						'siteorigin_panels_style_field_sanitize_all_' . $field['type'],
						$return,
						$return[ $k ],
						$k,
						$field,
						$styles
					);

					break;
			}
		}

		return $return;
	}

	/**
	 * Convert the single string attribute of the grid style into an array.
	 *
	 * @return mixed
	 */
	public function convert_data( $panels_data ) {
		if ( empty( $panels_data ) || empty( $panels_data['grids'] ) || ! is_array( $panels_data['grids'] ) ) {
			return $panels_data;
		}

		foreach ( $panels_data['grids'] as & $grid ) {
			if ( ! is_array( $grid ) || empty( $grid ) || empty( $grid['style'] ) ) {
				continue;
			}

			if ( is_string( $grid['style'] ) ) {
				$grid['style'] = array(
					$grid['style'],
				);
			}
		}

		return $panels_data;
	}

	function migrate_box_shadow( $color, $opacity ) {
		if ( ! class_exists( 'SiteOrigin_Color_Object' ) ) {
			require plugin_dir_path( __FILE__ ) . '../widgets/lib/color.php';
		}
		$color = new SiteOrigin_Color_Object( $color );
		$color = $color->__get( 'rgb' );
		$opacity = $opacity / 100;

		return "rgba($color[0],$color[1],$color[2],$opacity)";
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
	public function style_migration( $style, $post_id, $type, $args ) {
		if ( isset( $style['background_display'] ) && $style['background_display'] == 'parallax-original' ) {
			$style['background_display'] = 'parallax';
		}

		if ( isset( $style['box_shadow_color'] ) && isset( $style['box_shadow_opacity'] ) ) {
			$style['box_shadow_color'] = $this->migrate_box_shadow( $style['box_shadow_color'], $style['box_shadow_opacity'] );
			unset( $style['box_shadow_opacity'] );
		}

		if ( isset( $style['box_shadow_hover_color'] ) && isset( $style['box_shadow_hover_opacity'] ) ) {
			$style['box_shadow_hover_color'] = $this->migrate_box_shadow( $style['box_shadow_hover_color'], $style['box_shadow_hover_opacity'] );
			unset( $style['box_shadow_hover_opacity'] );
		}

		// Migrate old Full Width Stretched layouts to the new value.
		if (
			isset( $style['row_stretch'] ) &&
			(
				$style['row_stretch'] == 'full-stretched' ||
				$style['row_stretch'] == 'full-stretched-padded'
			)
		) {
			// To prevent unexpected spacing, remove any right/left padding.
			if ( $style['row_stretch'] == 'full-stretched' ) {
				SiteOrigin_Panels_Styles::single()->full_width_stretched_legacy_padding( $style, 'padding' );
				SiteOrigin_Panels_Styles::single()->full_width_stretched_legacy_padding( $style, 'mobile_padding' );
				SiteOrigin_Panels_Styles::single()->full_width_stretched_legacy_padding( $style, 'tablet_padding' );
			}

			$style['row_stretch'] = 'full-width-stretch';
		}

		return $style;
	}

	/**
	 * Get list of supported measurements.
	 *
	 * @return array
	 */
	public function measurements_list() {
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

	public static function get_image_sizes() {
		global $_wp_additional_image_sizes;

		$sizes = array(
			'full' => __( 'Full', 'siteorigin-panels' ),
			'thumb' => __( 'Thumbnail (Theme-defined)', 'siteorigin-panels' ),
		);

		foreach ( get_intermediate_image_sizes() as $size ) {
			if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) {
				$sizes[ $size ]['width'] = get_option( "{$size}_size_w" );
				$sizes[ $size ]['height'] = get_option( "{$size}_size_h" );
				$sizes[ $size ]['crop'] = (bool) get_option( "{$size}_crop" );
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
	 * @return int
	 */
	public static function sort_fields( $a, $b ) {
		return ( ( isset( $a['priority'] ) ? $a['priority'] : 10 ) > ( isset( $b['priority'] ) ? $b['priority'] : 10 ) ) ? 1 : - 1;
	}
}

// Initialize all the default styling.
SiteOrigin_Panels_Styles::single();
