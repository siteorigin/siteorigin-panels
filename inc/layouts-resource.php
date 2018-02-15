<?php
/**
 * Resource for SiteOrigin Panels layouts data.
 */

class SiteOrigin_Panels_Layouts_Resource extends WP_REST_Controller {
	
	public function register_routes() {
		$version = '1';
		$namespace = 'so-panels/v' . $version;
		$resource = 'layouts';
		
		// Might want to register a base layouts resource.
//		register_rest_route( $namespace, '/' . $resource, array(
//			'methods' => WP_REST_Server::READABLE,
//			'callback' => array( $this, 'get_layouts'),
//			'permission_callback' => array( $this, 'permissions_check' ),
//		) );
		
		$subresource = 'previews';
		register_rest_route( $namespace, '/' . $resource . '/' . $subresource, array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_layout_preview'),
			'args' => array(
				'panelsData' => array(
					'validate_callback' => array( $this, 'validate_panels_data'),
				),
			),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );
	}
	
	/**
	 * TODO: Check that current user has permission to access the requested data.
	 *
	 * @param $request
	 *
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return true;
	}
	
	/**
	 * TODO: Implement.
	 *
	 * @param $param
	 * @param $request
	 * @param $key
	 *
	 * @return bool
	 */
	function validate_panels_data( $param, $request, $key ) {
		return true;
	}
	
	/**
	 * Render and return the layout based in the supplied panels data.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_layout_preview( $request ) {
		$panels_data = json_decode( $request['panelsData'], true );
		
		$builder_id = 'gbp' . uniqid();
		
		$rendered_layout = SiteOrigin_Panels::renderer()->render( $builder_id, true, $panels_data, $layout_data );
		
		$widget_css = '@import url(' . SiteOrigin_Panels::front_css_url() . '); ';
		$widget_css .= SiteOrigin_Panels::renderer()->generate_css( $builder_id, $panels_data, $layout_data );
		$widget_css = preg_replace( '/\s+/', ' ', $widget_css );
		$rendered_layout .= "\n\n" .
			 '<style type="text/css" class="panels-style" data-panels-style-for-post="' . esc_attr( $builder_id ) . '">' .
			 $widget_css .
			 '</style>';
		
		return rest_ensure_response( $rendered_layout );
	}
}
