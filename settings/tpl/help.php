<p>
	<?php
	echo preg_replace(
		'/1\{ *(.*?) *\}/',
		'<a href="https://siteorigin.com/page-builder/settings/" target="_blank" rel="noopener noreferrer">$1</a>',
		__( 'Please read the 1{settings guide} of the Page Builder documentation for help.', 'siteorigin-panels' )
	);
	?>
</p>
