jQuery( function($){

    // Handle the animations for the Page Builder logo
    $.each([1,2,3], function(i, v){
        var $$ = $('.settings-banner img.layer-' + v);
        var opacity = $$.css('opacity');

        setTimeout( function(){
            $$.show()
                .css({'margin-top' : -5, 'opacity': 0})
                .animate({'margin-top' : 0, 'opacity': opacity}, 280 + 40*(4 - v) );
        }, 150 + 225 * (4 - v) );
    });

    // Settings page tabbing

    $('.settings-nav li a').click(function(e){
        e.preventDefault();
        var $$ = $(this);
        $('.settings-nav li a').not($$).closest('li').removeClass('active');
        $$.closest('li').addClass('active');

        var tabClicked = $$.attr('href').split('#')[1];
        var $s = $('#panels-settings-section-' + tabClicked);

        $('#panels-settings-sections .panels-settings-section').not($s).hide();
        $s.show();
        setUserSetting('siteorigin_panels_setting_tab', tabClicked);
    });

    var tabClicked = getUserSetting('siteorigin_panels_setting_tab');

    if(tabClicked === '') {
        $('.settings-nav li a').first().click();
    }
    else {
        $('.settings-nav li a[href="#' + tabClicked + '"]').first().click();
    }

} );