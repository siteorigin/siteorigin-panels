var customHtmlWidget = require( './custom-html-widget' );
var mediaWidget = require( './media-widget' );
var textWidget = require( './text-widget' );

var jsWidget = {
	CUSTOM_HTML: 'custom_html',
	MEDIA_AUDIO: 'media_audio',
	MEDIA_GALLERY: 'media_gallery',
	MEDIA_IMAGE: 'media_image',
	MEDIA_VIDEO: 'media_video',
	TEXT: 'text',

	addWidget: function( widgetContainer, widgetId ) {
		var idBase = widgetContainer.find( '> .id_base' ).val();
		var widget;

		switch ( idBase ) {
			case this.CUSTOM_HTML:
				widget = customHtmlWidget;
				break;
			case this.MEDIA_AUDIO:
			case this.MEDIA_GALLERY:
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
