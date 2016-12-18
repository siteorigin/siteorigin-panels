<?php

class SiteOrigin_Panels_Courses {

	function __construct() {
		add_action( 'siteorigin_panels_courses', array( $this, 'courses' ) );
	}

	static function single(){
		static $single;
		return empty( $single ) ? ( $single = new self() ) : $single;
	}

	function courses( $courses ){
		$courses[ 'seo' ] = array(
			'button' => __( 'SEO', 'siteorigin-panels' ),
			'title' => __( 'Page Builder SEO', 'siteorigin-panels' ),
			'text' => __( 'Learn Page Builder SEO.', 'siteorigin-panels' ),
			'id' => '71ccd71c07'
		);

		$courses[ 'tips' ] = array(
			'button' => __( 'Tips', 'siteorigin-panels' ),
			'title' => __( 'Page Builder Tips', 'siteorigin-panels' ),
			'text' => __( '12 tips every Page Builder user should know.', 'siteorigin-panels' ),
			'id' => '300cd058f8'
		);

		return $courses;
	}

	function get_course(){
		static $course;
		if( ! empty( $course ) ) return $course;

		static $courses;
		if( empty( $courses ) ){
			$courses = apply_filters( 'siteorigin_panels_courses', array( ) );
		}

		if( empty( $courses ) ) return false;

		$ids = array_keys( $courses );
		$i = floor( time() / (20*60) ) % count( $ids );
		$course = $courses[ $ids[ $i ] ];

		$user = wp_get_current_user();
		$signup_url = add_query_arg( array(
			'email' => $user->user_email,
			'name' => $user->first_name,
		), 'https://siteorigin.com/wp-admin/admin-ajax.php?action=course_signup_form&course=' . urlencode( $course[ 'id' ] ) );

		$course['url'] = $signup_url;

		return $course;
	}
}

SiteOrigin_Panels_Courses::single();
