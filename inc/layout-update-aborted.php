<?php

/**
 * Carries a layout-update abort out of the save pipeline.
 *
 * Thrown when the `siteorigin_panels_layout_update_pre_write` filter stops a
 * layout-update ability write, when the layout holds an object other than a
 * plain object, or when the Layout Block stored form cannot be prepared. The
 * layout-update ability catches it and returns the WP_Error, so nothing is
 * stored or rendered.
 *
 * @since {NEXT_VERSION}
 */
class SiteOrigin_Panels_Layout_Update_Aborted extends Exception {
	/**
	 * @var WP_Error
	 */
	private $error;

	/**
	 * @param WP_Error $error The error the layout-update ability returns.
	 */
	public function __construct( WP_Error $error ) {
		parent::__construct( (string) $error->get_error_message() );

		$this->error = $error;
	}

	/**
	 * The error the layout-update ability returns.
	 *
	 * @return WP_Error
	 */
	public function get_error() {
		return $this->error;
	}
}
