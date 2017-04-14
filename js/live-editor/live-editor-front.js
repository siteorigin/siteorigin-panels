var iframe = window.frameElement;

if ( iframe ) {
	iframe.contentDocument = document;
	var windowParent = window.parent;
	
	if( typeof windowParent !== 'undefined' && typeof windowParent.jQuery !== 'undefined' ) {
		windowParent.jQuery( iframe ).trigger( "iframeloading" );
		jQuery( function () {
			windowParent.jQuery( iframe ).trigger( "iframeready" );
		} );

		window.liveEditorRefresh = function(){
			windowParent.jQuery( iframe ).trigger( "live-editor-refresh" );
		};
	}
}

/**
 * Scroll this window over a specific element. Called by the main live editor.
 * @param el
 */
function liveEditorScrollTo( el ){
	var $ = jQuery,
		$el = $( el ),
		rect = $el[0].getBoundingClientRect();

	if( rect.top <= 0 || rect.bottom >= $(window).height() ) {
		var newScrollTop = 0;

		if( rect.top < 0 || $el.height() >= $( window ).height() * 0.8 ) {
			// Scroll up to the element
			newScrollTop = $( window ).scrollTop() + rect.top - 150;
		} else if( rect.bottom > $(window).height() ) {
			// Scroll down to the element
			newScrollTop = $( window ).scrollTop() + ( rect.bottom -  $(window).height() ) + 150;
		}

		$( window )
			.clearQueue()
			.animate( {
				scrollTop: newScrollTop
			}, 450 );
	}
};

jQuery( function( $ ){

	window.handlePanelsDataChange = function( panelsData ) {
		var currentPanelsData = liveEditor.panelsData;

		// Call this if we can't figure out how to refresh the page.
		window.liveEditorRefresh();

		// After we've
		liveEditor.panelsData = panelsData;
	}
} );