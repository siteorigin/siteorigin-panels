var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The row view that this widget belongs to
	row: null,

	widgets: [],

	initialize: function( options ){
		this.setElement( options.$el );
		this.$el.data( 'view', this );

		// Create the rows, cells and widget views
		var cellView = this;

		cellView.$( '> .so-panel' ).each( function( i, el ){
			var $$ = $(el);
			var widgetView = new liveEditor.view.widget( {
				model: cellView.model.get('widgets').at( i ),
				$el: $$
			} );
			widgetView.cell = cellView;
			cellView.widgets.push( widgetView );
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
