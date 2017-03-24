(function e(t,n,r){function s(o,u){if(!n[o]){if(!t[o]){var a=typeof require=="function"&&require;if(!u&&a)return a(o,!0);if(i)return i(o,!0);var f=new Error("Cannot find module '"+o+"'");throw f.code="MODULE_NOT_FOUND",f}var l=n[o]={exports:{}};t[o][0].call(l.exports,function(e){var n=t[o][1][e];return s(n?n:e)},l,l.exports,e,t,n,r)}return n[o].exports}var i=typeof require=="function"&&require;for(var o=0;o<r.length;o++)s(r[o]);return s})({1:[function(require,module,exports){
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

},{"./util/setup":2,"./view/cell":3,"./view/layout":4,"./view/row":5,"./view/widget":6}],2:[function(require,module,exports){
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

};

},{}],3:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The row view that this widget belongs to
	row: null,

	widget: {}
} );

},{}],4:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {

	rows: {},

	initialize: function( options ){
		console.log( options );
	},

	attach: function( $el ){

	}

} );

},{}],5:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The builder view that this widget belongs to
	builder: null,

	cells: {},
} );

},{}],6:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The cell view that this widget belongs to
	cell: null,

	initialize: function(){

	}
} );

},{}]},{},[1]);
