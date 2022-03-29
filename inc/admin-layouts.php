<?php

/**
 * Class SiteOrigin_Panels_Admin
 *
 * Handles all the admin and database interactions.
 */
class SiteOrigin_Panels_Admin_Layouts {
	
	const LAYOUT_URL = 'https://layouts.siteorigin.com/';
	
	function __construct() {
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
	public function filter_directories( $directories ){
		if ( apply_filters( 'siteorigin_panels_layouts_directory_enabled', true ) ) {
			$directories['siteorigin'] = array(
				// The title of the layouts directory in the sidebar.
				'title' => __( 'Layouts Directory', 'siteorigin-panels' ),
				// The URL of the directory.
				'url'   => self::LAYOUT_URL,
				// Any additional arguments to pass to the layouts server
				'args'  => array()
			);
		}
		
		return $directories;
	}
	
	/**
	 * Get all the layout directories.
	 *
	 * @return array
	 */
	public function get_directories(){
		$directories = apply_filters( 'siteorigin_panels_external_layout_directories', array() );
		if( empty( $directories ) || ! is_array( $directories ) ) {
			$directories = array();
		}
		
		return $directories;
	}
	
	
	/**
	 * Looks through local folders in the active theme and any others filtered in by theme and plugins, to find JSON
	 * prebuilt layouts.
	 *
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
			$folder = realpath($folder);
			if ( file_exists( $folder ) && is_dir( $folder ) ) {
				$files = list_files( $folder, 1 );
				if ( empty( $files ) ) {
					continue;
				}
				
				foreach ( $files as $file ) {
					
					if ( function_exists( 'mime_content_type' ) ) {
						// get file mime type
						$mime_type = mime_content_type( $file );
						
						// Valid if text files.

						// Valid if text or json file.
						$valid_file_type = strpos( $mime_type, '/json' ) || strpos( $mime_type, 'text/' ) > -1;
					} else {
						// If `mime_content_type` isn't available, just check file extension.
						$ext = pathinfo( $file, PATHINFO_EXTENSION );
						
						// skip files which don't have a `.json` extension.
						$valid_file_type = ! empty( $ext ) && $ext === 'json';
					}
					
					if ( ! $valid_file_type ) {
						continue;
					}
					
					// get file contents
					$file_contents = file_get_contents( $file );
					
					// skip if file_get_contents fails
					if ( $file_contents === false ) {
						continue;
					}
					
					// json decode
					$panels_data = json_decode( $file_contents, true );
					
					if ( ! empty( $panels_data ) ) {
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
		}
		
		return $layouts;
	}
	
	private function get_layout_id( $layout_data, $fallback ) {
		if ( ! empty( $layout_data['id'] ) ) {
			return $layout_data['id'];
		} else if ( ! empty( $layout_data['name'] ) ) {
			return $layout_data['name'];
		} else {
			return $fallback;
		}
	}

	static private function get_files( $folder_path, $file_name ) {
		$paths = array();
		$types = array (
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
	function action_get_prebuilt_layouts() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die( __( 'Invalid request.', 'siteorigin-panels' ), 403 );
		}
		
		// Get any layouts that the current user could edit.
		header( 'content-type: application/json' );
		
		$type   = ! empty( $_REQUEST['type'] ) ? $_REQUEST['type'] : 'directory-siteorigin';
		$search = ! empty( $_REQUEST['search'] ) ? trim( strtolower( $_REQUEST['search'] ) ) : '';
		$page_num = ! empty( $_REQUEST['page'] ) ? (int) $_REQUEST['page'] : 1;
		
		$return = array(
			'title' => '',
			'items' => array()
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
					'title'       => $vals['name'],
					'id'          => $id,
					'type'        => 'prebuilt',
					'description' => isset( $vals['description'] ) ? $vals['description'] : '',
					'screenshot'  => ! empty( $vals['screenshot'] ) ? $vals['screenshot'] : ''
				);
			}
			
			$return['max_num_pages'] = 1;
		} elseif ( substr( $type, 0, 10 ) == 'directory-' ) {
			$return['title'] = __( 'Layouts Directory', 'siteorigin-panels' );
			
			// This is a query of the prebuilt layout directory
			$query = array();
			if ( ! empty( $search ) ) {
				$query['search'] = $search;
			}
			$query['page'] = $page_num;
			
			$directory_id = str_replace( 'directory-', '', $type );
			$directories = $this->get_directories();
			$directory = ! empty( $directories[ $directory_id ] ) ? $directories[ $directory_id ] : false;
			
			if( empty( $directory ) ) {
				return false;
			}
			
			$url = add_query_arg( $query, $directory[ 'url' ] . 'wp-admin/admin-ajax.php?action=query_layouts' );
			if( ! empty( $directory[ 'args' ] ) && is_array( $directory[ 'args' ] ) ) {
				$url = add_query_arg( $directory[ 'args' ], $url );
			}
			
			$url = apply_filters( 'siteorigin_panels_layouts_directory_url', $url );
			$response = wp_remote_get( $url );
			
			if ( is_array( $response ) && $response['response']['code'] == 200 ) {
				$results = json_decode( $response['body'], true );
				if ( ! empty( $results ) && ! empty( $results['items'] ) ) {
					foreach ( $results['items'] as $item ) {
						$item['id']         = $item['slug'];
						$item['type']       = $type;
						
						if( empty( $item['screenshot'] ) && ! empty( $item['preview'] ) ) {
							$preview_url = add_query_arg( 'screenshot', 'true', $item[ 'preview' ] );
							$item['screenshot'] = 'https://s.wordpress.com/mshots/v1/' . urlencode( $preview_url ) . '?w=700';
						}
						
						$return['items'][]  = $item;
					}
				}
				
				$return['max_num_pages'] = $results['max_num_pages'];
			}
		} elseif ( strpos( $type, 'clone_' ) !== false ) {
			// Check that the user can view the given page types
			$post_type = get_post_type_object( str_replace( 'clone_', '', $type ) );
			if( empty( $post_type ) ) {
				 return;
			}
			
			$return['title'] = sprintf( __( 'Clone %s', 'siteorigin-panels' ), esc_html( $post_type->labels->singular_name ) );
			
			global $wpdb;
			$user_can_read_private = ( $post_type->name == 'post' && current_user_can( 'read_private_posts' ) || ( $post_type->name == 'page' && current_user_can( 'read_private_pages' ) ) );
			$include_private       = $user_can_read_private ? "OR posts.post_status = 'private' " : "";
			
			// Select only the posts with the given post type that also have panels_data
			$results     = $wpdb->get_results( "
				SELECT SQL_CALC_FOUND_ROWS DISTINCT ID, post_title, meta.meta_value
				FROM {$wpdb->posts} AS posts
				JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
				WHERE
					posts.post_type = '" . esc_sql( $post_type->name ) . "'
					AND meta.meta_key = 'panels_data'
					" . ( ! empty( $search ) ? 'AND posts.post_title LIKE "%' . esc_sql( $search ) . '%"' : '' ) . "
					AND ( posts.post_status = 'publish' OR posts.post_status = 'draft' " . $include_private . ")
				ORDER BY post_date DESC
				LIMIT 16 OFFSET " . (int) ( $page_num - 1 ) * 16 );
			$total_posts = $wpdb->get_var( "SELECT FOUND_ROWS();" );
			
			foreach ( $results as $result ) {
				$thumbnail         = get_the_post_thumbnail_url( $result->ID, array( 400, 300 ) );
				$return['items'][] = array(
					'id'         => $result->ID,
					'title'      => $result->post_title,
					'type'       => $type,
					'screenshot' => ! empty( $thumbnail ) ? $thumbnail : ''
				);
			}
			
			$return['max_num_pages'] = ceil( $total_posts / 16 );
			
		} else {
			// An invalid type. Display an error message.
		}
		
		// Add the search part to the title
		if ( ! empty( $search ) ) {
			$return['title'] .= __( ' - Results For:', 'siteorigin-panels' ) . ' <em>' . esc_html( $search ) . '</em>';
		}
		
		echo json_encode( apply_filters( 'siteorigin_panels_layouts_result', $return, $type ) );
		
		wp_die();
	}
	
	/**
	 * Ajax handler to get an individual prebuilt layout
	 */
	function action_get_prebuilt_layout() {
		if ( empty( $_REQUEST['type'] ) ) {
			wp_die();
		}
		if ( empty( $_REQUEST['lid'] ) ) {
			wp_die();
		}
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}
		
		header( 'content-type: application/json' );
		$panels_data = array();
		$raw_panels_data = false;
		
		if ( $_REQUEST['type'] == 'prebuilt' ) {
			$layouts = apply_filters( 'siteorigin_panels_prebuilt_layouts', array() );
			$lid = ! empty( $_REQUEST['lid'] ) ? $_REQUEST['lid'] : false;
			
			if ( empty( $lid ) || empty( $layouts[ $lid ] ) ) {
				wp_send_json_error( array(
					'error'   => true,
					'message' => __( 'Missing layout ID or no such layout exists', 'siteorigin-panels' ),
				) );
			}
			
			$layout = $layouts[ $_REQUEST['lid'] ];
			
			// Fix the format of this layout
			if( !empty( $layout[ 'filename' ] ) ) {
				$filename = $layout[ 'filename' ];
				// Only accept filenames that end with .json
				if( substr( $filename, -5, 5 ) === '.json' && file_exists( $filename ) ) {
					$panels_data = json_decode( file_get_contents( $filename ), true );
					$layout[ 'widgets' ] = ! empty( $panels_data[ 'widgets' ] ) ? $panels_data[ 'widgets' ] : array();
					$layout[ 'grids' ] = ! empty( $panels_data[ 'grids' ] ) ? $panels_data[ 'grids' ] : array();
					$layout[ 'grid_cells' ] = ! empty( $panels_data[ 'grid_cells' ] ) ? $panels_data[ 'grid_cells' ] : array();
				}
			}
			
			// A theme or plugin could use this to change the data in the layout
			$panels_data = apply_filters( 'siteorigin_panels_prebuilt_layout', $layout, $lid );
			
			// Remove all the layout specific attributes
			if ( isset( $panels_data['name'] ) ) unset( $panels_data['name'] );
			if ( isset( $panels_data['screenshot'] ) ) unset( $panels_data['screenshot'] );
			if ( isset( $panels_data['filename'] ) ) unset( $panels_data['filename'] );

			$raw_panels_data = true;
			
		} elseif ( substr( $_REQUEST['type'], 0, 10 ) == 'directory-' ) {
			$directory_id = str_replace( 'directory-', '', $_REQUEST['type'] );
			$directories = $this->get_directories();
			$directory = ! empty( $directories[ $directory_id ] ) ? $directories[ $directory_id ] : false;
			
			if( ! empty( $directory ) ) {
				$url = $directory[ 'url' ] . 'layout/' . urlencode( $_REQUEST[ 'lid' ] ) . '/?action=download';
				if( ! empty( $directory[ 'args' ] ) && is_array( $directory[ 'args' ] ) ) {
					$url = add_query_arg( $directory[ 'args' ], $url );
				}
				
				$response = wp_remote_get( $url );
				if ( $response['response']['code'] == 200 ) {
					// For now, we'll just pretend to load this
					$panels_data = json_decode( $response['body'], true );
				} else {
					wp_send_json_error( array(
						'error'   => true,
						'message' => __( 'There was a problem fetching the layout. Please try again later.', 'siteorigin-panels' ),
					) );
				}
			}
			$raw_panels_data = true;

		} elseif ( current_user_can( 'edit_post', $_REQUEST['lid'] ) ) {
			$panels_data = get_post_meta( $_REQUEST['lid'], 'panels_data', true );
			
			// Clear id and timestamp for SO widgets to prevent 'newer content version' notification in widget forms.
			foreach ( $panels_data['widgets'] as &$widget ) {
				unset( $widget['_sow_form_id'] );
				unset( $widget['_sow_form_timestamp'] );
			}
		}

		if( $raw_panels_data ) {
			// This panels_data is flagged as raw, so it needs to be processed.
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, false );
			$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], array(), true, true );
		}
		
		wp_send_json_success( $panels_data );
	}
	
	/**
	 * Ajax handler to import a layout
	 */
	function action_import_layout() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}
		
		if ( ! empty( $_FILES['panels_import_data']['tmp_name'] ) ) {
			header( 'content-type:application/json' );
			$json = file_get_contents( $_FILES['panels_import_data']['tmp_name'] );
			$panels_data = json_decode( $json, true );
			$panels_data = apply_filters( 'siteorigin_panels_data', $panels_data, false );
			$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], array(), true, true );
			$json = json_encode( $panels_data );
			@unlink( $_FILES['panels_import_data']['tmp_name'] );
			echo $json;
		}
		wp_die();
	}
	
	/**
	 * Export a given layout as a JSON file.
	 */
	function action_export_layout() {
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}
		
		$export_data = wp_unslash( $_POST['panels_export_data'] );
		
		$decoded_export_data = json_decode( $export_data, true );
		
		if ( ! empty( $decoded_export_data['name'] ) ) {
			$decoded_export_data['id'] = sanitize_title_with_dashes( $decoded_export_data['name'] );
			$filename = $decoded_export_data['id'];
		} else {
			$filename = 'layout-' . date( 'dmY' );
		}
		
		
		header( 'content-type: application/json' );
		header( "Content-Disposition: attachment; filename=$filename.json" );
		
		echo $export_data;
		
		wp_die();
	}

	/**
	 * Enable the directory.
	 */
	function action_directory_enable() {
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
	 * @param $id
	 * @param $name
	 * @param $json_file
	 * @param bool $screenshot
	 *
	 * @return array The data for the layout
	 */
	public static function load_layout($id, $name, $json_file, $screenshot = false) {
		$layout_data = json_decode(file_get_contents($json_file), true);
		$layout_data = apply_filters('siteorigin_panels_load_layout_' . $id, $layout_data);

		$layout_data = array_merge(array(
			'name' => $name,
			'screenshot' => $screenshot,
		), $layout_data);

		return $layout_data;
	}
}
