var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
    template: _.template( $('#siteorigin-panels-live-editor').html().panelsProcessTemplate() ),

    postId: false,
	previewScrollTop: 0,
	loadTimes: [ ],

    events: {
        'click .live-editor-close': 'close',
        'click .live-editor-collapse': 'collapse'
    },

    initialize: function( options ){
	    this.builder = options.builder;
	    this.builder.model.on( 'change', this.refreshPreview, this );
    },

    /**
     * Render the live editor
     */
    render: function(){
        this.setElement( this.template() );
	    this.$el.hide();
	    var thisView = this;

	    this.$( '.so-preview iframe' )
		    .on( 'load', function(){
			    var $$ = $(this ),
				    $iframeContents = $$.contents();

			    if( $$.data('load-start') !== undefined ) {
				    thisView.loadTimes.unshift( new Date().getTime() - $$.data('load-start') );

				    if ( thisView.loadTimes.length ) {
					    thisView.loadTimes = thisView.loadTimes.slice( 0, 4 );
				    }
			    }

			    // Scroll to the correct position
			    $iframeContents.scrollTop( thisView.previewScrollTop );
			    thisView.$('.so-preview-overlay' ).hide();

			    // Lets find all the first level grids. This is to account for the Page Builder layout widget.
			    $iframeContents.find('.panel-grid .panel-grid-cell .so-panel')
				    .filter(function(){
					    // Filter to only include non nested
					    return $(this).parents('.widget_siteorigin-panels-builder').length === 0;
				    })
				    .each(function(i, el){
					    var $$ = $(el);
					    var widgetEdit = thisView.$('.so-live-editor-builder .so-widget-wrapper').eq(i);
					    var overlay = false;

					    widgetEdit.data( 'live-editor-preview-widget', $$ );

					    $$
						    .css({
							    'cursor' : 'pointer'
						    })
						    .mouseenter(function(){
							    widgetEdit.parent().addClass('so-hovered');
							    overlay = thisView.createPreviewOverlay( $(this) );
						    })
						    .mouseleave( function(){
							    widgetEdit.parent().removeClass('so-hovered');

							    if( overlay !== false ) {
								    overlay.fadeOut( 'fast', function () {
									    $( this ).remove();
									    overlay = false;
								    } );
							    }

						    } )
						    .click(function(e){
							    e.preventDefault();
							    // When we click a widget, send that click to the form
							    widgetEdit.find('.title h4').click();
						    });
				    });

			    // Prevent default clicks
			    $iframeContents.find( "a").css({'pointer-events' : 'none'}).click(function(e){
				    e.preventDefault();
			    });

		    } );

	    // Handle highlighting the relevant widget in the live editor preview

	    var previewOverlay = false;
	    thisView.$el.on( 'mouseenter', '.so-widget-wrapper', function(){
		    var $$ = $(this ), previewWidget = $(this ).data( 'live-editor-preview-widget' );


			if( previewWidget !== undefined && previewWidget.length && !thisView.$('.so-preview-overlay' ).is(':visible') ) {
				previewOverlay = thisView.createPreviewOverlay( previewWidget );
			}
	    } );

	    thisView.$el.on( 'mouseleave', '.so-widget-wrapper', function(){
		    if( previewOverlay !== false ) {
			    previewOverlay.fadeOut( 'fast', function () {
				    $( this ).remove();
				    overlay = false;
			    } );
		    }
	    } );

	    thisView.builder.on('open_dialog', function(){
		    if( previewOverlay !== false ) {
			    previewOverlay.fadeOut( 'fast', function () {
				    $( this ).remove();
				    previewOverlay = false;
			    } );
		    }
	    });

	    return this;
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

	    // Disable page scrolling
	    this.builder.lockPageScroll();

	    if( this.$el.is(':visible') ) {
		    return this;
	    }

        // Refresh the preview display
        this.$el.show();
	    this.refreshPreview();

	    this.originalContainer = this.builder.$el.parent();
	    this.builder.$el.appendTo( this.$('.so-live-editor-builder') );
	    this.builder.$('.so-tool-button.so-live-editor' ).hide();
	    this.builder.trigger('builder_resize');
    },

	/**
	 * Close the live editor
	 */
    close: function(){
	    if( !this.$el.is(':visible') ) {
		    return this;
	    }

	    this.$el.hide();
		this.builder.unlockPageScroll();

	    // Move the builder back to its original container
	    this.builder.$el.appendTo( this.originalContainer );
	    this.builder.$('.so-tool-button.so-live-editor' ).show();
	    this.builder.trigger('builder_resize');
    },

	collapse: function(){
		this.$el.toggleClass('so-collapsed');

		var text = this.$('.live-editor-collapse span');
		text.html( text.data( this.$el.hasClass('so-collapsed') ?  'expand' : 'collapse' ) );
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
	 * Refresh the Live Editor preview.
	 * @returns {exports}
	 */
	refreshPreview: function( ){
		if( !this.$el.is(':visible') ) {
			return this;
		}

		var iframe = this.$('.so-preview iframe' ),
			form = this.$('.so-preview form' );

		if( !this.$('.so-preview-overlay' ).is(':visible') ) {
			this.previewScrollTop = iframe.contents().scrollTop();
		}

		var loadTimePrediction = this.loadTimes.length ?
			_.reduce( this.loadTimes, function( memo, num ){
				return memo + num
			}, 0 ) / this.loadTimes.length : 1000;

		this.$('.so-preview-overlay' ).show();

		// Add a loading bar
		this.$('.so-preview-overlay .so-loading-bar')
			.clearQueue()
			.css( 'width', '0%' )
			.animate( { width: '100%' }, parseInt (loadTimePrediction)  );

		// Set the preview data and submit the form
		form.find('input[name="live_editor_panels_data"]' ).val( JSON.stringify( this.builder.model.getPanelsData() ) );
		form.submit()

		iframe.data( 'load-start', new Date().getTime() );
	},

    /**
     * Return true if the live editor has a valid preview URL.
     * @return {boolean}
     */
    hasPreviewUrl: function(){
        return this.$('form.live-editor-form').attr('action') !== '';
    }
} );
