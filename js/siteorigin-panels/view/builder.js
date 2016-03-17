var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {

	// Config options
	editorType: null,
	postId: null,
	editorId: null,

    template: _.template( $('#siteorigin-panels-builder').html().panelsProcessTemplate() ),
    dialogs: {  },
    rowsSortable: null,
    dataField : false,
    currentData: '',

    attachedToEditor: false,
    liveEditor: false,
    menu: false,

    /* The builderType is sent with all requests to the server */
    builderType: '',

    events: {
        'click .so-tool-button.so-widget-add': 'displayAddWidgetDialog',
        'click .so-tool-button.so-row-add': 'displayAddRowDialog',
        'click .so-tool-button.so-prebuilt-add': 'displayAddPrebuiltDialog',
        'click .so-tool-button.so-history': 'displayHistoryDialog',
        'click .so-tool-button.so-live-editor': 'displayLiveEditor'
    },

    /* A row collection */
    rows: null,

    /**
     * Initialize the builder
     */
    initialize: function(options){
        var builder = this;

		if(!_.isUndefined(options.config)) {
			this.editorType = options.config.editorType;
			this.editorId = options.config.editorId;
			this.builderType = options.config.builderType;
			this.postId = options.config.postId;
		}

        // Now lets create all the dialog boxes that the main builder interface uses
        this.dialogs = {
            widgets: new panels.dialog.widgets(),
            row: new panels.dialog.row(),
            prebuilt: new panels.dialog.prebuilt()
        };

        // Set the builder for each dialog and render it.
        _.each(this.dialogs, function(p, i, d){
            d[i].setBuilder( builder );
        });

        this.dialogs.row.setRowDialogType('create');

        // This handles a new row being added to the collection - we'll display it in the interface
        this.model.rows.on('add', this.onAddRow, this);

        // Reflow the entire builder when ever the
        $(window).resize(function(e){
            if(e.target === window) {
                builder.trigger('builder_resize');
            }
        });

        // When the data changes in the model, store it in the field
        this.model.on('change:data load_panels_data', this.storeModelData, this);

        // Handle a content change
        this.on( 'content_change', this.handleContentChange, this );
        this.on( 'display_builder', this.handleDisplayBuilder, this );
	    this.on( 'builder_rendered builder_resize', this.handleBuilderSizing, this );
        this.model.on('change:data load_panels_data', this.toggleWelcomeDisplay, this);

        // Create the context menu for this builder
        this.menu = new panels.utils.menu({});
        this.menu.on('activate_context', this.activateContextMenu, this);

        return this;
    },

    /**
     * Render the builder interface.
     *
     * @return {panels.view.builder}
     */
    render: function(){
        this.$el.html( this.template() );
        this.$el
            .attr( 'id', 'siteorigin-panels-builder-' + this.cid )
            .addClass('so-builder-container');

        this.trigger( 'builder_rendered' );
        return this;
    },

    /**
     * Attach the builder to the given container
     *
     * @param container
     * @returns {panels.view.builder}
     */
    attach: function(options) {

        options = _.extend({
            type: '',
            container: false,
            dialog: false
        }, options);

        if( options.dialog ) {
            // We're going to add this to a dialog
            this.dialog = new panels.dialog.builder();
            this.dialog.builder = this;
        }
        else {
            // Attach this in the standard way
            this.$el.appendTo( options.container );
            this.metabox = options.container.closest('.postbox');
            this.initSortable();
            this.trigger('attached_to_container', options.container);
        }

        // Store the builder type
        this.builderType = options.type;

        return this;
    },

    /**
     * This will move the Page Builder meta box into the editor if we're in the post/page edit interface.
     *
     * @returns {panels.view.builder}
     */
    attachToEditor: function(){
		if(this.editorType !== 'tinymce') {
			this.attachedToEditor = !_.isUndefined(this.editorId);
			return this;
		}

		// No metabox... :/
        if( typeof this.metabox === 'undefined' || this.metabox.length === 0) {
            return this;
        }

        this.attachedToEditor = true;
        var metabox = this.metabox;
        var thisView = this;

        // Handle switching between the page builder and other tabs
        $( '#wp-content-wrap .wp-editor-tabs' )
            .find( '.wp-switch-editor' )
            .click(function (e) {
                e.preventDefault();
                $( '#wp-content-editor-container, #post-status-info' ).show();
                // metabox.hide();
                $( '#wp-content-wrap' ).removeClass('panels-active');
                $('#content-resize-handle' ).show();
                thisView.trigger('hide_builder');
            } ).end()
            .append(
            $( '<a id="content-panels" class="hide-if-no-js wp-switch-editor switch-panels">' + metabox.find( '.hndle span' ).html() + '</a>' )
                .click( function (e) {
                    // Switch to the Page Builder interface
                    e.preventDefault();

                    var $$ = jQuery( this );

                    // Hide the standard content editor
                    $( '#wp-content-wrap, #post-status-info' ).hide();

                    // Show page builder and the inside div
                    metabox.show().find('> .inside').show();

                    // Triggers full refresh
                    $( window ).resize();
                    $( document).scroll();

                    thisView.trigger('display_builder');

                } )
        );

        // Switch back to the standard editor
        metabox.find('.so-switch-to-standard').click(function(e){
            e.preventDefault();

            if( !confirm(panelsOptions.loc.confirm_stop_builder) ) {
                return;
            }

            // User is switching to the standard visual editor
            thisView.addHistoryEntry( 'back_to_editor' );
            thisView.model.loadPanelsData( false );

            // Switch back to the standard editor
            $( '#wp-content-wrap, #post-status-info' ).show();
            metabox.hide();

            // Resize to trigger reflow of WordPress editor stuff
            $( window ).resize();
        }).show();

        // Move the panels box into a tab of the content editor
        metabox.insertAfter( '#wp-content-wrap').hide().addClass('attached-to-editor');

        // Switch to the Page Builder interface as soon as we load the page if there are widgets
        var data = this.model.get('data');
        if(
            ( typeof data.widgets !== 'undefined' && _.size(data.widgets) !== 0 ) ||
            ( typeof data.grids !== 'undefined' && _.size(data.grids) !== 0 )
        ) {
            $('#content-panels.switch-panels').click();
        }

        // We will also make this sticky if its attached to an editor.
        var stickToolbar = function(){
            var toolbar = thisView.$('.so-builder-toolbar');
            var newTop = $(window).scrollTop() - thisView.$el.offset().top;

            if( $('#wpadminbar').css('position') === 'fixed' ) {
                newTop += $('#wpadminbar').outerHeight();
            }

            var limits = {
                top: 0,
                bottom: thisView.$el.outerHeight() - toolbar.outerHeight() + 20
            };

            if( newTop > limits.top && newTop < limits.bottom ) {
                if( toolbar.css('position') !== 'fixed' ) {
                    // The toolbar needs to stick to the top, over the interface
                    toolbar.css({
                        top: $('#wpadminbar').outerHeight(),
                        left: thisView.$el.offset().left,
                        width: thisView.$el.outerWidth(),
                        position: 'fixed'
                    });
                }
            }
            else {
                // The toolbar needs to be at the top or bottom of the interface
                toolbar.css({
                    top: Math.min( Math.max( newTop, 0 ), thisView.$el.outerHeight() - toolbar.outerHeight() + 20 ),
                    left: 0,
                    width: '100%',
                    position: 'absolute'
                });
            }

            thisView.$el.css('padding-top', toolbar.outerHeight() );
        };

        $( window ).resize( stickToolbar );
        $( document ).scroll( stickToolbar );
        stickToolbar();

        return this;
    },

    /**
     * Initialize the row sortables
     */
    initSortable: function(){
        // Create the sortable for the rows
        var $el = this.$el;
        var builderView = this;

        this.rowsSortable = this.$el.find('.so-rows-container').sortable( {
            appendTo: '#wpwrap',
            items: '.so-row-container',
            handle: '.so-row-move',
            axis: 'y',
            tolerance: 'pointer',
            scroll: false,
            stop: function (e) {
                builderView.addHistoryEntry('row_moved');

                // Sort the rows collection after updating all the indexes.
                builderView.sortCollections();
            }
        } );
    },

    /**
     * Refresh the row sortable
     */
    refreshSortable: function(){
        // Refresh the sortable to account for the new row
        if(this.rowsSortable !== null) {
            this.rowsSortable.sortable('refresh');
        }
    },

    /**
     * Set the field that's used to store the data
     * @param field
     */
    setDataField: function(field, options){
        options = _.extend({
            load: true
        }, options);

        this.dataField = field;
        this.dataField.data('builder', this);

        if( options.load && field.val() !== '') {
            var data;
            try {
                data = JSON.parse( this.dataField.val( ) );
            }
            catch(err) {
                data = '';
            }

            this.model.loadPanelsData(data);
            this.currentData = data;
            this.toggleWelcomeDisplay();
        }

        return this;
    },

    /**
     * Store the model data in the data html field set in this.setDataField.
     */
    storeModelData: function(){
        var data = JSON.stringify( this.model.get('data' ) );

        if( $(this.dataField).val() !== data ) {
            // If the data is different, set it and trigger a content_change event
            $(this.dataField).val( data );
            $(this.dataField).trigger( 'change' );
            this.trigger('content_change');
        }
    },

    /**
     * HAndle the visual side of adding a new row to the builder.
     *
     * @param row
     * @param collection
     * @param options
     */
    onAddRow: function(row, collection, options){
        options = _.extend( {noAnimate: false}, options );
        // Create a view for the row
        var rowView = new panels.view.row( { model: row } );
        rowView.builder = this;
        rowView.render();

        // Attach the row elements to this builder
        if( typeof options.at === 'undefined' || collection.length <= 1 ) {
            // Insert this at the end of the widgets container
            rowView.$el.appendTo( this.$( '.so-rows-container' ) );
        }
        else {
            // We need to insert this at a specific position
            rowView.$el.insertAfter(
                this.$('.so-rows-container .so-row-container').eq( options.at - 1 )
            );
        }

        if(options.noAnimate === false) {
            rowView.visualCreate();
        }

        this.refreshSortable();
        rowView.resize();
    },

    /**
     * Display the dialog to add a new widget.
     *
     * @returns {boolean}
     */
    displayAddWidgetDialog: function(){
        this.dialogs.widgets.openDialog();
        return false;
    },

    /**
     * Display the dialog to add a new row.
     *
     * @returns {boolean}
     */
    displayAddRowDialog: function(){
        this.dialogs.row.openDialog();
        this.dialogs.row.setRowModel(); // Set this to an empty row model
        return false;
    },

    /**
     * Display the dialog to add prebuilt layouts.
     *
     * @returns {boolean}
     */
    displayAddPrebuiltDialog: function(){
        this.dialogs.prebuilt.openDialog();
        return false;
    },

    /**
     * Display the history dialog.
     *
     * @returns {boolean}
     */
    displayHistoryDialog: function(){
        this.dialogs.history.openDialog();
        return false;
    },

    /**
     * Get the model for the currently selected cell
     */
    getActiveCell: function( options ){
        options = _.extend( {
            createCell: true,
            defaultPosition: 'first'
        }, options );

        if( this.$('.so-cells .cell').length === 0 ) {

            if( options.createCell ) {
                // Create a row with a single cell
                this.model.addRow( [1], {noAnimate: true} );
            }
            else {
                return null;
            }

        }

        var activeCell = this.$('.so-cells .cell.cell-selected');

        if(!activeCell.length) {
            if( options.defaultPosition === 'last' ){
                activeCell = this.$('.so-cells .cell').first();
            }
            else {
                activeCell = this.$('.so-cells .cell').last();
            }
        }

        return activeCell.data('view').model;
    },

    /**
     * Sort all widget and row collections based on their dom position
     */
    sortCollections: function(){
        // Create an array that stores model indexes within the array
        var indexes = {};

        this.$('.so-rows-container .so-row-container').each(function(ri, el){
            var $r = $(el);
            indexes[ $r.data('view').model.cid ] = ri;

            $r.find('.so-cells .cell').each(function(ci, el){
                var $c = $(el);

                $c.find('.so-widget').each(function(wi, el) {
                    var $w = $(el);
                    indexes[ $w.data('view').model.cid ] = wi;
                });
            });
        });

        // Sort everything
        this.model.rows.models = this.model.rows.sortBy(function(model){
            return indexes[model.cid];
        });

        this.model.rows.each(function(row){
            row.cells.each(function(cell){
                cell.widgets.models = cell.widgets.sortBy(function(widget){
                    return indexes[widget.cid];
                });
            });
        });

        // Update the builder model to reflect the newly ordered data.
        this.model.refreshPanelsData();
    },

    /**
     * Add a live editor
     *
     * @returns {panels.view.builder}
     */
    addLiveEditor: function(postId){
        if( typeof panels.view.liveEditor === 'undefined' ) {
            return this;
        }

        // Create the live editor and set the builder to this.
        this.liveEditor = new panels.view.liveEditor();
        this.liveEditor.setPostId(postId);

        this.liveEditor.builder = this;

        // Display the live editor button in the toolbar
        if( this.liveEditor.hasPreviewUrl() ) {
            this.$('.so-builder-toolbar .so-live-editor').show();
        }

        return this;
    },

    /**
     * Show the current live editor
     */
    displayLiveEditor: function(){
        if(typeof this.liveEditor === 'undefined') {
            return false;
        }

        this.liveEditor.open();
        return false;
    },

    /**
     * Add the history browser.
     *
     * @return {panels.view.builder}
     */
    addHistoryBrowser: function(){
        if(typeof panels.dialog.history === 'undefined') {
            return this;
        }

        this.dialogs.history = new panels.dialog.history();
        this.dialogs.history.builder = this;
        this.dialogs.history.entries.builder = this.model;

        // Set the revert entry
        this.dialogs.history.setRevertEntry( this.model );

        // Display the live editor button in the toolbar
        this.$('.so-builder-toolbar .so-history').show();
    },

    /**
     * Add an entry.
     *
     * @param text
     * @param data
     */
    addHistoryEntry: function(text, data){
        if(typeof data === 'undefined') {
            data = null;
        }

        if( typeof this.dialogs.history !== 'undefined' ) {
            this.dialogs.history.entries.addEntry(text, data);
        }
    },

    /**
     * Handle a change of the content
     */
    handleContentChange: function(){

        // Make sure we actually need to copy content.
        if( panelsOptions.copy_content && this.attachedToEditor && this.$el.is(':visible')) {

            // We're going to create a copy of page builder content into the post content
            $.post(
                panelsOptions.ajaxurl,
                {
                    action: 'so_panels_builder_content',
                    panels_data: JSON.stringify( this.model.getPanelsData() ),
                    post_id: this.postId
                },
                function(content){

                    // Strip all the known layout divs
                    var t = $('<div />').html( content );
                    t.find( 'div').each(function() {
                        var c = $(this).contents();
                        $(this).replaceWith(c);
                    });

                    content = t.html()
                        .replace(/[\r\n]+/g, "\n")
                        .replace(/\n\s+/g, "\n")
                        .trim();

					this.updateEditorContent(content);
                }.bind(this)
            );
        }

        if( this.liveEditor !== false ) {
            // Refresh the content of the builder
            this.liveEditor.refreshPreview();
        }
    },

    /**
     * Update editor content with the given content.
     *
     * @param content
     */
    updateEditorContent:function ( content ) {
        // Switch back to the standard editor
        if( this.editorType !== 'tinymce' || typeof tinyMCE === 'undefined' || tinyMCE.get("content") === null ) {
			var $editor = $(this.editorId);
			$editor.val(content).trigger( 'change' ).trigger( 'keyup' );
        }
        else {
            var contentEd = tinyMCE.get("content");

            contentEd.setContent(content);

            contentEd.fire( 'change' );
            contentEd.fire( 'keyup' );
        }

        this.triggerYoastSeoChange();
    },

    /**
     * Trigger a change on Yoast SEO
     */
    triggerYoastSeoChange: function(){
        if( $('#yoast_wpseo_focuskw_text_input').length ) {
            var element = document.getElementById( 'yoast_wpseo_focuskw_text_input'), event;

            if (document.createEvent) {
                event = document.createEvent("HTMLEvents");
                event.initEvent("keyup", true, true);
            } else {
                event = document.createEventObject();
                event.eventType = "keyup";
            }

            event.eventName = "keyup";

            if (document.createEvent) {
                element.dispatchEvent(event);
            } else {
                element.fireEvent("on" + event.eventType, event);
            }
        }
    },

    /**
     * Handle displaying the builder
     */
    handleDisplayBuilder: function(){
        var editorContent = '';
        var editor;

        if ( typeof tinyMCE !== 'undefined' ) {
            editor = tinyMCE.get( 'content' );
        }
        if( editor && typeof( editor.getContent ) === "function" ) {
            editorContent = editor.getContent();
        }
        else {
            editorContent = $('textarea#content').val();
        }

        if( _.isEmpty( this.model.get('data') ) && editorContent !== '') {
            // Confirm that the user wants to copy their content to Page Builder.
            if( !confirm( panelsOptions.loc.confirm_use_builder ) ) { return; }

            var widgetClass = '';
            // There is a small chance a theme will have removed this, so check
            if( typeof panelsOptions.widgets.SiteOrigin_Widget_Editor_Widget !== 'undefined' ) {
                widgetClass = 'SiteOrigin_Widget_Editor_Widget';
            }
            else if( typeof panelsOptions.widgets.WP_Widget_Text !== 'undefined' ) {
                widgetClass = 'WP_Widget_Text';
            }

            if( widgetClass === '' ) { return; }

            // Create the existing page content in a single widget
            this.model.loadPanelsData( {
                grid_cells : [ { grid: 0, weight: 1 } ],
                grids: [ { cells: 1 } ],
                widgets: [{
                    filter: "1",
                    text: editorContent,
                    title: "",
                    type: "visual",
                    panels_info: {
                        class: widgetClass,
                        raw: false,
                        grid: 0,
                        cell: 0
                    }
                }]
            } );
            this.model.trigger('change');
            this.model.trigger('change:data');
        }
    },

	handleBuilderSizing: function(){
		var width = this.$el.width();

		if( ! width ) {
			return this;
		}

		if( width < 480 ) {
			this.$el.addClass( 'so-display-narrow' );
		}
		else {
			this.$el.removeClass( 'so-display-narrow' );
		}

	},

    /**
     * Set the parent dialog for all the dialogs in this builder.
     *
     * @param text
     * @param dialog
     */
    setDialogParents: function(text, dialog){
        _.each(this.dialogs, function(p, i, d){
            d[i].setParent(text, dialog );
        });

        // For any future dialogs
        this.on('add_dialog', function(newDialog){
            newDialog.setParent(text, dialog);
        }, this);
    },

    /**
     * This shows or hides the welcome display depending on whether there are any rows in the collection.
     */
    toggleWelcomeDisplay: function(){
        if( this.model.rows.length ) {
            this.$('.so-panels-welcome-message').hide();
        }
        else {
            this.$('.so-panels-welcome-message').show();
        }
    },

    activateContextMenu: function( e, menu ){
        var builder = this;

        // Skip this if any of the dialogs are open. They can handle their own contexts.
        if( typeof window.panelsDialogOpen === 'undefined' || !window.panelsDialogOpen ) {
            // Check if any of the widgets get the contextual menu
            var overItem = false, overItemType = false;

            var over = $([])
                .add( builder.$('.so-rows-container > .so-row-container') )
                .add( builder.$('.so-cells > .cell') )
                .add( builder.$('.cell-wrapper > .so-widget') )
                .filter( function(i){
                    return menu.isOverEl( $(this), e );
                } );

            var activeView = over.last().data('view');
            if( activeView !== undefined && activeView.buildContextualMenu !== undefined ) {
                // We'll pass this to the current active view so it can popular the contextual menu
                activeView.buildContextualMenu( e, menu );
            }
        }
    }

} );
