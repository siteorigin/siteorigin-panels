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
