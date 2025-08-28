<?php

/**
 * Class SiteOrigin_Panels_Admin
 *
 * Handles all the admin and database interactions.
 */
class SiteOrigin_Panels_Admin_Layouts {
	const LAYOUT_URL = 'https://layouts.siteorigin.com/';

	const VALID_MIME_TYPES = array(
		'application/json',
		'text/plain',
		'text/html',
	);

	public function __construct() {
		// Filter all the available external layout directories.
		add_filter( 'siteorigin_panels_external_layout_directories', array( $this, 'filter_directories' ), 8 );
		// Filter all the available local layout folders.
		add_filter( 'siteorigin_panels_prebuilt_layouts', array( $this, 'get_local_layouts' ), 8 );

		add_action( 'wp_ajax_so_panels_layouts_query', array( $this, 'action_get_prebuilt_layouts' ) );
		add_action( 'wp_ajax_so_panels_get_layout', array( $this, 'action_get_prebuilt_layout' ) );
		add_action( 'wp_ajax_so_panels_import_layout', array( $this, 'action_import_layout' ) );
		add_action( 'wp_ajax_so_panels_export_layout', array( $this, 'action_export_layout' ) );
		add_action( 'wp_ajax_so_panels_directory_enable', array( $this, 'action_directory_enable' ) );
	}

	/**
	 * @return SiteOrigin_Panels_Admin_Layouts
	 */
	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Add the main SiteOrigin layout directory
	 */
	public function filter_directories( $directories ) {
		if ( apply_filters( 'siteorigin_panels_layouts_directory_enabled', true ) ) {
			$directories['siteorigin'] = array(
				// The title of the layouts directory in the sidebar.
				'title' => __( 'Layouts Directory', 'siteorigin-panels' ),
				// The URL of the directory.
				'url'   => self::LAYOUT_URL,
				// Any additional arguments to pass to the layouts server
				'args'  => array(),
			);
		}

		return $directories;
	}

	/**
	 * Get all the layout directories.
	 *
	 * @return array
	 */
	public function get_directories() {
		$directories = apply_filters( 'siteorigin_panels_external_layout_directories', array() );

		if ( empty( $directories ) || ! is_array( $directories ) ) {
			$directories = array();
		}

		return $directories;
	}

	/**
	 * Check if the file has a valid MIME type.
	 *
	 * This method checks if the given file has a valid MIME type. It first verifies
	 * if the `mime_content_type` function exists. If it doesn't, it returns true
	 * as it can't check the MIME type due to server settings.
	 * If the function exists, it retrieves the MIME type of the file and checks
	 * if it is in the list of valid MIME types.
	 *
	 * @param string $file The file path to check.
	 *
	 * @return bool True if the file has a valid MIME type, false otherwise.
	 */
	private static function check_file_mime( $file ) {
		if ( ! function_exists( 'mime_content_type' ) ) {
			// Can't check mime type due to server settings.
			return true;
		}

		$mime_type = mime_content_type( $file );
		return in_array( $mime_type, self::VALID_MIME_TYPES );
	}

	/**
	 * Determines if file has a JSON extension.
	 *
	 * @param string $file File path.
	 * @return bool True if JSON, false otherwise.
	 */
	private static function check_file_ext( $file ) {
		$ext = pathinfo( $file, PATHINFO_EXTENSION );
		return ! empty( $ext ) && $ext === 'json';
	}

	/**
	 * Looks through local folders in the active theme and any others filtered in by theme and plugins, to find JSON
	 * prebuilt layouts.
	 */
	public function get_local_layouts() {
		// By default we'll look for layouts in a directory in the active theme
		$layout_folders = array( get_template_directory() . '/siteorigin-page-builder-layouts' );

		// And the child theme if there is one.
		if ( is_child_theme() ) {
			$layout_folders[] = get_stylesheet_directory() . '/siteorigin-page-builder-layouts';
		}

		// This allows themes and plugins to customize where we look for layouts.
		$layout_folders = apply_filters( 'siteorigin_panels_local_layouts_directories', $layout_folders );

		$layouts = array();

		foreach ( $layout_folders as $folder ) {
			$folder = realpath( $folder );

			if ( file_exists( $folder ) && is_dir( $folder ) ) {
				$files = list_files( $folder, 1 );

				if ( empty( $files ) ) {
					continue;
				}

				foreach ( $files as $file ) {
					// Check the file.
					if (
						! self::check_file_mime( $file ) ||
						! self::check_file_ext( $file )
					) {
						continue;
					}

					// get file contents
					$file_contents = file_get_contents( $file );

					// skip if file_get_contents fails
					if ( $file_contents === false ) {
						continue;
					}

					$panels_data = $this->decode_panels_data( $file_contents );

					if ( empty( $panels_data ) ) {
						continue;
					}

					// get file name by stripping out folder path and .json extension
					$file_name = str_replace( array( $folder . '/', '.json' ), '', $file );

					// get name: check for id or name else use filename
					$panels_data['id'] = sanitize_title_with_dashes( $this->get_layout_id( $panels_data, $file_name ) );

					if ( empty( $panels_data['name'] ) ) {
						$panels_data['name'] = $file_name;
					}

					$panels_data['name'] = sanitize_text_field( $panels_data['name'] );

					// get screenshot: check for screenshot prop else try use image file with same filename.
					$panels_data['screenshot'] = $this->get_layout_file_screenshot( $panels_data, $folder, $file_name );

					// set item on layouts array
					$layouts[ $panels_data['id'] ] = $panels_data;
				}
			}
		}

		return $layouts;
	}

	private function get_layout_id( $layout_data, $fallback ) {
		if ( ! empty( $layout_data['id'] ) ) {
			return $layout_data['id'];
		} elseif ( ! empty( $layout_data['name'] ) ) {
			return $layout_data['name'];
		} else {
			return $fallback;
		}
	}

	private static function get_files( $folder_path, $file_name ) {
		$paths = array();
		$types = array(
			'jpg',
			'jpeg',
			'gif',
			'png',
		);

		foreach ( $types as $ext ) {
			$paths = array_merge( glob( $folder_path . "/$file_name.$ext" ), $paths );
		}

		return $paths;
	}

	private function get_layout_file_screenshot( $panels_data, $folder_path, $file_name ) {
		if ( ! empty( $panels_data['screenshot'] ) ) {
			return $panels_data['screenshot'];
		} else {
			$paths = self::get_files( $folder_path, $file_name );
			// Highlander Condition. There can be only one.
			$screenshot_path = empty( $paths ) ? '' : wp_normalize_path( $paths[0] );
			$wp_content_dir = wp_normalize_path( WP_CONTENT_DIR );
			$screenshot_url = '';

			if ( file_exists( $screenshot_path ) &&
				 strrpos( $screenshot_path, $wp_content_dir ) === 0 ) {
				$screenshot_url = str_replace( $wp_content_dir, content_url(), $screenshot_path );
			}

			return $screenshot_url;
		}
	}

	/**
	 * Gets all the prebuilt layouts.
	 */
	public function action_get_prebuilt_layouts() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die( __( 'Invalid request.', 'siteorigin-panels' ), 403 );
		}

		// Get any layouts that the current user could edit.
		header( 'content-type: application/json' );

		$type = ! empty( $_REQUEST['type'] ) ? sanitize_key( $_REQUEST['type'] ) : 'directory-siteorigin';
		$search = ! empty( $_REQUEST['search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search'] ) ) : '';
		$page_num = ! empty( $_REQUEST['page'] ) ? intval( $_REQUEST['page'] ) : 1;

		$return = array(
			'title' => '',
			'items' => array(),
		);

		if ( $type == 'prebuilt' ) {
			$return['title'] = __( 'Theme Defined Layouts', 'siteorigin-panels' );

			// This is for theme bundled prebuilt directories
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

			foreach ( $layouts as $id => $vals ) {
				if ( ! empty( $search ) && strpos( strtolower( $vals['name'] ), $search ) === false ) {
					continue;
				}

				$return['items'][] = array(
					'title'       => esc_html( $vals['name'] ),
					'id'          => esc_html( $id ),
					'type'        => 'prebuilt',
					'description' => isset( $vals['description'] ) ? esc_html( $vals['description'] ) : '',
					'screenshot'  => ! empty( $vals['screenshot'] ) ? esc_url( $vals['screenshot'] ) : '',
				);
			}

			$return['max_num_pages'] = 1;
		} elseif ( substr( $type, 0, 10 ) == 'directory-' ) {
			$directory_id = str_replace( 'directory-', '', $type );
			// If the user isn't searching, check if we have a cached version of the results.
			if ( empty( $search ) ) {
				$cache = get_transient( 'siteorigin_panels_layouts_directory_cache_' . $directory_id .'_page_' . $page_num );
				if ( ! empty( $cache ) ) {
					$return = $cache;
				}
			}

			if ( empty( $return['items'] ) ) {
				$return['title'] = __( 'Layouts Directory', 'siteorigin-panels' );

				// This is a query of the prebuilt layout directory
				$query = array(
					'search' => $search,
					'page' => $page_num,
				);
				$directories = $this->get_directories();
				$directory = ! empty( $directories[ $directory_id ] ) ? $directories[ $directory_id ] : false;

				if ( empty( $directory ) ) {
					return false;
				}
				$url = add_query_arg( $query, $directory[ 'url' ] . 'wp-admin/admin-ajax.php?action=query_layouts' );

				if ( ! empty( $directory[ 'args' ] ) && is_array( $directory[ 'args' ] ) ) {
					$url = add_query_arg( $directory[ 'args' ], $url );
				}

				$url = apply_filters( 'siteorigin_panels_layouts_directory_url', $url );
				$response = wp_remote_get( esc_url_raw( $url ) );

				if (
					! is_wp_error( $response ) &&
					is_array( $response ) &&
					$response['response']['code'] == 200
				) {
					$results = json_decode( $response['body'], true );

					if ( ! empty( $results ) && ! empty( $results['items'] ) ) {
						foreach ( $results['items'] as $item ) {
							$item['id'] = esc_html( $item['slug'] );
							$item['type'] = esc_html( $type );

							// Always process category and niche classes for filtering.
							$item['access'] = ! empty( $item['access'] ) ? esc_html( $item['access'] ) : '';
							
							// Convert category and niche names to CSS class format.
							if ( ! empty( $item['category'] ) ) {
								$item['category'] = 'so-' . sanitize_title( $item['category'] );
							}
							
							if ( ! empty( $item['niches'] ) ) {
								$niche_names = json_decode( $item['niches'] );
								if ( is_array( $niche_names ) ) {
									$formatted_niches = array_map( function( $niche ) {
										return 'so-' . sanitize_title( $niche );
									}, $niche_names );
									$item['niches'] = ' ' . implode( ' ', $formatted_niches );
								}
							}
							
							// Set the CSS class to be the category + niches.
							$item['class'] = trim( $item['category'] . ( ! empty( $item['niches'] ) ? $item['niches'] : '' ) );

							if ( empty( $item['screenshot'] ) && ! empty( $item['preview'] ) ) {
								$preview_url = add_query_arg( 'screenshot', 'true', $item[ 'preview' ] );
								$item['screenshot'] = esc_url( 'https://s.wordpress.com/mshots/v1/' . urlencode( $preview_url ) . '?w=700' );
							}

							$return['items'][] = $item;
						}

						if ( ! empty( $results['niches'] ) ) {
							// Convert the categories and niches to the expected format for filtering.
							// The layout-viewer returns them as slug => name, but we need to format them.
							// so the filter buttons work with the CSS classes
							$return['niches'] = $this->format_filter_terms( $results['niches'] );
							$return['categories'] = $this->format_filter_terms( $results['categories'] );
						}
					}

					$return['max_num_pages'] = $results['max_num_pages'];

					// If the user isn't searching, cache the results.
					if ( empty( $search ) ) {
						set_transient( 'siteorigin_panels_layouts_directory_cache_' . $directory_id .'_page_' . $page_num, $return, 86400 );
					}
				}
			}
			$no_search_title = true;
		} elseif ( strpos( $type, 'clone_' ) !== false ) {
			$post_type = str_replace( 'clone_', '', $type );
			$post_types_editable_by_user = SiteOrigin_Panels_Admin_Layouts::single()->post_types();

			// Can the user edit posts from this post type?
			if (
				empty( $post_type ) ||
				empty( $post_types_editable_by_user ) ||
				! in_array(
					$post_type,
					$post_types_editable_by_user
				)
			) {
				return;
			}

			$post_type = get_post_type_object( $post_type );
			if ( empty( $post_type ) ) {
				return;
			}

			$return['title'] = sprintf( __( 'Clone %s', 'siteorigin-panels' ), esc_html( $post_type->labels->singular_name ) );

			global $wpdb;
			$user_can_read_private = ( $post_type->name == 'post' && current_user_can( 'read_private_posts' ) || ( $post_type->name == 'page' && current_user_can( 'read_private_pages' ) ) );
			$include_private = $user_can_read_private ? "OR posts.post_status = 'private' " : '';

			// Select only the posts with the given post type that also have panels_data
			$results = $wpdb->get_results( "
				SELECT SQL_CALC_FOUND_ROWS DISTINCT ID, post_title, meta.meta_value
				FROM {$wpdb->posts} AS posts
				JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
				WHERE
					posts.post_type = '" . esc_sql( $post_type->name ) . "'
					AND meta.meta_key = 'panels_data'
					" . ( ! empty( $search ) ? 'AND posts.post_title LIKE "%' . esc_sql( $search ) . '%"' : '' ) . "
					AND ( posts.post_status = 'publish' OR posts.post_status = 'draft' " . $include_private . ')
				ORDER BY post_date DESC
				LIMIT 16 OFFSET ' . (int) ( $page_num - 1 ) * 16 );
			$total_posts = $wpdb->get_var( 'SELECT FOUND_ROWS();' );

			foreach ( $results as $result ) {
				$thumbnail = get_the_post_thumbnail_url( (int) $result->ID, array( 400, 300 ) );
				$return['items'][] = array(
					'id'         => (int) $result->ID,
					'title'      => esc_html( $result->post_title ),
					'type'       => esc_html( $type ),
					'screenshot' => ! empty( $thumbnail ) ? esc_url( $thumbnail ) : '',
				);
			}

			$return['max_num_pages'] = ceil( $total_posts / 16 );
		} else {
			// An invalid type. Display an error message.
		}

		// Add the search part to the title
		if ( ! empty( $search ) && empty( $no_search_title ) ) {
			$return['title'] .= __( ' - Results For:', 'siteorigin-panels' ) . ' <em>' . esc_html( $search ) . '</em>';
		}

		$return = apply_filters( 'siteorigin_panels_layouts_result', $return, $type );
		
		echo wp_json_encode( $return );

		wp_die();
	}

	/**
	 * Escapes the keys and values of an array using the `esc_html` function.
	 *
	 * @param array $results The array to escape.
	 * @return array The escaped array.
	 */
	private function escape_results( $results = array() ) {
		$escaped_values = array();
		foreach ( $results as $key => $value ) {
			$escaped_key = esc_html( $key );
			$escaped_value = esc_html( $value );
			$escaped_values[ $escaped_key ] = $escaped_value;
		}
		return $escaped_values;
	}

	/**
	 * Format filter terms for category/niche filtering.
	 * Converts from layout-viewer format (slug => name) to filtering format.
	 *
	 * @param array $terms The terms array from layout-viewer API.
	 * @return array Formatted terms for filtering.
	 */
	private function format_filter_terms( $terms = array() ) {
		$formatted_terms = array();
		foreach ( $terms as $slug => $name ) {
			// The layout-viewer API returns slug => name where slug already has 'so-' prefix.
			// Use the slug as-is since it already matches the CSS classes on layout items.
			$formatted_terms[ $slug ] = esc_html( $name );
		}
		return $formatted_terms;
	}

	private function delete_file( $file ) {
		if ( ! empty( $file ) && file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	public function decode_panels_data( $data, $file = null ) {
		$panels_data = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->delete_file( $file );
			wp_die();
		}

		// Newer exports may require further decoding.
		if ( ! is_array( $panels_data ) ) {
			$panels_data = $this->decode_panels_data( $panels_data );
		}

		$panels_data = wp_unslash( $panels_data );
		$this->delete_file( $file );
		return $panels_data;
	}

	/**
	 * Ajax handler to get an individual prebuilt layout
	 */
	public function action_get_prebuilt_layout() {
		if (
			empty( $_REQUEST['_panelsnonce'] ) ||
			! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' )
		) {
			wp_die();
		}

		$type = isset( $_REQUEST['type'] ) ? sanitize_key( $_REQUEST['type'] ) : '';
		$layout_id = isset( $_REQUEST['lid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lid'] ) ) : '';

		if ( empty( $type ) ) {
			wp_die();
		}

		if ( empty( $layout_id ) ) {
			wp_die();
		}

		header( 'content-type: application/json' );
		$panels_data = array();
		$raw_panels_data = false;

		if ( $type == 'prebuilt' ) {
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );

			if (
				! is_numeric( $layout_id ) &&
				empty( $layouts[ $layout_id ] )
			) {
				wp_send_json_error( array(
					'error'   => true,
					'message' => __( 'Missing layout ID or no such layout exists', 'siteorigin-panels' ),
				) );
			}

			$layout = $layouts[ $layout_id ];

			// Fix the format of this layout
			if ( ! empty( $layout[ 'filename' ] ) ) {
				$filename = $layout[ 'filename' ];
				// Only accept filenames that end with .json
				if ( substr( $filename, -5, 5 ) === '.json' && file_exists( $filename ) ) {
					$panels_data = json_decode( file_get_contents( $filename ), true );
					$layout[ 'widgets' ] = ! empty( $panels_data[ 'widgets' ] ) ? $panels_data[ 'widgets' ] : array();
					$layout[ 'grids' ] = ! empty( $panels_data[ 'grids' ] ) ? $panels_data[ 'grids' ] : array();
					$layout[ 'grid_cells' ] = ! empty( $panels_data[ 'grid_cells' ] ) ? $panels_data[ 'grid_cells' ] : array();
				}
			}

			// A theme or plugin could use this to change the data in the layout
			$panels_data = apply_filters( 'siteorigin_panels_prebuilt_layout', $layout, $layout_id );

			// Remove all the layout specific attributes
			if ( isset( $panels_data['name'] ) ) {
				unset( $panels_data['name'] );
			}

			if ( isset( $panels_data['screenshot'] ) ) {
				unset( $panels_data['screenshot'] );
			}

			if ( isset( $panels_data['filename'] ) ) {
				unset( $panels_data['filename'] );
			}

			$raw_panels_data = true;
		} elseif ( substr( $type, 0, 10 ) == 'directory-' ) {
			$directory_id = str_replace( 'directory-', '', $type );

			$directories = $this->get_directories();
			$directory = ! empty( $directories[ $directory_id ] ) ? $directories[ $directory_id ] : false;

			if ( ! empty( $directory ) ) {
				$url = $directory[ 'url' ] . 'layout/' . urlencode( $layout_id ) . '/?action=download';

				if ( ! empty( $directory[ 'args' ] ) && is_array( $directory[ 'args' ] ) ) {
					$url = add_query_arg( $directory[ 'args' ], $url );
				}

				$response = wp_remote_get( $url );

				if ( is_wp_error( $response ) ) {
					wp_send_json_error( array(
						'error'   => true,
						'message' => 'WordPress error: ' . $response->get_error_message(),
					) );
				} elseif ( $response['response']['code'] == 200 ) {
					$panels_data = json_decode( $response['body'], true );
				} else {
					wp_send_json_error( array(
						'error'   => true,
						'message' => 'HTTP Error ' . $response['response']['code'] . ': There was a problem fetching the layout. Please try again later.',
					) );
				}
			}
			$raw_panels_data = true;
		} elseif ( current_user_can( 'edit_post', $layout_id ) ) {
			$panels_data = get_post_meta( $layout_id, 'panels_data', true );

			// Clear id and timestamp for SO widgets to prevent 'newer content version' notification in widget forms.
			foreach ( $panels_data['widgets'] as &$widget ) {
				unset( $widget['_sow_form_id'] );
				unset( $widget['_sow_form_timestamp'] );
			}
		}

		if ( $raw_panels_data ) {
			// This panels_data is flagged as raw, so it needs to be processed.
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, false );
			$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], array(), true, true );
		}

		if ( ! empty( $panels_data['widgets'] ) ) {
			$panels_data['widgets'] = $this->close_all_containers( $panels_data['widgets'] );
		}

		wp_send_json_success( $panels_data );
	}

	/**
	 * Recursively close all containers in the widget.
	 *
	 * @param array $widget The widget data.
	 *
	 * @return array The widget data with all fields closed.
	 */
	private function close_all_containers( $widget ) {
		foreach( $widget as $key => $value ) {
			if ( is_array( $value ) ) {
				$widget[ $key ] = $this->close_all_containers( $value );
				continue;
			}

			// If the key is `so_field_container_state`, set it to true.
			if ( $key === 'so_field_container_state' ) {
				$widget[ $key ] = 'closed';
			}
		}

		return $widget;
	}

	/**
	 * Ajax handler to import a layout
	 */
	public function action_import_layout() {
		if (
			empty( $_REQUEST['_panelsnonce'] ) ||
			! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' )
		) {
			wp_die();
		}

		// Ensure there wasn't an error during upload.
		if (
			empty( $_FILES['panels_import_data']['tmp_name'] ) ||
			! file_exists( $_FILES['panels_import_data']['tmp_name'] )
		) {
			wp_die();
		}

		$json = file_get_contents( $_FILES['panels_import_data']['tmp_name'] );
		$panels_data = $this->decode_panels_data( $json, $_FILES['panels_import_data']['tmp_name'] );

		header( 'content-type:application/json' );
		$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, false );
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], array(), true, true );

		if ( ! empty( $panels_data['widgets'] ) ) {
			$panels_data['widgets'] = $this->close_all_containers( $panels_data['widgets'] );
		}

		echo wp_json_encode( $panels_data );
		wp_die();
	}

	/**
	 * Export a given layout as a JSON file.
	 */
	public function action_export_layout() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}

		$export_data = wp_unslash( $_POST['panels_export_data'] );
		$decoded_export_data = $this->decode_panels_data( $export_data );

		if ( ! empty( $decoded_export_data['name'] ) ) {
			$decoded_export_data['id'] = sanitize_title_with_dashes( $decoded_export_data['name'] );
			$filename = sanitize_file_name( $decoded_export_data['id'] );
		} else {
			$filename = 'layout-' . date( 'dmY' );
		}

		header( 'content-type: application/json' );
		header( "Content-Disposition: attachment; filename=$filename.json" );

		echo wp_json_encode( $decoded_export_data );

		wp_die();
	}

	/**
	 * Enable the directory.
	 */
	public function action_directory_enable() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}
		$user = get_current_user_id();
		update_user_meta( $user, 'so_panels_directory_enabled', true );
		wp_die();
	}

	/**
	 * Load a layout from a json file
	 *
	 * @param bool $screenshot
	 *
	 * @return array The data for the layout
	 */
	public function load_layout( $id, $name, $json_file, $screenshot = false ) {
		$json = file_get_contents( $json_file );
		$layout_data = $this->decode_panels_data( $json );

		$layout_data = apply_filters( 'siteorigin_panels_load_layout_' . $id, $layout_data );

		$layout_data = array_merge( array(
			'name' => sanitize_text_field( $name ),
			'screenshot' => esc_url( $screenshot ),
		), $layout_data );

		return $layout_data;
	}

	/**
	 * Get the post types that the current user can edit.
	 *
	 * This function retrieves the post types specified in the
	 * SiteOrigin Panels settings. It then filters out post types that the
	 * current user does not have permission to edit.
	 *
	 * @return array The post types that the current user can edit.
	 */
	public function post_types() {
		$post_types = siteorigin_panels_setting( 'post-types' );
		if ( empty( $post_types ) ) {
			return array();
		}

		foreach ( $post_types as $id => $post_type ) {
			$post_type_object = get_post_type_object( $post_type );

			if (
				empty( $post_type_object ) ||
				! current_user_can( $post_type_object->cap->edit_posts )
			) {
				unset( $post_types[ $id ] );
			}
		}

		return $post_types;
	}
}
