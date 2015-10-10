
/* global jQuery */

jQuery(function($){

    var fullContainer = $( panelsStyles.fullContainer );
    if( fullContainer.length === 0 ) {
        fullContainer = $('body');
    }

    // This will handle stretching the cells.
    $('.siteorigin-panels-stretch.panel-row-style').each(function(){
        var $$ = $(this);

        var onResize = function(){

            $$.css({
                'margin-left' : 0,
                'margin-right' : 0,
                'padding-left' : 0,
                'padding-right' : 0,
                'visibility' : 'visible'
            });

            var leftSpace = $$.offset().left - fullContainer.offset().left;
            var rightSpace = fullContainer.outerWidth() - leftSpace - $$.parent().outerWidth();

            $$.css({
                'margin-left' : -leftSpace,
                'margin-right' : -rightSpace,
                'padding-left' : $$.data('stretch-type') === 'full' ? leftSpace : 0,
                'padding-right' : $$.data('stretch-type') === 'full' ? rightSpace : 0,
                'visibility' : 'visible'
            });

            var cells = $$.find('> .panel-grid-cell');

            if( $$.data('stretch-type') === 'full-stretched' && cells.length === 1 ) {
                cells.css({
                    'padding-left' : 0,
                    'padding-right' : 0
                });
            }
        };

        $(window).resize( onResize );
        onResize();

        $$.css({
            'border-left' : 0,
            'border-right' : 0
        });
    });

    if( $('.siteorigin-panels-stretch.panel-row-style').length ) {
        // This is to allow everything to reset after styling has changed
        $(window).resize();
    }
});
