var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The cell view that this widget belongs to
	cell: null,

	initialize: function( options ){
		this.setElement( options.$el );
	}
} );
