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
		// sanitize-and-sign Layout Blocks on this surface. Signing here lets
		// widget-area Layout Blocks render via the trusted path (fixing the
		// blank-embed regression for this surface) and applies the save-time
		// kses floor for admins lacking unfiltered_html (e.g. multisite).
		add_filter( 'pre_update_option_widget_block', array( $this, 'validate_widget_block_option' ), 10, 1 );

		// Supplemental (NOT a replacement for the rest_pre_insert_* hooks
		// above): wp_insert_post_data fires for EVERY wp_insert_post()/
		// wp_update_post() caller — XML-RPC, importers, WP-CLI, cron, direct
		// calls — none of which pass through the REST hooks. On REST-driven
		// saves both hooks legitimately co-fire; validate_post_data() dedupes
		// by verifying each Layout Block's existing signature first and only
		// sanitizing blocks that have NOT already been validated, avoiding the
		// documented double-sanitization mutation risk (so-widgets-bundle PR
		// #2316).
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
		if ( empty( $attributes['panelsData'] ) ) {
			return '<div>' .
			__( "You need to add a widget, row, or prebuilt layout before you'll see anything here. :)", 'siteorigin-panels' ) .
			'</div>';
		}
		$panels_data = $attributes['panelsData'];
		if ( $this->return_layout ) {
			// Normal render (front-end or editor preview): trust only data
			// carrying a valid save-time signature. Skips BOTH
			// process_raw_widgets()'s update() calls AND sanitize_all() — never
			// re-execute sanitizers against their own stored output; this
			// codebase has repeatedly found that unsafe (see so-widgets-bundle
			// PR #2316: posts field wiped to array(), multiple-media PHP 8
			// TypeError, select/icon/font fields reset valid values to default —
			// all from re-running sanitizers against already-sanitized stored
			// data). The trusted path is safe specifically BECAUSE it never
			// re-executes anything, not because re-running would be a no-op.
			$panels_data = $this->prepare_render_panels_data( $panels_data );
		} else {
			// Save-time validation (sanitize_block()): strict, capability-gated,
			// sanitize then sign.
			$panels_data = $this->sanitize_panels_data( $panels_data );

			// current_user_can() runs in the real save-time request context
			// (the author's session), which is the only place capability-gated
			// sanitization is meaningful.
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				// Floor: the signature must not depend on any individual
				// field/widget sanitizer being "healthy" this request (some
				// SiteOrigin Widgets Bundle field sanitizers can silently pass
				// through unvalidated when their options registry isn't
				// hydrated on a given request — see so-widgets-bundle PR
				// #2316). wp_kses_post() needs no hydrated registry and is
				// idempotent, so it's a safe universal floor independent of
				// that failure mode.
				$panels_data['widgets'] = SiteOrigin_Panels_Admin::kses_deep( $panels_data['widgets'] );
			}
			$panels_data['sanitize_signature'] = $this->sign_panels_data( $panels_data );
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
		// Strip any inbound signature so a client-forged 'sanitize_signature'
		// key can never survive into what gets processed or later re-signed.
		unset( $panels_data['sanitize_signature'] );
		$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()->process_raw_widgets( $panels_data['widgets'], false, true );
		$panels_data = SiteOrigin_Panels_Styles_Admin::single()->sanitize_all( $panels_data );

		return $panels_data;
	}

	/**
	 * Prepare panels_data for rendering, trusting only validly-signed data.
	 *
	 * @param array $panels_data Panels data from the stored block attribute.
	 * @return array
	 */
	private function prepare_render_panels_data( $panels_data ) {
		if ( $this->verify_panels_data( $panels_data ) ) {
			// Verified: this exact array is the persisted output of a
			// save-time sanitize run. Structural processing only (class
			// resolution, panels_info assembly, raw-flag strip) — do NOT
			// call update() or sanitize_all() again. process_raw_widgets()'s
			// $trusted param skips the update()/kses_deep sanitize branches
			// while keeping class resolution, escape_classes, and raw-flag
			// unset intact.
			$panels_data['widgets'] = SiteOrigin_Panels_Admin::single()
				->process_raw_widgets( $panels_data['widgets'], false, true, false, true );
			return $panels_data; // sanitize_all() deliberately NOT called here
		}

		// Unsigned, tampered, or pre-existing content with no signature yet:
		// exactly today's strict path. Never sign here — this call can run
		// with an admin VIEWER's capabilities over content an unprivileged
		// AUTHOR supplied, and signing in this branch would launder attacker
		// content as trusted.
		return $this->sanitize_panels_data( $panels_data );
	}

	/**
	 * Compute the HMAC signature certifying that $panels_data is the exact
	 * output of a save-time capability-gated sanitize run.
	 *
	 * @param array $panels_data Sanitized panels data (any existing signature
	 *                           key is ignored).
	 * @return string|false HMAC-SHA256 hex digest, or false if wp_json_encode()
	 *                      of $panels_data failed.
	 */
	private function sign_panels_data( $panels_data ) {
		unset( $panels_data['sanitize_signature'] );

		// wp_json_encode() can return false (malformed UTF-8, resource refs,
		// depth exceeded). Without this guard, false would silently coerce to
		// '' in the concatenation below, producing a "valid-looking" signature
		// over "$version|" that is NOT tied to the real content. Fail closed
		// instead: a false return here stores an empty/false signature at the
		// save-time call site (which verify_panels_data() treats as 'missing')
		// and is treated as a failed verification at the verify call site.
		$encoded = wp_json_encode( $panels_data );
		if ( false === $encoded ) {
			return false;
		}

		// Version bump policy: bump 'panels:1' -> 'panels:2' ONLY for future
		// security-tightening changes to sanitization semantics, NEVER for
		// idempotency/bugfix changes. A bump invalidates every existing
		// signature, falling all previously-signed content back to strict
		// re-sanitization until each post is individually re-saved by its real
		// author (no safe bulk/cron re-signing exists, because capability-gated
		// sanitization is only meaningful under the real author's session).
		$version = apply_filters( 'siteorigin_panels_sanitize_version', 'panels:1' );
		return hash_hmac( 'sha256', $version . '|' . $encoded, wp_salt( 'auth' ) );
	}

	/**
	 * Verify that $panels_data carries a valid save-time signature.
	 *
	 * Fails closed: any missing, malformed, or non-matching signature returns
	 * false, sending the caller down the strict sanitize path.
	 *
	 * @param array $panels_data Panels data possibly carrying 'sanitize_signature'.
	 * @return bool
	 */
	private function verify_panels_data( $panels_data ) {
		if ( empty( $panels_data['sanitize_signature'] ) ) {
			// Case (a): no signature at all. Expected/normal for legacy
			// content saved before this scheme existed, or content from a
			// still-unvalidated save surface. Not itself suspicious, so this
			// logs only when debugging is explicitly enabled.
			$this->maybe_log_signature_failure( 'missing' );
			return false;
		}

		// sign_panels_data() returns false when wp_json_encode() fails; passing
		// that to hash_equals() would throw a PHP 8 TypeError (non-string
		// $known_string) — an uncaught fatal on the render path. Capture and
		// type-check it first: an UNVERIFIABLE signature (content that fails to
		// re-encode) is the same fail-closed class as an invalid one.
		$computed_signature = $this->sign_panels_data( $panels_data );

		if ( ! is_string( $panels_data['sanitize_signature'] )
			|| ! is_string( $computed_signature )
			|| ! hash_equals( $computed_signature, $panels_data['sanitize_signature'] )
		) {
			// Case (b): a signature IS present but does not verify. Suggests
			// tampering, a version bump, a salt rotation, OR canonicalization
			// drift (e.g. a widget update() output containing a JSON-object
			// value like stdClass, which round-trips as '{}' -> '[]' through
			// wp_json_encode()/json_decode(), silently and permanently
			// breaking that post's signature with no other visible signal).
			$this->maybe_log_signature_failure( 'invalid' );
			return false;
		}

		return true;
	}

	/**
	 * Log a signature verification failure when WP_DEBUG is enabled. Never
	 * outputs anything to the page — error_log() only, matching this
	 * codebase's absence of any prior debug-logging convention (no existing
	 * error_log()/WP_DEBUG usage was found anywhere in this plugin, so this
	 * establishes the minimal standard WordPress pattern for future use).
	 *
	 * @param string $reason 'missing' or 'invalid'.
	 */
	private function maybe_log_signature_failure( $reason ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( sprintf(
			'[SiteOrigin Panels] Layout Block render signature verification failed (%s). Falling back to strict sanitization for this render.',
			$reason === 'invalid'
				? 'signature present but did not verify — possible tampering, version/salt rotation, or JSON canonicalization drift'
				: 'no signature present — expected for legacy or not-yet-validated content'
		) );
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
			$block = $this->sanitize_blocks( $block, true );
		}

		$prepared_post->post_content = serialize_blocks( $blocks );

		return $prepared_post;
	}

	/**
	 * Validate and sign any Layout Block content embedded in a block-based
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
	 * rows (covers both plain revisions and autosaves). For any Layout Block
	 * found, skips re-sanitizing blocks whose panelsData ALREADY carries a
	 * valid signature (verify_panels_data() === true) to avoid redundant
	 * double-sanitization on REST-driven saves where rest_pre_insert_{type}
	 * already validated the content earlier in the same request.
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
		// quotes), which would (a) make verify_panels_data() always fail,
		// defeating the dedup below, and (b) cause serialize_blocks() to write
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
			$block = $this->sanitize_blocks_if_unverified( $block );
		}
		unset( $block );

		$data['post_content'] = wp_slash( serialize_blocks( $blocks ) );

		return $data;
	}

	/**
	 * Dedup-aware variant of sanitize_blocks(): consults verify_panels_data()
	 * first and only sanitizes Layout Blocks that do NOT already carry a valid
	 * signature. A verifying block is already-validated output from earlier in
	 * this same request (the rest_pre_insert_* hooks) or from a prior save —
	 * re-sanitizing it would risk the documented double-sanitization mutation
	 * bug (so-widgets-bundle PR #2316) for no security benefit. Mirrors
	 * sanitize_blocks()'s recursion shape; reuses sanitize_block() and
	 * verify_panels_data() unmodified.
	 *
	 * @param array $block A single parsed block.
	 * @return array The (possibly sanitized) block.
	 */
	private function sanitize_blocks_if_unverified( $block ) {
		if (
			! empty( $block['blockName'] ) &&
			$block['blockName'] === 'siteorigin-panels/layout-block' &&
			! empty( $block['attrs'] ) &&
			! empty( $block['attrs']['panelsData'] ) &&
			! $this->verify_panels_data( $block['attrs']['panelsData'] )
		) {
			$block = $this->sanitize_block( $block );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $i => $inner ) {
				$block['innerBlocks'][ $i ] = $this->sanitize_blocks_if_unverified( $inner );
			}
		}

		return $block;
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
