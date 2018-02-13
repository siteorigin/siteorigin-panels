<?php

class SiteOrigin_Panels_Compat_Gutenberg_Block {
	
	const BLOCK_NAME = 'siteorigin-panels/layout-block';
	
	/**
	 * Get the singleton instance
	 *
	 * @return SiteOrigin_Panels_Compat_Gutenberg_Block
	 */
	public static function single() {
		static $single;
		
		return empty( $single ) ? $single = new self() : $single;
	}
	
	public function __construct() {
		add_action( 'init', array( $this, 'register_layout_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_layout_block_editor_assets' ) );
		
		add_filter( 'rest_pre_insert_post', array( $this, 'sanitize_panels_data' ) );
		add_filter( 'rest_pre_insert_page', array( $this, 'sanitize_panels_data' ) );
	}
	
	public function register_layout_block() {
		register_block_type( self::BLOCK_NAME, array(
			'render_callback' => array( $this, 'render_layout_block' ),
		) );
	}
	
	public function enqueue_layout_block_editor_assets() {
		$panels_admin = SiteOrigin_Panels_Admin::single();
		$panels_admin->enqueue_admin_scripts();
		$panels_admin->enqueue_admin_styles();
		$panels_admin->js_templates();
		
		wp_enqueue_script(
			'siteorigin-panels-layout-block',
			plugins_url( 'js/siteorigin-panels-layout-block' . SITEORIGIN_PANELS_JS_SUFFIX . '.js', __FILE__ ),
			array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'so-panels-admin' ),
			SITEORIGIN_PANELS_VERSION
		);
	}
	
	public function render_layout_block( $attributes ) {
		$panels_data = $attributes['panelsData'];
		$builder_id = isset( $attributes['builder_id'] ) ? $attributes['builder_id'] : uniqid( 'gb' . get_the_ID() . '-' );
		$rendered_layout = SiteOrigin_Panels::renderer()->render( $builder_id, true, $panels_data );
		return $rendered_layout;
	}
	
	public function sanitize_panels_data( $post ) {
		if ( gutenberg_content_has_blocks( $post->post_content ) ) {
			$blocks = gutenberg_parse_blocks( $post->post_content );
			foreach ( $blocks as &$block ) {
				if ( isset( $block['blockName'] ) && $block['blockName'] == self::BLOCK_NAME ) {
					$panels_data = $block['attrs']['panelsData'];
					$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true );
					$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );
					$block['attrs']['panelsData'] = $panels_data;
				}
			}
			$post->post_content = gutenberg_serialize_blocks( $blocks );
		}
		return $post;
	}
}
