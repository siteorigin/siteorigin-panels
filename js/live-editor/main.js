var iframe = window.frameElement,
	windowParent = window.parent;

if(
	typeof iframe === 'undefined' ||
	typeof windowParent === 'undefined' ||
	typeof windowParent.jQuery === 'undefined'
) {
	throw "Live editor must be run in an iframe";
}

iframe.contentDocument = document;

var md5 = require( 'md5' );
require( './jquery-layout-children' );

// The variable setup
liveEditor.scrollTo = require( './scrollTo' );
liveEditor.processor = require('./processor');
liveEditor.processor.liveEditor = liveEditor;

// This is all for the loading time prediction
windowParent.jQuery( iframe ).trigger( "iframeloading" );
jQuery( function () {
	windowParent.jQuery( iframe ).trigger( "iframeready" );
} );
liveEditor.refresh = function(){
	windowParent.jQuery( iframe ).trigger( "live-editor-refresh" );
};

var triggerLiveEditorEvent = function( e ){
	windowParent.jQuery( iframe ).trigger( 'live-editor-event', e );
};

jQuery( function( $ ){

	var $layout = $( '#pl-' + liveEditor.postId );
	liveEditor.processor.$layout = $layout;

	// Setup all the events that 
	$layout
		.on( 'click', '.so-panel', function(){
			var $widgets = $layout.findLayoutChildren( '.so-panel' );
			if( $widgets.index( $( this ) ) === -1 ) return;

			triggerLiveEditorEvent( {
				type: 'widget-click',
				target: $( this ),
				index: $widgets.index( $( this ) ),
			} );
		} );

	window.handlePanelsDataChange = function( panelsData ) {
		var currentPanelsData = liveEditor.panelsData;

		// Run through all the steps to try match the page to the new panelsData
		if( ! liveEditor.processor.runSteps( currentPanelsData, panelsData ) ) {
			windowParent.jQuery( iframe ).trigger( 'live-editor-refresh' );
		}

		// After we've processed everything, store the new panelsData
		liveEditor.panelsData = panelsData;
	}
} );