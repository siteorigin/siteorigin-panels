<?php

class SiteOrigin_Panels_Compat_Layout_Block {
	
	const BLOCK_NAME = 'siteorigin-panels/layout-block';
	
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
	}
	
	public function register_layout_block() {
		register_block_type( self::BLOCK_NAME, array(
			'render_callback' => array( $this, 'render_layout_block' ),
		) );
	}
	
	public function enqueue_layout_block_editor_assets() {
		if (  SiteOrigin_Panels_Admin::is_block_editor() ) {
			$panels_admin = SiteOrigin_Panels_Admin::single();
			$panels_admin->enqueue_admin_scripts();
			$panels_admin->enqueue_admin_styles();
			$panels_admin->js_templates();
			
			wp_enqueue_script(
				'siteorigin-panels-layout-block',
				plugins_url( 'js/siteorigin-panels-layout-block' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', __FILE__ ),
				array(
					'wp-editor',
					'wp-blocks',
					'wp-i18n',
					'wp-element',
					'wp-components',
					'wp-compose',
					'so-panels-admin'
				),
				SITEORIGIN_PANELS_VERSION
			);
			
			$current_screen = get_current_screen();
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
					'showAddButton' => apply_filters( 'siteorigin_layout_block_show_add_button', $is_panels_post_type ),
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
	
	public function render_layout_block( $attributes ) {
		
		if ( empty( $attributes['panelsData'] ) ) {
			return '<div>'.
				   __( "You need to add a widget, row, or prebuilt layout before you'll see anything here. :)", 'siteorigin-panels' ) .
				   '</div>';
		}
		$panels_data = $attributes['panelsData'];
		$panels_data = $this->sanitize_panels_data( $panels_data );
		$builder_id = isset( $attributes['builder_id'] ) ? $attributes['builder_id'] : uniqid( 'gb' . get_the_ID() . '-' );

		// Support for custom CSS classes
		$add_custom_class_name = function( $class_names ) use ($attributes) {
			if ( ! empty( $attributes['className'] ) ) {
				$class_names[] = $attributes['className'];
			}
			return $class_names;
		};
		add_filter( 'siteorigin_panels_layout_classes', $add_custom_class_name );
		$rendered_layout = SiteOrigin_Panels::renderer()->render( $builder_id, true, $panels_data );
		remove_filter( 'siteorigin_panels_layout_classes', $add_custom_class_name );
		return $rendered_layout;
	}
	
	private function sanitize_panels_data( $panels_data ) {
		// We force calling widgets' update functions here, but a better solution is to ensure these are called when
		// the block is saved, but there is currently no simple method to do so.
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true );
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );
		return $panels_data;
	}

	function override_container( $container ) {
		return SiteOrigin_Panels_Admin::is_block_editor() ? '.editor-styles-wrapper' : $container;
	}
}
