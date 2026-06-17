<?php

/**
 * SiteOrigin Panels — Abilities API exposure.
 *
 * Public API — premium-addon-facing. Registers two WordPress Abilities API
 * abilities so the AI ecosystem can discover and use Page Builder layouts:
 *
 *   - siteorigin-panels/layout-get     (readonly) — reads a post's canonical
 *                                       panels_data across classic + block storage.
 *   - siteorigin-panels/layout-update  (write)    — persists a classic/meta-stored
 *                                       layout through the same sanitizer the
 *                                       classic save uses. Block-stored layouts are
 *                                       not writable in this slice and are declined.
 *
 * Core ships ZERO AI vendor logic: no API keys, model calls, or prompts. An
 * ability here is capability registration against existing sanitized seams —
 * exposure, like the read-only REST route in inc/ai-exposure.php. The premium
 * addon will later IMPLEMENT the AI behaviour that CALLS these abilities.
 *
 * @since {NEXT_VERSION}
 * @api
 */
class SiteOrigin_Panels_Abilities {
	/**
	 * @var SiteOrigin_Panels_Abilities
	 */
	private static $single;

	/**
	 * Get the singleton instance.
	 *
	 * @return SiteOrigin_Panels_Abilities
	 */
	public static function single() {
		if ( empty( self::$single ) ) {
			self::$single = new self();
		}

		return self::$single;
	}

	public function __construct() {
		// Abilities must be registered on the documented init hook; registering
		// outside it triggers _doing_it_wrong() and the registration fails.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the Page Builder abilities.
	 *
	 * Guarded for environments without the Abilities API (WP < 6.9, or the API
	 * plugin absent): we bail early rather than fatal. The plugin supports a wide
	 * range of WordPress versions, so core must stay safe where the API is missing.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'siteorigin-panels/layout-get',
			array(
				'label'               => __( 'Get Page Builder layout', 'siteorigin-panels' ),
				'description'         => __( "Reads a post's canonical Page Builder layout data. Returns layouts from both classic (meta-stored) and Layout Block storage; the 'source' field reports which storage path(s) supplied data.", 'siteorigin-panels' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID of the layout to read.', 'siteorigin-panels' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'source'  => array(
							'type' => 'string',
							'enum' => array( 'meta', 'block', 'mixed', 'none' ),
						),
						'layouts' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'permission_callback' => array( $this, 'layout_get_permission' ),
				'execute_callback'    => array( $this, 'layout_get' ),
				'meta'                => array(
					'readonly'     => true,
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			'siteorigin-panels/layout-update',
			array(
				'label'               => __( 'Update Page Builder layout', 'siteorigin-panels' ),
				'description'         => __( "Writes a post's classic (meta-stored) Page Builder layout. The incoming layout is re-sanitized through Page Builder's widget sanitizer before being persisted, so input is never trusted raw. Layout Block (block-stored) layouts are NOT supported yet: for those posts the ability declines the write and reports source 'block'.", 'siteorigin-panels' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Post ID of the layout to update.', 'siteorigin-panels' ),
							'minimum'     => 1,
						),
						'panels_data' => array(
							'type'        => 'object',
							'description' => __( 'Canonical panels_data to persist (widgets, grids, grid_cells).', 'siteorigin-panels' ),
						),
					),
					'required'             => array( 'post_id', 'panels_data' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'updated' => array( 'type' => 'boolean' ),
						'source'  => array(
							'type' => 'string',
							'enum' => array( 'meta', 'block', 'unsupported' ),
						),
						'message' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => array( $this, 'layout_update_permission' ),
				'execute_callback'    => array( $this, 'layout_update' ),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission check for siteorigin-panels/layout-update.
	 *
	 * Authorization, not just authentication: the caller must be able to edit the
	 * target post to update its layout (mirrors the read seam's check).
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param array $input Ability input — expects post_id.
	 *
	 * @return bool|WP_Error
	 */
	public function layout_update_permission( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'siteorigin_panels_cannot_update_layout',
				__( 'Sorry, you are not allowed to update this layout.', 'siteorigin-panels' )
			);
		}

		return true;
	}

	/**
	 * Execute siteorigin-panels/layout-update.
	 *
	 * Persists a classic (meta-stored) layout only. The incoming layout MUST
	 * traverse process_raw_widgets() before persist — the same §3 guarantee every
	 * other write path enforces; ability input is never trusted raw. Block-stored
	 * layouts are declined explicitly rather than half-writing post_content.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param array $input Ability input — expects post_id and panels_data.
	 *
	 * @return array|WP_Error
	 */
	public function layout_update( $input ) {
		$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$panels_data = isset( $input['panels_data'] ) && is_array( $input['panels_data'] ) ? $input['panels_data'] : array();

		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return array(
				'post_id' => $post_id,
				'updated' => false,
				'source'  => 'unsupported',
				'message' => __( 'Layout not found.', 'siteorigin-panels' ),
			);
		}

		// Block-stored layouts are not writable by this ability yet — decline,
		// do not half-write. A full block write (serialize_blocks across multiple
		// blocks) is a deliberately separate future slice.
		if ( $this->post_has_layout_block( $post ) ) {
			return array(
				'post_id' => $post_id,
				'updated' => false,
				'source'  => 'block',
				'message' => __( 'This post stores its layout in a Layout Block; block-stored layouts are not writable by this ability yet. Only classic (meta-stored) layouts can be updated.', 'siteorigin-panels' ),
			);
		}

		// Meta (classic) path. Re-sanitize through the SAME sanitizer the classic
		// save uses (admin.php save_post), mirroring its argument shape.
		$admin           = SiteOrigin_Panels_Admin::single();
		$old_panels_data = get_post_meta( $post_id, 'panels_data', true );

		$panels_data['widgets'] = $admin->process_raw_widgets(
			! empty( $panels_data['widgets'] ) ? $panels_data['widgets'] : array(),
			! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
			false
		);

		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		update_post_meta( $post_id, 'panels_data', $panels_data );

		return array(
			'post_id' => $post_id,
			'updated' => true,
			'source'  => 'meta',
		);
	}

	/**
	 * Whether a post stores its layout in a Layout Block.
	 *
	 * Reuses the same block-name walk as the read seam so detection stays
	 * consistent across read and write.
	 *
	 * @param WP_Post $post The post to inspect.
	 *
	 * @return bool
	 */
	protected function post_has_layout_block( $post ) {
		$block_name = class_exists( 'SiteOrigin_Panels_Compat_Layout_Block' )
			? SiteOrigin_Panels_Compat_Layout_Block::BLOCK_NAME
			: 'siteorigin-panels/layout-block';

		$blocks = parse_blocks( $post->post_content );
		if ( empty( $blocks ) ) {
			return false;
		}

		foreach ( $blocks as $block ) {
			if (
				! empty( $block['blockName'] ) &&
				$block['blockName'] === $block_name &&
				! empty( $block['attrs'] ) &&
				! empty( $block['attrs']['panelsData'] )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Permission check for siteorigin-panels/layout-get.
	 *
	 * Authorization, not just authentication: the caller must be able to edit the
	 * target post to read its layout (mirrors the read REST route).
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param array $input Ability input — expects post_id.
	 *
	 * @return bool|WP_Error
	 */
	public function layout_get_permission( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'siteorigin_panels_cannot_read_layout',
				__( 'Sorry, you are not allowed to read this layout.', 'siteorigin-panels' )
			);
		}

		return true;
	}

	/**
	 * Execute siteorigin-panels/layout-get.
	 *
	 * Returns the SAME { post_id, source, layouts } shape as the read REST route
	 * by delegating to the shared reader, so REST and ability consumers never drift.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param array $input Ability input — expects post_id.
	 *
	 * @return array|WP_Error
	 */
	public function layout_get( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		return SiteOrigin_Panels_AI_Exposure::single()->read_layouts( $post_id );
	}
}
