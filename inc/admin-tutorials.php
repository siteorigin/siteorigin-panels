<?php

class SiteOrigin_Panels_Admin_Tutorials {
	
	function __construct() {
		add_action( 'wp_ajax_so_panels_get_tutorials', array( $this, 'action_get_tutorials' ) );
	}
	
	/**
	 * @return SiteOrigin_Panels_Admin_Tutorials
	 */
	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}
	
	/**
	 * Get the latest tutorials from SiteOrigin
	 */
	public function action_get_tutorials(){
		if ( empty( $_REQUEST['_panelsnonce'] ) || ! wp_verify_nonce( $_REQUEST['_panelsnonce'], 'panels_action' ) ) {
			wp_die();
		}
		
		$user = get_current_user_id();
		update_user_meta( $user, 'so_panels_tutorials_enabled', true );
		
		header( 'content-type:application/json' );
		
		$response = wp_remote_get('https://siteorigin.com/wp-json/siteorigin/v1/tutorials/page-builder/');
		if ( is_array( $response ) && $response['response']['code'] == 200 ) {
			$tutorials = json_decode( $response['body'] );
			echo json_encode( $tutorials );
		}
		else {
			echo json_encode( array() );
		}
		
		wp_die();
	}
	
}