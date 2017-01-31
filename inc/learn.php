<?php

class SiteOrigin_Panels_Learn {

	static function display_learn_button(){
		return siteorigin_panels_setting( 'display-learn' ) &&
		       apply_filters( 'siteorigin_panels_learn', true );
	}

	/**
	 * Get the learning URL
	 *
	 * @param bool $lesson
	 * @param bool $category
	 *
	 * @return string
	 */
	static function get_url( $lesson = false, $category = false ){
		$learn_url = 'https://siteorigin.com/wp-admin/admin-ajax.php?action=lesson_explore';

		$user = wp_get_current_user();
		if( $user ) {
			$learn_url = add_query_arg( array(
				'email' => $user->user_email,
				'name' => $user->first_name,
			), $learn_url );
		}

		if( $lesson ) {
			$learn_url .= '#lesson-' . urlencode( $lesson );
		}
		elseif ( $category ) {
			$learn_url .= '#category-' . urlencode( $category );
		}

		return $learn_url;
	}

}
