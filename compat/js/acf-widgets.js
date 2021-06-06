( function( $ ) {
	$( document ).on( 'panelsopen', function(e) {
		setTimeout( function() {
			acf.doAction( 'append', $( '.so-content' ) );
		}, 1250 )
	} );
} )( jQuery );
