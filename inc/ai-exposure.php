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
		// The block_index emitted here MUST match the targeted write in
		// SiteOrigin_Panels_Abilities; both derive from the SAME shared walk below
		// (get_qualifying_block_layouts), so an index can never resolve to a
		// different block between get and update.
		$block_layouts = $this->get_qualifying_block_layouts( $post );
		foreach ( $block_layouts as $entry ) {
			$layouts[] = array(
				'storage'     => 'block',
				'block_index' => $entry['block_index'],
				'panels_data' => $entry['panels_data'],
			);
		}

		if ( ! empty( $block_layouts ) ) {
			$source = ( $source === 'meta' ) ? 'mixed' : 'block';
		}

		return array(
			'post_id' => $post_id,
			'source'  => $source,  // 'meta' | 'block' | 'mixed' | 'none'
			// Each entry: { storage:'meta'|'block', block_index:int|null, panels_data:{...} }.
			'layouts' => $layouts,
		);
	}

	/**
	 * The registered Layout Block name (with a stable fallback).
	 *
	 * Single source of truth for the block-name resolution shared by the read
	 * labeller and the Abilities API targeted write.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @return string
	 */
	public function layout_block_name() {
		return class_exists( 'SiteOrigin_Panels_Compat_Layout_Block' )
			? SiteOrigin_Panels_Compat_Layout_Block::BLOCK_NAME
			: 'siteorigin-panels/layout-block';
	}

	/**
	 * The canonical ordered list of qualifying Layout Block layouts for a post.
	 *
	 * THE single walk that defines `block_index`. A block "qualifies" when it is a
	 * Layout Block with non-empty `attrs.panelsData` AND, after the public
	 * `siteorigin_panels_data` filter runs, still yields a non-empty layout — the
	 * exact same emptiness test the read path applies. `block_index` is the 0-based
	 * ordinal among these qualifying blocks in document order.
	 *
	 * Both `read_layouts()` (labelling) and
	 * `SiteOrigin_Panels_Abilities::write_block_layout()` (targeting) consume THIS
	 * method, so the count, the emitted index, and the write target are always
	 * derived identically — a filter that empties a structurally-qualifying block
	 * skips it in BOTH, and no index ever resolves to a different block.
	 *
	 * TOP-LEVEL ONLY: the walk inspects only top-level `parse_blocks()` output.
	 * Layout Blocks nested inside a container block (Group / Columns) do NOT
	 * qualify and are not targetable in Phase 1 (a recursive, path-keyed walk is
	 * backlog). `parse_blocks()` exists only from WP 5.0, so when it is
	 * unavailable (or the post has no content) this returns an empty list rather
	 * than fataling.
	 *
	 * @since {NEXT_VERSION}
	 * @api
	 *
	 * @param WP_Post $post The post to inspect.
	 *
	 * @return array<int,array{block_index:int,block_key:int|string,panels_data:array}>
	 *         Ordered list; `block_key` is the original parse_blocks() array key so a
	 *         writer can mutate the exact block this index refers to.
	 */
	public function get_qualifying_block_layouts( $post ) {
		$block_name = $this->layout_block_name();
		$post_id    = isset( $post->ID ) ? (int) $post->ID : 0;
		$qualifying = array();
		$index      = 0;

		// parse_blocks() only exists from WP 5.0; bail (no block layouts) when it is
		// unavailable or there is nothing to parse, keeping the REST route safe on
		// WP 4.7–4.9.
		if ( ! function_exists( 'parse_blocks' ) || empty( $post->post_content ) ) {
			return $qualifying;
		}

		$blocks = parse_blocks( $post->post_content );

		if ( empty( $blocks ) ) {
			return $qualifying;
		}

		foreach ( $blocks as $key => $block ) {
			if (
				empty( $block['blockName'] ) ||
				$block['blockName'] !== $block_name ||
				empty( $block['attrs'] ) ||
				empty( $block['attrs']['panelsData'] )
			) {
				continue;
			}

			$block_layout = apply_filters( 'siteorigin_panels_data', $block['attrs']['panelsData'], $post_id );
			if ( empty( $block_layout ) ) {
				// Post-filter empty — the read path skips this and assigns it no
				// index, so the write path must skip it identically.
				continue;
			}

			$qualifying[] = array(
				'block_index' => $index,
				'block_key'   => $key,
				'panels_data' => $block_layout,
			);
			$index++;
		}

		return $qualifying;
	}
}
