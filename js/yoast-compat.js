/* global jQuery, YoastSEO */

jQuery(function($){
	var SiteOriginYoastCompat = function() {
		YoastSEO.app.registerPlugin( 'siteOriginYoastCompat', { status: 'ready' } );
		YoastSEO.app.registerModification( 'content', this.contentModification, 'siteOriginYoastCompat', 5 );
	}

	SiteOriginYoastCompat.prototype.contentModification = function(data) {
		// Remove siteorigin_widget shortcodes because they conflict with Yoast scoring
		data = data.replace(/\[siteorigin_widget.*?].*?\[\/siteorigin_widget\]/g);

		// Clean out any Page Builder wrapper tags in case there are more conflicts there.
		var $data = $(data);
		if( $data.find('.panel-grid') ) {
			// Remove any unimportant tags from Page Builder content
			$data.find('.panel-grid, .panel-grid-cell, .so-panel.widget').contents().unwrap();
		}
		$data.find('input[type="hidden"]').remove();
		return $data.html();
	};

	new SiteOriginYoastCompat();
});