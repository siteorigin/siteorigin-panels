/* global _, jQuery */

jQuery( function ( $ ) {
	// Stretch all the full width rows
	const stretchFullWidthRows = function () {
		let fullContainer = $( panelsStyles.fullContainer );
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

	if ( panelsStyles.stretchRows ) {
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

	// This should have been done in the footer, but run it here just incase.
	$( 'body' ).removeClass( 'siteorigin-panels-before-js' );
} );
