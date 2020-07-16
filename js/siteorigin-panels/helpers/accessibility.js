var $ = jQuery;

module.exports = {
	/**
	 * Trigger click on valid enter key press.
	 */
	triggerClickOnEnter: function( e ) {
		if ( e.which == 13 ) {
			$( e.target ).trigger( 'click' );
		}
	},

};
