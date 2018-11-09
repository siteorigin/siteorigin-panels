var $ = jQuery;

var textWidget = {
	addWidget: function( idBase, widgetContainer, widgetId ) {
		var component = wp.textWidgets;

		var options = {};
		var visualField = widgetContainer.find( '.visual' );
		// 'visual' field and syncContainer were introduced together in 4.8.1
		if ( visualField.length > 0 ) {
			// If 'visual' field has no value it's a legacy text widget.
			if ( ! visualField.val() ) {
				return null;
			}

			var fieldContainer = $( '<div></div>' );
			var syncContainer = widgetContainer.find( '.widget-content:first' );
			syncContainer.before( fieldContainer );

			options = {
				el: fieldContainer,
				syncContainer: syncContainer,
			};
		} else {
			options = { el: widgetContainer };
		}

		var widgetControl = new component.TextWidgetControl( options );
		var wpEditor = wp.oldEditor ? wp.oldEditor : wp.editor;
		if ( wpEditor && wpEditor.hasOwnProperty( 'autop' ) ) {
			wp.editor.autop = wpEditor.autop;
			wp.editor.removep = wpEditor.removep;
			wp.editor.initialize = wpEditor.initialize
		}

		widgetControl.initializeEditor();

		return widgetControl;
	}
};

module.exports = textWidget;
