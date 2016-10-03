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
