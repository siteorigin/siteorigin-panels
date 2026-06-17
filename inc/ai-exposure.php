<?php

/**
 * SiteOrigin Panels — AI Exposure.
 *
 * Public API — premium-addon-facing. Registers a read-only REST route that
 * exposes a post's canonical panels_data to authorized consumers (e.g. an AI
 * premium addon). Core ships ZERO AI vendor logic: no API keys, model calls,
 * or prompts. This class only moves layout data through a stable, read-only
 * REST seam.
 *
 * Route:    GET /wp-json/siteorigin-panels/v1/layouts/<id>
 * Auth:     requires `edit_post` on <id>.
 * Phase 1:  READ-ONLY. No write surface (CREATABLE/EDITABLE/DELETABLE) here.
 *
 * Response shape (committed public API):
 *   {
 *     "post_id": int,
 *     "source":  "meta" | "block" | "mixed" | "none",
 *     "layouts": array<panels_data>   // array of canonical panels_data documents
 *   }
 *
 * @since {NEXT_VERSION}
 * @api
 */
class SiteOrigin_Panels_AI_Exposure {
	/**
	 * @var SiteOrigin_Panels_AI_Exposure
	 */
	private static $single;

	/**
	 * Get the singleton instance.
	 *
	 * @return SiteOrigin_Panels_AI_Exposure
	 */
	public static function single() {
		if ( empty( self::$single ) ) {
			self::$single = new self();
		}

		return self::$single;
	}

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the read-only AI exposure REST routes.
	 *
	 * Public API — premium-addon-facing. The namespace `siteorigin-panels/v1`
	 * and the route path `/layouts/(?P<id>\d+)` are load-bearing; a premium
	 * addon binds to them. Do not rename.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 */
	public function register_routes() {
		register_rest_route(
			'siteorigin-panels/v1',
			'/layouts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE, // read-only — no write in Phase 1
				'callback'            => array( $this, 'get_layout' ),
				'permission_callback' => array( $this, 'get_layout_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Post ID of the layout to read.', 'siteorigin-panels' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission check for reading a layout.
	 *
	 * Authorization, not just authentication: the caller must be able to edit
	 * the target post to read its layout.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return true|WP_Error
	 */
	public function get_layout_permissions_check( $request ) {
		$post_id = (int) $request['id'];
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_read_layout',
				__( 'Sorry, you are not allowed to read this layout.', 'siteorigin-panels' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Read the canonical panels_data for a post across BOTH storage paths.
	 *
	 * Classic-builder layouts live in the `panels_data` post meta; Layout Block
	 * layouts live in `panelsData` attributes inside post_content. This route
	 * reads both and reports which storage path(s) supplied data via `source`.
	 *
	 * Public API — premium-addon-facing, read-only in Phase 1. The response
	 * shape (post_id / source / layouts) is a committed contract.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_layout( $request ) {
		$post_id = (int) $request['id'];
		$result  = $this->read_layouts( $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Read the canonical panels_data for a post across BOTH storage paths.
	 *
	 * Shared read source for the REST route AND the Abilities API
	 * `siteorigin-panels/layout-get` ability, so both consumers see byte-identical
	 * data. Returns `{ post_id, source, layouts }`, or a WP_Error when the post
	 * does not exist.
	 *
	 * Each `layouts` entry is a LABELLED object so a consumer can identify and
	 * target a specific storage location for a follow-up write:
	 *   { storage: 'meta'|'block', block_index: int|null, panels_data: {...canonical...} }
	 * The classic/meta layout is `block_index: null`; each qualifying Layout Block
	 * gets its 0-based ordinal in document order (see the index walk below). This
	 * index is the LOCKED selector `layout-update` accepts as `block_index`.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param int $post_id Post ID of the layout to read.
	 *
	 * @return array{post_id:int,source:string,layouts:array}|WP_Error
	 */
	public function read_layouts( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( empty( $post ) ) {
			return new WP_Error(
				'rest_layout_not_found',
				__( 'Layout not found.', 'siteorigin-panels' ),
				array( 'status' => 404 )
			);
		}

		$layouts = array();
		$source  = 'none';

		// Meta-stored (classic builder). The classic layout has no block index.
		$meta = get_post_meta( $post_id, 'panels_data', true );
		if ( ! empty( $meta ) ) {
			$meta = apply_filters( 'siteorigin_panels_data', $meta, $post_id );
			if ( ! empty( $meta ) ) {
				$layouts[] = array(
					'storage'     => 'meta',
					'block_index' => null,
					'panels_data' => $meta,
				);
				$source    = 'meta';
			}
		}

		// Block-stored (Layout Block). A post can contain multiple layout blocks.
		$block_name = class_exists( 'SiteOrigin_Panels_Compat_Layout_Block' )
			? SiteOrigin_Panels_Compat_Layout_Block::BLOCK_NAME
			: 'siteorigin-panels/layout-block';

		$has_block_layout = false;
		// 0-based ordinal of a QUALIFYING Layout Block in document order. This must
		// be derived identically by the targeted block write in
		// SiteOrigin_Panels_Abilities::write_block_layout(); if the two walks ever
		// diverge, an update silently targets the wrong block. The counter therefore
		// increments ONLY on the branch that pushes a qualifying block below.
		$block_index = 0;
		$blocks      = parse_blocks( $post->post_content );
		if ( ! empty( $blocks ) ) {
			foreach ( $blocks as $block ) {
				if (
					empty( $block['blockName'] ) ||
					$block['blockName'] !== $block_name ||
					empty( $block['attrs'] ) ||
					empty( $block['attrs']['panelsData'] )
				) {
					continue;
				}

				$block_layout = apply_filters( 'siteorigin_panels_data', $block['attrs']['panelsData'], $post_id );
				if ( ! empty( $block_layout ) ) {
					$layouts[] = array(
						'storage'     => 'block',
						'block_index' => $block_index,
						'panels_data' => $block_layout,
					);
					$has_block_layout = true;
					$block_index++;
				}
			}
		}

		if ( $has_block_layout ) {
			$source = ( $source === 'meta' ) ? 'mixed' : 'block';
		}

		return array(
			'post_id' => $post_id,
			'source'  => $source,  // 'meta' | 'block' | 'mixed' | 'none'
			// Each entry: { storage:'meta'|'block', block_index:int|null, panels_data:{...} }.
			'layouts' => $layouts,
		);
	}
}
