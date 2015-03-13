<?php

/**
 * If we're in debug mode, display the panels data.
 */
function siteorigin_panels_dump(){
	echo "<!--\n\n";
	echo "// Page Builder Data\n\n";

	if( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING ) == 'so_panels_home_page' ) {
		var_export( get_option( 'siteorigin_panels_home_page', null ) );
	}
	else{
		global $post;
		var_export( get_post_meta($post->ID, 'panels_data', true));
	}
	echo "\n\n-->";
}
add_action('siteorigin_panels_metabox_end', 'siteorigin_panels_dump');