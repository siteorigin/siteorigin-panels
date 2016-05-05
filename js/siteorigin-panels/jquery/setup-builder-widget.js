/* global _, jQuery, panels */

var panels = window.panels, $ = jQuery;

module.exports = function ( config ) {

	return this.each( function () {
		var $$ = jQuery( this );
		var widgetId = $$.closest( 'form' ).find( '.widget-id' ).val();

		// Create a config for this specific widget
		var thisConfig = $.extend(true, {}, config);

		// Exit if this isn't a real widget
		if ( ! _.isUndefined( widgetId ) && widgetId.indexOf( '__i__' ) > - 1 ) {
			return;
		}

		// Create the main builder model
		var builderModel = new panels.model.builder();

		// Now for the view to display the builder
		var builderView = new panels.view.builder( {
			model: builderModel,
			config: thisConfig
		} );

		// Save panels data when we close the dialog, if we're in a dialog
		var dialog = $$.closest( '.so-panels-dialog-wrapper' ).data( 'view' );
		if ( ! _.isUndefined( dialog ) ) {
			dialog.on( 'close_dialog', function () {
				builderModel.refreshPanelsData();
			} );

			dialog.on( 'open_dialog_complete', function () {
				// Make sure the new layout widget is always properly setup
				builderView.trigger( 'builder_resize' );
			} );

			dialog.model.on( 'destroy', function () {
				// Destroy the builder
				builderModel.emptyRows().destroy();
			} );

			// Set the parent for all the sub dialogs
			builderView.setDialogParents( panelsOptions.loc.layout_widget, dialog );
		}

		// Basic setup for the builder
		var isWidget = Boolean( $$.closest( '.widget-content' ).length );
		builderView
			.render()
			.attach( {
				container: $$,
				dialog: isWidget || $$.data('mode') === 'dialog',
				type: $$.data( 'type' )
			} )
			.setDataField( $$.find( 'input.panels-data' ) );

		if ( isWidget || $$.data('mode') === 'dialog' ) {
			// Set up the dialog opening
			builderView.setDialogParents( panelsOptions.loc.layout_widget, builderView.dialog );
			$$.find( '.siteorigin-panels-display-builder' ).click( function ( e ) {
				e.preventDefault();
				builderView.dialog.openDialog();
			} );
		} else {
			// Remove the dialog opener button, this is already being displayed in a page builder dialog.
			$$.find( '.siteorigin-panels-display-builder' ).parent().remove();
		}

		// Trigger a global jQuery event after we've setup the builder view
		$( document ).trigger( 'panels_setup', builderView );
	} );
};
