var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	rows: [],

	initialize: function( options ){
		this.setElement( options.$el );

		// Create the rows, cells and widget views
		var layoutView = this;

		layoutView.$( '> .panel-grid' ).each( function( i, el ){
			var $$ = $(el);
			var rowView = new liveEditor.view.row( {
				model: layoutView.model.rows.at( i ),
				$el: $$
			} );
			rowView.layout = layoutView;
			layoutView.rows.push( rowView );
		} );
	},

	attach: function( $el ){

	}

} );
