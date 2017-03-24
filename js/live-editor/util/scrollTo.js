/**
 * Scroll this window over a specific element. Called by the main live editor.
 * @param el
 */
module.exports = function( el ){
	var $ = jQuery,
		$el = $( el ),
		rect = $el[0].getBoundingClientRect();

	if( rect.top <= 0 || rect.bottom >= $(window).height() ) {
		var newScrollTop = 0;

		if( rect.top < 0 || $el.height() >= $( window ).height() * 0.8 ) {
			// Scroll up to the element
			newScrollTop = $( window ).scrollTop() + rect.top - 150;
		} else if( rect.bottom > $(window).height() ) {
			// Scroll down to the element
			newScrollTop = $( window ).scrollTop() + ( rect.bottom -  $(window).height() ) + 150;
		}

		$( window )
			.clearQueue()
			.animate({
				scrollTop: newScrollTop
			}, 450 );
	}
};
