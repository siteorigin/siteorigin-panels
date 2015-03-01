jQuery( function($){

    // Handle the animations for the Page Builder logo
    // Only run after the image is loaded
    $(".settings-banner img")
        .hide()
        .eq(0)
        .one("load", function() {
            $.each([1,2,3], function(i, v){
                var $$ = $('.settings-banner img.layer-' + v);
                var opacity = $$.css('opacity');

                setTimeout( function(){
                    $$.show()
                        .css({'margin-top' : -5, 'opacity': 0})
                        .animate({'margin-top' : 0, 'opacity': opacity}, 280 + 40*(4 - v) );
                }, 150 + 225 * (4 - v) );
            });
        })
        .each(function() { if(this.complete) { $(this).load(); } });

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

    // Save the tab the user last clicked

    var tabClicked = getUserSetting('siteorigin_panels_setting_tab');
    if(tabClicked === '') { $('.settings-nav li a').first().click(); }
    else { $('.settings-nav li a[href="#' + tabClicked + '"]').first().click(); }

    // Search settings

    var highlightSetting = function($s){

        // Click on the correct container
        $('.settings-nav li a[href="#' + $s.closest('.panels-settings-section').data('section') + '"]').first().click();

        $s.addClass('highlighted');

        $s
            .find('label')
            .css('border-left-width', 0)
            .animate({ 'border-left-width': 5 }, 'normal')
            .delay(4000)
            .animate({ 'border-left-width': 0 }, 'normal', function(){
                $s.removeClass('highlighted');
            });

        $s.find('input,textarea').focus();
    };

    var doSettingsSearch = function(){
        var $$ = $(this),
            $r = $('#panels-settings-search .results'),
            query = $$.val();

        if( query === '' ) {
            $r.empty().hide();
            return false;
        }

        // Search all the settings
        var settings = [];
        $('#panels-settings-sections .panels-setting').each(function(){
            var $s = $(this);
            var isMatch = 0;

            var indexes = {
                'title' : $s.find('label').html().toLowerCase().indexOf( query ),
                'keywords' : $s.find('.description').data('keywords').toLowerCase().indexOf( query ),
                'description' : $s.find('.description').html().toLowerCase().indexOf( query )
            };

            if( indexes.title === 0 ) isMatch += 10;
            else if( indexes.title !== -1 ) isMatch += 7;

            if( indexes.keywords === 0 ) isMatch += 4;
            else if( indexes.keywords !== -1 ) isMatch += 3;

            if( indexes.description === 0 ) isMatch += 2;
            else if( indexes.description !== -1 ) isMatch += 1;


            if( isMatch > 0 ) {
                settings.push($s);
                $s.data('isMatch', isMatch);
            }
        });

        $r.empty();

        if( settings.length > 0 ) {
            $r.show();
            settings.sort( function(a,b){
                return b.data('isMatch') - a.data('isMatch');
            } );

            settings = settings.slice(0, 8);

            $.each(settings, function(i, el){
                $('#panels-settings-search .results').append(
                    $('<li></li>')
                        .html( el.find('label').html() )
                        .click(function(){
                            highlightSetting( el );
                            $r.fadeOut('fast');
                            $('#panels-settings-search input').blur();
                        })
                );
            });
        }
        else {
            $r.hide();
        }
    };

    $('#panels-settings-search input')
        .keyup(doSettingsSearch)
        .click(doSettingsSearch)
        .blur( function(){
            $('#panels-settings-search .results').fadeOut('fast');
        } );

} );