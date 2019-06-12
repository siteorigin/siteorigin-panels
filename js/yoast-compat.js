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

		$data.find('.so-panel.widget').each(function(i, el) {
			
			var $widget = $(el);
			// Style wrappers prevent us from matching the widget shortcode correctly.
			if ( $widget.find( '> .panel-widget-style' ).length > 0 ) {
				$widget = $widget.find( '> .panel-widget-style' );
			}
			var match = re.exec( $widget.html() );

			try{
				if( ! _.isNull( match ) && $widget.html().replace( re, '' ).trim() === '' ) {
					var classMatch = /class="(.*?)"/.exec(match[3]),
						dataInput = jQuery(match[5]),
						data = JSON.parse(decodeEntities(dataInput.val())),
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

							case 'SiteOrigin_Widgets_ImageGrid_Widget':
							case 'SiteOrigin_Widget_Simple_Masonry_Widget':
								newHTML = $( '<div/>' );
								var imgItems = widgetClass === 'SiteOrigin_Widgets_ImageGrid_Widget' ? widgetInstance.images : widgetInstance.items;
								for ( var i = 0; i < imgItems.length; i++ ) {
									var imgItem = imgItems[ i ];
									var itemHTML = $('<img/>').attr({
										'src': '#' + imgItem.image,
										'srcset': '',
										'alt': ( imgItem.hasOwnProperty( 'alt' ) ? imgItem.alt : imgItem.title ),
										'title': imgItem.title,
									});

									newHTML.append( itemHTML )
								}
								newHTML = newHTML.prop( 'outerHTML' );
								break;

							case 'SiteOrigin_Widget_Accordion_Widget':
							case 'SiteOrigin_Widget_Tabs_Widget':
								var contentItems = widgetClass === 'SiteOrigin_Widget_Accordion_Widget' ? widgetInstance.panels : widgetInstance.tabs;
								newHTML = $( '<div/>' );
								for ( var i = 0; i < contentItems.length; i++ ) {
									var item = contentItems[ i ];
									if ( item.content_type !== 'text' ) {
										continue;
									}
									
									newHTML.append( '<h3>' + item.title + '</h3>' );
									newHTML.append( '<div>' + item.content_text + '</div>')
								}
								newHTML = newHTML.prop( 'outerHTML' );
								break;
							case 'SiteOrigin_Widget_Button_Widget':
								var hrefSeparator = widgetInstance.url.includes('://') ? '' : '#';
								newHTML = $( '<a>' + widgetInstance.text + '</a>' ).attr({
									'href': hrefSeparator + widgetInstance.url,
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
