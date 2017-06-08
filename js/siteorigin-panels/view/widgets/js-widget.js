var mediaWidget = require( './media-widget' );
var textWidget = require( './text-widget' );

var jsWidget = {
	MEDIA_AUDIO: 'media_audio',
	MEDIA_IMAGE: 'media_image',
	MEDIA_VIDEO: 'media_video',
	TEXT: 'text',

	addWidget: function( widgetContainer, widgetId ) {
		var idBase = widgetContainer.find( '> .id_base' ).val();
		var widget;

		switch ( idBase ) {
			case this.MEDIA_AUDIO:
			case this.MEDIA_IMAGE:
			case this.MEDIA_VIDEO:
				widget = mediaWidget;
				break;
			case this.TEXT:
				widget = textWidget;
				break
		}

		widget.addWidget( idBase, widgetContainer, widgetId );
	},
};

module.exports = jsWidget;
