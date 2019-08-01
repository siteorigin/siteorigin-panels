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
			data = window.soPanelsBuilderView.contentPreview;
		}

		return data;
	};

	new SiteOriginYoastCompat();
});
