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
	}
}
