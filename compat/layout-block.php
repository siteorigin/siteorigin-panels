<?php

class SiteOrigin_Panels_Compat_Layout_Block {
	const BLOCK_NAME = 'siteorigin-panels/layout-block';
	private $return_layout = true;

	/**
	 * Get the singleton instance
	 *
	 * @return SiteOrigin_Panels_Compat_Layout_Block
	 */
	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function __construct() {
		add_action( 'init', array( $this, 'register_layout_block' ) );
		// This action is slightly later than `enqueue_block_editor_assets`,
		// which we need to use to ensure our templates are loaded at the right time.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_layout_block_editor_assets' ) );

		// We need to override the container when using the Block Editor to allow for resizing.
		add_filter( 'siteorigin_panels_full_width_container', array( $this, 'override_container' ) );

		add_action( 'wp_head', array( $this, 'maybe_generate_layout_block_css' ) );

		$post_types = siteorigin_panels_setting( 'post-types' );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}
		foreach ( $post_types as $post_type ) {
			add_action( 'rest_pre_insert_' . $post_type, array( $this, 'server_side_validation' ), 10, 2 );
		}
	}

	public function register_layout_block() {
		register_block_type( self::BLOCK_NAME, array(
			'render_callback' => array( $this, 'render_layout_block' ),
		) );
	}

	public function enqueue_layout_block_editor_assets() {
		if ( SiteOrigin_Panels_Admin::is_block_editor() || is_customize_preview() ) {
			$panels_admin = SiteOrigin_Panels_Admin::single();
			$panels_admin->enqueue_admin_scripts();
			$panels_admin->enqueue_admin_styles();

			if ( ! is_customize_preview() ) {
				$panels_admin->js_templates();
			}

			$current_screen = get_current_screen();
			wp_enqueue_script(
				'siteorigin-panels-layout-block',
				plugins_url( 'js/siteorigin-panels-layout-block' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', __FILE__ ),
				array(
					// The WP 5.8 Widget Area requires a specific editor script to be used.
					$current_screen->base == 'widgets' ? 'wp-edit-widgets' : 'wp-editor',
					'wp-blocks',
					'wp-i18n',
					'wp-element',
					'wp-components',
					'wp-compose',
					'wp-data',
					'so-panels-admin',
				),
				SITEORIGIN_PANELS_VERSION
			);

			$is_panels_post_type = in_array( $current_screen->id, siteorigin_panels_setting( 'post-types' ) );
			wp_localize_script(
				'siteorigin-panels-layout-block',
				'soPanelsBlockEditorAdmin',
				array(
					'sanitizeUrl' => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'layout-block-sanitize', '_panelsnonce' ),
					'previewUrl' => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'layout-block-preview', '_panelsnonce' ),
					'postId' => get_the_ID(),
					'liveEditor' => SiteOrigin_Panels::preview_url(),
					'defaultMode' => siteorigin_panels_setting( 'layout-block-default-mode' ),
					'showAddButton' => apply_filters(
						'siteorigin_layout_block_show_add_button',
						$is_panels_post_type && siteorigin_panels_setting( 'layout-block-quick-add' )
					),
				)
			);
			// This is only available in WP5.
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'siteorigin-panels-layout-block', 'siteorigin-panels' );
			}
			SiteOrigin_Panels_Styles::register_scripts();
			wp_enqueue_script( 'siteorigin-panels-front-styles' );

			// Enqueue front end scripts for our widgets bundle.
			if ( class_exists( 'SiteOrigin_Widgets_Bundle' ) ) {
				$sowb = SiteOrigin_Widgets_Bundle::single();
				$sowb->register_general_scripts();

				if ( method_exists( $sowb, 'enqueue_registered_widgets_scripts' ) ) {
					$sowb->enqueue_registered_widgets_scripts( true, false );
				}
			}
		}
	}

	public function render_layout_block( $attributes, $content = null ) {
		if ( empty( $attributes['panelsData'] ) ) {
			return '<div>' .
			__( "You need to add a widget, row, or prebuilt layout before you'll see anything here. :)", 'siteorigin-panels' ) .
			'</div>';
		}
		$panels_data = $attributes['panelsData'];
		$panels_data = $this->sanitize_panels_data( $panels_data );
		$builder_id = isset( $attributes['builder_id'] ) ? $attributes['builder_id'] : uniqid( 'gb' . get_the_ID() . '-' );

		// Support for custom CSS classes
		$add_custom_class_name = function ( $class_names ) use ( $attributes ) {
			if ( ! empty( $attributes['className'] ) ) {
				$class_names[] = $attributes['className'];
			}

			return $class_names;
		};

		$is_editing = SiteOrigin_Panels_Admin::is_block_editor();
		add_filter( 'siteorigin_panels_layout_classes', $add_custom_class_name );
		SiteOrigin_Panels_Post_Content_Filters::add_filters( true );
		$rendered_layout = SiteOrigin_Panels::renderer()->render( $builder_id, ! $is_editing, $panels_data );
		SiteOrigin_Panels_Post_Content_Filters::remove_filters( true );
		remove_filter( 'siteorigin_panels_layout_classes', $add_custom_class_name );

		if ( is_wp_error( $rendered_layout ) ) {
			return $rendered_layout;
		}

		if ( $is_editing ) {
			$rendered_layout .= SiteOrigin_Panels_Renderer::single()->print_inline_css( true );
		}

		$rendered_layout = $this->remove_block_comments( $rendered_layout );
		if ( $this->return_layout ) {
			return $is_editing ? wp_json_encode( $rendered_layout ) : $rendered_layout;
		}

		$attributes['panelsData'] = $panels_data;
		$attributes['contentPreview'] = wp_json_encode( $rendered_layout );

		return $attributes;
	}

	// Remove Blocks to prevent potential issues.
	private function remove_block_comments( $content ) {
		return preg_replace( '/<!-- \/?(wp:.*?)-->/s', '', $content );
	}

	private function sanitize_panels_data( $panels_data ) {
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true );
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		return $panels_data;
	}

	public function override_container( $container ) {
		return SiteOrigin_Panels_Admin::is_block_editor() ? '.editor-styles-wrapper' : $container;
	}

	// If the CSS Output Location is set to Header, we need to generate the CSS early to allow for it to work as expected.
	public function maybe_generate_layout_block_css() {
		if ( SiteOrigin_Panels_Admin::is_block_editor() ) {
			return;
		}

		$content = get_post_field( 'post_content', get_the_ID() );
		if ( empty( $content ) ) {
			return;
		}

		if ( siteorigin_panels_setting( 'output-css-header' ) != 'header' ) {
			return;
		}

		// Okay! We're good to look for Layout Blocks.
		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return;
		}

		$blocks = array_filter( $blocks, array( $this, 'find_layout_block' ) );
		if ( empty( $blocks ) ) {
			return;
		}

		// Found them. Let's generate the CSS.
		foreach ( $blocks as $block ) {
			if (
				empty( $block['attrs'] ) ||
				empty( $block['attrs']['panelsData'] )
			) {
				continue;
			}

			$panels_data = $block['attrs']['panelsData'];
			if ( empty( $panels_data ) ) {
				continue;
			}

			$panels_data = $this->sanitize_panels_data( $panels_data );
			$builder_id = isset( $block['attrs']['builder_id'] ) ? $block['attrs']['builder_id'] : 'gb' . get_the_ID() . '-' . md5( serialize( $panels_data ) ) . '-';

			SiteOrigin_Panels::renderer()->render(
				$builder_id,
				true,
				$panels_data
			);
		}
	}

	public function server_side_validation( $prepared_post, $request ) {
		if ( empty( $prepared_post->post_content ) ) {
			return $prepared_post;
		}

		$blocks = parse_blocks( $prepared_post->post_content );
		if ( empty( $blocks ) ) {
			return $prepared_post;
		}

		foreach( $blocks as &$block ) {
			$block = $this->sanitize_blocks( $block, true );
		}

		$prepared_post->post_content = serialize_blocks( $blocks );

		return $prepared_post;
	}

	public function sanitize_blocks( $block ) {
		if (
			! empty( $block['blockName'] ) &&
			$block['blockName'] === 'siteorigin-panels/layout-block'
		) {
				$block = $this->sanitize_block( $block );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach( $block['innerBlocks'] as $i => $inner ) {
				$block['innerBlocks'][$i] = $this->sanitize_blocks( $inner );
			}
		}

		return $block;
	}

	public function sanitize_block( $block ) {
		if (
			empty( $block['attrs'] ) ||
			empty( $block['attrs']['panelsData'] )
		) {
			return $block;
		}

		$this->return_layout = false;
		$block['attrs'] = $this->render_layout_block( $block['attrs'] );
		$this->return_layout = true;
		unset( $block['innerHTML'] );
		if ( ! empty( $block['attrs']['renderedLayout'] ) ) {
			unset( $block['attrs']['renderedLayout'] );
		}
		return $block;
	}

	public function find_layout_block( $block ) {
		$found_blocks = array();

		if ( ! empty( $block['blockName'] ) && $block['blockName'] === 'siteorigin-panels/layout-block' ) {
			$found_blocks[] = $block;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach( $block['innerBlocks'] as $inner ) {
				$inner_blocks = $this->find_layout_block( $inner );
				$found_blocks = array_merge( $found_blocks, $inner_blocks );
			}
		}

		return $found_blocks;
	}
}
