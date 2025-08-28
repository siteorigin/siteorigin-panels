<?php
/**
 * Compatibility class for Polylang integration with SiteOrigin Page Builder.
 *
 * Ensures that Page Builder data is properly synchronized between translations.
 */
class SiteOrigin_Panels_Compat_Polylang {
	private $pll_language_cache = array();

	/**
	 * This function is called using a priority of `22` to bypass the
	 * `wpml-config.xml` rules. The WPML Config contains an instruction
	 * to exclude panels_data from the sync process and copy.
	 * This is a workaround to ensure that panels_data is included.
	*/
	public function __construct() {
		add_filter( 'pll_copy_post_metas', array( $this, 'copy_panels_data' ), 22, 5 );
	}

	public static function single() {
		static $single;

		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Get the Polylang language data for a post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array The Polylang language data for the post.
	 */
	private function get_pll_language_cache( $post_id ) {
		if ( ! empty( $this->pll_language_cache[ $post_id ] ) ) {
			return $this->pll_language_cache[ $post_id ];
		}

		$terms = get_object_term_cache( $post_id, 'post_translations' );
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			$this->pll_language_cache[ $post_id ] = array();
			return array();
		}

		$language_data = maybe_unserialize( $terms[0]->description );

		// If there's no sync data, there's nothing to sync.
		if ( empty( $language_data['sync'] ) || ! is_array( $language_data['sync'] ) ) {
			return $this->pll_language_cache[ $post_id ] = array();
		}

		$sync_data = $language_data['sync'];
		unset( $language_data['sync'] );

		return $this->pll_language_cache[ $post_id ] = array(
			'posts' => array_flip( $language_data ),
			'sync' => $sync_data,
		);
	}

	/**
	 * Determines if synchronization is enabled between two posts in different languages.
	 *
	 * @param int    $from The source post ID.
	 * @param int    $to   The target post ID.
	 * @param string $lang The language code.
	 *
	 * @return bool True if sync is enabled between the posts, false otherwise.
	 */
	private function is_sync_enabled( $from, $to, $lang ) {
		$language_data = $this->get_pll_language_cache( $from );
		if ( empty( $language_data ) ) {
			return false;
		}

		// Confirm valid $from and $to language codes.
		if (
			empty( $language_data['posts'][ $from ] ) ||
			empty( $language_data['posts'][ $to ] )
		) {
			return false;
		}

		$to_language = $language_data['posts'][ $to ];
		$from_language = $language_data['posts'][ $from ];

		// Does the to & from language have a sync value?
		if (
			empty( $language_data['sync'][ $to_language ] ) ||
			empty( $language_data['sync'][ $from_language ] )
		) {
			return false;
		}

		return $language_data['sync'][ $to_language ] === $language_data['sync'][ $from_language ];
	}

	/**
	 * Adds panels_data to the list of meta keys to copy when translating posts
	 * if synchronization is enabled between the source and target posts.
	 *
	 * @param array  $keys List of meta keys to copy.
	 * @param bool   $sync Whether synchronization is enabled for the current action.
	 * @param int    $from Source post ID.
	 * @param int    $to   Target post ID.
	 * @param string $lang Language code.
	 *
	 * @return array Modified list of meta keys to copy.
	 */
	public function copy_panels_data( $keys, $sync, $from, $to, $lang ) {
		if ( $this->is_sync_enabled( $from, $to, $lang ) ) {
			$keys[] = 'panels_data';
		} else {
			// To avoid potential syncing issues, we need to make sure that
			// 'panels_data' is not included in the keys to copy.
			$keys = array_diff( $keys, array( 'panels_data' ) );
		}

		return $keys;
	}

}
