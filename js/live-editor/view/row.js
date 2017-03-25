var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The layout view that this widget belongs to
	layout: null,

	cells: [],

	initialize: function( options ){
		this.setElement( options.$el );
		this.$el.data( 'view', this );

		// Create the rows, cells and widget views
		var rowView = this;

		rowView.$( '> .panel-row-style > .panel-grid-cell, > .panel-grid-cell' ).each( function( i, el ){
			var $$ = $(el);
			var cellView = new liveEditor.view.cell( {
				model: rowView.model.get('cells').at( i ),
				$el: $$
			} );
			cellView.row = rowView;
			rowView.cells.push( cellView );
		} );

		this.listenTo( this.model, 'move', this.handleReposition );
		this.listenTo( this.model, 'reweight_cells', this.handleReweightCells );
		this.listenTo( this.model, 'change:style', this.handleChangeStyle );

		// For adding and removing cells
		this.listenTo( this.model.get('cells'), 'add', this.handleCellAddRemove );
		this.listenTo( this.model.get('cells'), 'remove', this.handleCellAddRemove );
	},

	/**
	 * Get the container
	 * @returns {*}
	 */
	getCellsContainer: function(){
		return this.$( '> *' ).hasClass( 'panel-row-style' ) ? this.$( '> .panel-row-style' ) : this.$el;
	},

	handleReposition: function(){
		var rowIndex = this.model.builder.get('rows').indexOf( this.model ),
			rowContainer = this.layout.getRowsContainer();

		if( rowContainer.length ) {
			this.$el.detach();

			if( rowIndex === 0 ) {
				// This is the first element
				rowContainer.prepend( this.$el );
			}
			else {
				// This needs to go in place of another widget
				var replaceRow = this.layout.rowAt( rowIndex - 1 );
				if( replaceRow.cid !== this.cid ) {
					replaceRow.$el.after( this.$el );
				}

			}
		}
	},

	/**
	 * Reweight the cells based on their new weights
	 */
	handleReweightCells: function(){
		var rowView = this;
		rowView.$( '> .panel-row-style > .panel-grid-cell, > .panel-grid-cell' ).each( function( i, el ){
			var $$ = $(this);
			var cell = rowView.model.get('cells').at( i );
			if( cell !== undefined ) {
				$$.css( 'width', ( cell.get('weight') * 100 ) + '%' );
			}
		} );
	},

	handleChangeStyle: function(){
		this.layout.liveEditor.refreshPreview();
	},

	handleCellAddRemove: function(){
		var thisView = this;
		setTimeout( function(){
			thisView.layout.liveEditor.refreshPreview();
		}, 50 );
	},

	/**
	 * Get the row view at a specific index.
	 * @param i
	 */
	cellAt: function( i ) {
		return this.$( '> .panel-row-style > .panel-grid-cell, > .panel-grid-cell' ).eq( i ).data( 'view' );
	}
} );
