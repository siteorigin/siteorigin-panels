var $ = jQuery;

var customHtmlWidget = {
	addWidget: function( idBase, widgetContainer, widgetId ) {
		var component = wp.customHtmlWidgets;
		
		var fieldContainer = $( '<div></div>' );
		var syncContainer = widgetContainer.find( '.widget-content:first' );
		syncContainer.before( fieldContainer );

		var widgetControl = new component.CustomHtmlWidgetControl( {
			el: fieldContainer,
			syncContainer: syncContainer,
		} );

		widgetControl.initializeEditor();
		
		// HACK: To ensure CodeMirror resize for the gutter.
		widgetControl.editor.codemirror.refresh();
		
		return widgetControl;
	}
};

module.exports = customHtmlWidget;
