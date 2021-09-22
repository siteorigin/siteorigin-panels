var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	historyEntryTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-dialog-history-entry' ).html() ) ),

	entries: {},
	currentEntry: null,
	revertEntry: null,
	selectedEntry: null,

	previewScrollTop: null,

	dialogClass: 'so-panels-dialog-history',
	dialogIcon: 'history',

	events: {
		'click .so-close': 'closeDialog',
		'keyup .so-close': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-restore': 'restoreSelectedEntry',
		'keyup .history-entry': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
	},

	initializeDialog: function () {
		this.entries = new panels.collection.historyEntries();

		this.on( 'open_dialog', this.setCurrentEntry, this );
		this.on( 'open_dialog', this.renderHistoryEntries, this );

		this.on( 'open_dialog_complete', function () {
			this.$( '.history-entry' ).trigger( 'focus' );
		} );
	},

	render: function () {
		var thisView = this;

		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-history' ).html(), {} ) );

		// Set the history URL.
		this.$( 'form.history-form' ).attr( 'action', this.builder.config.editorPreview );

		this.$( 'iframe.siteorigin-panels-history-iframe' ).on( 'load', function () {
			var $$ = $( this );
			$$.show();

			$$.contents().scrollTop( thisView.previewScrollTop );
		} );
	},

	/**
	 * Set the original entry. This should be set when creating the dialog.
	 *
	 * @param {panels.model.builder} builder
	 */
	setRevertEntry: function ( builder ) {
		this.revertEntry = new panels.model.historyEntry( {
			data: JSON.stringify( builder.getPanelsData() ),
			time: parseInt( new Date().getTime() / 1000 )
		} );
	},

	/**
	 * This is triggered when the dialog is opened.
	 */
	setCurrentEntry: function () {
		this.currentEntry = new panels.model.historyEntry( {
			data: JSON.stringify( this.builder.model.getPanelsData() ),
			time: parseInt( new Date().getTime() / 1000 )
		} );

		this.selectedEntry = this.currentEntry;
		this.previewEntry( this.currentEntry );
		this.$( '.so-buttons .so-restore' ).addClass( 'disabled' );
	},

	/**
	 * Render the history entries in the sidebar
	 */
	renderHistoryEntries: function () {
		// Set up an interval that will display the time since every 10 seconds
		var thisView = this;

		var c = this.$( '.history-entries' ).empty();

		if ( this.currentEntry.get( 'data' ) !== this.revertEntry.get( 'data' ) || ! _.isEmpty( this.entries.models ) ) {
			$( this.historyEntryTemplate( {title: panelsOptions.loc.history.revert, count: 1} ) )
				.data( 'historyEntry', this.revertEntry )
				.prependTo( c );
		}

		// Now load all the entries in this.entries
		this.entries.each( function ( entry ) {

			var html = thisView.historyEntryTemplate( {
				title: panelsOptions.loc.history[entry.get( 'text' )],
				count: entry.get( 'count' )
			} );

			$( html )
				.data( 'historyEntry', entry )
				.prependTo( c );
		} );


		$( this.historyEntryTemplate( {title: panelsOptions.loc.history['current'], count: 1} ) )
			.data( 'historyEntry', this.currentEntry )
			.addClass( 'so-selected' )
			.prependTo( c );

		// Handle loading and selecting
		c.find( '.history-entry' ).on( 'click', function(e) {
			if ( e.type == 'keyup' && e.which != 13 ) {
				return;
			}

			var $$ = jQuery( this );
			c.find( '.history-entry' ).not( $$ ).removeClass( 'so-selected' );
			$$.addClass( 'so-selected' );

			var entry = $$.data( 'historyEntry' );

			thisView.selectedEntry = entry;

			if ( thisView.selectedEntry.cid !== thisView.currentEntry.cid ) {
				thisView.$( '.so-buttons .so-restore' ).removeClass( 'disabled' );
			} else {
				thisView.$( '.so-buttons .so-restore' ).addClass( 'disabled' );
			}

			thisView.previewEntry( entry );
		} );

		this.updateEntryTimes();
	},

	/**
	 * Preview an entry
	 *
	 * @param entry
	 */
	previewEntry: function ( entry ) {
		var iframe = this.$( 'iframe.siteorigin-panels-history-iframe' );
		iframe.hide();
		this.previewScrollTop = iframe.contents().scrollTop();

		this.$( 'form.history-form input[name="live_editor_panels_data"]' ).val( entry.get( 'data' ) );
		this.$( 'form.history-form input[name="live_editor_post_ID"]' ).val( this.builder.config.postId );
		this.$( 'form.history-form' ).trigger( 'submit' );
	},

	/**
	 * Restore the current entry
	 */
	restoreSelectedEntry: function () {

		if ( this.$( '.so-buttons .so-restore' ).hasClass( 'disabled' ) ) {
			return false;
		}

		if ( this.currentEntry.get( 'data' ) === this.selectedEntry.get( 'data' ) ) {
			this.closeDialog();
			return false;
		}

		// Add an entry for this restore event
		if ( this.selectedEntry.get( 'text' ) !== 'restore' ) {
			this.builder.addHistoryEntry( 'restore', this.builder.model.getPanelsData() );
		}

		this.builder.model.loadPanelsData( JSON.parse( this.selectedEntry.get( 'data' ) ) );

		this.closeDialog();

		return false;
	},

	/**
	 * Update the entry times for the list of entries down the side
	 */
	updateEntryTimes: function () {
		var thisView = this;

		this.$( '.history-entries .history-entry' ).each( function () {
			var $$ = jQuery( this );

			var time = $$.find( '.timesince' );
			var entry = $$.data( 'historyEntry' );

			time.html( thisView.timeSince( entry.get( 'time' ) ) );
		} );
	},

	/**
	 * Gets the time since as a nice string.
	 *
	 * @param date
	 */
	timeSince: function ( time ) {
		var diff = parseInt( new Date().getTime() / 1000 ) - time;

		var parts = [];
		var interval;

		// There are 3600 seconds in an hour
		if ( diff > 3600 ) {
			interval = Math.floor( diff / 3600 );
			if ( interval === 1 ) {
				parts.push( panelsOptions.loc.time.hour.replace( '%d', interval ) );
			} else {
				parts.push( panelsOptions.loc.time.hours.replace( '%d', interval ) );
			}
			diff -= interval * 3600;
		}

		// There are 60 seconds in a minute
		if ( diff > 60 ) {
			interval = Math.floor( diff / 60 );
			if ( interval === 1 ) {
				parts.push( panelsOptions.loc.time.minute.replace( '%d', interval ) );
			} else {
				parts.push( panelsOptions.loc.time.minutes.replace( '%d', interval ) );
			}
			diff -= interval * 60;
		}

		if ( diff > 0 ) {
			if ( diff === 1 ) {
				parts.push( panelsOptions.loc.time.second.replace( '%d', diff ) );
			} else {
				parts.push( panelsOptions.loc.time.seconds.replace( '%d', diff ) );
			}
		}

		// Return the amount of time ago
		return _.isEmpty( parts ) ? panelsOptions.loc.time.now : panelsOptions.loc.time.ago.replace( '%s', parts.slice( 0, 2 ).join( ', ' ) );

	}

} );
