var $ = jQuery;

module.exports = {
	/**
	 * Trigger click on valid enter key press.
	 */
	triggerClickOnEnter: function( e, refocus = false ) {
		if ( e.which == 13 ) {
			$( e.target ).trigger( 'click' );
			if ( refocus ) {
				setTimeout( function() {
					$( e.target ).trigger( 'focus' );
				}, 100 );
			}
		}
	},

};
