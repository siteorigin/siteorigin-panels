var $ = jQuery;

var mediaWidget = {
	addWidget: function( idBase, widgetContainer, widgetId ) {
		var component = wp.mediaWidgets;

		var ControlConstructor = component.controlConstructors[ idBase ];
		if ( ! ControlConstructor ) {
			return;
		}

		var ModelConstructor = component.modelConstructors[ idBase ] || component.MediaWidgetModel;
		var widgetContent = widgetContainer.find( '> .widget-content' );
		var controlContainer = $( '<div class="media-widget-control"></div>' );
		widgetContent.before( controlContainer );

		var modelAttributes = {};
		widgetContent.find( '.media-widget-instance-property' ).each( function() {
			var input = $( this );
			modelAttributes[ input.data( 'property' ) ] = input.val();
		});
		modelAttributes.widget_id = widgetId;

		var widgetModel = new ModelConstructor( modelAttributes );

		var widgetControl = new ControlConstructor({
			el: controlContainer,
			model: widgetModel
		});

		widgetControl.render();

		return widgetControl;
	}
};

module.exports = mediaWidget;
