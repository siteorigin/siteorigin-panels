var iframe = window.frameElement;

if ( iframe ) {
	iframe.contentDocument = document;

	var parent = window.parent;
	jQuery( parent.document ).ready( function () {//wait for parent to make sure it has jQuery ready
		var parentjQuery = parent.jQuery;

		parentjQuery( iframe ).trigger( "iframeloading" );

		jQuery( function () {
			parentjQuery( iframe ).trigger( "iframeready" );
		} );

	} );
}

/**
 * Scroll this window over a specific element. Called by the main live editor.
 * @param el
 */
function liveEditorScrollTo( el ){
	var $ = jQuery,
		$el = $( el ),
		rect = $el[0].getBoundingClientRect();

	if( rect.top >= 0 &&
	    rect.left >= 0 &&
	    rect.bottom <= $(window).height() &&
	    rect.right <= $(window).width()
	) {
		// This is already in the viewport, don't need to do anything
		return;
	} else {

		var newScrollTop = 0;

		if( rect.top < 0 ) {
			// Scroll up to the element
			newScrollTop = $( window ).scrollTop() + rect.top - 150;
		} else if( rect.bottom > $(window).height() ) {
			// Scroll down to the element
			newScrollTop = $( window ).scrollTop() + ( rect.bottom -  $(window).height() ) + 150;
		}

		$( window )
			.clearQueue()
			.animate({
				scrollTop: newScrollTop
			}, 450 );
	}
};
