<?php
/**
 * Compatibility with WooCommerce.
 */
class SiteOrigin_Panels_Compat_WooCommerce {
	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	public function __construct() {
		add_filter( 'woocommerce_format_content', array( $this, 'generate_shop_content' ), 10, 2 );
	}

	/**
	 * Generate post content for the WooCommerce Shop page if it uses a Page Builder layout.
	 *
	 * @param string $content     Formatted content.
	 * @param string $raw_content Raw content before WooCommerce formatting.
	 *
	 * @return string
	 */
	public function generate_shop_content( $content, $_raw_content = '' ) {
		if ( ! self::is_shop_description_context() ) {
			return $content;
		}

		$shop_page_id = wc_get_page_id( 'shop' );
		if ( empty( $shop_page_id ) ) {
			return $content;
		}

		$shop_page = get_post( $shop_page_id );
		if ( empty( $shop_page ) ) {
			return $content;
		}

		global $post;
		$original_post = $post;

		// Ensure downstream content checks run against the Shop page context.
		$post = $shop_page;
		$content = SiteOrigin_Panels::single()->generate_post_content( $content );
		$post = $original_post;

		return $content;
	}

	/**
	 * Is WooCommerce currently rendering the Shop page archive description.
	 *
	 * Mirrors WooCommerce's own archive description conditions to avoid
	 * intercepting unrelated `wc_format_content()` calls (e.g. checkout TOS).
	 *
	 * @return bool
	 */
	private static function is_shop_description_context() {
		return (
			class_exists( 'WooCommerce' ) &&
			doing_action( 'woocommerce_archive_description' ) &&
			! is_search() &&
			is_post_type_archive( 'product' ) &&
			in_array( absint( get_query_var( 'paged' ) ), array( 0, 1 ), true )
		);
	}

	/**
	 * Should we use the configured WooCommerce Shop page ID as the current post ID.
	 *
	 * @return bool
	 */
	public static function should_use_shop_page_id() {
		if ( ! class_exists( 'WooCommerce' ) || ! is_shop() ) {
			return false;
		}

		$shop_page_id = wc_get_page_id( 'shop' );
		if ( empty( $shop_page_id ) ) {
			return false;
		}

		// The shop is a product archive request, even when loop internals shift the queried object.
		if ( is_post_type_archive( 'product' ) ) {
			return true;
		}

		return (int) get_queried_object_id() === (int) $shop_page_id;
	}
}
