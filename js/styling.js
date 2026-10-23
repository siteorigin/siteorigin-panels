/* global _, jQuery */

jQuery( function ( $ ) {
	// A caching plugin that delays inline scripts can run this file before
	// panelsStyles exists. Read the settings when they are needed, and fall
	// back to the plugin defaults so the rows still stretch.
	const settings = function () {
		if ( typeof panelsStyles !== 'undefined' && panelsStyles ) {
			return panelsStyles;
		}

		return {
			fullContainer: 'body',
			stretchRows: ! $( 'body' ).hasClass( 'siteorigin-panels-css-container' ),
		};
	};

	// Stretch all the full width rows
	const stretchFullWidthRows = function () {
		let fullContainer = $( settings().fullContainer );
		if ( fullContainer.length === 0 ) {
			fullContainer = $( 'body' );
		}

		const $panelsRow = $( '.siteorigin-panels-stretch.panel-row-style' );
		// Are there any rows to stretch?
		if ( ! $panelsRow.length ) {
			return;
		}

		$panelsRow.each( function () {
			const $$ = $( this );
			const stretchType = $$.data( 'stretch-type' );

			// Reset all the styles associated with row stretching
			$$.css( {
				'margin-left': 0,
				'margin-right': 0,
			} );

			const leftSpace = $$.offset().left - fullContainer.offset().left;
			const rightSpace = fullContainer.outerWidth() - leftSpace - $$.parent().outerWidth();

			$$.css( {
				'margin-left': - leftSpace + 'px',
				'margin-right': - rightSpace + 'px',
			} );

			// If Row Layout is Full Width, apply content container.
			if ( stretchType === 'full' ) {
				$$.css( {
					'padding-left': leftSpace + 'px',
					'padding-right': rightSpace + 'px'
				} );
			}
		} );

		$( window ).trigger( 'panelsStretchRows' );
	}

	// Clear the fallback before measuring row offsets. Keep this in the same
	// callback as stretching so the un-stretched rows cannot paint in between.
	$( 'body' ).removeClass( 'siteorigin-panels-before-js' );

	if ( settings().stretchRows ) {
		$( window ).on( 'resize load', stretchFullWidthRows ).trigger( 'resize' );
	}

	if (
		typeof parallaxStyles !== 'undefined' &&
		typeof simpleParallax !== 'undefined'
	) {
		const { 'disable-parallax-mobile': disableParallaxMobile, 'mobile-breakpoint': mobileBreakpoint, delay, scale } = parallaxStyles;

		if (
			! disableParallaxMobile ||
			! window.matchMedia( `(max-width: ${ mobileBreakpoint })` ).matches
		) {
			new simpleParallax( document.querySelectorAll( '[data-siteorigin-parallax], .sow-slider-image-parallax .sow-slider-background-image' ), {
				delay,
				scale: scale < 1.1 ? 1.1 : scale,
			} );
		}
	}

} );
