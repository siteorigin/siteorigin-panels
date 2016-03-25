var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

    builder: null,
    sidebarWidgetTemplate: _.template( $('#siteorigin-panels-dialog-widget-sidebar-widget').html().panelsProcessTemplate() ),
    dialogClass : 'so-panels-dialog-edit-widget',
    widgetView : false,
    savingWidget: false,

    events: {
        'click .so-close': 'saveHistory',
        'click .so-nav.so-previous': 'navToPrevious',
        'click .so-nav.so-next': 'navToNext',

        // Action handlers
        'click .so-toolbar .so-delete': 'deleteHandler',
        'click .so-toolbar .so-duplicate': 'duplicateHandler'
    },

    initializeDialog: function(){
        this.model.on( 'change:values', this.handleChangeValues, this );
        this.model.on( 'destroy', this.remove, this );
    },

    /**
     * Render the widget dialog.
     */
    render: function() {
        // Render the dialog and attach it to the builder interface
        this.renderDialog( this.parseDialogContent( $('#siteorigin-panels-dialog-widget').html(), {} ) );
        this.loadForm();

        if( typeof panelsOptions.widgets[ this.model.get('class') ] !== 'undefined') {
            this.$('.so-title .widget-name').html( panelsOptions.widgets[ this.model.get('class')].title );
        }
        else {
            this.$('.so-title .widget-name').html( panelsOptions.loc.missing_widget.title );
        }

        // Now we need to attach the style window
        this.styles = new panels.view.styles();
        this.styles.model = this.model;
        this.styles.render( 'widget', $('#post_ID').val(), {
            builderType : this.builder.builderType
        } );

		var $rightSidebar = this.$('.so-sidebar.so-right-sidebar');
        this.styles.attach( $rightSidebar );

        // Handle the loading class
        this.styles.on('styles_loaded', function(hasStyles){
			// If we have styles remove the loading spinner, else remove the whole empty sidebar.
			if(hasStyles) {
				$rightSidebar.removeClass('so-panels-loading');
			} else {
				$rightSidebar.closest('.so-panels-dialog').removeClass('so-panels-dialog-has-right-sidebar');
				$rightSidebar.remove();
			}
        }, this);
		$rightSidebar.addClass('so-panels-loading');
    },

    /**
     * Get the previous widget editing dialog by looking at the dom.
     * @returns {*}
     */
    getPrevDialog: function(){
        var widgets = this.builder.$('.so-cells .cell .so-widget');
        if(widgets.length <= 1) {
            return false;
        }
        var currentIndex = widgets.index( this.widgetView.$el );

        if( currentIndex === 0 ) {
            return false;
        }
        else {
            var widgetView = widgets.eq(currentIndex - 1).data('view');
            if(typeof widgetView === 'undefined') {
                return false;
            }

            return widgetView.getEditDialog();
        }
    },

    /**
     * Get the next widget editing dialog by looking at the dom.
     * @returns {*}
     */
    getNextDialog: function(){
        var widgets = this.builder.$('.so-cells .cell .so-widget');
        if(widgets.length <= 1) {
            return false;
        }
        var currentIndex = widgets.index( this.widgetView.$el );

        if( currentIndex === widgets.length - 1 ) {
            return false;
        }
        else {
            var widgetView = widgets.eq(currentIndex + 1).data('view');
            if(typeof widgetView === 'undefined') {
                return false;
            }

            return widgetView.getEditDialog();
        }
    },

    /**
     * Load the widget form from the server.
     * This is called when rendering the dialog for the first time.
     */
    loadForm: function(){
        // don't load the form if this dialog hasn't been rendered yet
        if( !this.$('> *').length ) {
            return;
        }

        var thisView = this;
        this.$('.so-content').addClass('so-panels-loading');

        var data = {
            'action' : 'so_panels_widget_form',
            'widget' : this.model.get('class'),
            'instance' : JSON.stringify( this.model.get('values') ),
            'raw' : this.model.get('raw')
        };

        $.post(
            panelsOptions.ajaxurl,
            data,
            function(result){
                // Add in the CID of the widget model
                var html = result.replace( /{\$id}/g, thisView.model.cid );

                // Load this content into the form
                thisView.$('.so-content')
                    .removeClass('so-panels-loading')
                    .html(html);

                // Trigger all the necessary events
                thisView.trigger('form_loaded', thisView);

                // For legacy compatibility, trigger a panelsopen event
                thisView.$('.panel-dialog').trigger('panelsopen');

                // If the main dialog is closed from this point on, save the widget content
                thisView.on('close_dialog', thisView.saveWidget, thisView);
            },
            'html'
        );
    },

    /**
     * Save the widget from the form to the model
     */
    saveWidget: function(){
        // Get the values from the form and assign the new values to the model
        this.savingWidget = true;

        if( !this.model.get('missing') ) {
            // Only get the values for non missing widgets.
            var values = this.getFormValues();
            if ( typeof values.widgets === 'undefined' ) {
                values = {};
            }
            else {
                values = values.widgets;
                values = values[ Object.keys(values)[0] ];
            }

            this.model.setValues(values);
            this.model.set('raw', true); // We've saved from the widget form, so this is now raw
        }

        if( this.styles.stylesLoaded ) {
            // If the styles view has loaded
            var style = {};
            try {
                style = this.getFormValues('.so-sidebar .so-visual-styles').style;
            }
            catch (e) {
            }
            this.model.set('style', style);
        }

        this.savingWidget = false;
    },

    /**
     *
     */
    handleChangeValues: function(){
        if( !this.savingWidget ) {
            // Reload the form when we've changed the model and we're not currently saving from the form
            this.loadForm();
        }
    },

    /**
     * Save a history entry for this widget. Called when the dialog is closed.
     */
    saveHistory: function(){
        this.builder.addHistoryEntry('widget_edited');
        this.closeDialog();
    },

    /**
     * When the user clicks delete.
     *
     * @returns {boolean}
     */
    deleteHandler: function(){

        if(this.builder.liveEditor.displayed) {
            // We need to instantly destroy the widget
            this.model.destroy();
            this.builder.liveEditor.refreshWidgets();
        }
        else {
            this.model.trigger('visual_destroy');
        }

        this.closeDialog();

        return false;
    },

    duplicateHandler: function(){
        this.model.trigger('user_duplicate');

        if(this.builder.liveEditor.displayed) {
            this.builder.liveEditor.refreshWidgets();
        }

        this.closeDialog();

        return false;
    }

} );
