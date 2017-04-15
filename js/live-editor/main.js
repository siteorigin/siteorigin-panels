var iframe = window.frameElement,
	md5 = require( 'md5' );

liveEditor.scrollTo = require( './scrollTo' );
liveEditor.processor = require('./processor');

liveEditor.processor.liveEditor = liveEditor;

if ( iframe ) {
	iframe.contentDocument = document;
	var windowParent = window.parent;

	// This is all for the loading time prediction
	if( typeof windowParent !== 'undefined' && typeof windowParent.jQuery !== 'undefined' ) {
		windowParent.jQuery( iframe ).trigger( "iframeloading" );
		jQuery( function () {
			windowParent.jQuery( iframe ).trigger( "iframeready" );
		} );

		liveEditor.refresh = function(){
			windowParent.jQuery( iframe ).trigger( "live-editor-refresh" );
		};
	}
}

jQuery( function( $ ){


	var $layout, $rows, $cells, $widgets;

	$layout = $( '#pl-' + liveEditor.postId );
	liveEditor.processor.$layout = $layout;

	var elementFilter = function(){
		return $( this ).closest( '.panel-layout' ).is( $layout );
	};

	var setupElements = function( panelsData ){
		$rows = $layout.find( '.panel-grid' ).filter( elementFilter );
		$cells = $layout.find( '.panel-grid-cell' ).filter( elementFilter );
		$widgets = $layout.find( '.so-panel' ).filter( elementFilter );

		// Lets add some data to each of the elements
		$widgets.each( function( i, el ){
			var info = panelsData.widgets[ i ].panels_info,
				widget = JSON.parse( JSON.stringify( panelsData.widgets[ i ] ) );
			delete widget.panels_info;

			$( el )
				.data( 'style', md5( JSON.stringify( info.style ) ) )
				.data( 'widget',md5( JSON.stringify( widget ) ) );
		} );
	};
	setupElements( liveEditor.panelsData );

	window.handlePanelsDataChange = function( panelsData ) {
		var currentPanelsData = liveEditor.panelsData;

		// Run through all the steps to try match the page to the new panelsData
		if( ! liveEditor.processor.runSteps( currentPanelsData, panelsData ) ) {
			windowParent.jQuery( iframe ).trigger( 'live-editor-refresh' );
		}

		// var differences = diff( currentPanelsData.widgets[0], panelsData.widgets[0] );
		// console.log( differences );

		// Call this if we can't figure out how to refresh the page.
		// liveEditor.refresh();

		// After we've
		liveEditor.panelsData = panelsData;
	}
} );