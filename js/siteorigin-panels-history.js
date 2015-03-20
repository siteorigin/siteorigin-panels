/**
 * History browser for Page Builder.
 *
 * @copyright Greg Priday 2014 - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

/* global Backbone, _, jQuery, tinyMCE, soPanelsOptions, confirm */

( function( $, _, panelsOptions ){

    var panels = window.siteoriginPanels;

    /**
     *
     */
    panels.model.historyEntry = Backbone.Model.extend( {
        defaults: {
            text : '',
            data : '',
            time: null,
            count: 1
        }
    } );

    /**
     *
     */
    panels.collection.historyEntries = Backbone.Collection.extend( {
        model: panels.model.historyEntry,

        /**
         * The builder model
         */
        builder: null,

        /**
         * The maximum number of items in the history
         */
        maxSize: 12,

        initialize: function(){
            this.on( 'add', this.onAddEntry, this );
        },

        /**
         * Add an entry to the collection.
         *
         * @param text The text that defines the action taken to get to this
         * @param data
         */
        addEntry: function(text, data) {

            if(typeof data === 'undefined' || data === null) {
                data = this.builder.getPanelsData();
            }

            var entry = new panels.model.historyEntry( {
                text: text,
                data: JSON.stringify( data ),
                time: parseInt( new Date().getTime() / 1000 ),
                collection: this
            } );

            this.add( entry );
        },

        /**
         * Resize the collection so it's not bigger than this.maxSize
         */
        onAddEntry: function(entry){

            if(this.models.length > 1) {
                var lastEntry = this.at(this.models.length - 2);

                if(
                    ( entry.get('text') === lastEntry.get('text') &&  entry.get('time') - lastEntry.get('time') < 15 ) ||
                    ( entry.get('data') === lastEntry.get('data') )
                ) {
                    // If both entries have the same text and are within 20 seconds of each other, or have the same data, then remove most recent
                    this.remove( entry );
                    lastEntry.set( 'count', lastEntry.get('count') + 1 );
                }
            }

            // Make sure that there are not to many entries in this collection
            while( this.models.length > this.maxSize ) {
                this.shift();
            }
        }
    } );

    /**
     * The history manager is
     */
    panels.dialog.history = panels.view.dialog.extend( {

        historyEntryTemplate: _.template( $('#siteorigin-panels-dialog-history-entry').html().panelsProcessTemplate() ),

        entries: {},
        currentEntry: null,
        revertEntry: null,
        selectedEntry: null,

        dialogClass: 'so-panels-dialog-history',

        events: {
            'click .so-close': 'closeDialog',
            'click .so-restore': 'restoreSelectedEntry'
        },

        initializeDialog: function(){
            this.entries = new panels.collection.historyEntries();

            this.on('open_dialog', this.setCurrentEntry, this);
            this.on('open_dialog', this.renderHistoryEntries, this);
        },

        render: function(){
            // Render the dialog and attach it to the builder interface
            this.renderDialog( this.parseDialogContent( $('#siteorigin-panels-dialog-history').html(), {} ) );

            this.$('iframe.siteorigin-panels-history-iframe').load(function(){
                $(this).show();
            });
        },

        /**
         * Set the origianl entry. This should be set when creating the dialog.
         *
         * @param {panels.model.builder} builder
         */
        setRevertEntry: function(builder){
            this.revertEntry = new panels.model.historyEntry( {
                data: JSON.stringify( builder.getPanelsData() ),
                time: parseInt( new Date().getTime() / 1000 )
            } );
        },

        /**
         * This is triggered when the dialog is opened.
         */
        setCurrentEntry: function(){
            this.currentEntry = new panels.model.historyEntry( {
                data: JSON.stringify( this.builder.model.getPanelsData() ),
                time: parseInt( new Date().getTime() / 1000 )
            } );

            this.selectedEntry = this.currentEntry;
            this.previewEntry( this.currentEntry );
            this.$('.so-buttons .so-restore').addClass('disabled');
        },

        /**
         * Render the history entries
         */
        renderHistoryEntries: function(){
            var c = this.$('.history-entries');

            // Set up an interval that will display the time since every 10 seconds
            var thisView = this;

            c.empty();

            if( this.currentEntry.get('data') !== this.revertEntry.get('data') || this.entries.models.length > 0 ) {
                $(this.historyEntryTemplate({title: panelsOptions.loc.history.revert, count: 1}))
                    .data('historyEntry', this.revertEntry)
                    .prependTo(c);
            }

            // Now load all the entries in this.entries
            this.entries.each(function(entry){

                var html = thisView.historyEntryTemplate( {
                    title: panelsOptions.loc.history[ entry.get('text') ],
                    count: entry.get('count')
                } );

                $( html )
                    .data('historyEntry', entry)
                    .prependTo(c);
            });


            $(this.historyEntryTemplate({title: panelsOptions.loc.history['current'], count: 1}))
                .data('historyEntry', this.currentEntry)
                .addClass('so-selected')
                .prependTo(c);

            // Handle loading and selecting
            c.find('.history-entry').click(function(){
                var $$ = $(this);
                c.find('.history-entry').not($$).removeClass('so-selected');
                $$.addClass('so-selected');

                var entry = $$.data('historyEntry');

                thisView.selectedEntry = entry;

                if( thisView.selectedEntry.cid !== thisView.currentEntry.cid ) {
                    thisView.$('.so-buttons .so-restore').removeClass('disabled');
                }
                else {
                    thisView.$('.so-buttons .so-restore').addClass('disabled');
                }

                thisView.previewEntry( entry );
            });

            this.updateEntryTimes();
        },

        /**
         * Preview an entry
         *
         * @param entry
         */
        previewEntry: function(entry){
            this.$('iframe.siteorigin-panels-history-iframe').hide();
            this.$('form.history-form input[name="siteorigin_panels_data"]').val( entry.get('data') );
            this.$('form.history-form').submit();
        },

        /**
         * Restore the current entry
         */
        restoreSelectedEntry: function(){

            if( this.$('.so-buttons .so-restore').hasClass('disabled') ) {
                return false;
            }

            if( this.currentEntry.get('data') === this.selectedEntry.get('data') ) {
                this.closeDialog();
                return false;
            }

            // Add an entry for this restore event
            if( this.selectedEntry.get('text') !== 'restore' ) {
                this.entries.addEntry( 'restore', this.builder.model.getPanelsData() );
            }

            this.builder.model.loadPanelsData( JSON.parse( this.selectedEntry.get('data') ) );

            this.closeDialog();

            return false;
        },

        /**
         * Update the entry times for the list of entries down the side
         */
        updateEntryTimes: function(){
            var thisView = this;

            this.$('.history-entries .history-entry').each(function(){
                var $$ = $(this);

                var time = $$.find('.timesince');
                var entry = $$.data('historyEntry');

                time.html( thisView.timeSince( entry.get('time') ) );
            });
        },

        /**
         * Gets the time since as a nice string.
         *
         * @param date
         */
        timeSince: function(time){
            var diff = parseInt( new Date().getTime() / 1000 ) - time;

            var parts = [];
            var interval;

            // There are 3600 seconds in an hour
            if( diff > 3600 ) {
                interval = Math.floor( diff / 3600 );
                if(interval === 1) {
                    parts.push(panelsOptions.loc.time.hour.replace('%d', interval ));
                }
                else  {
                    parts.push(panelsOptions.loc.time.hours.replace('%d', interval ));
                }
                diff -= interval * 3600;
            }

            // There are 60 seconds in a minute
            if( diff > 60 ) {
                interval = Math.floor( diff / 60 );
                if(interval === 1) {
                    parts.push(panelsOptions.loc.time.minute.replace('%d', interval ));
                }
                else {
                    parts.push(panelsOptions.loc.time.minutes.replace('%d', interval ));
                }
                diff -= interval * 60;
            }

            if( diff > 0 ) {
                if(diff === 1) {
                    parts.push(panelsOptions.loc.time.second.replace('%d', diff ));
                }
                else  {
                    parts.push(panelsOptions.loc.time.seconds.replace('%d', diff ));
                }
            }

            // Return the amount of time ago
            return parts.length === 0 ? panelsOptions.loc.time.now : panelsOptions.loc.time.ago.replace('%s', parts.slice(0,2).join(', ') );

        }

    } );

} )( jQuery, _, soPanelsOptions );