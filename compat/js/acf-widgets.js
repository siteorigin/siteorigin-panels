( function( $ ) {
	$( document ).on( 'panelsopen', function() {
		setTimeout( function() {
			acf.doAction( 'append', $( '.so-content' ) );
		}, 1250 )
	} );
} )( jQuery );
