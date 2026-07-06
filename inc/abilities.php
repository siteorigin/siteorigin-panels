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
 *                                       layout, OR a specific block-stored Layout
 *                                       Block selected by block_index, through the
 *                                       same sanitizer the corresponding save uses.
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
		// Categories must be registered on the categories-init hook, BEFORE the
		// abilities-init hook (an ability references its category at registration).
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );

		// Abilities must be registered on the documented init hook; registering
		// outside it triggers _doing_it_wrong() and the registration fails.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the Page Builder ability category.
	 *
	 * Groups the paired layout abilities for client-side discoverability (matching
	 * the AIOSEO precedent). Guarded for environments without the Abilities API,
	 * same as register_abilities(), so core never fatals on WP < 6.9.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 */
	public function register_ability_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'siteorigin-panels',
			array(
				'label'       => __( 'Page Builder by SiteOrigin', 'siteorigin-panels' ),
				'description' => __( 'Read and update SiteOrigin Page Builder layouts.', 'siteorigin-panels' ),
			)
		);
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
				'description'         => __( "Reads a post's canonical Page Builder layout data. Returns layouts from both classic (meta-stored) and Layout Block storage; the 'source' field reports which storage path(s) supplied data. Each layouts entry is labelled with its storage ('meta' or 'block') and block_index (null for the classic layout; the 0-based ordinal among Layout Blocks otherwise). Pass that block_index to layout-update to write a specific Layout Block.", 'siteorigin-panels' ),
				'category'            => 'siteorigin-panels',
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
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'storage'     => array(
										'type' => 'string',
										'enum' => array( 'meta', 'block' ),
									),
									'block_index' => array(
										'type' => array( 'integer', 'null' ),
									),
									'panels_data' => array( 'type' => 'object' ),
								),
							),
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
				'description'         => __( "Writes a post's Page Builder layout — either the classic (meta-stored) layout, or a specific block-stored Layout Block selected by block_index (the 0-based index from layout-get). The incoming layout is re-sanitized through Page Builder's widget sanitizer before being persisted, so input is never trusted raw. When a post has multiple Layout Blocks, block_index is required; if it is missing or out of range the call declines as 'block-ambiguous' rather than guessing.", 'siteorigin-panels' ),
				'category'            => 'siteorigin-panels',
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
						'block_index' => array(
							'type'        => 'integer',
							'description' => __( 'For block-stored posts, the 0-based index (from layout-get) of the Layout Block to write. Optional for a single-block post (defaults to 0); required when the post has multiple Layout Blocks. Ignored for classic/meta posts.', 'siteorigin-panels' ),
							'minimum'     => 0,
						),
					),
					'required'             => array( 'post_id', 'panels_data' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'updated'     => array( 'type' => 'boolean' ),
						'source'      => array(
							'type' => 'string',
							'enum' => array( 'meta', 'block', 'block-ambiguous', 'unsupported' ),
						),
						'block_index' => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
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
	 * Persists either a classic (meta-stored) layout or a specific block-stored
	 * Layout Block. Routing mirrors exactly what layout-get advertises:
	 *  - `block_index` omitted (null) AND the post has a meta layout → write meta.
	 *    This honors the `{ storage:'meta', block_index:null }` entry layout-get
	 *    surfaces, so a mixed (meta + block) post can still update its meta layout.
	 *  - otherwise, if the post has Layout Block(s) → write the targeted block
	 *    (block_index resolved against the SAME shared walk; ambiguity is declined,
	 *    never guessed).
	 *  - otherwise (no blocks) → meta path.
	 * The incoming layout MUST traverse process_raw_widgets() before persist — the
	 * same §3 guarantee every other write path enforces; input is never trusted raw.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param array $input Ability input — expects post_id, panels_data; optional block_index.
	 *
	 * @return array|WP_Error
	 */
	public function layout_update( $input ) {
		$post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$panels_data = isset( $input['panels_data'] ) && is_array( $input['panels_data'] ) ? $input['panels_data'] : array();
		$block_index = isset( $input['block_index'] ) && is_numeric( $input['block_index'] ) ? (int) $input['block_index'] : null;

		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return array(
				'post_id' => $post_id,
				'updated' => false,
				'source'  => 'unsupported',
				'message' => __( 'Layout not found.', 'siteorigin-panels' ),
			);
		}

		$old_panels_data = get_post_meta( $post_id, 'panels_data', true );
		$has_meta_layout = ! empty( $old_panels_data );

		// No block_index given AND a meta layout exists → write meta. This covers
		// the pure-classic post and honors the meta entry layout-get advertises on
		// a mixed post (so its classic layout stays writable).
		if ( $block_index === null && $has_meta_layout ) {
			return $this->update_meta_layout( $post_id, $panels_data, $old_panels_data );
		}

		// Block-stored: write the targeted Layout Block (never guess on ambiguity).
		$block_count = $this->count_layout_blocks( $post );
		if ( $block_count > 0 ) {
			return $this->update_block_layout( $post, $block_count, $block_index, $panels_data );
		}

		// No blocks present → meta path (creates/updates the classic layout).
		return $this->update_meta_layout( $post_id, $panels_data, $old_panels_data );
	}

	/**
	 * Write the classic (meta-stored) layout.
	 *
	 * Mirrors the persist semantics of the classic save (admin.php save_post):
	 * re-sanitizes via process_raw_widgets(), runs the sidebars-emulator when
	 * enabled, applies the public siteorigin_panels_data_pre_save filter, and —
	 * like save_post — DELETES the meta when the sanitized layout has no widgets
	 * and no grids (rather than persisting an empty layout). §3: input is never
	 * persisted raw; the value is double-slashed because update_post_meta()
	 * wp_unslash()es its input.
	 *
	 * @param int   $post_id         The post to write to.
	 * @param array $panels_data     Incoming canonical panels_data.
	 * @param mixed $old_panels_data Existing meta value (any scalar/array from get_post_meta).
	 *
	 * @return array The layout-update result array.
	 */
	protected function update_meta_layout( $post_id, $panels_data, $old_panels_data ) {
		$admin = SiteOrigin_Panels_Admin::single();

		// Fetch the post up-front so it can be passed to the pre-save filter and
		// reused for the copy-content refresh.
		$post = get_post( $post_id );

		// get_post_meta() can return a non-array scalar (e.g. '') — normalize so the
		// ['widgets'] read below is explicit and future-proof.
		$old_panels_data = is_array( $old_panels_data ) ? $old_panels_data : array();

		$panels_data['widgets'] = $admin->process_raw_widgets(
			! empty( $panels_data['widgets'] ) ? $panels_data['widgets'] : array(),
			! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
			false
		);

		// Sidebars-emulator parity (admin.php save_post): generate sidebar widget IDs
		// when the setting is on, between sanitize passes.
		if ( siteorigin_panels_setting( 'sidebars-emulator' ) ) {
			$panels_data['widgets'] = SiteOrigin_Panels_Sidebars_Emulator::single()->generate_sidebar_widget_ids( $panels_data['widgets'], $post_id );
		}

		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		// Apply the same public pre-save filter save_post applies, so third-party
		// pre-save transforms run on ability writes too.
		$panels_data = apply_filters( 'siteorigin_panels_data_pre_save', $panels_data, $post, $post_id );

		// Empty-layout parity (admin.php save_post): a layout with no widgets and no
		// grids means "clear the layout" — delete the meta rather than storing an
		// empty layout, and skip the copy-content refresh.
		if ( empty( $panels_data['widgets'] ) && empty( $panels_data['grids'] ) ) {
			delete_post_meta( $post_id, 'panels_data' );

			return array(
				'post_id' => $post_id,
				'updated' => true,
				'source'  => 'meta',
				'message' => __( 'Layout cleared.', 'siteorigin-panels' ),
			);
		}

		// update_post_meta() wp_unslash()es its input, so backslashes (e.g. a
		// namespaced widget class 'SiteOrigin\Widget\Foo', or content like C:\path)
		// must be double-slashed first — same as save_post() and the home-page save.
		update_post_meta( $post_id, 'panels_data', map_deep( $panels_data, array( 'SiteOrigin_Panels_Admin', 'double_slash_string' ) ) );

		// Refresh the copy-content post_content mirror through the SAME code a human
		// editor save uses, so on copy-content sites this programmatic write is not
		// stale until the next save. No-op when the copy-content setting is off.
		// Guarded: copy_content_to_post() may call wp_update_post(), which re-fires
		// save_post(); with_save_guard() sets/restores $in_save_post so that nested
		// save early-returns exactly as it does for the editor's own copy write.
		// NOT called for block writes (write_block_layout) — block layouts render
		// dynamically and have no stale post_content mirror.
		if ( ! empty( $post ) ) {
			$admin->with_save_guard(
				function () use ( $admin, $post, $post_id, $panels_data ) {
					$admin->copy_content_to_post( $post, $post_id, $panels_data );
				}
			);
		}

		return array(
			'post_id' => $post_id,
			'updated' => true,
			'source'  => 'meta',
		);
	}

	/**
	 * Resolve which Layout Block to write, then write it.
	 *
	 * Selection rules (never guess on ambiguity):
	 *  - exactly ONE block: block_index defaults to 0; a non-zero index is declined.
	 *  - MORE THAN ONE block: block_index is REQUIRED and must be in range; a missing
	 *    or out-of-range index is declined as 'block-ambiguous' with the valid range.
	 *
	 * @param WP_Post  $post        The post being written.
	 * @param int      $block_count Number of qualifying Layout Blocks in the post.
	 * @param int|null $block_index Caller-supplied index, or null when omitted.
	 * @param array    $panels_data Canonical panels_data to persist.
	 *
	 * @return array The layout-update result array.
	 */
	protected function update_block_layout( $post, $block_count, $block_index, $panels_data ) {
		$max_index = $block_count - 1;

		if ( $block_count === 1 ) {
			if ( $block_index !== null && $block_index !== 0 ) {
				return array(
					'post_id' => (int) $post->ID,
					'updated' => false,
					'source'  => 'block',
					'message' => __( 'This post has a single Layout Block; block_index must be 0 or omitted.', 'siteorigin-panels' ),
				);
			}

			$block_index = 0;
		} else {
			// More than one block — an explicit, in-range index is mandatory.
			if ( $block_index === null || $block_index < 0 || $block_index > $max_index ) {
				return array(
					'post_id' => (int) $post->ID,
					'updated' => false,
					'source'  => 'block-ambiguous',
					'message' => sprintf(
						/* translators: %s: valid block_index range, e.g. "0-2". */
						__( 'This post has multiple Layout Blocks; a valid block_index is required. Valid indices: %s.', 'siteorigin-panels' ),
						'0-' . $max_index
					),
				);
			}
		}

		$written = $this->write_block_layout( $post, $block_index, $panels_data );

		if ( is_wp_error( $written ) ) {
			// A genuine save failure: index was valid but the post update did not
			// persist. Report a non-silent block failure (not ambiguity).
			if ( $written->get_error_code() === 'block_write_failed' ) {
				return array(
					'post_id' => (int) $post->ID,
					'updated' => false,
					'source'  => 'block',
					'message' => __( 'The layout could not be saved.', 'siteorigin-panels' ),
				);
			}

			// 'block_index_not_found' is purely defensive: after the get/write walk
			// unification an in-range index always resolves, so this is effectively
			// unreachable here. Treat any residual miss as ambiguous with the range.
			return array(
				'post_id' => (int) $post->ID,
				'updated' => false,
				'source'  => 'block-ambiguous',
				'message' => sprintf(
					/* translators: %s: valid block_index range, e.g. "0-2". */
					__( 'Requested block_index could not be resolved. Valid indices: %s.', 'siteorigin-panels' ),
					'0-' . $max_index
				),
			);
		}

		return array(
			'post_id'     => (int) $post->ID,
			'updated'     => true,
			'source'      => 'block',
			'block_index' => $written,
		);
	}

	/**
	 * The ordered qualifying Layout Block layouts for a post.
	 *
	 * Delegates to the SINGLE shared walk on SiteOrigin_Panels_AI_Exposure so the
	 * count, the index layout-get emits, and the write target are all derived
	 * identically (including the post-`siteorigin_panels_data`-filter emptiness
	 * test). This is what guarantees a chosen block_index can never resolve to a
	 * different block between get and update.
	 *
	 * @param WP_Post $post The post to inspect.
	 *
	 * @return array Ordered list of { block_index, block_key, panels_data }.
	 */
	protected function qualifying_block_layouts( $post ) {
		return SiteOrigin_Panels_AI_Exposure::single()->get_qualifying_block_layouts( $post );
	}

	/**
	 * Count the qualifying Layout Blocks in a post.
	 *
	 * @param WP_Post $post The post to inspect.
	 *
	 * @return int
	 */
	protected function count_layout_blocks( $post ) {
		return count( $this->qualifying_block_layouts( $post ) );
	}

	/**
	 * Whether a post stores its layout in a Layout Block.
	 *
	 * @param WP_Post $post The post to inspect.
	 *
	 * @return bool
	 */
	protected function post_has_layout_block( $post ) {
		return $this->count_layout_blocks( $post ) > 0;
	}

	/**
	 * Write a sanitized layout into a specific qualifying Layout Block.
	 *
	 * Targets the block whose 0-based ordinal among QUALIFYING Layout Blocks equals
	 * $block_index — resolved from the SAME shared walk read_layouts() labels with,
	 * so get and update never disagree about which block an index means. The walk
	 * returns each block's original parse_blocks() key, so we mutate exactly that
	 * block.
	 *
	 * §3: the incoming layout is re-sanitized through the SAME path a Layout Block
	 * save uses (process_raw_widgets( $widgets, false, true ) + sanitize_all()),
	 * mirroring compat/layout-block.php::sanitize_panels_data(); AI input is never
	 * persisted raw. Only the target block's panelsData is replaced — every other
	 * block is left byte-identical — then post_content is re-serialized and saved.
	 *
	 * @param WP_Post $post        The post to write to.
	 * @param int     $block_index 0-based qualifying-block ordinal to target.
	 * @param array   $panels_data Canonical panels_data to persist into that block.
	 *
	 * @return int|WP_Error Matched block index on success; WP_Error 'block_index_not_found'
	 *                      when no qualifying block has that index, or 'block_write_failed'
	 *                      when the post update did not persist.
	 */
	protected function write_block_layout( $post, $block_index, $panels_data ) {
		$qualifying = $this->qualifying_block_layouts( $post );

		$target_key = null;
		foreach ( $qualifying as $entry ) {
			if ( $entry['block_index'] === $block_index ) {
				$target_key = $entry['block_key'];
				break;
			}
		}

		if ( $target_key === null ) {
			return new WP_Error( 'block_index_not_found', __( 'Requested block index not found.', 'siteorigin-panels' ) );
		}

		// §3 — re-sanitize through the SAME path block saves use. Never trust raw.
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets(
			! empty( $panels_data['widgets'] ) ? $panels_data['widgets'] : array(),
			false,
			true
		);
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		// Re-parse and replace ONLY the target block's panelsData (the walk's
		// block_key indexes this same parse_blocks() array); all other blocks
		// untouched.
		$blocks = parse_blocks( $post->post_content );
		$blocks[ $target_key ]['attrs']['panelsData'] = $panels_data;

		// wp_update_post()/wp_insert_post() run wp_unslash() on their input, so the
		// content MUST be slashed first — otherwise the backslash in every JSON
		// escape that serialize_blocks() produces (e.g. < for '<') is stripped,
		// corrupting all markup in the stored block attributes. Core's own REST
		// controller slashes for the same reason.
		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( serialize_blocks( $blocks ) ),
			),
			true
		);

		// No silent success: wp_update_post returns 0 or a WP_Error on failure.
		if ( empty( $result ) || is_wp_error( $result ) ) {
			return new WP_Error( 'block_write_failed', __( 'The layout could not be saved.', 'siteorigin-panels' ) );
		}

		return $block_index;
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
