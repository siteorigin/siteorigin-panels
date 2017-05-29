var $ = jQuery;

var textWidget = {
	addWidget: function( idBase, widgetContainer, widgetId ) {
		var component = wp.textWidgets;

		var widgetControl = new component.TextWidgetControl({
			el: widgetContainer
		});

		widgetControl.initializeEditor();

		return widgetControl;
	}
};

module.exports = textWidget;
