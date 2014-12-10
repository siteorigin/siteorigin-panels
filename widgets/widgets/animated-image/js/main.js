jQuery(function($){
    var theInterval = setInterval(function(){
        // Check if any images that weren't visible, now are
        $('.origin-widget-animated-image img').not('.animated').filter(':onScreen').each(function(){
            var $$ = $(this);
            // TODO wait for images
            if(!$$.get(0).complete) return;

            $$.addClass('animated');

            setTimeout(function(){
                var a = $$.data('animation');

                if(a == 'fade') {
                    $$.css('visibility', 'visible');
                    $$.hide().fadeIn(750);
                }
                else {
                    var offset;
                    if(a == 'slide-up') offset = {top : 25, left: 0};
                    else if(a == 'slide-down') offset = {top : -25, left: 0};
                    else if(a == 'slide-left') offset = {top : 0, left: 25};
                    else if(a == 'slide-right') offset = {top : 0, left: -25};

                    var $a = $$.clone().insertAfter($$).css({
                        'visibility' : 'visible',
                        'opacity' : 0,
                        'position' : 'absolute',
                        'top' : $$.position().top + offset.top,
                        'left' : $$.position().left + offset.left,
                        'width' : $$.width(),
                        'height' : $$.height()
                    }).animate({top: $$.position().top, left: $$.position().left, opacity: 1}, 750, function(){$(this).remove(); $$.css('visibility', 'visible');});
                }
            }, 750);
        } );

        if($('.origin-widget-animated-image img').not('.animated').length == 0) {
            clearInterval(theInterval);
        }
    }, 500);
});