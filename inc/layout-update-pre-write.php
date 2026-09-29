<?php

/**
 * The layout-update pre-write hook.
 *
 * The single place that applies the `siteorigin_panels_layout_update_pre_write`
 * filter. Both layout-update write paths call run(): the classic (meta) path in
 * SiteOrigin_Panels_Abilities::update_meta_layout(), and the Layout Block path
 * in SiteOrigin_Panels_Compat_Layout_Block::render_layout_block().
 *
 * @since {NEXT_VERSION}
 */
class SiteOrigin_Panels_Layout_Update_Pre_Write {
	/**
	 * Fire the pre-write hook for one layout-update write.
	 *
	 * @since {NEXT_VERSION}
	 *
	 * @param array    $panels_data Sanitized layout exactly as it will be stored. The filter receives a deep copy.
	 * @param int      $post_id     The post being written.
	 * @param string   $storage     'meta' or 'block'.
	 * @param int|null $block_index 0-based Layout Block index; null for 'meta'.
	 *
	 * @throws SiteOrigin_Panels_Layout_Update_Aborted When the layout holds an object other than a plain
	 *                                                 object, or the filter returns a WP_Error or exactly false.
	 */
	public static function run( $panels_data, $post_id, $storage, $block_index ) {
		$payload = self::detach( $panels_data );

		/**
		 * Filters whether a layout-update ability write may continue.
		 *
		 * Lets an add-on inspect the final layout of a layout-update ability
		 * write and stop the write before anything is stored or rendered.
		 *
		 * Fires only for the `siteorigin-panels/layout-update` ability. Human
		 * editor saves, REST block saves and other save surfaces do not fire it.
		 * Fires exactly once per layout-update write, on both storage paths,
		 * after the final sanitizer and before any write or render:
		 *  - 'meta': after the widget sanitizer, the
		 *    `siteorigin_panels_data_pre_save` filter, the style check and the
		 *    kses floor; before the meta update, the empty-layout meta delete
		 *    (it fires for an empty layout too) and the copy-content refresh.
		 *  - 'block': after the widget sanitizer and the forced kses floor, and
		 *    after Page Builder applies the WordPress save transforms the block
		 *    will receive; before the block preview render and the post update.
		 *
		 * Abort: return a WP_Error, or exactly `false`, to stop the write. Then
		 * nothing is stored, deleted, mirrored or rendered, no revision is made,
		 * and layout-update returns the WP_Error. For `false` it returns a
		 * WP_Error with the code `siteorigin_panels_layout_update_aborted`. Any
		 * other return value lets the write continue. An exception thrown by a
		 * listener is not caught.
		 *
		 * Read-only: the filter cannot change what is stored. $panels_data is a
		 * deep copy. A layout may hold only arrays, scalars, null and plain
		 * objects (stdClass), which is all REST or MCP input can hold. If it
		 * holds any other object, the write stops before this filter with the
		 * code `siteorigin_panels_layout_update_unsupported_value`, because
		 * such an object can keep state the copy cannot reach. Then nothing is
		 * stored, deleted, mirrored or rendered.
		 *
		 * Scope of "equals what is stored": $panels_data equals the stored
		 * layout for the WordPress core and Page Builder save pipeline — core's
		 * default `pre_post_content` and `content_save_pre` filters (including
		 * the kses filters core adds for users without `unfiltered_html`), Page
		 * Builder's own `wp_insert_post_data` processing, and
		 * `update_post_meta()`. Third-party save filters are outside this
		 * guarantee: a filter that reads the surrounding post content, or acts
		 * differently depending on how many times it has run, or any
		 * third-party `wp_insert_post_data`, `update_post_metadata` or
		 * `sanitize_post_meta_*` filter, can make the stored data differ from
		 * $panels_data. On the 'block' path the write can also stop with the
		 * code `siteorigin_panels_layout_update_unstable` when the stored form
		 * does not settle within 8 save-filter passes; then nothing is written
		 * or rendered and this filter does not fire.
		 *
		 * @since {NEXT_VERSION}
		 * @api
		 *
		 * @param true|WP_Error $result      Pass-through; true means continue.
		 * @param array         $panels_data The exact, fully sanitized layout that will be stored.
		 * @param int           $post_id     The post being written.
		 * @param string        $storage     'meta' (classic layout) or 'block' (Layout Block).
		 * @param int|null      $block_index 0-based Layout Block index, as in layout-get; null for 'meta'.
		 */
		$result = apply_filters( 'siteorigin_panels_layout_update_pre_write', true, $payload, (int) $post_id, $storage, $block_index );

		if ( $result === false ) {
			$result = new WP_Error(
				'siteorigin_panels_layout_update_aborted',
				__( 'The layout update was stopped before it was saved.', 'siteorigin-panels' )
			);
		}

		if ( is_wp_error( $result ) ) {
			throw new SiteOrigin_Panels_Layout_Update_Aborted( $result );
		}
	}

	/**
	 * Recursive copy of a layout value for the filter payload.
	 *
	 * Arrays are copied value by value; plain objects (stdClass) are cloned
	 * and their properties copied, so a listener that changes a nested object
	 * in its payload cannot change the value that is stored. A plain object
	 * has only public properties, so the copy reaches all of its state.
	 *
	 * Any other object stops the write: it can hold state a copy cannot
	 * reach (non-public or readonly properties, or storage inside an internal
	 * class). REST and JSON input only ever holds arrays and plain objects.
	 *
	 * @param mixed $value The value to copy.
	 *
	 * @throws SiteOrigin_Panels_Layout_Update_Aborted Code siteorigin_panels_layout_update_unsupported_value.
	 *
	 * @return mixed
	 */
	private static function detach( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::detach( $item );
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			if ( get_class( $value ) !== 'stdClass' ) {
				throw new SiteOrigin_Panels_Layout_Update_Aborted(
					new WP_Error(
						'siteorigin_panels_layout_update_unsupported_value',
						__( 'The layout contains a value that cannot be saved by a layout update.', 'siteorigin-panels' )
					)
				);
			}

			$copy = clone $value;
			foreach ( get_object_vars( $copy ) as $key => $item ) {
				$copy->$key = self::detach( $item );
			}

			return $copy;
		}

		return $value;
	}
}
