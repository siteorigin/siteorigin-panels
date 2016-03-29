var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {
	dialogClass: 'so-panels-dialog-add-builder',

	render: function () {
		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-builder' ).html(), {} ) );
		this.$( '.so-content .siteorigin-panels-builder' ).append( this.builder.$el );
	},

	initializeDialog: function () {
		var thisView = this;
		this.once( 'open_dialog_complete', function () {
			thisView.builder.initSortable();
		} );

		this.on( 'open_dialog_complete', function () {
			thisView.builder.trigger( 'builder_resize' );
		} );
	}
} );
