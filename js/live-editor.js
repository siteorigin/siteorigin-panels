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

	$( window ).unload( function() {

	} );

};

},{}],3:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The row view that this widget belongs to
	row: null,

	// widgets: [],

	initialize: function( options ){
		this.setElement( options.$el );

		options.$el.data( 'view', this );

		// Create the rows, cells and widget views
		var cellView = this;

		cellView.$( '> .so-panel' ).each( function( i, el ){
			var $$ = $(el);
			var widgetView = new liveEditor.view.widget( {
				model: cellView.model.widgets.at( i ),
				$el: $$
			} );
			widgetView.cell = cellView;
			// cellView.widgets.push( widgetView );
		} );
	},

	getWidgetsContainer: function() {
		return this.$el;
	},

	/**
	 * Get the widget at a specific index
	 * @param i
	 * @returns {*}
	 */
	widgetAt: function( i ){
		return this.$( '> .so-panel' ).eq( i ).data( 'view' );
	}
} );

},{}],4:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// rows: [],

	initialize: function( options ){
		this.setElement( options.$el );

		options.$el.data( 'view', this );

		// Create the rows, cells and widget views
		var layoutView = this;

		layoutView.$( '> .panel-grid' ).each( function( i, el ){
			var $$ = $(el);
			var rowView = new liveEditor.view.row( {
				model: layoutView.model.rows.at( i ),
				$el: $$
			} );
			rowView.layout = layoutView;
			// layoutView.rows.push( rowView );
		} );
	},

	/**
	 * Get the container
	 * @returns {*}
	 */
	getRowsContainer: function(){
		return $el;
	},

	/**
	 * Get the row view at a specific index.
	 * @param i
	 */
	rowAt: function( i ) {
		return this.$( '> .panel-grid' ).eq( i ).data( 'view' );
	}
} );

},{}],5:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The layout view that this widget belongs to
	layout: null,

	// cells: [],

	initialize: function( options ){
		this.setElement( options.$el );

		options.$el.data( 'view', this );

		// Create the rows, cells and widget views
		var rowView = this;

		rowView.$( '> .panel-row-style > .panel-grid-cell, > .panel-grid-cell' ).each( function( i, el ){
			var $$ = $(el);
			var cellView = new liveEditor.view.cell( {
				model: rowView.model.cells.at( i ),
				$el: $$
			} );
			cellView.row = rowView;
			// rowView.cells.push( cellView );
		} );

		this.listenTo( this.model, 'reweight_cells', this.handleReweightCells );
	},

	/**
	 * Get the container
	 * @returns {*}
	 */
	getCellsContainer: function(){
		return this.$( '> *' ).hasClass( 'panel-row-style' ) ? this.$( '> .panel-row-style' ) : this.$el;
	},

	/**
	 * Reweight the cells based on their new weights
	 */
	handleReweightCells: function(){
		var rowView = this;
		rowView.$( '> .panel-row-style > .panel-grid-cell, > .panel-grid-cell' ).each( function( i, el ){
			var $$ = $(this);
			var cell = rowView.model.cells.at( i );
			$$.css( 'width', ( cell.get('weight') * 100 ) + '%' );
		} );
	},

	/**
	 * Get the row view at a specific index.
	 * @param i
	 */
	cellAt: function( i ) {
		return this.$( '> .panel-row-style > .panel-grid-cell, > .panel-grid-cell' ).eq( i ).data( 'view' );
	}
} );

},{}],6:[function(require,module,exports){
var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The cell view that this widget belongs to
	cell: null,

	initialize: function( options ){
		this.setElement( options.$el );
		options.$el.data( 'view', this );

		this.listenTo( this.model, 'move', this.reposition );
		this.listenTo( this.model, 'change:values', this.changeValues );
		this.listenTo( this.model, 'change:style', this.changeStyle );
	},

	reposition: function(){
		// We need to move this view

		var rowIndex = this.model.cell.row.builder.rows.indexOf( this.model.cell.row ),
			cellIndex = this.model.cell.row.cells.indexOf( this.model.cell ),
			widgetIndex = this.model.cell.widgets.indexOf( this.model );

		var rowView = this.cell.row.layout.rowAt( rowIndex ),
			cellView = rowView.cellAt( cellIndex ),
			widgetsContainer = cellView.getWidgetsContainer();

		if( widgetsContainer.length ) {
			this.$el.detach();

			if( widgetIndex === 0 ) {
				// This is the first element
				widgetsContainer.prepend( this.$el );
			}
			else {
				// This needs to go in place of another widget
				var replaceWidget = cellView.widgetAt( widgetIndex - 1 );
				if( replaceWidget.cid !== this.cid ) {
					replaceWidget.$el.after( this.$el );
				}

			}
		}
	},

	getWidgetContainer: function(){
		return this.$('> .panel-widget-style').length ? this.$('> .panel-widget-style') : this.$el;
	},

	changeValues: function(){
	},

	changeStyle: function(){
		console.log('change style');
	}
} );

},{}]},{},[1]);
