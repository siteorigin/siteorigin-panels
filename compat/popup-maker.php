<?php
// Popup Maker doesn't directly trigger the_content.
add_filter( 'pum_popup_content', array( SiteOrigin_Panels::single(), 'generate_post_content' ), 10, 1 );