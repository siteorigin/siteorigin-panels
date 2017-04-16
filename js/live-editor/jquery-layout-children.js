( function( $ ){
	$.fn.findLayoutChildren = function( q ) {
		var $$ = $( this );
		return $$.find( q ).filter( function(){
			return $( this ).closest( '.panel-layout' ).is( $$ );
		} );
	};
} )( jQuery );