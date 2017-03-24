module.exports = {
	/**
	 * Lock window scrolling for the main overlay
	 */
	lock: function () {
		if ( jQuery( 'body' ).css( 'overflow' ) === 'hidden' ) {
			return;
		}

		// lock scroll position, but retain settings for later
		var scrollPosition = [
			self.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft,
			self.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop
		];

		jQuery( 'body' )
			.data( {
				'scroll-position': scrollPosition
			} )
			.css( 'overflow', 'hidden' );

		if( ! _.isUndefined( scrollPosition ) ) {
			window.scrollTo( scrollPosition[0], scrollPosition[1] );
		}
	},

	/**
	 * Unlock window scrolling
	 */
	unlock: function () {
		if ( jQuery( 'body' ).css( 'overflow' ) !== 'hidden' ) {
			return;
		}

		// Check that there are no more dialogs or a live editor
		if ( ! jQuery( '.so-panels-dialog-wrapper' ).is( ':visible' ) && ! jQuery( '.so-panels-live-editor' ).is( ':visible' ) ) {
			jQuery( 'body' ).css( 'overflow', 'visible' );
			var scrollPosition = jQuery( 'body' ).data( 'scroll-position' );

			if( ! _.isUndefined( scrollPosition ) ) {
				window.scrollTo( scrollPosition[0], scrollPosition[1] );
			}
		}
	},
};
