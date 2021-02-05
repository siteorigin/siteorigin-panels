( function( $ ) {
	$( document ).on( 'open_dialog', function( e, view ) {
		setTimeout( function() {
			acf.doAction( 'append', $( '.so-content' ) );
		}, 1250 )
	} );
} )( jQuery );
