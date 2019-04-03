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

	    $('#panels-settings-page input[type="submit"]').css({
            'visibility' : tabClicked === 'welcome' ? 'hidden' : 'visible'
	    });

        setUserSetting('siteorigin_panels_setting_tab', tabClicked);
    });

	if( window.location.hash ) {
		$('.settings-nav li a[href="' + window.location.hash + '"]').click();
	}

    $('#panels-settings-section-welcome').fitVids();

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

// Fitvids
;(function( $ ){

	'use strict';

	$.fn.fitVids = function( options ) {
		var settings = {
			customSelector: null,
			ignore: null
		};

		if(!document.getElementById('fit-vids-style')) {
			// appendStyles: https://github.com/toddmotto/fluidvids/blob/master/dist/fluidvids.js
			var head = document.head || document.getElementsByTagName('head')[0];
			var css = '.fluid-width-video-wrapper{width:100%;position:relative;padding:0;}.fluid-width-video-wrapper iframe,.fluid-width-video-wrapper object,.fluid-width-video-wrapper embed {position:absolute;top:0;left:0;width:100%;height:100%;}';
			var div = document.createElement("div");
			div.innerHTML = '<p>x</p><style id="fit-vids-style">' + css + '</style>';
			head.appendChild(div.childNodes[1]);
		}

		if ( options ) {
			$.extend( settings, options );
		}

		return this.each(function(){
			var selectors = [
				'iframe[src*="player.vimeo.com"]',
				'iframe[src*="youtube.com"]',
				'iframe[src*="youtube-nocookie.com"]',
				'iframe[src*="kickstarter.com"][src*="video.html"]',
				'object',
				'embed'
			];

			if (settings.customSelector) {
				selectors.push(settings.customSelector);
			}

			var ignoreList = '.fitvidsignore';

			if(settings.ignore) {
				ignoreList = ignoreList + ', ' + settings.ignore;
			}

			var $allVideos = $(this).find(selectors.join(','));
			$allVideos = $allVideos.not('object object'); // SwfObj conflict patch
			$allVideos = $allVideos.not(ignoreList); // Disable FitVids on this video.

			$allVideos.each(function(){
				var $this = $(this);
				if($this.parents(ignoreList).length > 0) {
					return; // Disable FitVids on this video.
				}
				if (this.tagName.toLowerCase() === 'embed' && $this.parent('object').length || $this.parent('.fluid-width-video-wrapper').length) { return; }
				if ((!$this.css('height') && !$this.css('width')) && (isNaN($this.attr('height')) || isNaN($this.attr('width'))))
				{
					$this.attr('height', 9);
					$this.attr('width', 16);
				}
				var height = ( this.tagName.toLowerCase() === 'object' || ($this.attr('height') && !isNaN(parseInt($this.attr('height'), 10))) ) ? parseInt($this.attr('height'), 10) : $this.height(),
					width = !isNaN(parseInt($this.attr('width'), 10)) ? parseInt($this.attr('width'), 10) : $this.width(),
					aspectRatio = height / width;
				if(!$this.attr('name')){
					var videoName = 'fitvid' + $.fn.fitVids._count;
					$this.attr('name', videoName);
					$.fn.fitVids._count++;
				}
				$this.wrap('<div class="fluid-width-video-wrapper"></div>').parent('.fluid-width-video-wrapper').css('padding-top', (aspectRatio * 100)+'%');
				$this.removeAttr('height').removeAttr('width');
			});
		});
	};

	// Internal counter for unique video names.
	$.fn.fitVids._count = 0;

// Works with either jQuery or Zepto
})( window.jQuery || window.Zepto );