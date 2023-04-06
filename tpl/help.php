<p>
	<?php _e( 'You can use SiteOrigin Page Builder to create home and sub pages, filled your own widgets.', 'siteorigin-panels' ); ?>
	<?php _e( 'The page layouts are responsive and fully customizable.', 'siteorigin-panels' ); ?>
</p>
<p>
	<?php
	preg_replace(
		array(
			'/1\{ *(.*?) *\}/',
			'/2\{ *(.*?) *\}/',
			'/3\{ *(.*?) *\}/',
		),
		array(
			'<a href="http://siteorigin.com/page-builder/documentation/" target="_blank" rel="noopener noreferrer">$1</a>',
			'<a href="http://siteorigin.com/threads/plugin-page-builder/" target="_blank" rel="noopener noreferrer">$1</a>',
			'<a href="http://siteorigin.com/#newsletter" target="_blank" rel="noopener noreferrer">$1</a>',
		),
		__( 'Read the 1{full documentation} on SiteOrigin. Ask a question on our 2{support forum} if you need help and sign up to 3{our newsletter} to stay up to date with future developments.', 'siteorigin-panels' )
	);
	?>
</p>
