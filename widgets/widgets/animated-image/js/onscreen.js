// onScreen jQuery plugin v0.2.1
// (c) 2011 Ben Pickles
//
// http://benpickles.github.com/onScreen
//
// Released under MIT license.
;(function($) {
    $.expr[":"].onScreen = function(elem) {
        // The viewport position
        var $window = $(window);
        var viewport_top = $window.scrollTop();
        var viewport_height = $window.height();
        var viewport_bottom = viewport_top + viewport_height;

        // Element position
        var $elem = $(elem);
        var top = $elem.offset().top;
        var height = $elem.height();
        var bottom = top + height;

        return (top >= viewport_top && bottom + 30 < viewport_bottom);

    }
})(jQuery);