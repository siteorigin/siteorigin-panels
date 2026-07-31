<?php

class SiteOrigin_Panels_Compat_Layout_Block {
	const BLOCK_NAME = 'siteorigin-panels/layout-block';
	private $return_layout = true;

	/**
	 * Request-local set of hashes of panelsData already sanitized this request,
	 * so the two save hooks that both fire on a single REST save (the
	 * rest_pre_insert_* server_side_validation() and the wp_insert_post_data
	 * validate_post_data() safety net) do not each run every widget's update()
	 * a second time. Purely in-memory: no postmeta, option, transient, marker,
	 * signature or salt. A hash MISS (JSON encoding differing across the
	 * serialize_blocks -> wp_slash -> wp_unslash -> parse_blocks round trip)
	 * just sanitizes twice, which is safe; a false HIT would require a sha256
	 * collision. Do NOT canonicalize to force matches — that is the complexity
	 * the trust-signature removal deliberately shed.
	 *
	 * @var array<string,true>
	 */
	private $sanitized_this_request = array();

	/**
	 * When true, the save-time kses floor in render_layout_block() is applied
	 * regardless of the author's `unfiltered_html` capability. Set/restored
	 * only by sanitize_block_untrusted() around the chokepoint call (the same
	 * instance-scoped discipline as $return_layout): origin-untrusted content
	 * (AI ability writes) must never be stored unfloored, because the
	 * capability belongs to the request's author while the content's origin
	 * does not. Deliberately private state, not a filter — third-party code
	 * must not be able to toggle a security floor.
	 */
	private $force_kses_floor = false;

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

		// Reusable blocks (wp_block posts) are never controlled by the
		// 'post-types' setting above — that setting toggles which post types
		// SHOW the Layout Block in their editor, not which saved content
		// requires validation. A Layout Block embedded in a reusable block can
		// end up rendered inside ANY post type via block-reuse, so wp_block
		// saves must always be validated regardless of site configuration.
		// wp_block is a REST-enabled ('show_in_rest' => true) built-in post
		// type using WP_REST_Blocks_Controller (extends WP_REST_Posts_Controller
		// without overriding prepare_item_for_database()), so
		// rest_pre_insert_wp_block fires through the identical mechanism as
		// rest_pre_insert_post/rest_pre_insert_page.
		add_action( 'rest_pre_insert_wp_block', array( $this, 'server_side_validation' ), 10, 2 );

		// Block-based widget areas store Block-widget content (including any
		// embedded Layout Block) in the widget_block OPTION, written via
		// WP_Widget::save_settings() -> update_option( 'widget_block', ... ) —
		// never through wp_insert_post()/rest_pre_insert_*. Every write path
		// (classic widgets.php, Customizer, REST widgets controller) funnels
		// through that same update_option() call, so the option-specific
		// pre_update_option_widget_block filter is the single hook needed to
		// sanitize Layout Blocks on this surface. Sanitizing here applies the
		// save-time kses floor for admins lacking unfiltered_html (e.g.
		// multisite).
		add_filter( 'pre_update_option_widget_block', array( $this, 'validate_widget_block_option' ), 10, 1 );

		// Supplemental (NOT a replacement for the rest_pre_insert_* hooks
		// above): wp_insert_post_data fires for every wp_insert_post()/
		// wp_update_post() caller EXCEPT attachments (post.php branches
		// attachment saves to wp_insert_attachment_data instead; core kses
		// still floors those) — XML-RPC, importers, WP-CLI, cron, direct
		// calls — none of which pass through the REST hooks. On REST-driven
		// saves both hooks legitimately co-fire and sanitize twice; sanitize
		// is expected to be idempotent on its own output (non-idempotent field
		// sanitizers are bugs in themselves — so-widgets-bundle PR #2316).
		add_filter( 'wp_insert_post_data', array( $this, 'validate_post_data' ), 10, 1 );
	}

	public function register_layout_block() {
		register_block_type( self::BLOCK_NAME, array(
			'render_callback' => array( $this, 'render_layout_block' ),
		) );
	}

	public function enqueue_layout_block_editor_assets() {
		$is_block_editor = SiteOrigin_Panels_Admin::is_block_editor();

		if ( $is_block_editor || is_customize_preview() ) {
			if ( $is_block_editor && function_exists( 'aioseo' ) ) {
				$aioseo = aioseo();
				if (
					is_object( $aioseo ) &&
					isset( $aioseo->standalone ) &&
					is_object( $aioseo->standalone ) &&
					isset( $aioseo->standalone->pageBuilderIntegrations ) &&
					is_array( $aioseo->standalone->pageBuilderIntegrations ) &&
					isset( $aioseo->standalone->pageBuilderIntegrations['siteorigin'] ) &&
					is_object( $aioseo->standalone->pageBuilderIntegrations['siteorigin'] )
				) {
					remove_action(
						'siteorigin_panel_enqueue_admin_scripts',
						array( $aioseo->standalone->pageBuilderIntegrations['siteorigin'], 'enqueue' )
					);
				}
			}
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
					'wp-block-editor',
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
		if ( empty( $attributes['panelsData'] ) || ! is_array( $attributes['panelsData'] ) ) {
			return '<div>' .
			__( "You need to add a widget, row, or prebuilt layout before you'll see anything here. :)", 'siteorigin-panels' ) .
			'</div>';
		}
		$panels_data = $attributes['panelsData'];
		if ( $this->return_layout ) {
			// Normal render (front-end or editor preview): unconditionally
			// structural. Skips BOTH process_raw_widgets()'s update() calls AND
			// sanitize_all() — never re-execute sanitizers against their own
			// stored output; this codebase has repeatedly found that unsafe
			// (see so-widgets-bundle PR #2316: posts field wiped to array(),
			// multiple-media PHP 8 TypeError, select/icon/font fields reset
			// valid values to default — all from re-running sanitizers against
			// already-sanitized stored data). Markup protection happens at
			// save time (core kses on HTTP paths, the kses floor below on
			// unarmed paths), never at render.
			$panels_data = $this->prepare_render_panels_data( $panels_data );
		} else {
			/**
			 * Filter a single Layout Block's panels_data before it is sanitized,
			 * allowing an AI-generated layout to be supplied or transformed.
			 *
			 * Block-editor counterpart of the classic-editor
			 * `siteorigin_panels_ai_layout_pre_save` filter (see inc/admin.php).
			 * Public API — premium-addon-facing. Fires PER LAYOUT BLOCK (a post may
			 * contain several), carrying only that block's panels_data.
			 *
			 * SAVE-TIME ONLY: this fires once per block on every save surface that
			 * reaches the sanitize chokepoint — REST save validation
			 * (`rest_pre_insert_*` → server_side_validation()), block widget areas
			 * (`pre_update_option_widget_block` → validate_widget_block_option()),
			 * the `wp_insert_post_data` safety net (validate_post_data()), and AI
			 * ability block writes (sanitize_block_untrusted()). It never fires at
			 * render: render is structural-only and deliberately does not re-fire
			 * save-time transforms.
			 *
			 * Whatever a consumer returns is re-sanitized through
			 * sanitize_panels_data() below, so returned widgets are NEVER trusted
			 * raw. A layout CHANGED by this filter is additionally passed through
			 * the kses floor regardless of the author's `unfiltered_html`
			 * capability — AI-transformed output is prompt-injectable no matter
			 * whose credential carries the request. Non-array returns are ignored.
			 *
			 * @since {NEXT_VERSION}
			 * @api
			 *
			 * @param array $panels_data The Layout Block's panels_data (grids, grid_cells, widgets).
			 */
			$filtered_panels_data = apply_filters( 'siteorigin_panels_ai_block_layout_pre_save', $panels_data );
			$ai_changed_layout = is_array( $filtered_panels_data ) && $filtered_panels_data !== $panels_data;
			if ( $ai_changed_layout ) {
				$panels_data = $filtered_panels_data;
			}

			// Save-time validation (sanitize_block()): strict, capability-gated
			// sanitize.
			$panels_data = $this->sanitize_panels_data( $panels_data );

			// current_user_can() runs in the real save-time request context
			// (the author's session), which is the only place capability-gated
			// sanitization is meaningful. Origin-untrusted content is floored
			// unconditionally: the capability belongs to the request's author,
			// but the content's origin is the AI (a forced-floor write via
			// sanitize_block_untrusted(), or a layout the AI pre-save filter
			// changed).
			if ( $this->force_kses_floor || $ai_changed_layout || ! current_user_can( 'unfiltered_html' ) ) {
				// Floor: stored output must not depend on any individual
				// field/widget sanitizer being "healthy" this request (some
				// SiteOrigin Widgets Bundle field sanitizers can silently pass
				// through unvalidated when their options registry isn't
				// hydrated on a given request — see so-widgets-bundle PR
				// #2316). wp_kses_post() needs no hydrated registry and is
				// idempotent, so it's a safe universal floor independent of
				// that failure mode.
				$panels_data['widgets'] = SiteOrigin_Panels_Admin::kses_deep( $panels_data['widgets'] );
			}
		}
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

		if ( $is_editing ) {
			SiteOrigin_Panels_Post_Content_Filters::add_filters( true );
		}

		if ( $is_editing || ! $this->return_layout ) {
			$rendered_layout = SiteOrigin_Panels_Admin::render_and_restore_post_globals( $builder_id, ! $is_editing, $panels_data );
		} else {
			$rendered_layout = SiteOrigin_Panels::renderer()->render( $builder_id, true, $panels_data );
		}

		if ( $is_editing ) {
			SiteOrigin_Panels_Post_Content_Filters::remove_filters( true );
		}

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
		if ( ! is_array( $panels_data ) ) {
			return $panels_data;
		}
		// Strip any inbound 'sanitize_signature' key (legacy trust marker from
		// the removed signing scheme) so stale or client-forged keys age out on
		// re-save and never persist into stored panels_data.
		unset( $panels_data['sanitize_signature'] );

		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true );
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		return $panels_data;
	}

	/**
	 * Prepare panels_data for rendering — unconditionally structural.
	 *
	 * Structural processing only (class resolution, panels_info assembly,
	 * raw-flag strip) — do NOT call update() or sanitize_all() here; never
	 * re-execute sanitizers against their own stored output (see
	 * so-widgets-bundle PR #2316). process_raw_widgets()'s $structural_only
	 * param skips the update()/kses_deep sanitize branches while keeping class
	 * resolution, escape_classes, and raw-flag unset intact. Render never
	 * consults a trust marker: save-time markup protection lives at the save
	 * chokepoints (core kses on HTTP paths, the kses floor on unarmed paths).
	 *
	 * Kept as the single shared prep point for render (render_layout_block())
	 * and CSS (maybe_generate_layout_block_css()) so the two never disagree
	 * about structure.
	 *
	 * @param array $panels_data Panels data from the stored block attribute.
	 * @return array
	 */
	private function prepare_render_panels_data( $panels_data ) {
		$panels_data = $this->normalize_render_fields( $panels_data );
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()
			->process_raw_widgets( $panels_data['widgets'], false, true, false, true );

		return $panels_data; // sanitize_all() deliberately NOT called here
	}

	/**
	 * Recursively normalize volatile per-save fields so nothing downstream
	 * chokes on a malformed value: 'builder_id' is kept only when it matches
	 * [A-Za-z0-9_-]+ (regenerated otherwise); '_sow_form_timestamp' is cast
	 * to int.
	 *
	 * @param array $panels_data Panels data (or any nested array of it).
	 * @return array
	 */
	private function normalize_render_fields( $panels_data ) {
		if ( ! is_array( $panels_data ) ) {
			return $panels_data;
		}

		foreach ( $panels_data as $key => $value ) {
			if ( $key === 'builder_id' ) {
				if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
					$panels_data[ $key ] = uniqid( 'gb' );
				}
			} elseif ( $key === '_sow_form_timestamp' ) {
				$panels_data[ $key ] = (int) $value;
			} elseif ( is_array( $value ) ) {
				$panels_data[ $key ] = $this->normalize_render_fields( $value );
			}
		}

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

			// Use the same trust/strict classification as render_layout_block()'s
			// render branch so the CSS generated here matches the HTML rendered
			// for the same panels_data on the same request.
			$panels_data = $this->prepare_render_panels_data( $panels_data );
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
			$block = $this->sanitize_blocks( $block );
		}

		$prepared_post->post_content = serialize_blocks( $blocks );

		return $prepared_post;
	}

	/**
	 * Validate any Layout Block content embedded in a block-based
	 * widget area's stored instances before the `widget_block` option is
	 * written. Fires on EVERY save path for this option (classic widgets.php,
	 * Customizer, REST) via the option-specific `pre_update_option_widget_block`
	 * filter.
	 *
	 * No unslash/reslash handling is needed here (unlike validate_post_data()):
	 * WP_Widget::update_callback() already runs stripslashes_deep() on the
	 * instance before the option write, so this handler receives unslashed data.
	 *
	 * @param array $value Proposed new `widget_block` option value (numeric
	 *                     widget-instance keys plus '_multiwidget').
	 * @return array The (possibly modified) value to actually persist.
	 */
	public function validate_widget_block_option( $value ) {
		if ( empty( $value ) || ! is_array( $value ) ) {
			// Fail-closed means "do nothing to make things worse," not
			// "invent structure that isn't there."
			return $value;
		}

		foreach ( $value as $number => &$instance ) {
			if ( $number === '_multiwidget' ) {
				// Bookkeeping flag, not a widget instance.
				continue;
			}

			if (
				! is_array( $instance ) ||
				empty( $instance['content'] ) ||
				! is_string( $instance['content'] )
			) {
				// Nothing to sanitize for this instance.
				continue;
			}

			$blocks = parse_blocks( $instance['content'] );
			if ( empty( $blocks ) ) {
				continue;
			}

			foreach ( $blocks as &$block ) {
				$block = $this->sanitize_blocks( $block );
			}
			unset( $block );

			$instance['content'] = serialize_blocks( $blocks );
		}
		unset( $instance );

		return $value;
	}

	/**
	 * Supplemental save-time validation for post saves that do not go through
	 * the REST API (XML-RPC, direct wp_insert_post()/wp_update_post() calls,
	 * importers, classic non-block-editor saves). Skips 'revision' post-type
	 * rows (covers both plain revisions and autosaves). Every Layout Block
	 * found is sanitized unconditionally — there is no trust marker to skip
	 * on, and sanitize is expected to be idempotent on its own output.
	 *
	 * @param array $data Slashed, processed post data about to be inserted/updated.
	 * @return array The (possibly modified) $data to actually persist.
	 */
	public function validate_post_data( $data ) {
		if ( ! empty( $data['post_type'] ) && $data['post_type'] === 'revision' ) {
			// Revisions AND autosaves are both nested wp_insert_post() calls
			// with post_type 'revision', fired on the same request as the
			// parent post's own save. Revision rows are never independently
			// rendered by render_layout_block(), so skipping them loses no
			// coverage — only avoids redundant work.
			return $data;
		}

		if ( empty( $data['post_content'] ) ) {
			return $data;
		}

		// Slashing contract: $data['post_content'] arrives SLASHED at this
		// filter (wp_insert_post() only unslashes AFTER wp_insert_post_data
		// returns — wp-includes/post.php). Parsing the slashed string would
		// leave every Layout Block's panelsData JSON undecodable (escaped
		// quotes), which would cause serialize_blocks() to write
		// back attrs-wiped blocks — silently DELETING panelsData. Unslash
		// before parsing, re-slash before writing back so this field matches
		// the slashed shape of its $data siblings. NOTE: this asymmetry versus
		// validate_widget_block_option() is intentional — that handler
		// receives already-unslashed data (WP_Widget::update_callback() runs
		// stripslashes_deep() upstream); this one does not.
		$content = wp_unslash( $data['post_content'] );

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return $data;
		}

		// Cheap presence check: most post saves contain no Layout Block at
		// all — bail before any sanitize/serialize work.
		$has_layout_block = false;
		foreach ( $blocks as $block ) {
			if ( $this->find_layout_block( $block ) ) {
				$has_layout_block = true;
				break;
			}
		}

		if ( ! $has_layout_block ) {
			return $data;
		}

		foreach ( $blocks as &$block ) {
			$block = $this->sanitize_blocks( $block );
		}
		unset( $block );

		$data['post_content'] = wp_slash( serialize_blocks( $blocks ) );

		return $data;
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
			empty( $block['attrs']['panelsData'] ) ||
			! is_array( $block['attrs']['panelsData'] )
		) {
			return $block;
		}

		// Same-request dedup: if this exact panelsData was already sanitized
		// earlier in THIS request (the rest_pre_insert_* hook), the
		// wp_insert_post_data safety net must not run update() on it a second
		// time. Check the INPUT here and record the OUTPUT below: on the second
		// hook the incoming block IS the first hook's sanitized output, so the
		// input hash here matches the output hash recorded there. Reached at
		// every tree depth via sanitize_blocks(), so nested Layout Blocks dedup
		// for free.
		//
		// Guard on a false encode: wp_json_encode() returns false when it cannot
		// encode (e.g. nesting past depth 512, or an unencodable value injected
		// via a filter), and hash( 'sha256', false ) collapses to the digest of
		// '' — so EVERY unencodable block would share one hash and a second such
		// block would falsely hit the memo and skip sanitization. A false encode
		// therefore neither checks nor records: it degrades to sanitizing twice,
		// the safe direction. (develop's sign_panels_data() guarded the same case.)
		$incoming_encoded = wp_json_encode( $block['attrs']['panelsData'] );
		if (
			false !== $incoming_encoded &&
			isset( $this->sanitized_this_request[ hash( 'sha256', $incoming_encoded ) ] )
		) {
			return $block;
		}

		// Save/restore the PRIOR value (not hard true) via try/finally: restore
		// keeps a thrown widget update() from leaving the flag stuck on the save
		// branch, and preserving the prior value keeps a re-entrant sanitize_block()
		// — reachable when the AI pre-save filter runs a nested block write — from
		// flipping the OUTER call back to the render branch mid-save.
		$previous_return_layout = $this->return_layout;
		$this->return_layout = false;
		try {
			$block['attrs'] = $this->render_layout_block( $block['attrs'] );
		} finally {
			$this->return_layout = $previous_return_layout;
		}
		unset( $block['innerHTML'] );
		if ( ! empty( $block['attrs']['renderedLayout'] ) ) {
			unset( $block['attrs']['renderedLayout'] );
		}

		// Record the OUTPUT: on the second hook of the same request this
		// sanitized panelsData is what arrives as the incoming block, so hashing
		// the output here is what the input check above will match. A re-entrant
		// nested sanitize_block() (AI pre-save filter running a nested block
		// write) records its own output first, then the outer records its own —
		// the outer already passed its input check before the inner ran.
		if (
			! empty( $block['attrs']['panelsData'] ) &&
			is_array( $block['attrs']['panelsData'] )
		) {
			// Same false-encode guard as the input check: never record the
			// digest of '' (what hash( 'sha256', false ) yields), or two
			// unencodable blocks would collide on it and the second would skip
			// sanitization.
			$output_encoded = wp_json_encode( $block['attrs']['panelsData'] );
			if ( false !== $output_encoded ) {
				$this->sanitized_this_request[ hash( 'sha256', $output_encoded ) ] = true;
			}
		}

		return $block;
	}

	/**
	 * Sanitize a Layout Block whose content origin is untrusted, forcing the
	 * kses floor regardless of the current user's capabilities.
	 *
	 * Entry point for AI ability writes (see inc/abilities.php): AI output is
	 * prompt-injectable no matter whose credential carries the request, so an
	 * admin application password must not exempt it from the floor the way it
	 * would exempt the author's own content. Runs the full save chokepoint —
	 * the `siteorigin_panels_ai_block_layout_pre_save` filter, strict
	 * sanitize, then the forced floor — in one pass.
	 *
	 * The flag restore uses try/finally because it guards a security floor; if
	 * an exception did escape, a stuck-true flag would only over-floor later
	 * saves in the same request — fail-closed. It restores the PRIOR value
	 * rather than a hard false so a re-entrant call (a consumer of the
	 * siteorigin_panels_ai_block_layout_pre_save filter or widget_update_callback
	 * calling this method on a sub-layout, both of which run while the outer
	 * flag is true) cannot clear the outer write's floor when the inner call
	 * returns.
	 *
	 * @param array $block A parsed Layout Block (parse_blocks() shape).
	 * @return array The block with sanitized, floored panelsData.
	 */
	public function sanitize_block_untrusted( $block ) {
		$previous = $this->force_kses_floor;
		$this->force_kses_floor = true;

		try {
			$block = $this->sanitize_block( $block );
		} finally {
			$this->force_kses_floor = $previous;
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
