var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The row view that this widget belongs to
	row: null,

	widgets: [],

	initialize: function( options ){

		this.setElement( options.$el );

		// Create the rows, cells and widget views
		var cellView = this;

		cellView.$( '> .so-panel' ).each( function( i, el ){
			var $$ = $(el);
			var widgetView = new liveEditor.view.widget( {
				model: cellView.model.widgets.at( i ),
				$el: $$
			} );
			widgetView.cell = cellView;
			cellView.widgets.push( widgetView );
		} );
	}
} );
