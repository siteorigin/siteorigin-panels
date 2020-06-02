/* global jQuery, YoastSEO, _, panelsOptions */

jQuery(function($){

	if( typeof YoastSEO.app === 'undefined' ){
		// Skip all this if we don't have the yoast app
		return;
	}

	var SiteOriginYoastCompat = function() {
		YoastSEO.app.registerPlugin( 'siteOriginYoastCompat', { status: 'ready' } );
		YoastSEO.app.registerModification( 'content', this.contentModification, 'siteOriginYoastCompat', 5 );
	};

	SiteOriginYoastCompat.prototype.contentModification = function(data) {
		if(
			typeof window.soPanelsBuilderView !== 'undefined' &&
			window.soPanelsBuilderView.contentPreview
		) {
			var $data = $( window.soPanelsBuilderView.contentPreview );

			if( $data.find('.so-panel.widget').length === 0 ) {
				// Skip this for empty pages
				return data;
			}

			// Remove style tags created by Widgets Bundle
			$data.find('style').remove();

			var whitelist = [
				'p', 'a', 'img', 'caption', 'br',
				'blockquote', 'cite',
				'em', 'strong', 'i', 'b',
				'q',
				'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
				'ul', 'ol', 'li',
				'table', 'tr', 'th', 'td'
			].join(',');

			$data.find("*").not(whitelist).each(function() {
				var content = $(this).contents();
				$(this).replaceWith(content);
			});

			data = $data.html();
		}

		return data;
	};

	new SiteOriginYoastCompat();
});
