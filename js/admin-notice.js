jQuery( function( $ ) {
	$( '.siteorigin-notice-dismiss' ).on( 'click', function( e ) {
		e.preventDefault();
		$.get( $( this ).data( 'url' ) );

		$( '#siteorigin-panels-use-classic-notice' ).slideUp( function(){
			$( this ).remove();
		} );
	} );
} );
