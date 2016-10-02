var liveEditor = {};

window.liveEditor = liveEditor;
liveEditor.iframe = window.frameElement;

// The views
liveEditor.view = {};
liveEditor.view.widget = require( './view/widget' );
liveEditor.view.cell = require( './view/cell' );
liveEditor.view.row = require( './view/row' );
liveEditor.view.layout = require( './view/layout' );

liveEditor.setup = require( './util/setup' );

var iframe = window.frameElement;

if ( iframe ) {
	iframe.contentDocument = document;
	var windowParent = window.parent;

	if( typeof windowParent !== 'undefined' && typeof windowParent.jQuery !== 'undefined' ) {
		windowParent.jQuery( iframe ).trigger( "iframeloading" );
		jQuery( function () {
			windowParent.jQuery( iframe ).trigger( "iframeready" );
		} );
	}
}

jQuery( function( $ ){
} );
