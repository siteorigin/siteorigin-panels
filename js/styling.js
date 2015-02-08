jQuery(function($){

    // This will handle stretching the cells.
    $('.siteorigin-panels-stretch.panel-row-style').each(function(){
        var $$ = $(this);

        var onResize = function(){

            $$.css({
                'margin-left' : 0,
                'margin-right' : 0,
                'padding-left' : 0,
                'padding-right' : 0
            });

            var leftSpace = $$.offset().left;
            var rightSpace = $(window).outerWidth() - $$.offset().left - $$.parent().outerWidth();

            $$.css({
                'margin-left' : -leftSpace,
                'margin-right' : -rightSpace,
                'padding-left' : $$.data('stretch-type') === 'full' ? leftSpace : 0,
                'padding-right' : $$.data('stretch-type') === 'full' ? rightSpace : 0
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

});