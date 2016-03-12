var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
    template: _.template( $('#siteorigin-panels-live-editor').html().panelsProcessTemplate() ),

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
        this.$el.html( this.template() );
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
        this.$el.show();

	    this.originalContainer = this.builder.$el.parent();
	    this.builder.$el.appendTo( this.$('.so-live-editor-builder') );
	    this.builder.trigger('builder_resize');

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

	    // Move the builder back to its original container
	    this.builder.$el.appendTo( this.originalContainer );
	    this.builder.trigger('builder_resize');

        this.displayed = false;

        return false;
    },

    /**
     * Refresh the preview display
     */
    refreshPreview: function(){
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
     * Return true if the live editor has a valid preview URL.
     * @return {boolean}
     */
    hasPreviewUrl: function(){
        return this.$('form.live-editor-form').attr('action') !== '';
    }
} );
