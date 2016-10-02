var liveEditor = window.liveEditor, $ = jQuery;

/**
 * Setup the Live Editor with a builder model. This should be called from the main builder interface.
 *
 * @param builder
 */
module.exports = function( postId, builder ){

	// Create the main layout view
	var layout = new liveEditor.view.layout( {
		model: builder,
		$el: $( '#pl-' + postId )
	} );

	$( window ).unload( function() {

	} );

};
