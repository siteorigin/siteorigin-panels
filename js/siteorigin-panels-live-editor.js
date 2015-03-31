/**
 * Handles the live editor interface in Page Builder
 *
 * @copyright Greg Priday 2014 - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

/* global Backbone, _, jQuery, tinyMCE, soPanelsOptions, confirm */

( function( $, _, panelsOptions ){

    var panels = window.siteoriginPanels;

    /**
     * Live editor handles
     */
    panels.view.liveEditor = Backbone.View.extend( {
        template: _.template( $('#siteorigin-panels-live-editor').html().panelsProcessTemplate() ),

        sectionTemplate: _.template( $('#siteorigin-panels-live-editor-sidebar-section').html().panelsProcessTemplate() ),

        postId: false,
        bodyScrollTop : null,
        displayed: false,

        events: {
            'click .live-editor-close': 'close'
        },
        frameScrollTop: 0,

        initialize: function(){
        },

        /**
         * Render the live editor
         */
        render: function(){
            this.setElement( this.template() );
            this.$el.html( this.template() );

            var thisView = this;

            // Prevent clicks inside the iframe
            this.$('iframe#siteorigin-panels-live-editor-iframe')
                .load(function(){
                    $(this).show();

                    var ifc = $(this).contents();

                    // Lets find all the first level grids. This is to account for the Page Builder layout widget.
                    ifc.find('.panel-grid .panel-grid-cell .so-panel.widget')
                        .filter(function(){
                            // Filter to only include non nested
                            return $(this).parents('.widget_siteorigin-panels-builder').length == 0;
                        })
                        .each(function(i, el){
                            var $$ = $(el);
                            var widgetEdit = thisView.$('.page-widgets .so-widget').eq(i);
                            var overlay;

                            $$
                                .css({
                                    'cursor' : 'pointer'
                                })
                                .mouseenter(function(){
                                    widgetEdit.addClass('so-hovered');
                                    overlay = thisView.createPreviewOverlay( $(this) );
                                })
                                .mouseleave( function(){
                                    widgetEdit.removeClass('so-hovered');
                                    overlay.fadeOut('fast', function(){ $(this).remove(); });
                                } )
                                .click(function(e){
                                    e.preventDefault();
                                    // When we click a widget, send that click to the form
                                    widgetEdit.click();
                                });
                        });

                    // Prevent default clicks
                    ifc.find( "a").css({'pointer-events' : 'none'}).click(function(e){
                        return false;
                    });

                });
        },

        /**
         * Attach the live editor to the document
         */
        attach: function(){
            this.$el.appendTo('body');
        },

        setPostId: function(postId){
            this.postId = postId;
        },

        /**
         * Display the live editor
         */
        open: function(){
            if( this.$el.html() === '' ) {
                this.render();
            }
            if( this.$el.closest('body').length === 0 ) {
                this.attach();
            }

            // Refresh the preview display
            this.refreshWidgets();
            this.$el.show();

            // Refresh the preview after we show the editor
            this.refreshPreview();

            // Disable page scrolling
            this.bodyScrollTop = $('body').scrollTop();
            $('body').css( {overflow:'hidden'} );

            this.displayed = true;
        },

        close: function(){
            this.$el.hide();
            $('body').css( {overflow:'auto'} );
            $('body').scrollTop( this.bodyScrollTop );

            this.displayed = false;

            return false;
        },

        /**
         * Refresh the preview display
         */
        refreshPreview: function(){
            if( !this.$el.is(':visible') ) {
                return false;
            }

            this.$('iframe#siteorigin-panels-live-editor-iframe').hide();

            this.frameScrollTop = this.$('iframe#siteorigin-panels-live-editor-iframe').contents().find('body').scrollTop();

            this.$('form.live-editor-form input[name="siteorigin_panels_data"]').val( JSON.stringify( this.builder.model.getPanelsData() ) );
            this.$('form.live-editor-form').submit();
        },

        /**
         * Create an overlay in the preview.
         *
         * @param over
         * @return {*|Object} The item we're hovering over.
         */
        createPreviewOverlay: function(over) {
            var previewFrame = this.$('iframe#siteorigin-panels-live-editor-iframe');

            // Remove any old overlays
            var body = previewFrame.contents().find('body').css('position', 'relative');

            previewFrame.contents().find('.panels-live-editor-overlay').remove();

            // Create the new overlay
            var overlayContainer = $('<div />').addClass('panels-live-editor-overlay').css( {
                'pointer-events' : 'none'
            } );

            // The overlay item used to highlight the current element
            var overlay = $('<div />').css({
                'position' : 'absolute',
                'background' : '#000000',
                'z-index' : 10000,
                'opacity' : 0.25
            });

            var spacing = 15;

            overlayContainer
                .append(
                    // The top overlay
                    overlay.clone().css({
                        'top' : -body.offset().top,
                        'left' : 0,
                        'right' : 0,
                        'height' : over.offset().top - spacing
                    })
                )
                .append(
                    // The bottom overlay
                    overlay.clone().css({
                        'bottom' : 0,
                        'left' : 0,
                        'right' : 0,
                        'height' : Math.round( body.height() - over.offset().top -  over.outerHeight() - spacing + body.offset().top - 0.01 )
                    })
                )
                .append(
                    // The left overlay
                    overlay.clone().css({
                        'top' : over.offset().top - spacing - body.offset().top,
                        'left' : 0,
                        'width' : over.offset().left - spacing,
                        'height' : Math.ceil(over.outerHeight() + spacing*2)
                    })
                )
                .append(
                    // The right overlay
                    overlay.clone().css({
                        'top' : over.offset().top - spacing - body.offset().top,
                        'right' : 0,
                        'left' : over.offset().left + over.outerWidth() + spacing,
                        'height' : Math.ceil(over.outerHeight() + spacing*2)
                    })
                );

            // Create a new overlay
            previewFrame.contents().find('body').append(overlayContainer);
            return overlayContainer;
        },

        /**
         * Refresh the widgets in the left sidebar.
         */
        refreshWidgets: function(){
            // Empty all the current widgets
            this.$('.so-sidebar .page-widgets').empty();
            var previewFrame = this.$('iframe#siteorigin-panels-live-editor-iframe');

            // Now lets move all the widgets to the sidebar
            var thisView = this;
            var widgetIndex = 0;

            this.builder.$('.so-row-container').each(function(ri, el) {
                var row = $(el);
                var widgets = row.find('.so-cells .cell .so-widget');

                var sectionWrapper = $( thisView.sectionTemplate({ title: 'Row ' + (ri+1) }) )
                    .appendTo( thisView.$('.so-sidebar .page-widgets') );

                sectionWrapper.find('.section-header').click(function(){
                    row.data('view').editSettingsHandler();
                });

                var widgetsWrapper = sectionWrapper.find('.section-widgets');

                widgets.each(function(i, el){
                    var widget = $(this);
                    var widgetClone = widget.clone().show().css({
                        opacity : 1
                    });

                    // Remove all the action buttons from the clone
                    widgetClone.find('.actions').remove();
                    widgetClone.find('.widget-icon').remove();

                    var thisWidgetIndex = (widgetIndex++);
                    var getHoverWidget = function(){
                        // TODO this should target the #pl-x selector
                        return previewFrame.contents()
                            .find('#pl-' + thisView.postId + ' .panel-grid .panel-grid-cell .widget')
                            .filter(function(){
                                // Filter to only include non nested
                                return $(this).parents('.widget_siteorigin-panels-builder').length === 0;
                            })
                            .not('panel-hover-widget')
                            .eq(thisWidgetIndex);
                    };

                    var overlay = null, hoverWidget = null;

                    widgetClone
                        .click(function(e){
                            e.preventDefault();
                            widget.data('view').editHandler();
                            return false;
                        })
                        .mouseenter(function(){
                            var hoverWidget = getHoverWidget();

                            // Center the iframe on the over item
                            if(hoverWidget && hoverWidget.offset()) {
                                previewFrame.contents()
                                    .find('html,body')
                                    .clearQueue()
                                    .animate( {
                                        scrollTop: hoverWidget.offset().top - Math.max(30, ( Math.min( previewFrame.contents().height(), previewFrame.height() ) - hoverWidget.outerHeight() ) /2 )
                                    }, 750);

                                // Create the overlay
                                overlay = thisView.createPreviewOverlay( hoverWidget );
                            }

                        })
                        .mouseleave(function(){
                            // Stop any scroll animations that are currently happening
                            previewFrame.contents()
                                .find('html,body')
                                .clearQueue();

                            if(overlay !== null) {
                                overlay.fadeOut('fast', function(){
                                    $(this).remove();
                                });
                                overlay = null;
                            }
                            if(hoverWidget !== null) {
                                hoverWidget.remove();
                                hoverWidget = null;
                            }
                        })
                        .appendTo( widgetsWrapper );
                });
            });
        },

        /**
         * Return true if the live editor has a valid preview URL.
         * @return {boolean}
         */
        hasPreviewUrl: function(){
            return this.$('form.live-editor-form').attr('action') !== '';
        }
    } );

} )( jQuery, _, soPanelsOptions );