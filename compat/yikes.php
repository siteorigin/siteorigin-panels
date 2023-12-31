<?php
// Yikes Product Tabs applies the content filter for tab contents.
// This causes Page Builder to re-render the current page.
add_filter( 'yikes_woo_use_the_content_filter', '__return_false', 11 );
