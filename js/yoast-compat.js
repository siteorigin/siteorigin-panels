/* global jQuery, YoastSEO */

jQuery(function($){

	if( typeof YoastSEO.app === 'undefined' ){
		// Skip all this if we don't have the yoast app
		return;
	}

	var decodeEntities = (function() {
		// this prevents any overhead from creating the object each time
		var element = document.createElement('div');

		function decodeHTMLEntities (str) {
			if(str && typeof str === 'string') {
				// strip script/html tags
				str = str.replace(/<script[^>]*>([\S\s]*?)<\/script>/gmi, '');
				str = str.replace(/<\/?\w(?:[^"'>]|"[^"]*"|'[^']*')*>/gmi, '');
				element.innerHTML = str;
				str = element.textContent;
				element.textContent = '';
			}

			return str;
		}

		return decodeHTMLEntities;
	})();

	var SiteOriginYoastCompat = function() {
		YoastSEO.app.registerPlugin( 'siteOriginYoastCompat', { status: 'ready' } );
		YoastSEO.app.registerModification( 'content', this.contentModification, 'siteOriginYoastCompat', 5 );
	};

	SiteOriginYoastCompat.prototype.contentModification = function(data) {
		var re = new RegExp( panelsOptions.siteoriginWidgetRegex , "i" );
		var $data = $( '<div>' + data + '</div>' );

		if( $data.find('.so-panel.widget').length === 0 ) {
			// Skip this for non Page builder pages
			return data;
		}

		$data.find('.so-panel.widget').each(function(i, el){
			var $widget = $(el),
				match = re.exec( $widget.html() );

			try{
				if( ! _.isNull( match ) && $widget.html().replace( re, '' ).trim() === '' ) {
					var classMatch = /class="(.*?)"/.exec( match[3] ),
						dataInput = jQuery( match[5] ),
						data = JSON.parse( decodeEntities( dataInput.val( ) ) ),
						widgetInstance = data.instance,
						newHTML = '';

					if( ! _.isNull(widgetInstance.title) ) {
						newHTML += '<h3>' + widgetInstance.title + '</h3>';
					}

					if( ! _.isNull( classMatch ) ) {
						var widgetClass = classMatch[1];
						switch( widgetClass ) {
							case 'SiteOrigin_Widget_Image_Widget':
								// We want a direct assignment for the SO Image Widget to get rid of the title
								newHTML = $('<img/>').attr({
									'src': '#' + widgetInstance.image,
									'srcset': '',
									'alt': widgetInstance.alt,
									'title': widgetInstance.title,
								}).prop('outerHTML');
								break;

							case 'WP_Widget_Media_Image':
								newHTML = $('<img/>').attr({
									'src': '#' + widgetInstance.attachment_id,
									'srcset': '',
									'alt': widgetInstance.alt,
									'title': widgetInstance.image_title,
								}).prop('outerHTML');
								break;
						}
					}

					$widget.html(newHTML);
				}
			}
			catch(e) {
				// If there was an error, just clear the widget content.
				$widget.html('');
			}

		});
		return $data.html();
	};

	new SiteOriginYoastCompat();
});