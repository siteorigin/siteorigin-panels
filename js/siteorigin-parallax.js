/**
 * @copyright Greg Priday - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

( function( $ ){

	$.fn.siteOriginParallax = function( options ){

		var $$ = $(this);

		if( options === 'refreshParallax' ) {
			return $$.trigger( 'refreshParallax' );
		}

		options = $.extend( {
				// We need to know the background image URL to use
				backgroundUrl: null,
				// And the exact size of that image
				backgroundSize: null,
				// We can work out the aspect ratio from the size
				backgroundAspectRatio: null,
				// How we want to handle sizing of the background image
				backgroundSizing: 'scaled'
			}, options );

		if( options.backgroundAspectRatio === null ) {
			options.backgroundAspectRatio = options.backgroundSize[0] / options.backgroundSize[1];
		}

		var setupParallax = function( ){
			var wrapperSize = [
				$$.outerWidth(),
				$$.outerHeight()
			];
			var bounding = $$[0].getBoundingClientRect();

			if( $$.data('siteorigin-parallax-init') === undefined ) {
				// Do the initial setup
				$$.css( {
					'background-image' : 'url(' + options.backgroundUrl + ')'
				} );
			}

			// What percent is this through a screen cycle
			var position = ( bounding.bottom + ( bounding.top - $(window ).outerHeight() ) ) / ( $(window ).outerHeight() + bounding.height );
			var percent = ( position - 1 ) / - 2;
			var topPosition = 0;

			// Do the setup for every time something changes
			if( options.backgroundSizing === 'scaled' ) {
				$$.css( 'background-size', 'cover' );

				var scaleX = wrapperSize[0] / options.backgroundSize[0];
				var scaleY = wrapperSize[1] / options.backgroundSize[1];

				if( scaleY < scaleX ) {
					// Work out the top position
					if( bounding.top > - wrapperSize[1] && bounding.bottom - $(window ).outerHeight() < wrapperSize[1] ) {
						// This is the scaled background height
						var backgroundHeight = options.backgroundSize[1] * scaleX;
						topPosition = - ( backgroundHeight - wrapperSize[1] ) * percent;
					}

					$$.css( 'background-position', '0px ' + topPosition + 'px' );

				} else {
					$$.css( 'background-position', '50% 50%' );
				}
			} else if( options.backgroundSizing === 'original' ) {
				topPosition = - ( options.backgroundSize[1] - wrapperSize[1] ) * percent;

				// This is a version with no scaling
				$$.css( 'background-size', 'auto' );
				$$.css( 'background-position', '50% ' + topPosition + 'px' );
			}

		};
		setupParallax();

		// All the events where we'll need to change the parallax
		$( window ).on( 'scroll', setupParallax );
		$( window ).on( 'resize', setupParallax );
		$( window ).on( 'panelsStretchRows', setupParallax );
		$$.on( 'refreshParallax', setupParallax );
	};

}( jQuery ) );


jQuery( function( $ ){
	$('[data-siteorigin-parallax]' ).each( function(){
		$( this ).siteOriginParallax( $( this ).data('siteorigin-parallax') );
	} );
} );
