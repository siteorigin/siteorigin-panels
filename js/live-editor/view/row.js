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
