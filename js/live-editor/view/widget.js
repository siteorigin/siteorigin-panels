var liveEditor = window.liveEditor, $ = jQuery;

module.exports = Backbone.View.extend( {
	// The cell view that this widget belongs to
	cell: null,

	initialize: function( options ){
		this.setElement( options.$el );

		this.listenTo( this.model, 'move_to_cell', this.reposition );
		this.listenTo( this.model, 'change:values', this.changeValues );
	},

	reposition: function(){
		// We need to move this view
	},

	changeValues: function(){
	}
} );
