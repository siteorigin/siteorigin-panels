var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend({
    template: _.template( $('#siteorigin-panels-builder-widget').html().panelsProcessTemplate() ),

    // The cell view that this widget belongs to
    cell: null,

    // The edit dialog
    dialog: null,

    events: {
        'click .widget-edit' : 'editHandler',
        'click .title h4' : 'editHandler',
        'click .actions .widget-duplicate' : 'duplicateHandler',
        'click .actions .widget-delete' : 'deleteHandler'
    },

    /**
     * Initialize the widget
     */
    initialize: function(){
        // The 2 user actions on the model that this view will handle.
        this.model.on('user_edit', this.editHandler, this);                 // When a user wants to edit the widget model
        this.model.on('user_duplicate', this.duplicateHandler, this);       // When a user wants to duplicate the widget model
        this.model.on('destroy', this.onModelDestroy, this);
        this.model.on('visual_destroy', this.visualDestroyModel, this);

        this.model.on('change:values', this.onModelChange, this);
    },

    /**
     * Render the widget
     */
    render: function(options){
        options = _.extend({'loadForm': false}, options);

        this.setElement( this.template( {
            title : this.model.getWidgetField('title'),
            description : this.model.getTitle()
        } ) );

        this.$el.data( 'view', this );

        if( _.size( this.model.get('values') ) === 0 ||  options.loadForm) {
            // If this widget doesn't have a value, create a form and save it
            var dialog = this.getEditDialog();

            // Save the widget as soon as the form is loaded
            dialog.once('form_loaded', dialog.saveWidget, dialog);

            // Setup the dialog to load the form
            dialog.setupDialog();
        }

	    return this;
    },

    /**
     * Display an animation that implies creation using a visual animation
     */
    visualCreate: function(){
        this.$el.hide().fadeIn( 'fast' );
    },

    /**
     * Get the dialog view of the form that edits this widget
     *
     * @returns {null}
     */
    getEditDialog: function(){
        if(this.dialog === null){
            this.dialog = new panels.dialog.widget({
                model: this.model
            });
            this.dialog.setBuilder(this.cell.row.builder);

            // Store the widget view
            this.dialog.widgetView = this;
        }
        return this.dialog;
    },

    /**
     * Handle clicking on edit widget.
     *
     * @returns {boolean}
     */
    editHandler: function(){
        // Create a new dialog for editing this
        this.getEditDialog().openDialog();
        return false;
    },

    /**
     * Handle clicking on duplicate.
     *
     * @returns {boolean}
     */
    duplicateHandler: function(){
        // Add the history entry
        this.cell.row.builder.addHistoryEntry('widget_duplicated');

        // Create the new widget and connect it to the widget collection for the current row
        var newWidget = this.model.clone( this.model.cell );

        this.cell.model.widgets.add(newWidget, {
            // Add this after the existing model
            at: this.model.collection.indexOf( this.model ) + 1
        });

        return false;
    },

    /**
     * Handle clicking on delete.
     *
     * @returns {boolean}
     */
    deleteHandler: function(){
        this.model.trigger('visual_destroy');
        return false;
    },

    onModelChange: function(){
        // Update the description when ever the model changes
        this.$('.description').html( this.model.getTitle() );
    },

    /**
     * When the model is destroyed, fade it out
     */
    onModelDestroy: function(){
        this.remove();
    },

    /**
     * Visually destroy a model
     */
    visualDestroyModel: function(){
        // Add the history entry
        this.cell.row.builder.addHistoryEntry('widget_deleted');

        var thisView = this;
        this.$el.fadeOut('fast', function(){
            thisView.cell.row.resize();
            thisView.model.destroy();
	        thisView.cell.row.builder.model.refreshPanelsData();
            thisView.remove();
        } );
    },

    /**
     * Build up the contextual menu for a widget
     *
     * @param e
     * @param menu
     */
    buildContextualMenu: function( e, menu ) {
        var thisView = this;

        menu.addSection(
            {
                sectionTitle: panelsOptions.loc.contextual.add_widget_below,
                searchPlaceholder: panelsOptions.loc.contextual.search_widgets,
                defaultDisplay: panelsOptions.contextual.default_widgets
            },
            panelsOptions.widgets,
            function(c){
                thisView.cell.row.builder.addHistoryEntry('widget_added');

                var widget = new panels.model.widget( {
                    class: c
                } );
                widget.cell = thisView.cell.model;

                // Insert the new widget below
                thisView.cell.model.widgets.add(widget, {
                    // Add this after the existing model
                    at: thisView.model.collection.indexOf( thisView.model ) + 1
                });
            }
        );

	    menu.addSection (
		    {
			    sectionTitle: panelsOptions.loc.contextual.widget_actions,
			    search: false,
		    },
		    {
			    'edit': {
				    title: panelsOptions.loc.contextual.widget_edit
			    },
			    'duplicate': {
				    title: panelsOptions.loc.contextual.widget_duplicate
			    },
			    'delete': {
				    title: panelsOptions.loc.contextual.widget_delete
			    },
		    },
		    function( c ){
			    switch( c ) {
				    case 'edit':
					    thisView.editHandler();
					    break;
				    case 'duplicate':
					    thisView.duplicateHandler();
					    break;
				    case 'delete':
					    thisView.visualDestroyModel();
					    break;
			    }
		    }
	    );

        // Lets also add the contextual menu for the entire row
        this.cell.row.buildContextualMenu( e, menu );
    }

});
