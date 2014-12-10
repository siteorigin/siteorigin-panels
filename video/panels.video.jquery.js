jQuery(function($){
    
    $(window ).resize(function(){
        $('.jp-jplayer' ).each(function(){
            var $$ = $(this);
            
            if($$.data('player-ready') != undefined){
                // Change the height of the player
                var ratio = Number($$.attr('data-ratio'));
                $$.jPlayer( { size: {height: Math.floor($$.closest('.widget' ).outerWidth() / 1.777)} } );
            }
        });
    })
    
    
    $('.jp-jplayer' ).each(function(){
        var $$ = $(this);

        var ratio = Number($$.attr('data-ratio'));
        
        $$.jPlayer({
            ready: function(){
                $$.data('player-ready', true);
                
                $(this).jPlayer("setMedia", {
                    m4v : $(this).attr('data-video'),
                    poster: $(this).attr('data-poster')
                } );
                
                if($(this ).attr('data-mobile') == 'true'){
                    $(this).find('.jp-gui' ).hide();
                }
                else{
                    $(this ).find('.jp-gui' ).show();
                }
                
                // Check if we're using autoplay
                if(Number($(this).attr('data-autoplay')) == 1){ $$.jPlayer("play"); }
                
                $(this).jPlayer( { size: {height: Math.floor($(this).closest('.widget' ).outerWidth() / ratio)} } );
            },
            solution: "flash, html",
            supplied : "m4v",
            swfPath : $(this).attr('data-swfpath'),
            autohide : {
                restored: false,
                full: false
            },
            play: function(){
                $(this).jPlayer("pauseOthers");
                $(this ).jPlayer('option', 'autohide', {
                    restored: true,
                    full: true,
                    hold: 2000
                });
            },
            size: {
                width: "100%",
                height: Math.floor($$.closest('.widget' ).outerWidth() / ratio)
            },
            cssSelectorAncestor: "#" + $$.closest('.jp-video' ).attr('id')
        });
    });
});