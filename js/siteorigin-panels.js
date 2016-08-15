(function e(t,n,r){function s(o,u){if(!n[o]){if(!t[o]){var a=typeof require=="function"&&require;if(!u&&a)return a(o,!0);if(i)return i(o,!0);var f=new Error("Cannot find module '"+o+"'");throw f.code="MODULE_NOT_FOUND",f}var l=n[o]={exports:{}};t[o][0].call(l.exports,function(e){var n=t[o][1][e];return s(n?n:e)},l,l.exports,e,t,n,r)}return n[o].exports}var i=typeof require=="function"&&require;for(var o=0;o<r.length;o++)s(r[o]);return s})({1:[function(require,module,exports){
var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.cell,

	initialize: function () {
	},

	/**
	 * Get the total weight for the cells in this collection.
	 * @returns {number}
	 */
	totalWeight: function () {
		var totalWeight = 0;
		this.each( function ( cell ) {
			totalWeight += cell.get( 'weight' );
		} );

		return totalWeight;
	},

	visualSortComparator: function ( item ) {
		if ( ! _.isNull( item.indexes ) ) {
			return item.indexes.builder;
		} else {
			return null;
		}
	},

	visualSort: function(){
		var oldComparator = this.comparator;
		this.comparator = this.visualSortComparator;
		this.sort();
		this.comparator = oldComparator;
	}
} );

},{}],2:[function(require,module,exports){
var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.historyEntry,

	/**
	 * The builder model
	 */
	builder: null,

	/**
	 * The maximum number of items in the history
	 */
	maxSize: 12,

	initialize: function () {
		this.on( 'add', this.onAddEntry, this );
	},

	/**
	 * Add an entry to the collection.
	 *
	 * @param text The text that defines the action taken to get to this
	 * @param data
	 */
	addEntry: function ( text, data ) {

		if ( _.isEmpty( data ) ) {
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
	onAddEntry: function ( entry ) {

		if ( this.models.length > 1 ) {
			var lastEntry = this.at( this.models.length - 2 );

			if (
				(
					entry.get( 'text' ) === lastEntry.get( 'text' ) && entry.get( 'time' ) - lastEntry.get( 'time' ) < 15
				) ||
				(
					entry.get( 'data' ) === lastEntry.get( 'data' )
				)
			) {
				// If both entries have the same text and are within 20 seconds of each other, or have the same data, then remove most recent
				this.remove( entry );
				lastEntry.set( 'count', lastEntry.get( 'count' ) + 1 );
			}
		}

		// Make sure that there are not to many entries in this collection
		while ( this.models.length > this.maxSize ) {
			this.shift();
		}
	}
} );

},{}],3:[function(require,module,exports){
var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.row,

	/**
	 * Destroy all the rows in this collection
	 */
	empty: function () {
		var model;
		do {
			model = this.collection.first();
			if ( ! model ) {
				break;
			}

			model.destroy();
		} while ( true );
	},

	visualSortComparator: function ( item ) {
		if ( ! _.isNull( item.indexes ) ) {
			return item.indexes.builder;
		} else {
			return null;
		}
	},

	visualSort: function(){
		var oldComparator = this.comparator;
		this.comparator = this.visualSortComparator;
		this.sort();
		this.comparator = oldComparator;
	}
} );

},{}],4:[function(require,module,exports){
var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.widget,

	initialize: function () {

	},

	visualSortComparator: function ( item ) {
		if ( ! _.isNull( item.indexes ) ) {
			return item.indexes.builder;
		} else {
			return null;
		}
	},

	visualSort: function(){
		var oldComparator = this.comparator;
		this.comparator = this.visualSortComparator;
		this.sort();
		this.comparator = oldComparator;
	}

} );

},{}],5:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {
	dialogClass: 'so-panels-dialog-add-builder',

	render: function () {
		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-builder' ).html(), {} ) );
		this.$( '.so-content .siteorigin-panels-builder' ).append( this.builder.$el );
	},

	initializeDialog: function () {
		var thisView = this;
		this.once( 'open_dialog_complete', function () {
			thisView.builder.initSortable();
		} );

		this.on( 'open_dialog_complete', function () {
			thisView.builder.trigger( 'builder_resize' );
		} );
	}
} );

},{}],6:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	historyEntryTemplate: _.template( $( '#siteorigin-panels-dialog-history-entry' ).html().panelsProcessTemplate() ),

	entries: {},
	currentEntry: null,
	revertEntry: null,
	selectedEntry: null,

	previewScrollTop: null,

	dialogClass: 'so-panels-dialog-history',

	events: {
		'click .so-close': 'closeDialog',
		'click .so-restore': 'restoreSelectedEntry'
	},

	initializeDialog: function () {
		this.entries = new panels.collection.historyEntries();

		this.on( 'open_dialog', this.setCurrentEntry, this );
		this.on( 'open_dialog', this.renderHistoryEntries, this );
	},

	render: function () {
		var thisView = this;

		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-history' ).html(), {} ) );

		this.$( 'iframe.siteorigin-panels-history-iframe' ).load( function () {
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
		c.find( '.history-entry' ).click( function () {
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
		this.$( 'form.history-form' ).submit();
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

},{}],7:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	directoryTemplate: _.template( $( '#siteorigin-panels-directory-items' ).html().panelsProcessTemplate() ),

	builder: null,
	dialogClass: 'so-panels-dialog-prebuilt-layouts',

	layoutCache: {},
	currentTab: false,
	directoryPage: 1,

	events: {
		'click .so-close': 'closeDialog',
		'click .so-sidebar-tabs li a': 'tabClickHandler',
		'click .so-content .layout': 'layoutClickHandler',
		'keyup .so-sidebar-search': 'searchHandler',

		// The directory items
		'click .so-screenshot, .so-title': 'directoryItemClickHandler'
	},

	/**
	 * Initialize the prebuilt dialog.
	 */
	initializeDialog: function () {
		var thisView = this;

		this.on( 'open_dialog', function () {
			thisView.$( '.so-sidebar-tabs li a' ).first().click();
			thisView.$( '.so-status' ).removeClass( 'so-panels-loading' );
		} );

		this.on( 'button_click', this.toolbarButtonClick, this );
	},

	/**
	 * Render the prebuilt layouts dialog
	 */
	render: function () {
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-prebuilt' ).html(), {} ) );

		this.initToolbar();
	},

	/**
	 *
	 * @param e
	 * @return {boolean}
	 */
	tabClickHandler: function ( e ) {
		e.preventDefault();
		// Reset selected item state when changing tabs
		this.selectedLayoutItem = null;
		this.uploadedLayout = null;
		this.updateButtonState( false );

		this.$( '.so-sidebar-tabs li' ).removeClass( 'tab-active' );

		var $$ = $( e.target );
		var tab = $$.attr( 'href' ).split( '#' )[1];
		$$.parent().addClass( 'tab-active' );

		var thisView = this;

		// Empty everything
		this.$( '.so-content' ).empty();

		thisView.currentTab = tab;
		if ( tab == 'import' ) {
			this.displayImportExport();
		} else {
			this.displayLayoutDirectory( '', 1, tab );
		}

		thisView.$( '.so-sidebar-search' ).val( '' );
	},

	/**
	 * Display and setup the import/export form
	 */
	displayImportExport: function () {
		var c = this.$( '.so-content' ).empty().removeClass( 'so-panels-loading' );
		c.html( $( '#siteorigin-panels-dialog-prebuilt-importexport' ).html() );

		var thisView = this;
		var uploadUi = thisView.$( '.import-upload-ui' ).hide();

		// Create the uploader
		var uploader = new plupload.Uploader( {
			runtimes: 'html5,silverlight,flash,html4',

			browse_button: uploadUi.find( '.file-browse-button' ).get( 0 ),
			container: uploadUi.get( 0 ),
			drop_element: uploadUi.find( '.drag-upload-area' ).get( 0 ),

			file_data_name: 'panels_import_data',
			multiple_queues: false,
			max_file_size: panelsOptions.plupload.max_file_size,
			url: panelsOptions.plupload.url,
			flash_swf_url: panelsOptions.plupload.flash_swf_url,
			silverlight_xap_url: panelsOptions.plupload.silverlight_xap_url,
			filters: [
				{title: panelsOptions.plupload.filter_title, extensions: 'json'}
			],

			multipart_params: {
				action: 'so_panels_import_layout'
			},

			init: {
				PostInit: function ( uploader ) {
					if ( uploader.features.dragdrop ) {
						uploadUi.addClass( 'has-drag-drop' );
					}
					uploadUi.show().find( '.progress-precent' ).css( 'width', '0%' );
				},
				FilesAdded: function ( uploader ) {
					uploadUi.find( '.file-browse-button' ).blur();
					uploadUi.find( '.drag-upload-area' ).removeClass( 'file-dragover' );
					uploadUi.find( '.progress-bar' ).fadeIn( 'fast' );
					thisView.$( '.js-so-selected-file' ).text( panelsOptions.loc.prebuilt_loading );
					uploader.start();
				},
				UploadProgress: function ( uploader, file ) {
					uploadUi.find( '.progress-precent' ).css( 'width', file.percent + '%' );
				},
				FileUploaded: function ( uploader, file, response ) {
					var layout = JSON.parse( response.response );
					if ( ! _.isUndefined( layout.widgets ) ) {

						thisView.uploadedLayout = layout;
						uploadUi.find( '.progress-bar' ).hide();
						thisView.$( '.js-so-selected-file' ).text(
							panelsOptions.loc.ready_to_insert.replace( '%s', file.name )
						);
						thisView.updateButtonState( true );
					} else {
						alert( panelsOptions.plupload.error_message );
					}
				},
				Error: function () {
					alert( panelsOptions.plupload.error_message );
				}
			}
		} );
		uploader.init();

		// This is
		uploadUi.find( '.drag-upload-area' )
			.on( 'dragover', function () {
				$( this ).addClass( 'file-dragover' );
			} )
			.on( 'dragleave', function () {
				$( this ).removeClass( 'file-dragover' );
			} );

		// Handle exporting the file
		c.find( '.so-export' ).submit( function ( e ) {
			var $$ = $( this );
			$$.find( 'input[name="panels_export_data"]' ).val( JSON.stringify( thisView.builder.model.getPanelsData() ) );
		} );

	},

	/**
	 * Display the layout directory tab.
	 *
	 * @param query
	 */
	displayLayoutDirectory: function ( search, page, type ) {
		var thisView = this;
		var c = this.$( '.so-content' ).empty().addClass( 'so-panels-loading' );

		if ( search === undefined ) {
			search = '';
		}
		if ( page === undefined ) {
			page = 1;
		}
		if ( type === undefined ) {
			type = 'directory';
		}

		if ( type === 'directory' && ! panelsOptions.directory_enabled ) {
			// Display the button to enable the prebuilt layout
			c.removeClass( 'so-panels-loading' ).html( $( '#siteorigin-panels-directory-enable' ).html() );
			c.find( '.so-panels-enable-directory' ).click( function ( e ) {
				e.preventDefault();
				// Sent the query to enable the directory, then enable the directory
				$.get(
					panelsOptions.ajaxurl,
					{action: 'so_panels_directory_enable'},
					function () {

					}
				);

				// Enable the layout directory
				panelsOptions.directory_enabled = true;
				c.addClass( 'so-panels-loading' );
				thisView.displayLayoutDirectory( search, page );
			} );
			return;
		}

		// Get all the items for the current query
		$.get(
			panelsOptions.ajaxurl,
			{
				action: 'so_panels_layouts_query',
				search: search,
				page: page,
				type: type,
			},
			function ( data ) {
				// Skip this if we're no longer viewing the layout directory
				if ( thisView.currentTab !== type ) {
					return;
				}

				// Add the directory items
				c.removeClass( 'so-panels-loading' ).html( thisView.directoryTemplate( data ) );

				// Lets setup the next and previous buttons
				var prev = c.find( '.so-previous' ), next = c.find( '.so-next' );

				if ( page <= 1 ) {
					prev.addClass( 'button-disabled' );
				} else {
					prev.click( function ( e ) {
						e.preventDefault();
						thisView.displayLayoutDirectory( search, page - 1, thisView.currentTab );
					} );
				}

				if ( page === data.max_num_pages || data.max_num_pages === 0 ) {
					next.addClass( 'button-disabled' );
				} else {
					next.click( function ( e ) {
						e.preventDefault();
						thisView.displayLayoutDirectory( search, page + 1, thisView.currentTab );
					} );
				}

				// Handle nice preloading of the screenshots
				c.find( '.so-screenshot' ).each( function () {
					var $$ = $( this ), $a = $$.find( '.so-screenshot-wrapper' );
					$a.css( 'height', (
					                  $a.width() / 4 * 3
					                  ) + 'px' ).addClass( 'so-loading' );

					if ( $$.data( 'src' ) !== '' ) {
						// Set the initial height
						var $img = $( '<img/>' ).attr( 'src', $$.data( 'src' ) ).load( function () {
							$a.removeClass( 'so-loading' ).css( 'height', 'auto' );
							$img.appendTo( $a ).hide().fadeIn( 'fast' );
						} );
					} else {
						$( '<img/>' ).attr( 'src', panelsOptions.prebuiltDefaultScreenshot ).appendTo( $a ).hide().fadeIn( 'fast' );
					}

				} );

				// Set the title
				c.find( '.so-directory-browse' ).html( data.title );
			},
			'json'
		);
	},

	/**
	 * Set the selected state for the clicked layout directory item and remove previously selected item.
	 * Enable the toolbar buttons.
	 */
	directoryItemClickHandler: function ( e ) {
		var $directoryItem = this.$( e.target ).closest( '.so-directory-item' );
		this.$( '.so-directory-items' ).find( '.selected' ).removeClass( 'selected' );
		$directoryItem.addClass( 'selected' );
		this.selectedLayoutItem = {lid: $directoryItem.data( 'layout-id' ), type: $directoryItem.data( 'layout-type' )};
		this.updateButtonState( true );

	},

	/**
	 * Load a particular layout into the builder.
	 *
	 * @param id
	 */
	toolbarButtonClick: function ( $button ) {
		if ( ! this.canAddLayout() ) {
			return false;
		}
		var position = $button.data( 'value' );
		if ( _.isUndefined( position ) ) {
			return false;
		}
		this.updateButtonState( false );

		if ( $button.hasClass( 'so-needs-confirm' ) && ! $button.hasClass( 'so-confirmed' ) ) {
			this.updateButtonState( true );
			if ( $button.hasClass( 'so-confirming' ) ) {
				return;
			}
			$button.addClass( 'so-confirming' );
			var originalText = $button.html();
			$button.html( '<span class="dashicons dashicons-yes"></span>' + $button.data( 'confirm' ) );
			setTimeout( function () {
				$button.removeClass( 'so-confirmed' ).html( originalText );
			}, 2500 );
			setTimeout( function () {
				$button.removeClass( 'so-confirming' );
				$button.addClass( 'so-confirmed' );
			}, 200 );
			return false;
		}
		this.addingLayout = true;
		if ( this.currentTab === 'import' ) {
			this.addLayoutToBuilder( this.uploadedLayout, position );
		} else {
			this.loadSelectedLayout().then( function ( layout ) {
				this.addLayoutToBuilder( layout, position );
			}.bind( this ) );
		}
	},

	canAddLayout: function () {
		return (
		       this.selectedLayoutItem || this.uploadedLayout
		       ) && ! this.addingLayout;
	},

	/**
	 * Load the layout according to selectedLayoutItem.
	 */
	loadSelectedLayout: function () {
		this.setStatusMessage( panelsOptions.loc.prebuilt_loading, true );

		var args = _.extend( this.selectedLayoutItem, {action: 'so_panels_get_layout'} );
		var deferredLayout = new $.Deferred();

		$.get(
			panelsOptions.ajaxurl,
			args,
			function ( layout ) {
				if ( layout.error !== undefined ) {
					// There was an error
					alert( layout.error );
					deferredLayout.reject( layout );
				} else {
					this.setStatusMessage( '', false );
					deferredLayout.resolve( layout );
				}
			}.bind( this )
		);
		return deferredLayout.promise();
	},

	/**
	 * Handle an update to the search
	 */
	searchHandler: function ( e ) {
		if ( e.keyCode === 13 ) {
			this.displayLayoutDirectory( $( e.currentTarget ).val(), 1, this.currentTab );
		}
	},

	/**
	 * Attempt to set the 'Insert' button's state according to the `enabled` argument, also checking whether the
	 * requirements for inserting a layout have valid values.
	 */
	updateButtonState: function ( enabled ) {
		enabled = enabled && (
			this.selectedLayoutItem || this.uploadedLayout
			);
		var $button = this.$( '.so-import-layout' );
		$button.prop( "disabled", ! enabled );
		if ( enabled ) {
			$button.removeClass( 'disabled' );
		} else {
			$button.addClass( 'disabled' );
		}
	},

	addLayoutToBuilder: function ( layout, position ) {
		this.builder.addHistoryEntry( 'prebuilt_loaded' );
		this.builder.model.loadPanelsData( layout, position );
		this.addingLayout = false;
		this.closeDialog();
	}
} );

},{}],8:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	cellPreviewTemplate: _.template( $( '#siteorigin-panels-dialog-row-cell-preview' ).html().panelsProcessTemplate() ),

	events: {
		'click .so-close': 'closeDialog',

		// Toolbar buttons
		'click .so-toolbar .so-save': 'saveHandler',
		'click .so-toolbar .so-insert': 'insertHandler',
		'click .so-toolbar .so-delete': 'deleteHandler',
		'click .so-toolbar .so-duplicate': 'duplicateHandler',

		// Changing the row
		'change .row-set-form > *': 'setCellsFromForm',
		'click .row-set-form button.set-row': 'setCellsFromForm'
	},

	dialogClass: 'so-panels-dialog-row-edit',
	styleType: 'row',

	dialogType: 'edit',

	/**
	 * The current settings, not yet saved to the model
	 */
	row: {
		// This is just the cell weights, cell content is not edited by this dialog
		cells: [],
		// The style settings of the row
		style: {}
	},

	initializeDialog: function () {
		this.on( 'open_dialog', function () {
			if ( ! _.isUndefined( this.model ) && ! _.isEmpty( this.model.cells ) ) {
				this.setRowModel( this.model );
			} else {
				this.setRowModel( null );
			}

			this.regenerateRowPreview();
		}, this );

		// This is the default row layout
		this.row = {
			cells: [0.5, 0.5],
			style: {}
		};

		// Refresh panels data after both dialog form components are loaded
		this.dialogFormsLoaded = 0;
		var thisView = this;
		this.on( 'form_loaded styles_loaded', function () {
			this.dialogFormsLoaded ++;
			if ( this.dialogFormsLoaded === 2 ) {
				thisView.updateModel( {
					refreshArgs: {
						silent: true
					}
				} );
			}
		} );
	},

	/**
	 *
	 * @param dialogType Either "edit" or "create"
	 */
	setRowDialogType: function ( dialogType ) {
		this.dialogType = dialogType;
	},

	/**
	 * Render the new row dialog
	 */
	render: function ( dialogType ) {
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-row' ).html(), {dialogType: this.dialogType} ) );

		if ( this.dialogType === 'edit' ) {
			// Now we need to attach the style window
			this.styles = new panels.view.styles();
			this.styles.model = this.model;
			this.styles.render( 'row', $( '#post_ID' ).val(), {
				builderType: this.builder.config.builderType,
				dialog: this
			} );

			if( ! this.builder.supports( 'addRow' ) ) {
				this.$( '.so-buttons .so-duplicate' ).remove();
			}
			if( ! this.builder.supports( 'deleteRow' ) ) {
				this.$( '.so-buttons .so-delete' ).remove();
			}

			var $rightSidebar = this.$( '.so-sidebar.so-right-sidebar' );
			this.styles.attach( $rightSidebar );

			// Handle the loading class
			this.styles.on( 'styles_loaded', function ( hasStyles ) {
				// If we have styles remove the loading spinner, else remove the whole empty sidebar.
				if ( hasStyles ) {
					$rightSidebar.removeClass( 'so-panels-loading' );
				} else {
					$rightSidebar.closest( '.so-panels-dialog' ).removeClass( 'so-panels-dialog-has-right-sidebar' );
					$rightSidebar.remove();
				}
			}, this );
			$rightSidebar.addClass( 'so-panels-loading' );
		}

		if ( ! _.isUndefined( this.model ) ) {
			// Set the initial value of the
			this.$( 'input.so-row-field' ).val( this.model.cells.length );
		}

		var thisView = this;
		this.$( 'input.so-row-field' ).keyup( function () {
			$( this ).trigger( 'change' );
		} );

		return this;
	},

	/**
	 * Set the row model we'll be using for this dialog.
	 *
	 * @param model
	 */
	setRowModel: function ( model ) {
		this.model = model;

		if ( _.isEmpty( this.model ) ) {
			return this;
		}

		// Set the rows to be a copy of the model
		this.row = {
			cells: this.model.cells.map( function ( cell ) {
				return cell.get( 'weight' );
			} ),
			style: {}
		};

		// Set the initial value of the cell field.
		this.$( 'input.so-row-field' ).val( this.model.cells.length );

		return this;
	},

	/**
	 * Regenerate the row preview and resizing interface.
	 */
	regenerateRowPreview: function () {
		var thisDialog = this;
		var rowPreview = this.$( '.row-preview' );

		rowPreview.empty();

		var timeout;

		// Represent the cells
		_.each( this.row.cells, function ( cell, i ) {
			var newCell = $( this.cellPreviewTemplate( {weight: cell} ) );
			rowPreview.append( newCell );

			var prevCell = newCell.prev();
			var handle;

			if ( prevCell.length ) {
				handle = $( '<div class="resize-handle"></div>' );
				handle
					.appendTo( newCell )
					.dblclick( function () {
						var t = thisDialog.row.cells[i] + thisDialog.row.cells[i - 1];
						thisDialog.row.cells[i] = thisDialog.row.cells[i - 1] = t / 2;
						thisDialog.scaleRowWidths();
					} );

				handle.draggable( {
					axis: 'x',
					containment: rowPreview,
					start: function ( e, ui ) {

						// Create the clone for the current cell
						var newCellClone = newCell.clone().appendTo( ui.helper ).css( {
							position: 'absolute',
							top: '0',
							width: newCell.outerWidth(),
							left: 6,
							height: newCell.outerHeight()
						} );
						newCellClone.find( '.resize-handle' ).remove();

						// Create the clone for the previous cell
						var prevCellClone = prevCell.clone().appendTo( ui.helper ).css( {
							position: 'absolute',
							top: '0',
							width: prevCell.outerWidth(),
							right: 6,
							height: prevCell.outerHeight()
						} );
						prevCellClone.find( '.resize-handle' ).remove();

						$( this ).data( {
							'newCellClone': newCellClone,
							'prevCellClone': prevCellClone
						} );

						// Hide the
						newCell.find( '> .preview-cell-in' ).css( 'visibility', 'hidden' );
						prevCell.find( '> .preview-cell-in' ).css( 'visibility', 'hidden' );
					},
					drag: function ( e, ui ) {
						// Calculate the new cell and previous cell widths as a percent
						var ncw = thisDialog.row.cells[i] - (
							(
							ui.position.left + 6
							) / rowPreview.width()
							);
						var pcw = thisDialog.row.cells[i - 1] + (
							(
							ui.position.left + 6
							) / rowPreview.width()
							);

						var helperLeft = ui.helper.offset().left - rowPreview.offset().left - 6;

						$( this ).data( 'newCellClone' ).css( 'width', rowPreview.width() * ncw )
							.find( '.preview-cell-weight' ).html( Math.round( ncw * 1000 ) / 10 );

						$( this ).data( 'prevCellClone' ).css( 'width', rowPreview.width() * pcw )
							.find( '.preview-cell-weight' ).html( Math.round( pcw * 1000 ) / 10 );
					},
					stop: function ( e, ui ) {
						// Remove the clones
						$( this ).data( 'newCellClone' ).remove();
						$( this ).data( 'prevCellClone' ).remove();

						// Reshow the main cells
						newCell.find( '.preview-cell-in' ).css( 'visibility', 'visible' );
						prevCell.find( '.preview-cell-in' ).css( 'visibility', 'visible' );

						// Calculate the new cell weights
						var offset = ui.position.left + 6;
						var percent = offset / rowPreview.width();

						// Ignore this if any of the cells are below 2% in width.
						if ( thisDialog.row.cells[i] - percent > 0.02 && thisDialog.row.cells[i - 1] + percent > 0.02 ) {
							thisDialog.row.cells[i] -= percent;
							thisDialog.row.cells[i - 1] += percent;
						}

						thisDialog.scaleRowWidths();
						ui.helper.css( 'left', - 6 );
					}
				} );
			}

			// Make this row weight click editable
			newCell.find( '.preview-cell-weight' ).click( function ( ci ) {

				// Disable the draggable while entering values
				thisDialog.$( '.resize-handle' ).css( 'pointer-event', 'none' ).draggable( 'disable' );

				rowPreview.find( '.preview-cell-weight' ).each( function () {
					var $$ = jQuery( this ).hide();
					$( '<input type="text" class="preview-cell-weight-input no-user-interacted" />' )
						.val( parseFloat( $$.html() ) ).insertAfter( $$ )
						.focus( function () {
							clearTimeout( timeout );
						} )
						.keyup( function ( e ) {
							if ( e.keyCode !== 9 ) {
								// Only register the interaction if the user didn't press tab
								$( this ).removeClass( 'no-user-interacted' );
							}

							// Enter is clicked
							if ( e.keyCode === 13 ) {
								e.preventDefault();
								$( this ).blur();
							}
						} )
						.keydown( function ( e ) {
							if ( e.keyCode === 9 ) {
								e.preventDefault();

								// Tab will always cycle around the row inputs
								var inputs = rowPreview.find( '.preview-cell-weight-input' );
								var i = inputs.index( $( this ) );
								if ( i === inputs.length - 1 ) {
									inputs.eq( 0 ).focus().select();
								} else {
									inputs.eq( i + 1 ).focus().select();
								}
							}
						} )
						.blur( function () {
							rowPreview.find( '.preview-cell-weight-input' ).each( function ( i, el ) {
								if ( isNaN( parseFloat( $( el ).val() ) ) ) {
									$( el ).val( Math.floor( thisDialog.row.cells[i] * 1000 ) / 10 );
								}
							} );

							timeout = setTimeout( function () {
								// If there are no weight inputs, then skip this
								if ( rowPreview.find( '.preview-cell-weight-input' ).legnth === 0 ) {
									return false;
								}

								// Go through all the inputs
								var rowWeights = [],
									rowChanged = [],
									changedSum = 0,
									unchangedSum = 0;

								rowPreview.find( '.preview-cell-weight-input' ).each( function ( i, el ) {
									var val = parseFloat( $( el ).val() );
									if ( isNaN( val ) ) {
										val = 1 / thisDialog.row.cells.length;
									} else {
										val = Math.round( val * 10 ) / 1000;
									}

									// Check within 3 decimal points
									var changed = ! $( el ).hasClass( 'no-user-interacted' );

									rowWeights.push( val );
									rowChanged.push( changed );

									if ( changed ) {
										changedSum += val;
									} else {
										unchangedSum += val;
									}
								} );

								if ( changedSum > 0 && unchangedSum > 0 && (
								                                           1 - changedSum
								                                           ) > 0 ) {
									// Balance out the unchanged rows to occupy the weight left over by the changed sum
									for ( var i = 0; i < rowWeights.length; i ++ ) {
										if ( ! rowChanged[i] ) {
											rowWeights[i] = (
											                rowWeights[i] / unchangedSum
											                ) * (
											                1 - changedSum
											                );
										}
									}
								}

								// Last check to ensure total weight is 1
								var sum = _.reduce( rowWeights, function ( memo, num ) {
									return memo + num;
								} );
								rowWeights = rowWeights.map( function ( w ) {
									return w / sum;
								} );

								// Set the new cell weights and regenerate the preview.
								if ( Math.min.apply( Math, rowWeights ) > 0.01 ) {
									thisDialog.row.cells = rowWeights;
								}

								// Now lets animate the cells into their new widths
								rowPreview.find( '.preview-cell' ).each( function ( i, el ) {
									$( el ).animate( {'width': Math.round( thisDialog.row.cells[i] * 1000 ) / 10 + "%"}, 250 );
									$( el ).find( '.preview-cell-weight-input' ).val( Math.round( thisDialog.row.cells[i] * 1000 ) / 10 );
								} );

								// So the draggable handle is not hidden.
								rowPreview.find( '.preview-cell' ).css( 'overflow', 'visible' );

								setTimeout( function () {
									thisDialog.regenerateRowPreview();
								}, 260 );

							}, 100 );
						} )
						.click( function () {
							$( this ).select();
						} );
				} );

				$( this ).siblings( '.preview-cell-weight-input' ).select();

			} );

		}, this );

		this.trigger( 'form_loaded', this );
	},

	/**
	 * Visually scale the row widths based on the cell weights
	 */
	scaleRowWidths: function () {
		var thisDialog = this;
		this.$( '.row-preview .preview-cell' ).each( function ( i, el ) {
			$( el )
				.css( 'width', thisDialog.row.cells[i] * 100 + "%" )
				.find( '.preview-cell-weight' ).html( Math.round( thisDialog.row.cells[i] * 1000 ) / 10 );
		} );
	},

	/**
	 * Get the weights from the
	 */
	setCellsFromForm: function () {

		try {
			var f = {
				'cells': parseInt( this.$( '.row-set-form input[name="cells"]' ).val() ),
				'ratio': parseFloat( this.$( '.row-set-form select[name="ratio"]' ).val() ),
				'direction': this.$( '.row-set-form select[name="ratio_direction"]' ).val()
			};

			if ( _.isNaN( f.cells ) ) {
				f.cells = 1;
			}
			if ( isNaN( f.ratio ) ) {
				f.ratio = 1;
			}
			if ( f.cells < 1 ) {
				f.cells = 1;
				this.$( '.row-set-form input[name="cells"]' ).val( f.cells );
			}
			else if ( f.cells > 10 ) {
				f.cells = 10;
				this.$( '.row-set-form input[name="cells"]' ).val( f.cells );
			}

			this.$( '.row-set-form input[name="ratio"]' ).val( f.ratio );

			var cells = [];
			var cellCountChanged = (
				this.row.cells.length !== f.cells
			);

			// Now, lets create some cells
			var currentWeight = 1;
			for ( var i = 0; i < f.cells; i ++ ) {
				cells.push( currentWeight );
				currentWeight *= f.ratio;
			}

			// Now lets make sure that the row weights add up to 1

			var totalRowWeight = _.reduce( cells, function ( memo, weight ) {
				return memo + weight;
			} );
			cells = _.map( cells, function ( cell ) {
				return cell / totalRowWeight;
			} );

			// Don't return cells that are too small
			cells = _.filter( cells, function ( cell ) {
				return cell > 0.01;
			} );

			if ( f.direction === 'left' ) {
				cells = cells.reverse();
			}

			this.row.cells = cells;

			if ( cellCountChanged ) {
				this.regenerateRowPreview();
			} else {
				var thisDialog = this;

				// Now lets animate the cells into their new widths
				this.$( '.preview-cell' ).each( function ( i, el ) {
					$( el ).animate( {'width': Math.round( thisDialog.row.cells[i] * 1000 ) / 10 + "%"}, 250 );
					$( el ).find( '.preview-cell-weight' ).html( Math.round( thisDialog.row.cells[i] * 1000 ) / 10 );
				} );

				// So the draggable handle is not hidden.
				this.$( '.preview-cell' ).css( 'overflow', 'visible' );

				setTimeout( function () {
					thisDialog.regenerateRowPreview();
				}, 260 );
			}
		}
		catch (err) {
			console.log( 'Error setting cells - ' + err.message );
		}


		// Remove the button primary class
		this.$( '.row-set-form .so-button-row-set' ).removeClass( 'button-primary' );
	},

	/**
	 * Handle a click on the dialog left bar tab
	 */
	tabClickHandler: function ( $t ) {
		if ( $t.attr( 'href' ) === '#row-layout' ) {
			this.$( '.so-panels-dialog' ).addClass( 'so-panels-dialog-has-right-sidebar' );
		} else {
			this.$( '.so-panels-dialog' ).removeClass( 'so-panels-dialog-has-right-sidebar' );
		}
	},

	/**
	 * Update the current model with what we have in the dialog
	 */
	updateModel: function ( args ) {
		args = _.extend( {
			refresh: true,
			refreshArgs: null
		}, args );

		// Set the cells
		if( ! _.isEmpty( this.model ) ) {
			this.model.setCells( this.row.cells );
		}

		// Update the styles if they've loaded
		if ( ! _.isUndefined( this.styles ) && this.styles.stylesLoaded ) {
			// This is an edit dialog, so there are styles
			var style = {};
			try {
				style = this.getFormValues( '.so-sidebar .so-visual-styles' ).style;
			}
			catch ( err ) {
				console.log( 'Error retrieving styles - ' + err.message );
			}

			this.model.set( 'style', style );
		}

		if ( args.refresh ) {
			this.builder.model.refreshPanelsData( args.refreshArgs );
		}
	},

	/**
	 * Insert the new row
	 */
	insertHandler: function () {
		this.builder.addHistoryEntry( 'row_added' );

		this.model = new panels.model.row();
		this.updateModel();

		var activeCell = this.builder.getActiveCell( {
			createCell: false,
			defaultPosition: 'last'
		} );

		var options = {};
		if ( activeCell !== null ) {
			options.at = this.builder.model.rows.indexOf( activeCell.row ) + 1;
		}

		// Set up the model and add it to the builder
		this.model.collection = this.builder.model.rows;
		this.builder.model.rows.add( this.model, options );

		this.closeDialog();

		this.builder.model.refreshPanelsData();

		return false;
	},

	/**
	 * We'll just save this model and close the dialog
	 */
	saveHandler: function () {
		this.builder.addHistoryEntry( 'row_edited' );
		this.updateModel();
		this.closeDialog();

		this.builder.model.refreshPanelsData();

		return false;
	},

	/**
	 * The user clicks delete, so trigger deletion on the row model
	 */
	deleteHandler: function () {
		// Trigger a destroy on the model that will happen with a visual indication to the user
		this.model.trigger( 'visual_destroy' );
		this.closeDialog( {silent: true} );

		return false;
	},

	/**
	 * Duplicate this row
	 */
	duplicateHandler: function () {
		this.builder.addHistoryEntry( 'row_duplicated' );

		var duplicateRow = this.model.clone( this.builder.model );

		this.builder.model.rows.add( duplicateRow, {
			at: this.builder.model.rows.indexOf( this.model ) + 1
		} );

		this.closeDialog( {silent: true} );

		return false;
	}

} );

},{}],9:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	builder: null,
	sidebarWidgetTemplate: _.template( $( '#siteorigin-panels-dialog-widget-sidebar-widget' ).html().panelsProcessTemplate() ),
	dialogClass: 'so-panels-dialog-edit-widget',
	widgetView: false,
	savingWidget: false,

	events: {
		'click .so-close': 'saveHandler',
		'click .so-nav.so-previous': 'navToPrevious',
		'click .so-nav.so-next': 'navToNext',

		// Action handlers
		'click .so-toolbar .so-delete': 'deleteHandler',
		'click .so-toolbar .so-duplicate': 'duplicateHandler'
	},

	initializeDialog: function () {
		var thisView = this;
		this.model.on( 'change:values', this.handleChangeValues, this );
		this.model.on( 'destroy', this.remove, this );

		// Refresh panels data after both dialog form components are loaded
		this.dialogFormsLoaded = 0;
		this.on( 'form_loaded styles_loaded', function () {
			this.dialogFormsLoaded ++;
			if ( this.dialogFormsLoaded === 2 ) {
				thisView.updateModel( {
					refreshArgs: {
						silent: true
					}
				} );
			}
		} );
	},

	/**
	 * Render the widget dialog.
	 */
	render: function () {
		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-widget' ).html(), {} ) );
		this.loadForm();

		if ( ! _.isUndefined( panelsOptions.widgets[this.model.get( 'class' )] ) ) {
			this.$( '.so-title .widget-name' ).html( panelsOptions.widgets[this.model.get( 'class' )].title );
		} else {
			this.$( '.so-title .widget-name' ).html( panelsOptions.loc.missing_widget.title );
		}

		if( ! this.builder.supports( 'addWidget' ) ) {
			this.$( '.so-buttons .so-duplicate' ).remove();
		}
		if( ! this.builder.supports( 'deleteWidget' ) ) {
			this.$( '.so-buttons .so-delete' ).remove();
		}

		// Now we need to attach the style window
		this.styles = new panels.view.styles();
		this.styles.model = this.model;
		this.styles.render( 'widget', $( '#post_ID' ).val(), {
			builderType: this.builder.config.builderType,
			dialog: this
		} );

		var $rightSidebar = this.$( '.so-sidebar.so-right-sidebar' );
		this.styles.attach( $rightSidebar );

		// Handle the loading class
		this.styles.on( 'styles_loaded', function ( hasStyles ) {
			// If we have styles remove the loading spinner, else remove the whole empty sidebar.
			if ( hasStyles ) {
				$rightSidebar.removeClass( 'so-panels-loading' );
			} else {
				$rightSidebar.closest( '.so-panels-dialog' ).removeClass( 'so-panels-dialog-has-right-sidebar' );
				$rightSidebar.remove();
			}
		}, this );
		$rightSidebar.addClass( 'so-panels-loading' );
	},

	/**
	 * Get the previous widget editing dialog by looking at the dom.
	 * @returns {*}
	 */
	getPrevDialog: function () {
		var widgets = this.builder.$( '.so-cells .cell .so-widget' );
		if ( widgets.length <= 1 ) {
			return false;
		}
		var currentIndex = widgets.index( this.widgetView.$el );

		if ( currentIndex === 0 ) {
			return false;
		} else {
			do {
				widgetView = widgets.eq( --currentIndex ).data( 'view' );
				if ( ! _.isUndefined( widgetView ) && ! widgetView.model.get( 'read_only' ) ) {
					return widgetView.getEditDialog();
				}
			} while( ! _.isUndefined( widgetView ) && currentIndex > 0 );
		}

		return false;
	},

	/**
	 * Get the next widget editing dialog by looking at the dom.
	 * @returns {*}
	 */
	getNextDialog: function () {
		var widgets = this.builder.$( '.so-cells .cell .so-widget' );
		if ( widgets.length <= 1 ) {
			return false;
		}

		var currentIndex = widgets.index( this.widgetView.$el ), widgetView;

		if ( currentIndex === widgets.length - 1 ) {
			return false;
		} else {
			do {
				widgetView = widgets.eq( ++currentIndex ).data( 'view' );
				if ( ! _.isUndefined( widgetView ) && ! widgetView.model.get( 'read_only' ) ) {
					return widgetView.getEditDialog();
				}
			} while( ! _.isUndefined( widgetView ) );
		}

		return false;
	},

	/**
	 * Load the widget form from the server.
	 * This is called when rendering the dialog for the first time.
	 */
	loadForm: function () {
		// don't load the form if this dialog hasn't been rendered yet
		if ( ! this.$( '> *' ).length ) {
			return;
		}

		var thisView = this;
		this.$( '.so-content' ).addClass( 'so-panels-loading' );

		var data = {
			'action': 'so_panels_widget_form',
			'widget': this.model.get( 'class' ),
			'instance': JSON.stringify( this.model.get( 'values' ) ),
			'raw': this.model.get( 'raw' )
		};

		$.post(
			panelsOptions.ajaxurl,
			data,
			function ( result ) {
				// Add in the CID of the widget model
				var html = result.replace( /{\$id}/g, thisView.model.cid );

				// Load this content into the form
				thisView.$( '.so-content' )
					.removeClass( 'so-panels-loading' )
					.html( html );

				// Trigger all the necessary events
				thisView.trigger( 'form_loaded', thisView );

				// For legacy compatibility, trigger a panelsopen event
				thisView.$( '.panel-dialog' ).trigger( 'panelsopen' );

				// If the main dialog is closed from this point on, save the widget content
				thisView.on( 'close_dialog', thisView.updateModel, thisView );
			},
			'html'
		);
	},

	/**
	 * Save the widget from the form to the model
	 */
	updateModel: function ( args ) {
		args = _.extend( {
			refresh: true,
			refreshArgs: null
		}, args );

		// Get the values from the form and assign the new values to the model
		this.savingWidget = true;

		if ( ! this.model.get( 'missing' ) ) {
			// Only get the values for non missing widgets.
			var values = this.getFormValues();
			if ( _.isUndefined( values.widgets ) ) {
				values = {};
			} else {
				values = values.widgets;
				values = values[Object.keys( values )[0]];
			}

			this.model.setValues( values );
			this.model.set( 'raw', true ); // We've saved from the widget form, so this is now raw
		}

		if ( this.styles.stylesLoaded ) {
			// If the styles view has loaded
			var style = {};
			try {
				style = this.getFormValues( '.so-sidebar .so-visual-styles' ).style;
			}
			catch ( e ) {
			}
			this.model.set( 'style', style );
		}

		this.savingWidget = false;

		if ( args.refresh ) {
			this.builder.model.refreshPanelsData( args.refreshArgs );
		}
	},

	/**
	 *
	 */
	handleChangeValues: function () {
		if ( ! this.savingWidget ) {
			// Reload the form when we've changed the model and we're not currently saving from the form
			this.loadForm();
		}
	},

	/**
	 * Save a history entry for this widget. Called when the dialog is closed.
	 */
	saveHandler: function () {
		this.builder.addHistoryEntry( 'widget_edited' );
		this.closeDialog();
	},

	/**
	 * When the user clicks delete.
	 *
	 * @returns {boolean}
	 */
	deleteHandler: function () {

		this.model.trigger( 'visual_destroy' );
		this.closeDialog( {silent: true} );
		this.builder.model.refreshPanelsData();

		return false;
	},

	duplicateHandler: function () {
		this.model.trigger( 'user_duplicate' );

		this.closeDialog( {silent: true} );
		this.builder.model.refreshPanelsData();

		return false;
	}

} );

},{}],10:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	builder: null,
	widgetTemplate: _.template( $( '#siteorigin-panels-dialog-widgets-widget' ).html().panelsProcessTemplate() ),
	filter: {},

	dialogClass: 'so-panels-dialog-add-widget',

	events: {
		'click .so-close': 'closeDialog',
		'click .widget-type': 'widgetClickHandler',
		'keyup .so-sidebar-search': 'searchHandler'
	},

	/**
	 * Initialize the widget adding dialog
	 */
	initializeDialog: function () {

		this.on( 'open_dialog', function () {
			this.filter.search = '';
			this.filterWidgets( this.filter );
		}, this );

		this.on( 'open_dialog_complete', function () {
			// Clear the search and re-filter the widgets when we open the dialog
			this.$( '.so-sidebar-search' ).val( '' ).focus();
			this.balanceWidgetHeights();
		} );

		// We'll implement a custom tab click handler
		this.on( 'tab_click', this.tabClickHandler, this );
	},

	render: function () {
		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-widgets' ).html(), {} ) );

		// Add all the widgets
		_.each( panelsOptions.widgets, function ( widget ) {
			var $w = $( this.widgetTemplate( {
				title: widget.title,
				description: widget.description
			} ) );

			if ( _.isUndefined( widget.icon ) ) {
				widget.icon = 'dashicons dashicons-admin-generic';
			}

			$( '<span class="widget-icon" />' ).addClass( widget.icon ).prependTo( $w.find( '.widget-type-wrapper' ) );

			$w.data( 'class', widget.class ).appendTo( this.$( '.widget-type-list' ) );
		}, this );

		// Add the sidebar tabs
		var tabs = this.$( '.so-sidebar-tabs' );
		_.each( panelsOptions.widget_dialog_tabs, function ( tab ) {
			$( this.dialogTabTemplate( {'title': tab.title} ) ).data( {
				'message': tab.message,
				'filter': tab.filter
			} ).appendTo( tabs );
		}, this );

		// We'll be using tabs, so initialize them
		this.initTabs();

		var thisDialog = this;
		$( window ).resize( function () {
			thisDialog.balanceWidgetHeights();
		} );
	},

	/**
	 * Handle a tab being clicked
	 */
	tabClickHandler: function ( $t ) {
		// Get the filter from the tab, and filter the widgets
		this.filter = $t.parent().data( 'filter' );
		this.filter.search = this.$( '.so-sidebar-search' ).val();

		var message = $t.parent().data( 'message' );
		if ( _.isEmpty( message ) ) {
			message = '';
		}

		this.$( '.so-toolbar .so-status' ).html( message );

		this.filterWidgets( this.filter );

		return false;
	},

	/**
	 * Handle changes to the search value
	 */
	searchHandler: function ( e ) {
		this.filter.search = $( e.target ).val();
		this.filterWidgets( this.filter );
	},

	/**
	 * Filter the widgets that we're displaying
	 * @param filter
	 */
	filterWidgets: function ( filter ) {
		if ( _.isUndefined( filter ) ) {
			filter = {};
		}

		if ( _.isUndefined( filter.groups ) ) {
			filter.groups = '';
		}

		this.$( '.widget-type-list .widget-type' ).each( function () {
			var $$ = $( this ), showWidget;
			var widgetClass = $$.data( 'class' );

			var widgetData = (
				! _.isUndefined( panelsOptions.widgets[widgetClass] )
			) ? panelsOptions.widgets[widgetClass] : null;

			if ( _.isEmpty( filter.groups ) ) {
				// This filter doesn't specify groups, so show all
				showWidget = true;
			} else if ( widgetData !== null && ! _.isEmpty( _.intersection( filter.groups, panelsOptions.widgets[widgetClass].groups ) ) ) {
				// This widget is in the filter group
				showWidget = true;
			} else {
				// This widget is not in the filter group
				showWidget = false;
			}

			// This can probably be done with a more intelligent operator
			if ( showWidget ) {

				if ( ! _.isUndefined( filter.search ) && filter.search !== '' ) {
					// Check if the widget title contains the search term
					if ( widgetData.title.toLowerCase().indexOf( filter.search.toLowerCase() ) === - 1 ) {
						showWidget = false;
					}
				}

			}

			if ( showWidget ) {
				$$.show();
			} else {
				$$.hide();
			}
		} );

		// Balance the tags after filtering
		this.balanceWidgetHeights();
	},

	/**
	 * Add the widget to the current builder
	 *
	 * @param e
	 */
	widgetClickHandler: function ( e ) {
		// Add the history entry
		this.builder.addHistoryEntry( 'widget_added' );

		var $w = $( e.currentTarget );

		var widget = new panels.model.widget( {
			class: $w.data( 'class' )
		} );

		// Add the widget to the cell model
		widget.cell = this.builder.getActiveCell();
		widget.cell.widgets.add( widget );

		this.closeDialog();
		this.builder.model.refreshPanelsData();
	},

	/**
	 * Balance widgets in a given row so they have enqual height.
	 * @param e
	 */
	balanceWidgetHeights: function ( e ) {
		var widgetRows = [[]];
		var previousWidget = null;

		// Work out how many widgets there are per row
		var perRow = Math.round( this.$( '.widget-type' ).parent().width() / this.$( '.widget-type' ).width() );

		// Add clears to create balanced rows
		this.$( '.widget-type' )
			.css( 'clear', 'none' )
			.filter( ':visible' )
			.each( function ( i, el ) {
				if ( i % perRow === 0 && i !== 0 ) {
					$( el ).css( 'clear', 'both' );
				}
			} );

		// Group the widgets into rows
		this.$( '.widget-type-wrapper' )
			.css( 'height', 'auto' )
			.filter( ':visible' )
			.each( function ( i, el ) {
				var $el = $( el );
				if ( previousWidget !== null && previousWidget.position().top !== $el.position().top ) {
					widgetRows[widgetRows.length] = [];
				}
				previousWidget = $el;
				widgetRows[widgetRows.length - 1].push( $el );
			} );

		// Balance the height of the widgets within the row.
		_.each( widgetRows, function ( row, i ) {
			var maxHeight = _.max( row.map( function ( el ) {
				return el.height();
			} ) );
			// Set the height of each widget in the row
			_.each( row, function ( el ) {
				el.height( maxHeight );
			} );

		} );
	}
} );

},{}],11:[function(require,module,exports){
/* global _, jQuery, panels */

var panels = window.panels, $ = jQuery;

module.exports = function ( config ) {

	return this.each( function () {
		var $$ = jQuery( this );
		var widgetId = $$.closest( 'form' ).find( '.widget-id' ).val();

		// Create a config for this specific widget
		var thisConfig = $.extend(true, {}, config);

		// Exit if this isn't a real widget
		if ( ! _.isUndefined( widgetId ) && widgetId.indexOf( '__i__' ) > - 1 ) {
			return;
		}

		// Create the main builder model
		var builderModel = new panels.model.builder();

		// Now for the view to display the builder
		var builderView = new panels.view.builder( {
			model: builderModel,
			config: thisConfig
		} );

		// Save panels data when we close the dialog, if we're in a dialog
		var dialog = $$.closest( '.so-panels-dialog-wrapper' ).data( 'view' );
		if ( ! _.isUndefined( dialog ) ) {
			dialog.on( 'close_dialog', function () {
				builderModel.refreshPanelsData();
			} );

			dialog.on( 'open_dialog_complete', function () {
				// Make sure the new layout widget is always properly setup
				builderView.trigger( 'builder_resize' );
			} );

			dialog.model.on( 'destroy', function () {
				// Destroy the builder
				builderModel.emptyRows().destroy();
			} );

			// Set the parent for all the sub dialogs
			builderView.setDialogParents( panelsOptions.loc.layout_widget, dialog );
		}

		// Basic setup for the builder
		var isWidget = Boolean( $$.closest( '.widget-content' ).length );
		builderView
			.render()
			.attach( {
				container: $$,
				dialog: isWidget || $$.data('mode') === 'dialog',
				type: $$.data( 'type' )
			} )
			.setDataField( $$.find( 'input.panels-data' ) );

		if ( isWidget || $$.data('mode') === 'dialog' ) {
			// Set up the dialog opening
			builderView.setDialogParents( panelsOptions.loc.layout_widget, builderView.dialog );
			$$.find( '.siteorigin-panels-display-builder' ).click( function ( e ) {
				e.preventDefault();
				builderView.dialog.openDialog();
			} );
		} else {
			// Remove the dialog opener button, this is already being displayed in a page builder dialog.
			$$.find( '.siteorigin-panels-display-builder' ).parent().remove();
		}

		// Trigger a global jQuery event after we've setup the builder view
		$( document ).trigger( 'panels_setup', builderView );
	} );
};

},{}],12:[function(require,module,exports){
/**
 * Everything we need for SiteOrigin Page Builder.
 *
 * @copyright Greg Priday 2013 - 2016 - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

/* global Backbone, _, jQuery, tinyMCE, panelsOptions, plupload, confirm, console, require */

/**
 * Convert template into something compatible with Underscore.js templates
 *
 * @param s
 * @return {*}
 */
String.prototype.panelsProcessTemplate = function () {
	var s = this;
	s = s.replace( /{{%/g, '<%' );
	s = s.replace( /%}}/g, '%>' );
	s = s.trim();
	return s;
};

var panels = {};

// Store everything globally
window.panels = panels;
window.siteoriginPanels = panels;

// The models
panels.model = {};
panels.model.widget = require( './model/widget' );
panels.model.cell = require( './model/cell' );
panels.model.row = require( './model/row' );
panels.model.builder = require( './model/builder' );
panels.model.historyEntry = require( './model/history-entry' );

// The collections
panels.collection = {};
panels.collection.widgets = require( './collection/widgets' );
panels.collection.cells = require( './collection/cells' );
panels.collection.rows = require( './collection/rows' );
panels.collection.historyEntries = require( './collection/history-entries' );

// The views
panels.view = {};
panels.view.widget = require( './view/widget' );
panels.view.cell = require( './view/cell' );
panels.view.row = require( './view/row' );
panels.view.builder = require( './view/builder' );
panels.view.dialog = require( './view/dialog' );
panels.view.styles = require( './view/styles' );
panels.view.liveEditor = require( './view/live-editor' );

// The dialogs
panels.dialog = {};
panels.dialog.builder = require( './dialog/builder' );
panels.dialog.widgets = require( './dialog/widgets' );
panels.dialog.widget = require( './dialog/widget' );
panels.dialog.prebuilt = require( './dialog/prebuilt' );
panels.dialog.row = require( './dialog/row' );
panels.dialog.history = require( './dialog/history' );

// The utils
panels.utils = {};
panels.utils.menu = require( './utils/menu' );

// jQuery Plugins
jQuery.fn.soPanelsSetupBuilderWidget = require( './jquery/setup-builder-widget' );


// Set up Page Builder if we're on the main interface
jQuery( function ( $ ) {

	var container,
		field,
		form,
		builderConfig;

	if ( $( '#siteorigin-panels-metabox' ).length && $( 'form#post' ).length ) {
		// This is usually the case when we're in the post edit interface
		container = $( '#siteorigin-panels-metabox' );
		field = $( '#siteorigin-panels-metabox .siteorigin-panels-data-field' );
		form = $( 'form#post' );

		builderConfig = {
			editorType: 'tinymce',
			postId: $( '#post_ID' ).val(),
			editorId: '#content',
			builderType: $( '#siteorigin-panels-metabox' ).data( 'builder-type' ),
			builderSupports: $( '#siteorigin-panels-metabox' ).data( 'builder-supports' ),
			loadLiveEditor: $( '#siteorigin-panels-metabox' ).data('live-editor') == 1,
			liveEditorPreview: container.data('preview-url')
		};
	}
	else if ( $( '.siteorigin-panels-builder-form' ).length ) {
		// We're dealing with another interface like the custom home page interface
		var $$ = $( '.siteorigin-panels-builder-form' );

		container = $$.find( '.siteorigin-panels-builder-container' );
		field = $$.find( 'input[name="panels_data"]' );
		form = $$;

		builderConfig = {
			editorType: 'standalone',
			postId: $$.data( 'post-id' ),
			editorId: '#post_content',
			builderType: $$.data( 'type' ),
			builderSupports: $$.data( 'builder-supports' ),
			loadLiveEditor: false,
			liveEditorPreview: $$.data( 'preview-url' )
		};
	}

	if ( ! _.isUndefined( container ) ) {
		// If we have a container, then set up the main builder
		var panels = window.siteoriginPanels;

		// Create the main builder model
		var builderModel = new panels.model.builder();

		// Now for the view to display the builder
		var builderView = new panels.view.builder( {
			model: builderModel,
			config: builderConfig
		} );

		// Set up the builder view
		builderView
			.render()
			.attach( {
				container: container
			} )
			.setDataField( field )
			.attachToEditor();

		// When the form is submitted, update the panels data
		form.submit( function () {
			// Refresh the data
			builderModel.refreshPanelsData();
		} );

		container.removeClass( 'so-panels-loading' );

		// Trigger a global jQuery event after we've setup the builder view. Everything is accessible form there
		$( document ).trigger( 'panels_setup', builderView, window.panels );
	}

	// Setup new widgets when they're added in the standard widget interface
	$( document ).on( 'widget-added', function ( e, widget ) {
		$( widget ).find( '.siteorigin-page-builder-widget' ).soPanelsSetupBuilderWidget();
	} );

	// Setup existing widgets on the page (for the widgets interface)
	if ( ! $( 'body' ).hasClass( 'wp-customizer' ) ) {
		$( function () {
			$( '.siteorigin-page-builder-widget' ).soPanelsSetupBuilderWidget();
		} );
	}
} );

},{"./collection/cells":1,"./collection/history-entries":2,"./collection/rows":3,"./collection/widgets":4,"./dialog/builder":5,"./dialog/history":6,"./dialog/prebuilt":7,"./dialog/row":8,"./dialog/widget":9,"./dialog/widgets":10,"./jquery/setup-builder-widget":11,"./model/builder":13,"./model/cell":14,"./model/history-entry":15,"./model/row":16,"./model/widget":17,"./utils/menu":18,"./view/builder":19,"./view/cell":20,"./view/dialog":21,"./view/live-editor":22,"./view/row":23,"./view/styles":24,"./view/widget":25}],13:[function(require,module,exports){
module.exports = Backbone.Model.extend( {
	layoutPosition: {
		BEFORE: 'before',
		AFTER: 'after',
		REPLACE: 'replace',
	},

	rows: {},

	defaults: {
		'data': {
			'widgets': [],
			'grids': [],
			'grid_cells': []
		}
	},

	initialize: function () {
		// These are the main rows in the interface
		this.rows = new panels.collection.rows();
	},

	/**
	 * Add a new row to this builder.
	 *
	 * @param weights
	 */
	addRow: function ( weights, options ) {
		options = _.extend( {
			noAnimate: false
		}, options );
		// Create the actual row
		var row = new panels.model.row( {
			collection: this.rows
		} );

		row.setCells( weights );
		row.builder = this;

		this.rows.add( row, options );

		return row;
	},

	/**
	 * Load the panels data into the builder
	 *
	 * @param data Object the layout and widgets data to load.
	 * @param position string Where to place the new layout. Allowed options are 'before', 'after'. Anything else will
	 *                          cause the new layout to replace the old one.
	 */
	loadPanelsData: function ( data, position ) {
		try {
			if ( position === this.layoutPosition.BEFORE ) {
				data = this.concatPanelsData( data, this.getPanelsData() );
			} else if ( position === this.layoutPosition.AFTER ) {
				data = this.concatPanelsData( this.getPanelsData(), data );
			}

			// Start by destroying any rows that currently exist. This will in turn destroy cells, widgets and all the associated views
			this.emptyRows();

			// This will empty out the current rows and reload the builder data.
			this.set( 'data', JSON.parse( JSON.stringify( data ) ), {silent: true} );

			var cit = 0;
			var rows = [];

			if ( _.isUndefined( data.grid_cells ) ) {
				this.trigger( 'load_panels_data' );
				return;
			}

			var gi;
			for ( var ci = 0; ci < data.grid_cells.length; ci ++ ) {
				gi = parseInt( data.grid_cells[ci].grid );
				if ( _.isUndefined( rows[gi] ) ) {
					rows[gi] = [];
				}

				rows[gi].push( parseFloat( data.grid_cells[ci].weight ) );
			}

			var builderModel = this;
			_.each( rows, function ( row, i ) {
				// This will create and add the row model and its cells
				var newRow = builderModel.addRow( row, {noAnimate: true} );

				if ( ! _.isUndefined( data.grids[i].style ) ) {
					newRow.set( 'style', data.grids[i].style );
				}
			} );


			if ( _.isUndefined( data.widgets ) ) {
				return;
			}

			// Add the widgets
			_.each( data.widgets, function ( widgetData ) {
				var panels_info = null;
				if ( ! _.isUndefined( widgetData.panels_info ) ) {
					panels_info = widgetData.panels_info;
					delete widgetData.panels_info;
				} else {
					panels_info = widgetData.info;
					delete widgetData.info;
				}

				var row = builderModel.rows.at( parseInt( panels_info.grid ) );
				var cell = row.cells.at( parseInt( panels_info.cell ) );

				var newWidget = new panels.model.widget( {
					class: panels_info.class,
					values: widgetData
				} );

				if ( ! _.isUndefined( panels_info.style ) ) {
					newWidget.set( 'style', panels_info.style );
				}

				if ( ! _.isUndefined( panels_info.read_only ) ) {
					newWidget.set( 'read_only', panels_info.read_only );
				}
				if ( ! _.isUndefined( panels_info.widget_id ) ) {
					newWidget.set( 'widget_id', panels_info.widget_id );
				}
				else {
					newWidget.set( 'widget_id', builderModel.generateUUID() );
				}

				newWidget.cell = cell;
				cell.widgets.add( newWidget, { noAnimate: true } );
			} );

			this.trigger( 'load_panels_data' );
		}
		catch ( err ) {
			console.log( 'Error loading data: ' + err.message );

		}
	},

	/**
	 * Concatenate the second set of Page Builder data to the first. There is some validation of input, but for the most
	 * part it's up to the caller to ensure the Page Builder data is well formed.
	 */
	concatPanelsData: function ( panelsDataA, panelsDataB ) {

		if ( _.isUndefined( panelsDataB ) || _.isUndefined( panelsDataB.grids ) || _.isEmpty( panelsDataB.grids ) ||
		     _.isUndefined( panelsDataB.grid_cells ) || _.isEmpty( panelsDataB.grid_cells ) ) {
			return panelsDataA;
		}

		if ( _.isUndefined( panelsDataA ) || _.isUndefined( panelsDataA.grids ) || _.isEmpty( panelsDataA.grids ) ) {
			return panelsDataB;
		}

		var gridsBOffset = panelsDataA.grids.length;
		var widgetsBOffset = ! _.isUndefined( panelsDataA.widgets ) ? panelsDataA.widgets.length : 0;
		var newPanelsData = {grids: [], 'grid_cells': [], 'widgets': []};

		// Concatenate grids (rows)
		newPanelsData.grids = panelsDataA.grids.concat( panelsDataB.grids );

		// Create a copy of panelsDataA grid_cells and widgets
		if ( ! _.isUndefined( panelsDataA.grid_cells ) ) {
			newPanelsData.grid_cells = panelsDataA.grid_cells.slice();
		}
		if ( ! _.isUndefined( panelsDataA.widgets ) ) {
			newPanelsData.widgets = panelsDataA.widgets.slice();
		}

		var i;
		// Concatenate grid cells (row columns)
		for ( i = 0; i < panelsDataB.grid_cells.length; i ++ ) {
			var gridCellB = panelsDataB.grid_cells[i];
			gridCellB.grid = parseInt( gridCellB.grid ) + gridsBOffset;
			newPanelsData.grid_cells.push( gridCellB );
		}

		// Concatenate widgets
		if ( ! _.isUndefined( panelsDataB.widgets ) ) {
			for ( i = 0; i < panelsDataB.widgets.length; i ++ ) {
				var widgetB = panelsDataB.widgets[i];
				widgetB.panels_info.grid = parseInt( widgetB.panels_info.grid ) + gridsBOffset;
				widgetB.panels_info.id = parseInt( widgetB.panels_info.id ) + widgetsBOffset;
				newPanelsData.widgets.push( widgetB );
			}
		}

		return newPanelsData;
	},

	/**
	 * Convert the content of the builder into a object that represents the page builder data
	 */
	getPanelsData: function () {

		var builder = this;

		var data = {
			'widgets': [],
			'grids': [],
			'grid_cells': []
		};
		var widgetId = 0;

		this.rows.each( function ( row, ri ) {

			row.cells.each( function ( cell, ci ) {

				cell.widgets.each( function ( widget, wi ) {
					// Add the data for the widget, including the panels_info field.
					var panels_info = {
						class: widget.get( 'class' ),
						raw: widget.get( 'raw' ),
						grid: ri,
						cell: ci,
						// Strictly this should be an index
						id: widgetId ++,
						widget_id: widget.get( 'widget_id' ),
						style: widget.get( 'style' )
					};

					if( _.isEmpty( panels_info.widget_id ) ) {
						panels_info.widget_id = builder.generateUUID();
					}

					var values = _.extend( _.clone( widget.get( 'values' ) ), {
						panels_info: panels_info
					} );
					data.widgets.push( values );
				} );

				// Add the cell info
				data.grid_cells.push( {
					grid: ri,
					weight: cell.get( 'weight' )
				} );

			} );

			data.grids.push( {
				cells: row.cells.length,
				style: row.get( 'style' )
			} );

		} );

		return data;

	},

	/**
	 * This will check all the current entries and refresh the panels data
	 */
	refreshPanelsData: function ( args ) {
		args = _.extend( {
			silent: false
		}, args );

		var oldData = this.get( 'data' );
		var newData = this.getPanelsData();
		this.set( 'data', newData, {silent: true} );

		if ( ! args.silent && JSON.stringify( newData ) !== JSON.stringify( oldData ) ) {
			// The default change event doesn't trigger on deep changes, so we'll trigger our own
			this.trigger( 'change' );
			this.trigger( 'change:data' );
			this.trigger( 'refresh_panels_data', newData, args );
		}
	},

	/**
	 * Empty all the rows and the cells/widgets they contain.
	 */
	emptyRows: function () {
		_.invoke( this.rows.toArray(), 'destroy' );
		this.rows.reset();

		return this;
	},

	isValidLayoutPosition: function ( position ) {
		return position === this.layoutPosition.BEFORE ||
		       position === this.layoutPosition.AFTER ||
		       position === this.layoutPosition.REPLACE;
	},

	generateUUID: function(){
		var d = new Date().getTime();
		if( window.performance && typeof window.performance.now === "function" ){
			d += performance.now(); //use high-precision timer if available
		}
		var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function(c) {
			var r = (d + Math.random()*16)%16 | 0;
			d = Math.floor(d/16);
			return ( c == 'x' ? r : (r&0x3|0x8) ).toString(16);
		} );
		return uuid;
	}

} );

},{}],14:[function(require,module,exports){
module.exports = Backbone.Model.extend( {
	/* A collection of widgets */
	widgets: {},

	/* The row this model belongs to */
	row: null,

	defaults: {
		weight: 0
	},

	indexes: null,

	/**
	 * Set up the cell model
	 */
	initialize: function () {
		this.widgets = new panels.collection.widgets();
		this.on( 'destroy', this.onDestroy, this );
	},

	/**
	 * Triggered when we destroy a cell
	 */
	onDestroy: function () {
		_.invoke( this.widgets.toArray(), 'destroy' );
		this.widgets.reset();
	},

	/**
	 * Create a clone of the cell, along with all its widgets
	 */
	clone: function ( row, cloneOptions ) {
		if ( _.isUndefined( row ) ) {
			row = this.row;
		}
		cloneOptions = _.extend( {cloneWidgets: true}, cloneOptions );

		var clone = new this.constructor( this.attributes );
		clone.set( 'collection', row.cells, {silent: true} );
		clone.row = row;

		if ( cloneOptions.cloneWidgets ) {
			// Now we're going add all the widgets that belong to this, to the clone
			this.widgets.each( function ( widget ) {
				clone.widgets.add( widget.clone( clone, cloneOptions ), {silent: true} );
			} );
		}

		return clone;
	}

} );

},{}],15:[function(require,module,exports){
module.exports = Backbone.Model.extend( {
	defaults: {
		text: '',
		data: '',
		time: null,
		count: 1
	}
} );

},{}],16:[function(require,module,exports){
module.exports = Backbone.Model.extend( {
	/* A collection of the cells in this row */
	cells: {},

	/* The builder model */
	builder: null,

	defaults: {
		style: {}
	},

	indexes: null,

	/**
	 * Initialize the row model
	 */
	initialize: function () {
		this.cells = new panels.collection.cells();
		this.on( 'destroy', this.onDestroy, this );
	},

	/**
	 * Add cells to the model row
	 *
	 * @param cells an array of cells, where each object in the array has a weight value
	 */
	setCells: function ( cells ) {
		var thisModel = this;

		if ( _.isEmpty( this.cells ) ) {
			// We're adding the initial cells
			_.each( cells, function ( cellWeight ) {
				// Add the new cell to the row
				var cell = new panels.model.cell( {
					weight: cellWeight,
					collection: thisModel.cells
				} );
				cell.row = thisModel;
				thisModel.cells.add( cell );
			} );
		} else {

			if ( cells.length > this.cells.length ) {
				// We need to add cells
				for ( var i = this.cells.length; i < cells.length; i ++ ) {
					var cell = new panels.model.cell( {
						weight: cells[cells.length + i],
						collection: thisModel.cells
					} );
					cell.row = this;
					thisModel.cells.add( cell );
				}

			}
			else if ( cells.length < this.cells.length ) {
				var newParentCell = this.cells.at( cells.length - 1 );

				// We need to remove cells
				_.each( this.cells.slice( cells.length, this.cells.length ), function ( cell ) {
					var widgetsToMove = cell.widgets.models.slice( 0 );
					for ( var i = 0; i < widgetsToMove.length; i ++ ) {
						widgetsToMove[i].moveToCell( newParentCell, {silent: false} );
					}

					// First move all the widgets to the new cell
					cell.destroy();
				} );
			}

			// Now we need to change the weights of all the cells
			this.cells.each( function ( cell, i ) {
				cell.set( 'weight', cells[i] );
			} );
		}

		// Rescale the cells when we add or remove
		this.reweightCells();
	},

	/**
	 * Make sure that all the cell weights add up to 1
	 */
	reweightCells: function () {
		var totalWeight = 0;
		this.cells.each( function ( cell ) {
			totalWeight += cell.get( 'weight' );
		} );

		this.cells.each( function ( cell ) {
			cell.set( 'weight', cell.get( 'weight' ) / totalWeight );
		} );

		// This is for the row view to hook into and resize
		this.trigger( 'reweight_cells' );
	},

	/**
	 * Triggered when the model is destroyed
	 */
	onDestroy: function () {
		// Also destroy all the cells
		_.invoke( this.cells.toArray(), 'destroy' );
		this.cells.reset();
	},

	/**
	 * Create a clone of the row, along with all its cells
	 *
	 * @param {panels.model.builder} builder The builder model to attach this to.
	 *
	 * @return {panels.model.row} The cloned row.
	 */
	clone: function ( builder, cloneOptions ) {
		if ( _.isUndefined( builder ) ) {
			builder = this.builder;
		}
		cloneOptions = _.extend( {cloneCells: true}, cloneOptions );

		var clone = new this.constructor( this.attributes );
		clone.set( 'collection', builder.rows, {silent: true} );
		clone.builder = builder;

		if ( cloneOptions.cloneCells ) {
			// Clone all the rows
			this.cells.each( function ( cell ) {
				clone.cells.add( cell.clone( clone, cloneOptions ), {silent: true} );
			} );
		}

		return clone;
	}
} );

},{}],17:[function(require,module,exports){
/**
 * Model for an instance of a widget
 */
module.exports = Backbone.Model.extend( {

	cell: null,

	defaults: {
		// The PHP Class of the widget
		class: null,

		// Is this class missing? Missing widgets are a special case.
		missing: false,

		// The values of the widget
		values: {},

		// Have the current values been passed through the widgets update function
		raw: false,

		// Visual style fields
		style: {},

		read_only: false,
		widget_id: '',
	},

	indexes: null,

	initialize: function () {
		var widgetClass = this.get( 'class' );
		if ( _.isUndefined( panelsOptions.widgets[widgetClass] ) || ! panelsOptions.widgets[widgetClass].installed ) {
			this.set( 'missing', true );
		}
	},

	/**
	 * @param field
	 * @returns {*}
	 */
	getWidgetField: function ( field ) {
		if ( _.isUndefined( panelsOptions.widgets[this.get( 'class' )] ) ) {
			if ( field === 'title' || field === 'description' ) {
				return panelsOptions.loc.missing_widget[field];
			} else {
				return '';
			}
		} else {
			return panelsOptions.widgets[this.get( 'class' )][field];
		}
	},

	/**
	 * Move this widget model to a new cell. Called by the views.
	 *
	 * @param panels.model.cell newCell
	 *
	 * @return bool Indicating if the widget was moved into a different cell
	 */
	moveToCell: function ( newCell, options ) {
		options = _.extend( {
			silent: true
		}, options );

		if ( this.cell.cid === newCell.cid ) {
			return false;
		}

		this.cell = newCell;
		this.collection.remove( this, options );
		newCell.widgets.add( this, options );

		return true;
	},

	/**
	 * Trigger an event on the model that indicates a user wants to edit it
	 */
	triggerEdit: function () {
		this.trigger( 'user_edit', this );
	},

	/**
	 * Trigger an event on the widget that indicates a user wants to duplicate it
	 */
	triggerDuplicate: function () {
		this.trigger( 'user_duplicate', this );
	},

	/**
	 * This is basically a wrapper for set that checks if we need to trigger a change
	 */
	setValues: function ( values ) {
		var hasChanged = false;
		if ( JSON.stringify( values ) !== JSON.stringify( this.get( 'values' ) ) ) {
			hasChanged = true;
		}

		this.set( 'values', values, {silent: true} );

		if ( hasChanged ) {
			// We'll trigger our own change events.
			// NB: Must include the model being changed (i.e. `this`) as a workaround for a bug in Backbone 1.2.3
			this.trigger( 'change', this );
			this.trigger( 'change:values' );
		}
	},

	/**
	 * Create a clone of this widget attached to the given cell.
	 *
	 * @param {panels.model.cell} cell The cell model we're attaching this widget clone to.
	 * @returns {panels.model.widget}
	 */
	clone: function ( cell, options ) {
		if ( _.isUndefined( cell ) ) {
			cell = this.cell;
		}

		var clone = new this.constructor( this.attributes );

		// Create a deep clone of the original values
		var cloneValues = JSON.parse( JSON.stringify( this.get( 'values' ) ) );

		// We want to exclude any fields that start with _ from the clone. Assuming these are internal.
		var cleanClone = function ( vals ) {
			_.each( vals, function ( el, i ) {
				if ( _.isString( i ) && i[0] === '_' ) {
					delete vals[i];
				}
				else if ( _.isObject( vals[i] ) ) {
					cleanClone( vals[i] );
				}
			} );

			return vals;
		};
		cloneValues = cleanClone( cloneValues );

		if ( this.get( 'class' ) === "SiteOrigin_Panels_Widgets_Layout" ) {
			// Special case of this being a layout widget, it needs a new ID
			cloneValues.builder_id = Math.random().toString( 36 ).substr( 2 );
		}

		clone.set( 'values', cloneValues, {silent: true} );
		clone.set( 'collection', cell.widgets, {silent: true} );
		clone.cell = cell;

		// This is used to force a form reload later on
		clone.isDuplicate = true;

		return clone;
	},

	/**
	 * Gets the value that makes most sense as the title.
	 */
	getTitle: function () {
		var widgetData = panelsOptions.widgets[this.get( 'class' )];

		if ( _.isUndefined( widgetData ) ) {
			return this.get( 'class' ).replace( /_/g, ' ' );
		}
		else if ( ! _.isUndefined( widgetData.panels_title ) ) {
			// This means that the widget has told us which field it wants us to use as a title
			if ( widgetData.panels_title === false ) {
				return panelsOptions.widgets[this.get( 'class' )].description;
			}
		}

		var values = this.get( 'values' );

		// Create a list of fields to check for a title
		var titleFields = ['title', 'text'];

		for ( var k in values ) {
			if ( values.hasOwnProperty( k ) ) {
				titleFields.push( k );
			}
		}

		titleFields = _.uniq( titleFields );

		for ( var i in titleFields ) {
			if (
				! _.isUndefined( values[titleFields[i]] ) &&
				_.isString( values[titleFields[i]] ) &&
				values[titleFields[i]] !== '' &&
				values[titleFields[i]] !== 'on' &&
				titleFields[i][0] !== '_' && ! jQuery.isNumeric( values[titleFields[i]] )
			) {
				var title = values[titleFields[i]];
				title = title.replace( /<\/?[^>]+(>|$)/g, "" );
				var parts = title.split( " " );
				parts = parts.slice( 0, 20 );
				return parts.join( ' ' );
			}
		}

		// If we still have nothing, then just return the widget description
		return this.getWidgetField( 'description' );
	}

} );

},{}],18:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	wrapperTemplate: _.template( $( '#siteorigin-panels-context-menu' ).html().panelsProcessTemplate() ),
	sectionTemplate: _.template( $( '#siteorigin-panels-context-menu-section' ).html().panelsProcessTemplate() ),

	contexts: [],
	active: false,

	events: {
		'keyup .so-search-wrapper input': 'searchKeyUp'
	},

	/**
	 * Intialize the context menu
	 */
	initialize: function () {
		this.listenContextMenu();
		this.render();
		this.attach();
	},

	/**
	 * Listen for the right click context menu
	 */
	listenContextMenu: function () {
		var thisView = this;

		$( window ).on( 'contextmenu', function ( e ) {
			if ( thisView.active && ! thisView.isOverEl( thisView.$el, e ) ) {
				thisView.closeMenu();
				thisView.active = false;
				e.preventDefault();
				return false;
			}

			if ( thisView.active ) {
				// Lets not double up on the context menu
				return true;
			}

			// Other components should listen to activate_context
			thisView.active = false;
			thisView.trigger( 'activate_context', e, thisView );

			if ( thisView.active ) {
				// We don't want the default event to happen.
				e.preventDefault();

				thisView.openMenu( {
					left: e.pageX,
					top: e.pageY
				} );
			}
		} );
	},

	render: function () {
		this.setElement( this.wrapperTemplate() );
	},

	attach: function () {
		this.$el.appendTo( 'body' );
	},

	/**
	 * Display the actual context menu.
	 *
	 * @param position
	 */
	openMenu: function ( position ) {
		this.trigger( 'open_menu' );

		// Start listening for situations when we should close the menu
		$( window ).on( 'keyup', {menu: this}, this.keyboardListen );
		$( window ).on( 'click', {menu: this}, this.clickOutsideListen );

		// Set the maximum height of the menu
		this.$el.css( 'max-height', $( window ).height() - 20 );

		// Correct the left position
		if ( position.left + this.$el.outerWidth() + 10 >= $( window ).width() ) {
			position.left = $( window ).width() - this.$el.outerWidth() - 10;
		}
		if ( position.left <= 0 ) {
			position.left = 10;
		}

		// Check top position
		if ( position.top + this.$el.outerHeight() - $( window ).scrollTop() + 10 >= $( window ).height() ) {
			position.top = $( window ).height() + $( window ).scrollTop() - this.$el.outerHeight() - 10;
		}
		if ( position.left <= 0 ) {
			position.left = 10;
		}

		// position the contextual menu
		this.$el.css( {
			left: position.left + 1,
			top: position.top + 1
		} ).show();
		this.$( '.so-search-wrapper input' ).focus();
	},

	closeMenu: function () {
		this.trigger( 'close_menu' );

		// Stop listening for situations when we should close the menu
		$( window ).off( 'keyup', this.keyboardListen );
		$( window ).off( 'click', this.clickOutsideListen );

		this.active = false;
		this.$el.empty().hide();
	},

	/**
	 * Keyboard events handler
	 */
	keyboardListen: function ( e ) {
		var menu = e.data.menu;

		switch ( e.which ) {
			case 27:
				menu.closeMenu();
				break;
		}
	},

	/**
	 * Listen for a click outside the menu to close it.
	 * @param e
	 */
	clickOutsideListen: function ( e ) {
		var menu = e.data.menu;
		if ( e.which !== 3 && menu.$el.is( ':visible' ) && ! menu.isOverEl( menu.$el, e ) ) {
			menu.closeMenu();
		}
	},

	/**
	 * Add a new section to the contextual menu.
	 *
	 * @param settings
	 * @param items
	 * @param callback
	 */
	addSection: function ( settings, items, callback ) {
		var thisView = this;
		settings = _.extend( {
			display: 5,
			defaultDisplay: false,
			search: true,

			// All the labels
			sectionTitle: '',
			searchPlaceholder: '',

			// This is the key to be used in items for the title. Makes it easier to list objects
			titleKey: 'title'
		}, settings );

		// Create the new section
		var section = $( this.sectionTemplate( {
			settings: settings,
			items: items
		} ) );
		this.$el.append( section );

		section.find( '.so-item:not(.so-confirm)' ).click( function () {
			var $$ = $( this );
			callback( $$.data( 'key' ) );
			thisView.closeMenu();
		} );

		section.find( '.so-item.so-confirm' ).click( function () {
			var $$ = $( this );

			if ( $$.hasClass( 'so-confirming' ) ) {
				callback( $$.data( 'key' ) );
				thisView.closeMenu();
				return;
			}

			$$
				.data( 'original-text', $$.html() )
				.addClass( 'so-confirming' )
				.html( '<span class="dashicons dashicons-yes"></span> ' + panelsOptions.loc.dropdown_confirm );

			setTimeout( function () {
				$$.removeClass( 'so-confirming' );
				$$.html( $$.data( 'original-text' ) );
			}, 2500 );
		} );

		section.data( 'settings', settings ).find( '.so-search-wrapper input' ).trigger( 'keyup' );

		this.active = true;
	},

	/**
	 * Handle searching inside a section.
	 *
	 * @param e
	 * @returns {boolean}
	 */
	searchKeyUp: function ( e ) {
		var
			$$ = $( e.currentTarget ),
			section = $$.closest( '.so-section' ),
			settings = section.data( 'settings' );

		if ( e.which === 38 || e.which === 40 ) {
			// First, lets check if this is an up, down or enter press
			var
				items = section.find( 'ul li:visible' ),
				activeItem = items.filter( '.so-active' ).eq( 0 );

			if ( activeItem.length ) {
				items.removeClass( 'so-active' );

				var activeIndex = items.index( activeItem );

				if ( e.which === 38 ) {
					if ( activeIndex - 1 < 0 ) {
						activeItem = items.last();
					} else {
						activeItem = items.eq( activeIndex - 1 );
					}
				}
				else if ( e.which === 40 ) {
					if ( activeIndex + 1 >= items.length ) {
						activeItem = items.first();
					} else {
						activeItem = items.eq( activeIndex + 1 );
					}
				}
			}
			else if ( e.which === 38 ) {
				activeItem = items.last();
			}
			else if ( e.which === 40 ) {
				activeItem = items.first();
			}

			activeItem.addClass( 'so-active' );
			return false;
		}
		if ( e.which === 13 ) {
			if ( section.find( 'ul li:visible' ).length === 1 ) {
				// We'll treat a single visible item as active when enter is clicked
				section.find( 'ul li:visible' ).trigger( 'click' );
				return false;
			}
			section.find( 'ul li.so-active:visible' ).trigger( 'click' );
			return false;
		}

		if ( $$.val() === '' ) {
			// We'll display the defaultDisplay items
			if ( settings.defaultDisplay ) {
				section.find( '.so-item' ).hide();
				for ( var i = 0; i < settings.defaultDisplay.length; i ++ ) {
					section.find( '.so-item[data-key="' + settings.defaultDisplay[i] + '"]' ).show();
				}
			} else {
				// We'll just display all the items
				section.find( '.so-item' ).show();
			}
		} else {
			section.find( '.so-item' ).hide().each( function () {
				var item = $( this );
				if ( item.html().toLowerCase().indexOf( $$.val().toLowerCase() ) !== - 1 ) {
					item.show();
				}
			} );
		}

		// Now, we'll only show the first settings.display visible items
		section.find( '.so-item:visible:gt(' + (
			settings.display - 1
			) + ')' ).hide();


		if ( section.find( '.so-item:visible' ).length === 0 && $$.val() !== '' ) {
			section.find( '.so-no-results' ).show();
		} else {
			section.find( '.so-no-results' ).hide();
		}
	},

	/**
	 * Check if the given mouse event is over the element
	 * @param el
	 * @param event
	 */
	isOverEl: function ( el, event ) {
		var elPos = [
			[el.offset().left, el.offset().top],
			[el.offset().left + el.outerWidth(), el.offset().top + el.outerHeight()]
		];

		// Return if this event is over the given element
		return (
			event.pageX >= elPos[0][0] && event.pageX <= elPos[1][0] &&
			event.pageY >= elPos[0][1] && event.pageY <= elPos[1][1]
		);
	}

} );

},{}],19:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {

	// Config options
	config: {},

	template: _.template( $( '#siteorigin-panels-builder' ).html().panelsProcessTemplate() ),
	dialogs: {},
	rowsSortable: null,
	dataField: false,
	currentData: '',

	attachedToEditor: false,
	liveEditor: undefined,
	menu: false,

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
	initialize: function ( options ) {
		var builder = this;

		this.config = _.extend( {
			loadLiveEditor: false,
			builderSupports : {}
		}, options.config);

		this.config.builderSupports = _.extend( {
			addRow: true,
			editRow: true,
			deleteRow: true,
			moveRow: true,
			addWidget: true,
			editWidget: true,
			deleteWidget: true,
			moveWidget: true,
			prebuilt: true,
			history: true,
			liveEditor: true,
			revertToEditor: true
		}, this.config.builderSupports );

		// Automatically load the live editor as soon as it's ready
		if( options.config.loadLiveEditor ) {
			this.on( 'builder_live_editor_added', function(){
				this.displayLiveEditor();
			} );
			}

		// Now lets create all the dialog boxes that the main builder interface uses
		this.dialogs = {
			widgets: new panels.dialog.widgets(),
			row: new panels.dialog.row(),
			prebuilt: new panels.dialog.prebuilt()
		};

		// Set the builder for each dialog and render it.
		_.each( this.dialogs, function ( p, i, d ) {
			d[i].setBuilder( builder );
		} );

		this.dialogs.row.setRowDialogType( 'create' );

		// This handles a new row being added to the collection - we'll display it in the interface
		this.model.rows.on( 'add', this.onAddRow, this );

		// Reflow the entire builder when ever the
		$( window ).resize( function ( e ) {
			if ( e.target === window ) {
				builder.trigger( 'builder_resize' );
			}
		} );

		// When the data changes in the model, store it in the field
		this.model.on( 'change:data load_panels_data', this.storeModelData, this );

		// Handle a content change
		this.on( 'content_change', this.handleContentChange, this );
		this.on( 'display_builder', this.handleDisplayBuilder, this );
		this.on( 'builder_rendered builder_resize', this.handleBuilderSizing, this );
		this.model.on( 'change:data load_panels_data', this.toggleWelcomeDisplay, this );

		// Create the context menu for this builder
		this.menu = new panels.utils.menu( {} );
		this.menu.on( 'activate_context', this.activateContextMenu, this );

		return this;
	},

	/**
	 * Render the builder interface.
	 *
	 * @return {panels.view.builder}
	 */
	render: function () {
		// this.$el.html( this.template() );
		this.setElement( this.template() );
		this.$el
			.attr( 'id', 'siteorigin-panels-builder-' + this.cid )
			.addClass( 'so-builder-container' );

		this.trigger( 'builder_rendered' );

		return this;
	},

	/**
	 * Attach the builder to the given container
	 *
	 * @param container
	 * @returns {panels.view.builder}
	 */
	attach: function ( options ) {

		options = _.extend( {
			container: false,
			dialog: false
		}, options );

		if ( options.dialog ) {
			// We're going to add this to a dialog
			this.dialog = new panels.dialog.builder();
			this.dialog.builder = this;
		} else {
			// Attach this in the standard way
			this.$el.appendTo( options.container );
			this.metabox = options.container.closest( '.postbox' );
			this.initSortable();
			this.trigger( 'attached_to_container', options.container );
		}

		this.trigger( 'builder_attached' );

		// Add support for components we have

		if( this.supports( 'liveEditor' ) ) {
			this.addLiveEditor();
		}
		if( this.supports( 'history' ) ) {
			this.addHistoryBrowser();
		}

		// Hide toolbar buttons we don't support
		var toolbar = this.$('.so-builder-toolbar');
		if( ! this.supports( 'addWidget' ) ) {
			toolbar.find('.so-widget-add' ).hide();
		}
		if( ! this.supports( 'addRow' ) ) {
			toolbar.find('.so-row-add' ).hide();
		}
		if( ! this.supports( 'prebuilt' ) ) {
			toolbar.find('.so-prebuilt-add' ).hide();
		}

		return this;
	},

	/**
	 * This will move the Page Builder meta box into the editor if we're in the post/page edit interface.
	 *
	 * @returns {panels.view.builder}
	 */
	attachToEditor: function () {
		if ( this.config.editorType !== 'tinymce' ) {
			return this;
		}

		this.attachedToEditor = true;
		var metabox = this.metabox;
		var thisView = this;

		// Handle switching between the page builder and other tabs
		$( '#wp-content-wrap .wp-editor-tabs' )
			.find( '.wp-switch-editor' )
			.click( function ( e ) {
				e.preventDefault();
				$( '#wp-content-editor-container, #post-status-info' ).show();
				// metabox.hide();
				$( '#wp-content-wrap' ).removeClass( 'panels-active' );
				$( '#content-resize-handle' ).show();
				thisView.trigger( 'hide_builder' );
			} ).end()
			.append(
			$( '<a id="content-panels" class="hide-if-no-js wp-switch-editor switch-panels">' + metabox.find( '.hndle span' ).html() + '</a>' )
				.click( function ( e ) {
					// Switch to the Page Builder interface
					e.preventDefault();

					var $$ = jQuery( this );

					// Hide the standard content editor
					$( '#wp-content-wrap, #post-status-info' ).hide();

					// Show page builder and the inside div
					metabox.show().find( '> .inside' ).show();

					// Triggers full refresh
					$( window ).resize();
					$( document ).scroll();

					thisView.trigger( 'display_builder' );

				} )
		);

		// Switch back to the standard editor
		if( this.supports( 'revertToEditor' ) ) {
			metabox.find( '.so-switch-to-standard' ).click( function ( e ) {
				e.preventDefault();

				if ( ! confirm( panelsOptions.loc.confirm_stop_builder ) ) {
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
			} ).show();
		}

		// Move the panels box into a tab of the content editor
		metabox.insertAfter( '#wp-content-wrap' ).hide().addClass( 'attached-to-editor' );

		// Switch to the Page Builder interface as soon as we load the page if there are widgets
		var data = this.model.get( 'data' );
		if ( (
			     ! _.isEmpty( data.widgets )
		     ) || (
			     ! _.isEmpty( data.grids )
		     ) ) {
			$( '#content-panels.switch-panels' ).click();
		}

		// We will also make this sticky if its attached to an editor.
		var stickToolbar = function () {
			var toolbar = thisView.$( '.so-builder-toolbar' );

			if ( thisView.$el.hasClass( 'so-display-narrow' ) ) {
				// In this case, we don't want to stick the toolbar.
				toolbar.css( {
					top: 0,
					left: 0,
					width: '100%',
					position: 'absolute'
				} );
				thisView.$el.css( 'padding-top', toolbar.outerHeight() );
				return;
			}

			var newTop = $( window ).scrollTop() - thisView.$el.offset().top;

			if ( $( '#wpadminbar' ).css( 'position' ) === 'fixed' ) {
				newTop += $( '#wpadminbar' ).outerHeight();
			}

			var limits = {
				top: 0,
				bottom: thisView.$el.outerHeight() - toolbar.outerHeight() + 20
			};

			if ( newTop > limits.top && newTop < limits.bottom ) {
				if ( toolbar.css( 'position' ) !== 'fixed' ) {
					// The toolbar needs to stick to the top, over the interface
					toolbar.css( {
						top: $( '#wpadminbar' ).outerHeight(),
						left: thisView.$el.offset().left,
						width: thisView.$el.outerWidth(),
						position: 'fixed'
					} );
				}
			} else {
				// The toolbar needs to be at the top or bottom of the interface
				toolbar.css( {
					top: Math.min( Math.max( newTop, 0 ), thisView.$el.outerHeight() - toolbar.outerHeight() + 20 ),
					left: 0,
					width: '100%',
					position: 'absolute'
				} );
			}

			thisView.$el.css( 'padding-top', toolbar.outerHeight() );
		};

		this.on( 'builder_resize', stickToolbar, this );
		$( document ).scroll( stickToolbar );
		stickToolbar();

		this.trigger('builder_attached_to_editor');

		return this;
	},

	/**
	 * Initialize the row sortables
	 */
	initSortable: function () {
		if( ! this.supports( 'moveRow' ) ) {
			return this;
		}

		// Create the sortable for the rows
		var builderView = this;

		this.rowsSortable = this.$( '.so-rows-container' ).sortable( {
			appendTo: '#wpwrap',
			items: '.so-row-container',
			handle: '.so-row-move',
			axis: 'y',
			tolerance: 'pointer',
			scroll: false,
			stop: function ( e ) {
				builderView.addHistoryEntry( 'row_moved' );

				// Sort the rows collection after updating all the indexes.
				builderView.sortCollections();
			}
		} );

		return this;
	},

	/**
	 * Refresh the row sortable
	 */
	refreshSortable: function () {
		// Refresh the sortable to account for the new row
		if ( ! _.isNull( this.rowsSortable ) ) {
			this.rowsSortable.sortable( 'refresh' );
		}
	},

	/**
	 * Set the field that's used to store the data
	 * @param field
	 */
	setDataField: function ( field, options ) {
		options = _.extend( {
			load: true
		}, options );

		this.dataField = field;
		this.dataField.data( 'builder', this );

		if ( options.load && field.val() !== '' ) {
			var data = this.dataField.val();
			try {
				data = JSON.parse( data );
			}
			catch ( err ) {
				data = {};
			}

			this.model.loadPanelsData( data );
			this.currentData = data;
			this.toggleWelcomeDisplay();
			this.sortCollections();
		}

		return this;
	},

	/**
	 * Store the model data in the data html field set in this.setDataField.
	 */
	storeModelData: function () {
		var data = JSON.stringify( this.model.get( 'data' ) );

		if ( $( this.dataField ).val() !== data ) {
			// If the data is different, set it and trigger a content_change event
			$( this.dataField ).val( data );
			$( this.dataField ).trigger( 'change' );
			this.trigger( 'content_change' );
		}
	},

	/**
	 * HAndle the visual side of adding a new row to the builder.
	 *
	 * @param row
	 * @param collection
	 * @param options
	 */
	onAddRow: function ( row, collection, options ) {
		options = _.extend( {noAnimate: false}, options );
		// Create a view for the row
		var rowView = new panels.view.row( {model: row} );
		rowView.builder = this;
		rowView.render();

		// Attach the row elements to this builder
		if ( _.isUndefined( options.at ) || collection.length <= 1 ) {
			// Insert this at the end of the widgets container
			rowView.$el.appendTo( this.$( '.so-rows-container' ) );
		} else {
			// We need to insert this at a specific position
			rowView.$el.insertAfter(
				this.$( '.so-rows-container .so-row-container' ).eq( options.at - 1 )
			);
		}

		if ( options.noAnimate === false ) {
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
	displayAddWidgetDialog: function () {
		this.dialogs.widgets.openDialog();
	},

	/**
	 * Display the dialog to add a new row.
	 *
	 * @returns {boolean}
	 */
	displayAddRowDialog: function () {
		this.dialogs.row.openDialog();
		this.dialogs.row.setRowModel(); // Set this to an empty row model
	},

	/**
	 * Display the dialog to add prebuilt layouts.
	 *
	 * @returns {boolean}
	 */
	displayAddPrebuiltDialog: function () {
		this.dialogs.prebuilt.openDialog();
	},

	/**
	 * Display the history dialog.
	 *
	 * @returns {boolean}
	 */
	displayHistoryDialog: function () {
		this.dialogs.history.openDialog();
	},

	/**
	 * Get the model for the currently selected cell
	 */
	getActiveCell: function ( options ) {
		options = _.extend( {
			createCell: true,
			defaultPosition: 'first'
		}, options );

		if ( this.$( '.so-cells .cell' ).length === 0 ) {

			if ( options.createCell ) {
				// Create a row with a single cell
				this.model.addRow( [1], {noAnimate: true} );
			} else {
				return null;
			}

		}

		var activeCell = this.$( '.so-cells .cell.cell-selected' );

		if ( activeCell.length === 0 ) {
			if ( options.defaultPosition === 'last' ) {
				activeCell = this.$( '.so-cells .cell' ).first();
			} else {
				activeCell = this.$( '.so-cells .cell' ).last();
			}
		}

		return activeCell.data( 'view' ).model;
	},

	/**
	 * Sort all widget and row collections based on their dom position
	 */
	sortCollections: function () {

		// Give the widgets their indexes
		this.$( '.so-widget' ).each( function ( i ) {
			var $$ = $( this );
			$$.data( 'view' ).model.indexes = {
				builder: i,
				cell: $$.index()
			};
		} );

		// Give the cells their indexes
		this.$( '.so-cells .cell' ).each( function ( i ) {
			var $$ = $( this );
			$$.data( 'view' ).model.indexes = {
				builder: i,
				row: $$.index()
			};
		} );

		// Give the rows their indexes
		this.$( '.so-row-container' ).each( function ( i ) {
			var $$ = $( this );
			$$.data( 'view' ).model.indexes = {
				builder: i,
			};
		} );


		// Sort the rows by their visual index
		this.model.rows.visualSort();

		// Sort the widget collections by their visual index
		this.model.rows.each( function ( row ) {
			row.cells.each( function ( cell ) {
				cell.widgets.visualSort();
			} );
		} );

		// Update the builder model to reflect the newly ordered data.
		this.model.refreshPanelsData();
	},

	/**
	 * Add a live editor to the builder
	 *
	 * @returns {panels.view.builder}
	 */
	addLiveEditor: function ( ) {
		if( _.isEmpty( this.config.liveEditorPreview ) ) {
			return this;
		}

		// Create the live editor and set the builder to this.
		this.liveEditor = new panels.view.liveEditor( {
			builder: this,
			previewUrl: this.config.liveEditorPreview
		} );

		// Display the live editor button in the toolbar
		if ( this.liveEditor.hasPreviewUrl() ) {
			this.$( '.so-builder-toolbar .so-live-editor' ).show();
		}

		this.trigger('builder_live_editor_added');

		return this;
	},

	/**
	 * Show the current live editor
	 */
	displayLiveEditor: function () {
		if ( _.isUndefined( this.liveEditor ) ) {
			return;
		}

		this.liveEditor.open();
	},

	/**
	 * Add the history browser.
	 *
	 * @return {panels.view.builder}
	 */
	addHistoryBrowser: function () {
		if( _.isEmpty( this.config.liveEditorPreview ) ) {
			return this;
		}

		this.dialogs.history = new panels.dialog.history();
		this.dialogs.history.builder = this;
		this.dialogs.history.entries.builder = this.model;

		// Set the revert entry
		this.dialogs.history.setRevertEntry( this.model );

		// Display the live editor button in the toolbar
		this.$( '.so-builder-toolbar .so-history' ).show();
	},

	/**
	 * Add an entry.
	 *
	 * @param text
	 * @param data
	 */
	addHistoryEntry: function ( text, data ) {
		if ( _.isUndefined( data ) ) {
			data = null;
		}

		if ( ! _.isUndefined( this.dialogs.history ) ) {
			this.dialogs.history.entries.addEntry( text, data );
		}
	},

	supports: function( thing ){

		if( thing === 'rowAction' ) {
			// Check if this supports any row action
			return this.supports( 'addRow' ) || this.supports( 'editRow' ) || this.supports( 'deleteRow' );
		} else if ( thing === 'widgetAction' ) {
			// Check if this supports any widget action
			return this.supports( 'addWidget' ) || this.supports( 'editWidget' ) || this.supports( 'deleteWidget' );
		}

		return _.isUndefined( this.config.builderSupports[ thing ] ) ? false : this.config.builderSupports[ thing ];
	},

	/**
	 * Handle a change of the content
	 */
	handleContentChange: function () {

		// Make sure we actually need to copy content.
		if ( panelsOptions.copy_content && this.attachedToEditor && this.$el.is( ':visible' ) ) {

			var panelsData = this.model.getPanelsData();
			if( ! _.isEmpty( panelsData.widgets ) ) {
				// We're going to create a copy of page builder content into the post content
				$.post(
					panelsOptions.ajaxurl,
					{
						action: 'so_panels_builder_content',
						panels_data: JSON.stringify( panelsData ),
						post_id: this.config.postId
					},
					function ( content ) {

						// Strip all the known layout divs
						var t = $( '<div />' ).html( content );
						t.find( 'div' ).each( function () {
							var c = $( this ).contents();
							$( this ).replaceWith( c );
						} );

						content = t.html()
							.replace( /[\r\n]+/g, "\n" )
							.replace( /\n\s+/g, "\n" )
							.trim();

						if( content !== '' ) {
							this.updateEditorContent( content );
						}
					}.bind( this )
				);
			}
		}
	},

	/**
	 * Update editor content with the given content.
	 *
	 * @param content
	 */
	updateEditorContent: function ( content ) {
		// Switch back to the standard editor
		if ( this.config.editorType !== 'tinymce' || _.isUndefined( tinyMCE ) || _.isNull( tinyMCE.get( "content" ) ) ) {
			var $editor = $( this.config.editorId );
			$editor.val( content ).trigger( 'change' ).trigger( 'keyup' );
		} else {
			var contentEd = tinyMCE.get( "content" );

			contentEd.setContent( content );

			contentEd.fire( 'change' );
			contentEd.fire( 'keyup' );
		}

		this.triggerYoastSeoChange();
	},

	/**
	 * Trigger a change on Yoast SEO
	 */
	triggerYoastSeoChange: function () {
		if ( $( '#yoast_wpseo_focuskw_text_input' ).length ) {
			var element = document.getElementById( 'yoast_wpseo_focuskw_text_input' ), event;

			if ( document.createEvent ) {
				event = document.createEvent( "HTMLEvents" );
				event.initEvent( "keyup", true, true );
			} else {
				event = document.createEventObject();
				event.eventType = "keyup";
			}

			event.eventName = "keyup";

			if ( document.createEvent ) {
				element.dispatchEvent( event );
			} else {
				element.fireEvent( "on" + event.eventType, event );
			}
		}
	},

	/**
	 * Handle displaying the builder
	 */
	handleDisplayBuilder: function () {
		var editorContent = '';
		var editor;

		if ( ! _.isUndefined( tinyMCE ) ) {
			editor = tinyMCE.get( 'content' );
		}
		if ( editor && _.isFunction( editor.getContent ) ) {
			editorContent = editor.getContent();
		} else {
			editorContent = $( 'textarea#content' ).val();
		}

		if (
			(
				_.isEmpty( this.model.get( 'data' ) ) ||
				( _.isEmpty( this.model.get( 'data' ).widgets ) && _.isEmpty( this.model.get( 'data' ).grids ) )
			) &&
			editorContent !== ''
		) {
			// Confirm that the user wants to copy their content to Page Builder.
			if ( ! confirm( panelsOptions.loc.confirm_use_builder ) ) {
				return;
			}

			var widgetClass = '';
			// There is a small chance a theme will have removed this, so check
			if ( ! _.isUndefined( panelsOptions.widgets.SiteOrigin_Widget_Editor_Widget ) ) {
				widgetClass = 'SiteOrigin_Widget_Editor_Widget';
			}
			else if ( ! _.isUndefined( panelsOptions.widgets.WP_Widget_Text ) ) {
				widgetClass = 'WP_Widget_Text';
			}

			if ( widgetClass === '' ) {
				return;
			}

			// Create the existing page content in a single widget
			this.model.loadPanelsData( {
				grid_cells: [{grid: 0, weight: 1}],
				grids: [{cells: 1}],
				widgets: [
					{
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
					}
				]
			} );
			this.model.trigger( 'change' );
			this.model.trigger( 'change:data' );
		}
	},

	handleBuilderSizing: function () {
		var width = this.$el.width();

		if ( ! width ) {
			return this;
		}

		if ( width < 480 ) {
			this.$el.addClass( 'so-display-narrow' );
		} else {
			this.$el.removeClass( 'so-display-narrow' );
		}

	},

	/**
	 * Set the parent dialog for all the dialogs in this builder.
	 *
	 * @param text
	 * @param dialog
	 */
	setDialogParents: function ( text, dialog ) {
		_.each( this.dialogs, function ( p, i, d ) {
			d[i].setParent( text, dialog );
		} );

		// For any future dialogs
		this.on( 'add_dialog', function ( newDialog ) {
			newDialog.setParent( text, dialog );
		}, this );
	},

	/**
	 * This shows or hides the welcome display depending on whether there are any rows in the collection.
	 */
	toggleWelcomeDisplay: function () {
		if ( ! this.model.rows.isEmpty() ) {
			this.$( '.so-panels-welcome-message' ).hide();
		} else {
			this.$( '.so-panels-welcome-message' ).show();
		}
	},

	activateContextMenu: function ( e, menu ) {
		var builder = this;

		// Of all the visible builders, find the topmost
		var topmostBuilder = $( '.siteorigin-panels-builder:visible' )
			.sort( function ( a, b ) {
				return $( a ).zIndex() > $( b ).zIndex() ? 1 : - 1;
			} )
			.last();

		var topmostDialog = $( '.so-panels-dialog-wrapper:visible' )
			.sort( function ( a, b ) {
				return $( a ).zIndex() > $( b ).zIndex() ? 1 : - 1;
			} )
			.last();

		var closestDialog = builder.$el.closest('.so-panels-dialog-wrapper');

		// Only run this if its element is the topmost builder, in the topmost dialog
		if (
			builder.$el.is( topmostBuilder ) &&
			(
				topmostDialog.length === 0 ||
				topmostDialog.is( closestDialog )
			)
		) {
			// Get the element we're currently hovering over
			var over = $( [] )
				.add( builder.$( '.so-rows-container > .so-row-container' ) )
				.add( builder.$( '.so-cells > .cell' ) )
				.add( builder.$( '.cell-wrapper > .so-widget' ) )
				.filter( function ( i ) {
					return menu.isOverEl( $( this ), e );
				} );

			var activeView = over.last().data( 'view' );
			if ( activeView !== undefined && activeView.buildContextualMenu !== undefined ) {
				// We'll pass this to the current active view so it can popular the contextual menu
				activeView.buildContextualMenu( e, menu );
			}
		}
	},

	/**
	 * Lock window scrolling for the main overlay
	 */
	lockPageScroll: function () {
		if ( $( 'body' ).css( 'overflow' ) === 'hidden' ) {
			return;
		}

		// lock scroll position, but retain settings for later
		var scrollPosition = [
			self.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft,
			self.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop
		];

		$( 'body' )
			.data( {
				'scroll-position': scrollPosition
			} )
			.css( 'overflow', 'hidden' );

		if( ! _.isUndefined( scrollPosition ) ) {
			window.scrollTo( scrollPosition[0], scrollPosition[1] );
		}
	},

	/**
	 * Unlock window scrolling
	 */
	unlockPageScroll: function () {
		if ( $( 'body' ).css( 'overflow' ) !== 'hidden' ) {
			return;
		}

		// Check that there are no more dialogs or a live editor
		if ( ! $( '.so-panels-dialog-wrapper' ).is( ':visible' ) && ! $( '.so-panels-live-editor' ).is( ':visible' ) ) {
			$( 'body' ).css( 'overflow', 'visible' );
			var scrollPosition = $( 'body' ).data( 'scroll-position' );

			if( ! _.isUndefined( scrollPosition ) ) {
				window.scrollTo( scrollPosition[0], scrollPosition[1] );
			}
		}
	}

} );

},{}],20:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( $( '#siteorigin-panels-builder-cell' ).html().panelsProcessTemplate() ),
	events: {
		'click .cell-wrapper': 'handleCellClick'
	},

	/* The row view that this cell is a part of */
	row: null,
	widgetSortable: null,

	initialize: function () {
		this.model.widgets.on( 'add', this.onAddWidget, this );
	},

	/**
	 * Render the actual cell
	 */
	render: function () {
		var templateArgs = {
			weight: this.model.get( 'weight' ),
			totalWeight: this.row.model.cells.totalWeight()
		};

		this.setElement( this.template( templateArgs ) );
		this.$el.data( 'view', this );

		// Now lets render any widgets that are currently in the row
		var thisView = this;
		this.model.widgets.each( function ( widget ) {
			var widgetView = new panels.view.widget( {model: widget} );
			widgetView.cell = thisView;
			widgetView.render();

			widgetView.$el.appendTo( thisView.$( '.widgets-container' ) );
		} );

		this.initSortable();
		this.initResizable();

		return this;
	},

	/**
	 * Initialize the widget sortable
	 */
	initSortable: function () {
		if( ! this.row.builder.supports( 'moveWidget' ) ) {
			return this;
		}

		var cellView = this;

		// Go up the view hierarchy until we find the ID attribute
		var builderID = cellView.row.builder.$el.attr( 'id' );

		// Create a widget sortable that's connected with all other cells
		this.widgetSortable = this.$( '.widgets-container' ).sortable( {
			placeholder: "so-widget-sortable-highlight",
			connectWith: '#' + builderID + ' .so-cells .cell .widgets-container',
			tolerance: 'pointer',
			scroll: false,
			over: function ( e, ui ) {
				// This will make all the rows in the current builder resize
				cellView.row.builder.trigger( 'widget_sortable_move' );
			},
			stop: function ( e, ui ) {
				cellView.row.builder.addHistoryEntry( 'widget_moved' );

				var widget = $( ui.item ).data( 'view' );
				var targetCell = $( ui.item ).closest( '.cell' ).data( 'view' );

				// Move the model and the view to the new cell
				widget.model.moveToCell( targetCell.model );
				widget.cell = targetCell;

				cellView.row.builder.sortCollections();
			},
			helper: function ( e, el ) {
				var helper = el.clone()
					.css( {
						'width': el.outerWidth(),
						'z-index': 10000,
						'position': 'fixed'
					} )
					.addClass( 'widget-being-dragged' ).appendTo( 'body' );

				// Center the helper to the mouse cursor.
				if ( el.outerWidth() > 720 ) {
					helper.animate( {
						'margin-left': e.pageX - el.offset().left - (
						480 / 2
						),
						'width': 480
					}, 'fast' );
				}

				return helper;
			}
		} );

		return this;
	},

	/**
	 * Refresh the widget sortable when a new widget is added
	 */
	refreshSortable: function () {
		if ( ! _.isNull( this.widgetSortable ) ) {
			this.widgetSortable.sortable( 'refresh' );
		}
	},

	/**
	 * This will make the cell resizble
	 */
	initResizable: function () {
		if( ! this.row.builder.supports( 'editRow' ) ) {
			return this;
		}

		// var neighbor = this.$el.previous().data('view');
		var handle = this.$( '.resize-handle' ).css( 'position', 'absolute' );
		var container = this.row.$el;
		var cellView = this;

		// The view of the cell to the left is stored when dragging starts.
		var previousCell;

		handle.draggable( {
			axis: 'x',
			containment: container,
			start: function ( e, ui ) {
				// Set the containment to the cell parent
				previousCell = cellView.$el.prev().data( 'view' );
				if ( _.isUndefined( previousCell ) ) {
					return;
				}

				// Create the clone for the current cell
				var newCellClone = cellView.$el.clone().appendTo( ui.helper ).css( {
					position: 'absolute',
					top: '0',
					width: cellView.$el.outerWidth(),
					left: 5,
					height: cellView.$el.outerHeight()
				} );
				newCellClone.find( '.resize-handle' ).remove();

				// Create the clone for the previous cell
				var prevCellClone = previousCell.$el.clone().appendTo( ui.helper ).css( {
					position: 'absolute',
					top: '0',
					width: previousCell.$el.outerWidth(),
					right: 5,
					height: previousCell.$el.outerHeight()
				} );
				prevCellClone.find( '.resize-handle' ).remove();

				$( this ).data( {
					'newCellClone': newCellClone,
					'prevCellClone': prevCellClone
				} );
			},
			drag: function ( e, ui ) {
				// Calculate the new cell and previous cell widths as a percent
				var containerWidth = cellView.row.$el.width() + 10;
				var ncw = cellView.model.get( 'weight' ) - (
					(
					ui.position.left + handle.outerWidth() / 2
					) / containerWidth
					);
				var pcw = previousCell.model.get( 'weight' ) + (
					(
					ui.position.left + handle.outerWidth() / 2
					) / containerWidth
					);

				$( this ).data( 'newCellClone' ).css( 'width', containerWidth * ncw )
					.find( '.preview-cell-weight' ).html( Math.round( ncw * 1000 ) / 10 );

				$( this ).data( 'prevCellClone' ).css( 'width', containerWidth * pcw )
					.find( '.preview-cell-weight' ).html( Math.round( pcw * 1000 ) / 10 );
			},
			stop: function ( e, ui ) {
				// Remove the clones
				$( this ).data( 'newCellClone' ).remove();
				$( this ).data( 'prevCellClone' ).remove();

				var containerWidth = cellView.row.$el.width() + 10;
				var ncw = cellView.model.get( 'weight' ) - (
					(
					ui.position.left + handle.outerWidth() / 2
					) / containerWidth
					);
				var pcw = previousCell.model.get( 'weight' ) + (
					(
					ui.position.left + handle.outerWidth() / 2
					) / containerWidth
					);

				if ( ncw > 0.02 && pcw > 0.02 ) {
					cellView.row.builder.addHistoryEntry( 'cell_resized' );
					cellView.model.set( 'weight', ncw );
					previousCell.model.set( 'weight', pcw );
					cellView.row.resize();
				}

				ui.helper.css( 'left', - handle.outerWidth() / 2 );

				// Refresh the panels data
				cellView.row.builder.model.refreshPanelsData();
			}
		} );

		return this;
	},

	/**
	 * This is triggered when ever a widget is added to the row collection.
	 *
	 * @param widget
	 */
	onAddWidget: function ( widget, collection, options ) {
		options = _.extend( {noAnimate: false}, options );

		// Create the view for the widget
		var view = new panels.view.widget( {
			model: widget
		} );
		view.cell = this;

		if ( _.isUndefined( widget.isDuplicate ) ) {
			widget.isDuplicate = false;
		}

		// Render and load the form if this is a duplicate
		view.render( {
			'loadForm': widget.isDuplicate
		} );

		if ( _.isUndefined( options.at ) || collection.length <= 1 ) {
			// Insert this at the end of the widgets container
			view.$el.appendTo( this.$( '.widgets-container' ) );
		} else {
			// We need to insert this at a specific position
			view.$el.insertAfter(
				this.$( '.widgets-container .so-widget' ).eq( options.at - 1 )
			);
		}

		if ( options.noAnimate === false ) {
			// We need an animation
			view.visualCreate();
		}

		this.refreshSortable();
		this.row.resize();
	},

	/**
	 * Handle this cell being clicked on
	 *
	 * @param e
	 * @returns {boolean}
	 */
	handleCellClick: function ( e ) {
		var cells = this.$el.closest( '.so-rows-container' ).find( '.so-cells .cell' ).removeClass( 'cell-selected' );
		$( e.target ).parent().addClass( 'cell-selected' );
	},

	/**
	 * Build up the contextual menu for a cell
	 *
	 * @param e
	 * @param menu
	 */
	buildContextualMenu: function ( e, menu ) {
		var thisView = this;
		menu.addSection(
			{
				sectionTitle: panelsOptions.loc.contextual.add_widget_cell,
				searchPlaceholder: panelsOptions.loc.contextual.search_widgets,
				defaultDisplay: panelsOptions.contextual.default_widgets
			},
			panelsOptions.widgets,
			function ( c ) {
				thisView.row.builder.addHistoryEntry( 'widget_added' );

				var widget = new panels.model.widget( {
					class: c
				} );

				// Add the widget to the cell model
				widget.cell = thisView.model;
				widget.cell.widgets.add( widget );

				thisView.row.builder.model.refreshPanelsData();
			}
		);

		this.row.buildContextualMenu( e, menu );
	}
} );

},{}],21:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	dialogTemplate: _.template( $( '#siteorigin-panels-dialog' ).html().panelsProcessTemplate() ),
	dialogTabTemplate: _.template( $( '#siteorigin-panels-dialog-tab' ).html().panelsProcessTemplate() ),

	tabbed: false,
	rendered: false,
	builder: false,
	className: 'so-panels-dialog-wrapper',
	dialogClass: '',
	parentDialog: false,
	dialogOpen: false,

	events: {
		'click .so-close': 'closeDialog',
		'click .so-nav.so-previous': 'navToPrevious',
		'click .so-nav.so-next': 'navToNext'
	},

	initialize: function () {
		// The first time this dialog is opened, render it
		this.once( 'open_dialog', this.render );
		this.once( 'open_dialog', this.attach );
		this.once( 'open_dialog', this.setDialogClass );

		this.trigger( 'initialize_dialog', this );

		if ( ! _.isUndefined( this.initializeDialog ) ) {
			this.initializeDialog();
		}
	},

	/**
	 * Returns the next dialog in the sequence. Should be overwritten by a child dialog.
	 * @returns {null}
	 */
	getNextDialog: function () {
		return null;
	},

	/**
	 * Returns the previous dialog in this sequence. Should be overwritten by child dialog.
	 * @returns {null}
	 */
	getPrevDialog: function () {
		return null;
	},

	/**
	 * Adds a dialog class to uniquely identify this dialog type
	 */
	setDialogClass: function () {
		if ( this.dialogClass !== '' ) {
			this.$( '.so-panels-dialog' ).addClass( this.dialogClass );
		}
	},

	/**
	 * Set the builder that controls this dialog.
	 * @param {panels.view.builder} builder
	 */
	setBuilder: function ( builder ) {
		this.builder = builder;

		// Trigger an add dialog event on the builder so it can modify the dialog in any way
		builder.trigger( 'add_dialog', this, this.builder );

		return this;
	},

	/**
	 * Attach the dialog to the window
	 */
	attach: function () {
		this.$el.appendTo( 'body' );

		return this;
	},

	/**
	 * Converts an HTML representation of the dialog into arguments for a dialog box
	 * @param html HTML for the dialog
	 * @param args Arguments passed to the template
	 * @returns {}
	 */
	parseDialogContent: function ( html, args ) {
		// Add a CID
		args = _.extend( {cid: this.cid}, args );


		var c = $( (
			_.template( html.panelsProcessTemplate() )
		)( args ) );
		var r = {
			title: c.find( '.title' ).html(),
			buttons: c.find( '.buttons' ).html(),
			content: c.find( '.content' ).html()
		};

		if ( c.has( '.left-sidebar' ) ) {
			r.left_sidebar = c.find( '.left-sidebar' ).html();
		}

		if ( c.has( '.right-sidebar' ) ) {
			r.right_sidebar = c.find( '.right-sidebar' ).html();
		}

		return r;

	},

	/**
	 * Render the dialog and initialize the tabs
	 *
	 * @param attributes
	 * @returns {panels.view.dialog}
	 */
	renderDialog: function ( attributes ) {
		this.$el.html( this.dialogTemplate( attributes ) ).hide();
		this.$el.data( 'view', this );
		this.$el.addClass( 'so-panels-dialog-wrapper' );

		if ( this.parentDialog !== false ) {
			// Add a link to the parent dialog as a sort of crumbtrail.
			var thisDialog = this;
			var dialogParent = $( '<h3 class="so-parent-link"></h3>' ).html( this.parentDialog.text + '<div class="so-separator"></div>' );
			dialogParent.click( function ( e ) {
				e.preventDefault();
				thisDialog.closeDialog();
				thisDialog.parentDialog.openDialog();
			} );
			this.$( '.so-title-bar' ).prepend( dialogParent );
		}

		return this;
	},

	/**
	 * Initialize the sidebar tabs
	 */
	initTabs: function () {
		var tabs = this.$( '.so-sidebar-tabs li a' );

		if ( tabs.length === 0 ) {
			return this;
		}

		var thisDialog = this;
		tabs.click( function ( e ) {
			e.preventDefault();
			var $$ = $( this );

			thisDialog.$( '.so-sidebar-tabs li' ).removeClass( 'tab-active' );
			thisDialog.$( '.so-content .so-content-tabs > *' ).hide();

			$$.parent().addClass( 'tab-active' );

			var url = $$.attr( 'href' );
			if ( ! _.isUndefined( url ) && url.charAt( 0 ) === '#' ) {
				// Display the new tab
				var tabName = url.split( '#' )[1];
				thisDialog.$( '.so-content .so-content-tabs .tab-' + tabName ).show();
			}

			// This lets other dialogs implement their own custom handlers
			thisDialog.trigger( 'tab_click', $$ );

		} );

		// Trigger a click on the first tab
		this.$( '.so-sidebar-tabs li a' ).first().click();
		return this;
	},

	initToolbar: function () {
		// Trigger simplified click event for elements marked as toolbar buttons.
		var buttons = this.$( '.so-toolbar .so-buttons .so-toolbar-button' );
		buttons.click( function ( e ) {
			e.preventDefault();

			this.trigger( 'button_click', $( e.currentTarget ) );
		}.bind( this ) );

		// Handle showing and hiding the dropdown list items
		var $dropdowns = this.$( '.so-toolbar .so-buttons .so-dropdown-button' );
		$dropdowns.click( function ( e ) {
			e.preventDefault();
			var $dropdownButton = $( e.currentTarget );
			var $dropdownList = $dropdownButton.siblings( '.so-dropdown-links-wrapper' );
			if ( $dropdownList.is( '.hidden' ) ) {
				$dropdownList.removeClass( 'hidden' );
			} else {
				$dropdownList.addClass( 'hidden' );
			}

		}.bind( this ) );

		// Hide dropdown list on click anywhere, unless it's a dropdown option which requires confirmation in it's
		// unconfirmed state.
		$( 'html' ).click( function ( e ) {
			this.$( '.so-dropdown-links-wrapper' ).not( '.hidden' ).each( function ( index, el ) {
				var $dropdownList = $( el );
				var $trgt = $( e.target );
				if ( $trgt.length === 0 || ! (
				     (
				     $trgt.is( '.so-needs-confirm' ) && ! $trgt.is( '.so-confirmed' )
				     ) || $trgt.is( '.so-dropdown-button' )
					) ) {
					$dropdownList.addClass( 'hidden' );
				}
			} );
		}.bind( this ) );
	},

	/**
	 * Quickly setup the dialog by opening and closing it.
	 */
	setupDialog: function () {
		this.openDialog();
		this.closeDialog();
	},

	/**
	 * Refresh the next and previous buttons.
	 */
	refreshDialogNav: function () {
		this.$( '.so-title-bar .so-nav' ).show().removeClass( 'so-disabled' );

		// Lets also hide the next and previous if we don't have a next and previous dialog
		var nextDialog = this.getNextDialog();
		var nextButton = this.$( '.so-title-bar .so-next' );

		var prevDialog = this.getPrevDialog();
		var prevButton = this.$( '.so-title-bar .so-previous' );

		if ( nextDialog === null ) {
			nextButton.hide();
		}
		else if ( nextDialog === false ) {
			nextButton.addClass( 'so-disabled' );
		}

		if ( prevDialog === null ) {
			prevButton.hide();
		}
		else if ( prevDialog === false ) {
			prevButton.addClass( 'so-disabled' );
		}
	},

	/**
	 * Open the dialog
	 */
	openDialog: function ( options ) {
		options = _.extend( {
			silent: false
		}, options );

		if ( ! options.silent ) {
			this.trigger( 'open_dialog' );
		}

		this.dialogOpen = true;

		this.refreshDialogNav();

		// Stop scrolling for the main body
		this.builder.lockPageScroll();

		// Start listen for keyboard keypresses.
		$( window ).on( 'keyup', this.keyboardListen );

		this.$el.show();

		if ( ! options.silent ) {
			// This triggers once everything is visible
			this.trigger( 'open_dialog_complete' );
			this.builder.trigger( 'open_dialog', this );
		}
	},

	/**
	 * Close the dialog
	 *
	 * @param e
	 * @returns {boolean}
	 */
	closeDialog: function ( options ) {
		options = _.extend( {
			silent: false
		}, options );

		if ( ! options.silent ) {
			this.trigger( 'close_dialog' );
		}

		this.dialogOpen = false;

		this.$el.hide();
		this.builder.unlockPageScroll();

		// Stop listen for keyboard keypresses.
		$( window ).off( 'keyup', this.keyboardListen );

		if ( ! options.silent ) {
			// This triggers once everything is hidden
			this.trigger( 'close_dialog_complete' );
			this.builder.trigger( 'close_dialog', this );
		}
	},

	/**
	 * Keyboard events handler
	 */
	keyboardListen: function ( e ) {
		// [Esc] to close
		if ( e.which === 27 ) {
			$( '.so-panels-dialog-wrapper .so-close' ).trigger( 'click' );
		}
	},

	/**
	 * Navigate to the previous dialog
	 */
	navToPrevious: function () {
		this.closeDialog();

		var prev = this.getPrevDialog();
		if ( prev !== null && prev !== false ) {
			prev.openDialog();
		}
	},

	/**
	 * Navigate to the next dialog
	 */
	navToNext: function () {
		this.closeDialog();

		var next = this.getNextDialog();
		if ( next !== null && next !== false ) {
			next.openDialog();
		}
	},

	/**
	 * Get the values from the form and convert them into a data array
	 */
	getFormValues: function ( formSelector ) {
		if ( _.isUndefined( formSelector ) ) {
			formSelector = '.so-content';
		}

		var $f = this.$( formSelector );

		var data = {}, parts;

		// Find all the named fields in the form
		$f.find( '[name]' ).each( function () {
			var $$ = $( this );

			try {

				var name = /([A-Za-z_]+)\[(.*)\]/.exec( $$.attr( 'name' ) );
				if ( _.isEmpty( name ) ) {
					return true;
				}

				// Create an array with the parts of the name
				if ( _.isUndefined( name[2] ) ) {
					parts = $$.attr( 'name' );
				} else {
					parts = name[2].split( '][' );
					parts.unshift( name[1] );
				}

				parts = parts.map( function ( e ) {
					if ( ! isNaN( parseFloat( e ) ) && isFinite( e ) ) {
						return parseInt( e );
					} else {
						return e;
					}
				} );

				var sub = data;
				var fieldValue = null;

				var fieldType = (
					_.isString( $$.attr( 'type' ) ) ? $$.attr( 'type' ).toLowerCase() : false
				);

				// First we need to get the value from the field
				if ( fieldType === 'checkbox' ) {
					if ( $$.is( ':checked' ) ) {
						fieldValue = $$.val() !== '' ? $$.val() : true;
					} else {
						fieldValue = null;
					}
				}
				else if ( fieldType === 'radio' ) {
					if ( $$.is( ':checked' ) ) {
						fieldValue = $$.val();
					} else {
						//skip over unchecked radios
						return;
					}
				}
				else if ( $$.prop( 'tagName' ) === 'TEXTAREA' && $$.hasClass( 'wp-editor-area' ) ) {
					// This is a TinyMCE editor, so we'll use the tinyMCE object to get the content
					var editor = null;
					if ( ! _.isUndefined( tinyMCE ) ) {
						editor = tinyMCE.get( $$.attr( 'id' ) );
					}

					if ( editor !== null && _.isFunction( editor.getContent ) && ! editor.isHidden() ) {
						fieldValue = editor.getContent();
					} else {
						fieldValue = $$.val();
					}
				}
				else if ( $$.prop( 'tagName' ) === 'SELECT' ) {
					var selected = $$.find( 'option:selected' );

					if ( selected.length === 1 ) {
						fieldValue = $$.find( 'option:selected' ).val();
					}
					else if ( selected.length > 1 ) {
						// This is a mutli-select field
						fieldValue = _.map( $$.find( 'option:selected' ), function ( n, i ) {
							return $( n ).val();
						} );
					}

				} else {
					// This is a fallback that will work for most fields
					fieldValue = $$.val();
				}

				// Now, we need to filter this value if necessary
				if ( ! _.isUndefined( $$.data( 'panels-filter' ) ) ) {
					switch ( $$.data( 'panels-filter' ) ) {
						case 'json_parse':
							// Attempt to parse the JSON value of this field
							try {
								fieldValue = JSON.parse( fieldValue );
							}
							catch ( err ) {
								fieldValue = '';
							}
							break;
					}
				}

				// Now convert this into an array
				if ( fieldValue !== null ) {
					for ( var i = 0; i < parts.length; i ++ ) {
						if ( i === parts.length - 1 ) {
							if ( parts[i] === '' ) {
								// This needs to be an array
								sub.push( fieldValue );
							} else {
								sub[parts[i]] = fieldValue;
							}
						} else {
							if ( _.isUndefined( sub[parts[i]] ) ) {
								if ( parts[i + 1] === '' ) {
									sub[parts[i]] = [];
								} else {
									sub[parts[i]] = {};
								}
							}
							sub = sub[parts[i]];
						}
					}
				}
			}
			catch ( error ) {
				// Ignore this error, just log the message for debugging
				console.log( 'Field [' + $$.attr('name') + '] could not be processed and was skipped - ' + error.message );
			}

		} ); // End of each through input fields

		return data;
	},

	/**
	 * Set a status message for the dialog
	 */
	setStatusMessage: function ( message, loading ) {
		this.$( '.so-toolbar .so-status' ).html( message );
		if ( ! _.isUndefined( loading ) && loading ) {
			this.$( '.so-toolbar .so-status' ).addClass( 'so-panels-loading' );
		}
	},

	/**
	 * Set the parent after.
	 */
	setParent: function ( text, dialog ) {
		this.parentDialog = {
			text: text,
			dialog: dialog
		};
	}
} );

},{}],22:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( $( '#siteorigin-panels-live-editor' ).html().panelsProcessTemplate() ),

	previewScrollTop: 0,
	loadTimes: [],
	previewFrameId: 1,
	previewUrl: null,
	previewIframe: null,

	events: {
		'click .live-editor-close': 'close',
		'click .live-editor-collapse': 'collapse',
		'click .live-editor-mode': 'mobileToggle'
	},

	initialize: function ( options ) {
		options = _.extend( {
			builder: false,
			previewUrl: false,
		}, options );

		if( _.isEmpty( options.previewUrl ) ) {
			options.previewUrl = panelsOptions.ajaxurl + "&action=so_panels_live_editor_preview";
		}

		this.builder = options.builder;
		this.previewUrl = options.previewUrl;

		this.builder.model.on( 'refresh_panels_data', this.handleRefreshData, this );
		this.builder.model.on( 'load_panels_data', this.handleLoadData, this );
	},

	/**
	 * Render the live editor
	 */
	render: function () {
		this.setElement( this.template() );
		this.$el.hide();
		var thisView = this;

		var isMouseDown = false;

		$( document )
			.mousedown( function () {
				isMouseDown = true;
			} )
			.mouseup( function () {
				isMouseDown = false;
			} );

		// Handle highlighting the relevant widget in the live editor preview
		thisView.$el.on( 'mouseenter', '.so-widget-wrapper', function () {
			var $$ = $( this ),
				previewWidget = $( this ).data( 'live-editor-preview-widget' );

			if ( ! isMouseDown && previewWidget !== undefined && previewWidget.length && ! thisView.$( '.so-preview-overlay' ).is( ':visible' ) ) {
				thisView.highlightElement( previewWidget );
				thisView.scrollToElement( previewWidget );
			}
		} );

		thisView.$el.on( 'mouseleave', '.so-widget-wrapper', function () {
			thisView.resetHighlights();
		} );

		thisView.builder.on( 'open_dialog', function () {
			thisView.resetHighlights();
		} );

		return this;
	},

	/**
	 * Attach the live editor to the document
	 */
	attach: function () {
		this.$el.appendTo( 'body' );
	},

	/**
	 * Display the live editor
	 */
	open: function () {
		if ( this.$el.html() === '' ) {
			this.render();
		}
		if ( this.$el.closest( 'body' ).length === 0 ) {
			this.attach();
		}

		// Disable page scrolling
		this.builder.lockPageScroll();

		if ( this.$el.is( ':visible' ) ) {
			return this;
		}

		// Refresh the preview display
		this.$el.show();
		this.refreshPreview( this.builder.model.getPanelsData() );

		this.originalContainer = this.builder.$el.parent();
		this.builder.$el.appendTo( this.$( '.so-live-editor-builder' ) );
		this.builder.$( '.so-tool-button.so-live-editor' ).hide();
		this.builder.trigger( 'builder_resize' );


		if( $('#original_post_status' ).val() === 'auto-draft' && ! this.autoSaved ) {
			// The live editor requires a saved draft post, so we'll create one for auto-draft posts
			var thisView = this;

			if ( wp.autosave ) {
				// Set a temporary post title so the autosave triggers properly
				if( $('#title[name="post_title"]' ).val() === '' ) {
					$('#title[name="post_title"]' ).val( panelsOptions.loc.draft ).trigger('keydown');
				}

				$( document ).one( 'heartbeat-tick.autosave', function(){
					thisView.autoSaved = true;
					thisView.refreshPreview( thisView.builder.model.getPanelsData() );
				} );
				wp.autosave.server.triggerSave();
			}
		}
	},

	/**
	 * Close the live editor
	 */
	close: function () {
		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		this.$el.hide();
		this.builder.unlockPageScroll();

		// Move the builder back to its original container
		this.builder.$el.appendTo( this.originalContainer );
		this.builder.$( '.so-tool-button.so-live-editor' ).show();
		this.builder.trigger( 'builder_resize' );
	},

	/**
	 * Collapse the live editor
	 */
	collapse: function () {
		this.$el.toggleClass( 'so-collapsed' );

		var text = this.$( '.live-editor-collapse span' );
		text.html( text.data( this.$el.hasClass( 'so-collapsed' ) ? 'expand' : 'collapse' ) );
	},

	/**
	 * Create an overlay in the preview.
	 *
	 * @param over
	 * @return {*|Object} The item we're hovering over.
	 */
	highlightElement: function ( over ) {
		if( ! _.isUndefined( this.resetHighlightTimeout ) ) {
			clearTimeout( this.resetHighlightTimeout );
		}

		// Remove any old overlays

		var body = this.previewIframe.contents().find( 'body' );
		body.find( '.panel-grid .panel-grid-cell .so-panel' )
			.filter( function () {
				// Filter to only include non nested
				return $( this ).parents( '.so-panel' ).length === 0;
			} )
			.not( over )
			.addClass( 'so-panels-faded' );

		over.removeClass( 'so-panels-faded' ).addClass( 'so-panels-highlighted' );
	},

	/**
	 * Reset highlights in the live preview
	 */
	resetHighlights: function() {

		var body = this.previewIframe.contents().find( 'body' );
		this.resetHighlightTimeout = setTimeout( function(){
			body.find( '.panel-grid .panel-grid-cell .so-panel' )
				.removeClass( 'so-panels-faded so-panels-highlighted' );
		}, 100 );
	},

	/**
	 * Scroll over an element in the live preview
	 * @param over
	 */
	scrollToElement: function( over ) {
		var contentWindow = this.$( '.so-preview iframe' )[0].contentWindow;
		contentWindow.liveEditorScrollTo( over );
	},

	handleRefreshData: function ( newData, args ) {
		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		this.refreshPreview( newData );
	},

	handleLoadData: function () {
		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		this.refreshPreview( this.builder.model.getPanelsData() );
	},

	/**
	 * Refresh the Live Editor preview.
	 * @returns {exports}
	 */
	refreshPreview: function ( data ) {
		var loadTimePrediction = this.loadTimes.length ?
		_.reduce( this.loadTimes, function ( memo, num ) {
			return memo + num;
		}, 0 ) / this.loadTimes.length : 1000;

		// Store the last preview iframe position
		if( ! _.isNull( this.previewIframe )  ) {
			if ( ! this.$( '.so-preview-overlay' ).is( ':visible' ) ) {
				this.previewScrollTop = this.previewIframe.contents().scrollTop();
			}
		}

		// Add a loading bar
		this.$( '.so-preview-overlay' ).show();
		this.$( '.so-preview-overlay .so-loading-bar' )
			.clearQueue()
			.css( 'width', '0%' )
			.animate( {width: '100%'}, parseInt( loadTimePrediction ) + 100 );


		this.postToIframe(
			{
				live_editor_panels_data: JSON.stringify( data )
			},
			this.previewUrl,
			this.$('.so-preview')
		);

		this.previewIframe.data( 'load-start', new Date().getTime() );
	},

	/**
	 * Use a temporary form to post data to an iframe.
	 *
	 * @param data The data to send
	 * @param url The preview URL
	 * @param target The target iframe
	 */
	postToIframe: function( data, url, target ){
		// Store the old preview

		if( ! _.isNull( this.previewIframe )  ) {
			this.previewIframe.remove();
		}

		var iframeId = 'siteorigin-panels-live-preview-' + this.previewFrameId;

		// Remove the old preview frame
		this.previewIframe = $('<iframe src="javascript:false;" />')
			.attr( {
				'id' : iframeId,
				'name' : iframeId,
			} )
			.appendTo( target )

		this.setupPreviewFrame( this.previewIframe );

		// We can use a normal POST form submit
		var tempForm = $('<form id="soPostToPreviewFrame" method="post" />')
			.attr( {
				id: iframeId,
				target: this.previewIframe.attr('id'),
				action: url
			} )
			.appendTo( 'body' );

		$.each( data, function( name, value ){
			$('<input type="hidden" />')
				.attr( {
					name: name,
					value: value
				} )
				.appendTo( tempForm );
		} );

		tempForm
			.submit()
			.remove();

		this.previewFrameId++;

		return this.previewIframe;
	},

	setupPreviewFrame: function( iframe ){
		var thisView = this;
		iframe
			.data( 'iframeready', false )
			.on( 'iframeready', function () {
				var $$ = $( this ),
					$iframeContents = $$.contents();

				if( $$.data( 'iframeready' ) ) {
					// Skip this if the iframeready function has already run
					return;
				}

				$$.data( 'iframeready', true );

				if ( $$.data( 'load-start' ) !== undefined ) {
					thisView.loadTimes.unshift( new Date().getTime() - $$.data( 'load-start' ) );

					if ( ! _.isEmpty( thisView.loadTimes ) ) {
						thisView.loadTimes = thisView.loadTimes.slice( 0, 4 );
					}
				}

				setTimeout( function(){
					// Scroll to the correct position
					$iframeContents.scrollTop( thisView.previewScrollTop );
					thisView.$( '.so-preview-overlay' ).hide();
				}, 100 );

				// Lets find all the first level grids. This is to account for the Page Builder layout widget.
				$iframeContents.find( '.panel-grid .panel-grid-cell .so-panel' )
					.filter( function () {
						// Filter to only include non nested
						return $( this ).parents( '.so-panel' ).length === 0;
					} )
					.each( function ( i, el ) {
						var $$ = $( el );
						var widgetEdit = thisView.$( '.so-live-editor-builder .so-widget-wrapper' ).eq( $$.data( 'index' ) );

						widgetEdit.data( 'live-editor-preview-widget', $$ );

						$$
							.css( {
								'cursor': 'pointer'
							} )
							.mouseenter( function () {
								widgetEdit.parent().addClass( 'so-hovered' );
								thisView.highlightElement( $$ );
							} )
							.mouseleave( function () {
								widgetEdit.parent().removeClass( 'so-hovered' );
								thisView.resetHighlights();
							} )
							.click( function ( e ) {
								e.preventDefault();
								// When we click a widget, send that click to the form
								widgetEdit.find( '.title h4' ).click();
							} );
					} );

				// Prevent default clicks
				$iframeContents.find( "a" ).css( {'pointer-events': 'none'} ).click( function ( e ) {
					e.preventDefault();
				} );

			} )
			.on( 'load', function(){
				var $$ = $( this );
				if( ! $$.data( 'iframeready' )  ) {
					$$.trigger('iframeready');
				}
			} );
	},

	/**
	 * Return true if the live editor has a valid preview URL.
	 * @return {boolean}
	 */
	hasPreviewUrl: function () {
		return this.$( 'form.live-editor-form' ).attr( 'action' ) !== '';
	},

	mobileToggle: function( e ){
		var button = $( e.currentTarget );
		this.$('.live-editor-mode' ).not( button ).removeClass('so-active');
		button.addClass( 'so-active' );

		this.$el
			.removeClass( 'live-editor-desktop-mode live-editor-tablet-mode live-editor-mobile-mode' )
			.addClass( 'live-editor-' + button.data( 'mode' ) + '-mode' );

	}
} );

},{}],23:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( $( '#siteorigin-panels-builder-row' ).html().panelsProcessTemplate() ),

	events: {
		'click .so-row-settings': 'editSettingsHandler',
		'click .so-row-duplicate': 'duplicateHandler',
		'click .so-row-delete': 'confirmedDeleteHandler'
	},

	builder: null,
	dialog: null,

	/**
	 * Initialize the row view
	 */
	initialize: function () {

		this.model.cells.on( 'add', this.handleCellAdd, this );
		this.model.cells.on( 'remove', this.handleCellRemove, this );
		this.model.on( 'reweight_cells', this.resize, this );

		this.model.on( 'destroy', this.onModelDestroy, this );
		this.model.on( 'visual_destroy', this.visualDestroyModel, this );

		var thisView = this;
		this.model.cells.each( function ( cell ) {
			thisView.listenTo( cell.widgets, 'add', thisView.resize );
		} );

		// When ever a new cell is added, listen to it for new widgets
		this.model.cells.on( 'add', function ( cell ) {
			thisView.listenTo( cell.widgets, 'add', thisView.resize );
		}, this );

	},

	/**
	 * Render the row.
	 *
	 * @returns {panels.view.row}
	 */
	render: function () {
		this.setElement( this.template() );
		this.$el.data( 'view', this );

		// Create views for the cells in this row
		var thisView = this;
		this.model.cells.each( function ( cell ) {
			var cellView = new panels.view.cell( {
				model: cell
			} );
			cellView.row = thisView;
			cellView.render();
			cellView.$el.appendTo( thisView.$( '.so-cells' ) );
		} );

		// Remove any unsupported actions
		if( ! this.builder.supports( 'rowAction' ) ) {
			this.$('.so-row-toolbar .so-dropdown-wrapper' ).remove();
			this.$el.addClass('so-row-no-actions');
		}
		else {
			if( ! this.builder.supports( 'editWidget' ) ) {
				this.$('.so-row-toolbar .so-row-settings' ).parent().remove();
				this.$el.addClass('so-row-no-edit');
			}
			if( ! this.builder.supports( 'addWidget' ) ) {
				this.$('.so-row-toolbar .so-row-duplicate' ).parent().remove();
				this.$el.addClass('so-row-no-duplicate');
			}
			if( ! this.builder.supports( 'deleteWidget' ) ) {
				this.$('.so-row-toolbar .so-row-delete' ).parent().remove();
				this.$el.addClass('so-row-no-delete');
			}
		}
		if( ! this.builder.supports( 'moveRow' ) ) {
			this.$('.so-row-toolbar .so-row-move' ).remove();
			this.$el.addClass('so-row-no-move');
		}
		if( !$.trim( this.$('.so-row-toolbar').html() ).length ) {
			this.$('.so-row-toolbar' ).remove();
		}

		// Resize the rows when ever the widget sortable moves
		this.builder.on( 'widget_sortable_move', this.resize, this );
		this.builder.on( 'builder_resize', this.resize, this );

		this.resize();

		return this;
	},

	/**
	 * Give a visual indication of the creation of this row
	 */
	visualCreate: function () {
		this.$el.hide().fadeIn( 'fast' );
	},

	/**
	 * Visually resize the row so that all cell heights are the same and the widths so that they balance to 100%
	 *
	 * @param e
	 */
	resize: function ( e ) {
		// Don't resize this
		if ( ! this.$el.is( ':visible' ) ) {
			return;
		}

		// Reset everything to have an automatic height
		this.$( '.so-cells .cell-wrapper' ).css( 'min-height', 0 );

		// We'll tie the values to the row view, to prevent issue with values going to different rows
		var height = 0;
		this.$( '.so-cells .cell' ).each( function () {
			height = Math.max(
				height,
				$( this ).height()
			);

			$( this ).css(
				'width',
				( $( this ).data( 'view' ).model.get( 'weight' ) * 100) + "%"
			);
		} );

		// Resize all the grids and cell wrappers
		this.$( '.so-cells .cell-wrapper' ).css( 'min-height', Math.max( height, 64 ) );
	},

	/**
	 * Remove the view from the dom.
	 */
	onModelDestroy: function () {
		this.remove();
	},

	/**
	 * Fade out the view and destroy the model
	 */
	visualDestroyModel: function () {
		this.builder.addHistoryEntry( 'row_deleted' );
		var thisView = this;
		this.$el.fadeOut( 'normal', function () {
			thisView.model.destroy();
			thisView.builder.model.refreshPanelsData();
		} );
	},

	/**
	 * Duplicate this row.
	 *
	 * @return {boolean}
	 */
	duplicateHandler: function () {
		this.builder.addHistoryEntry( 'row_duplicated' );

		var duplicateRow = this.model.clone( this.builder.model );

		this.builder.model.rows.add( duplicateRow, {
			at: this.builder.model.rows.indexOf( this.model ) + 1
		} );

		this.builder.model.refreshPanelsData();
	},

	/**
	 * Handles deleting the row with a confirmation.
	 */
	confirmedDeleteHandler: function ( e ) {
		var $$ = $( e.target );

		// The user clicked on the dashicon
		if ( $$.hasClass( 'dashicons' ) ) {
			$$ = $.parent();
		}

		if ( $$.hasClass( 'so-confirmed' ) ) {
			this.visualDestroyModel();
		} else {
			var originalText = $$.html();

			$$.addClass( 'so-confirmed' ).html(
				'<span class="dashicons dashicons-yes"></span>' + panelsOptions.loc.dropdown_confirm
			);

			setTimeout( function () {
				$$.removeClass( 'so-confirmed' ).html( originalText );
			}, 2500 );
		}
	},

	/**
	 * Handle displaying the settings dialog
	 */
	editSettingsHandler: function () {
		// Lets open up an instance of the settings dialog
		if ( this.dialog === null ) {
			// Create the dialog
			this.dialog = new panels.dialog.row();
			this.dialog.setBuilder( this.builder ).setRowModel( this.model );
		}

		this.dialog.openDialog();

		return this;
	},

	/**
	 * Handle deleting this entire row.
	 */
	deleteHandler: function () {
		this.model.destroy();
		return this;
	},

	/**
	 * Handle a new cell being added to this row view. For now we'll assume the new cell is always last
	 */
	handleCellAdd: function ( cell ) {
		var cellView = new panels.view.cell( {
			model: cell
		} );
		cellView.row = this;
		cellView.render();
		cellView.$el.appendTo( this.$( '.so-cells' ) );
	},

	/**
	 * Handle a cell being removed from this row view
	 */
	handleCellRemove: function ( cell ) {
		// Find the view that ties in to the cell we're removing
		this.$( '.so-cells > .cell' ).each( function () {
			var view = $( this ).data( 'view' );
			if ( _.isUndefined( view ) ) {
				return;
			}

			if ( view.model.cid === cell.cid ) {
				// Remove this view
				view.remove();
			}
		} );
	},

	/**
	 * Build up the contextual menu for a row
	 *
	 * @param e
	 * @param menu
	 */
	buildContextualMenu: function ( e, menu ) {
		var thisView = this;

		var options = [];
		for ( var i = 1; i < 5; i ++ ) {
			options.push( {
				title: i + ' ' + panelsOptions.loc.contextual.column
			} );
		}

		if( this.builder.supports( 'addRow' ) ) {
			menu.addSection(
				{
					sectionTitle: panelsOptions.loc.contextual.add_row,
					search: false
				},
				options,
				function ( c ) {
					thisView.builder.addHistoryEntry( 'row_added' );

					var columns = Number( c ) + 1;
					var weights = [];
					for ( var i = 0; i < columns; i ++ ) {
						weights.push( 100 / columns );
					}

					// Create the actual row
					var newRow = new panels.model.row( {
						collection: thisView.collection
					} );

					newRow.setCells( weights );
					newRow.builder = thisView.builder;

					thisView.builder.model.rows.add( newRow, {
						at: thisView.builder.model.rows.indexOf( thisView.model ) + 1
					} );

					thisView.builder.model.refreshPanelsData();
				}
			);
		}

		actions = {};

		if( this.builder.supports( 'editRow' ) ) {
			actions.edit = { title: panelsOptions.loc.contextual.row_edit };
		}
		if( this.builder.supports( 'addRow' ) ) {
			actions.duplicate = { title: panelsOptions.loc.contextual.row_duplicate };
		}
		if( this.builder.supports( 'deleteRow' ) ) {
			actions.delete = { title: panelsOptions.loc.contextual.row_delete, confirm: true };
		}

		if( ! _.isEmpty( actions ) ) {
			menu.addSection(
				{
					sectionTitle: panelsOptions.loc.contextual.row_actions,
					search: false,
				},
				actions,
				function ( c ) {
					switch ( c ) {
						case 'edit':
							thisView.editSettingsHandler();
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
		}
	}

} );

},{}],24:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {

	stylesLoaded: false,

	initialize: function () {

	},

	/**
	 * Render the visual styles object.
	 *
	 * @param type
	 * @param postId
	 */
	render: function ( stylesType, postId, args ) {
		if ( _.isUndefined( stylesType ) ) {
			return;
		}

		// Add in the default args
		args = _.extend( {
			builderType: '',
			dialog: null
		}, args );

		this.$el.addClass( 'so-visual-styles' );

		// Load the form
		var thisView = this;
		$.post(
			panelsOptions.ajaxurl,
			{
				action: 'so_panels_style_form',
				type: stylesType,
				style: this.model.get( 'style' ),
				args: JSON.stringify( {
					builderType: args.builderType
				} ),
				postId: postId
			},
			function ( response ) {
				thisView.$el.html( response );
				thisView.setupFields();
				thisView.stylesLoaded = true;
				thisView.trigger( 'styles_loaded', ! _.isEmpty( response ) );
				if ( ! _.isNull( args.dialog ) ) {
					args.dialog.trigger( 'styles_loaded', ! _.isEmpty( response ) );
				}
			}
		);

		return this;
	},

	/**
	 * Attach the style view to the DOM.
	 *
	 * @param wrapper
	 */
	attach: function ( wrapper ) {
		wrapper.append( this.$el );
	},

	/**
	 * Detach the styles view from the DOM
	 */
	detach: function () {
		this.$el.detach();
	},

	/**
	 * Setup all the fields
	 */
	setupFields: function () {

		// Set up the sections as collapsible
		this.$( '.style-section-wrapper' ).each( function () {
			var $s = $( this );

			$s.find( '.style-section-head' ).click( function ( e ) {
				e.preventDefault();
				$s.find( '.style-section-fields' ).slideToggle( 'fast' );
			} );
		} );

		// Set up the color fields
		if ( ! _.isUndefined( $.fn.wpColorPicker ) ) {
			if ( _.isObject( panelsOptions.wpColorPickerOptions.palettes ) && ! $.isArray( panelsOptions.wpColorPickerOptions.palettes ) ) {
				panelsOptions.wpColorPickerOptions.palettes = $.map( panelsOptions.wpColorPickerOptions.palettes, function ( el ) {
					return el;
				} );
			}
			this.$( '.so-wp-color-field' ).wpColorPicker( panelsOptions.wpColorPickerOptions );
		}

		// Set up the image select fields
		this.$( '.style-field-image' ).each( function () {
			var frame = null;
			var $s = $( this );

			$s.find( '.so-image-selector' ).click( function ( e ) {
				e.preventDefault();

				if ( frame === null ) {
					// Create the media frame.
					frame = wp.media( {
						// Set the title of the modal.
						title: 'choose',

						// Tell the modal to show only images.
						library: {
							type: 'image'
						},

						// Customize the submit button.
						button: {
							// Set the text of the button.
							text: 'Done',
							close: true
						}
					} );

					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().attributes;

						var url = attachment.url;
						if ( ! _.isUndefined( attachment.sizes ) ) {
							try {
								url = attachment.sizes.thumbnail.url;
							}
							catch ( e ) {
								// We'll use the full image instead
								url = attachment.sizes.full.url;
							}
						}
						$s.find( '.current-image' ).css( 'background-image', 'url(' + url + ')' );

						// Store the ID
						$s.find( 'input' ).val( attachment.id )
					} );
				}

				frame.open();

			} );

			// Handle clicking on remove
			$s.find( '.remove-image' ).click( function ( e ) {
				e.preventDefault();
				$s.find( '.current-image' ).css( 'background-image', 'none' );
				$s.find( 'input' ).val( '' );
			} );
		} );

		// Set up all the measurement fields
		this.$( '.style-field-measurement' ).each( function () {
			var $$ = $( this );

			var text = $$.find( 'input[type="text"]' );
			var unit = $$.find( 'select' );
			var hidden = $$.find( 'input[type="hidden"]' );

			text.focus( function(){
				$(this).select();
			} );

			/**
			 * Load value into the visible input fields.
			 * @param value
             */
			var loadValue = function( value ) {
				if( value === '' ) {
					return;
				}

				var re = /(?:([0-9\.,]+)(.*))+/;
				var valueList = hidden.val().split( ' ' );
				var valueListValue = [];
				for ( var i in valueList ) {
					var match = re.exec( valueList[i] );
					if ( ! _.isNull( match ) && ! _.isUndefined( match[1] ) && ! _.isUndefined( match[2] ) ) {
						valueListValue.push( match[1] );
						unit.val( match[2] );
					}
				}

				if( text.length === 1 ) {
					// This is a single input text field
					text.val( valueListValue.join( ' ' ) );
				}
				else {
					// We're dealing with a multiple field
					if( valueListValue.length === 1 ) {
						valueListValue = [ valueListValue[0], valueListValue[0], valueListValue[0], valueListValue[0] ];
					}
					else if( valueListValue.length === 2 ) {
						valueListValue = [ valueListValue[0], valueListValue[1], valueListValue[0], valueListValue[1] ];
					}
					else if( valueListValue.length === 3 ) {
						valueListValue = [ valueListValue[0], valueListValue[1], valueListValue[2], valueListValue[1] ];
					}

					// Store this in the visible fields
					text.each( function( i, el ) {
						$( el ).val( valueListValue[i] );
					} );
				}
			};
			loadValue( hidden.val() );

			/**
			 * Set value of the hidden field based on inputs
			 */
			var setValue = function( e ){
				var i;

				if( text.length === 1 ) {
					// We're dealing with a single measurement
					var fullString = text
						.val()
						.split( ' ' )
						.filter( function ( value ) {
							return value !== '';
						} )
						.map( function ( value ) {
							return value + unit.val();
						} )
						.join( ' ' );
					hidden.val( fullString );
				}
				else {
					var target = $( e.target ),
						valueList = [],
						emptyIndex = [],
						fullIndex = [];

					text.each( function( i, el ) {
						var value = $( el ).val( ) !== '' ? parseFloat( $( el ).val( ) ) : null;
						valueList.push( value );

						if( value === null ) {
							emptyIndex.push( i );
						}
						else {
							fullIndex.push( i );
						}
					} );

					if( emptyIndex.length === 3 && fullIndex[0] === text.index( target ) ) {
						text.val( target.val() );
						valueList = [ target.val(), target.val(), target.val(), target.val() ];
					}

					if( JSON.stringify( valueList ) === JSON.stringify( [ null, null, null, null ] ) ) {
						hidden.val('');
					}
					else {
						hidden.val( valueList.map( function( k ){
							return ( k === null ? 0 : k ) + unit.val();
						} ).join( ' ' ) );
					}
				}
			};

			// Set the value when ever anything changes
			text.change( setValue );
			unit.change( setValue );
		} );
	}

} );

},{}],25:[function(require,module,exports){
var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( $( '#siteorigin-panels-builder-widget' ).html().panelsProcessTemplate() ),

	// The cell view that this widget belongs to
	cell: null,

	// The edit dialog
	dialog: null,

	events: {
		'click .widget-edit': 'editHandler',
		'click .title h4': 'titleClickHandler',
		'click .actions .widget-duplicate': 'duplicateHandler',
		'click .actions .widget-delete': 'deleteHandler'
	},

	/**
	 * Initialize the widget
	 */
	initialize: function () {
		// The 2 user actions on the model that this view will handle.
		this.model.on( 'user_edit', this.editHandler, this );                 // When a user wants to edit the widget model
		this.model.on( 'user_duplicate', this.duplicateHandler, this );       // When a user wants to duplicate the widget model
		this.model.on( 'destroy', this.onModelDestroy, this );
		this.model.on( 'visual_destroy', this.visualDestroyModel, this );

		this.model.on( 'change:values', this.onModelChange, this );
	},

	/**
	 * Render the widget
	 */
	render: function ( options ) {
		options = _.extend( {'loadForm': false}, options );

		this.setElement( this.template( {
			title: this.model.getWidgetField( 'title' ),
			description: this.model.getTitle()
		} ) );

		this.$el.data( 'view', this );

		// Remove any unsupported actions
		if( ! this.cell.row.builder.supports( 'editWidget' ) || this.model.get( 'read_only' ) ) {
			this.$( '.actions .widget-edit' ).remove();
			this.$el.addClass('so-widget-no-edit');
		}
		if( ! this.cell.row.builder.supports( 'addWidget' ) ) {
			this.$( '.actions .widget-duplicate' ).remove();
			this.$el.addClass('so-widget-no-duplicate');
		}
		if( ! this.cell.row.builder.supports( 'deleteWidget' ) ) {
			this.$( '.actions .widget-delete' ).remove();
			this.$el.addClass('so-widget-no-delete');
		}
		if( ! this.cell.row.builder.supports( 'moveWidget' ) ) {
			this.$el.addClass('so-widget-no-move');
		}
		if( !$.trim( this.$('.actions').html() ).length ) {
			this.$( '.actions' ).remove();
		}

		if( this.model.get( 'read_only' ) ) {
			this.$el.addClass('so-widget-read-only');
		}

		if ( _.size( this.model.get( 'values' ) ) === 0 || options.loadForm ) {
			// If this widget doesn't have a value, create a form and save it
			var dialog = this.getEditDialog();

			// Save the widget as soon as the form is loaded
			dialog.once( 'form_loaded', dialog.saveWidget, dialog );

			// Setup the dialog to load the form
			dialog.setupDialog();
		}

		return this;
	},

	/**
	 * Display an animation that implies creation using a visual animation
	 */
	visualCreate: function () {
		this.$el.hide().fadeIn( 'fast' );
	},

	/**
	 * Get the dialog view of the form that edits this widget
	 *
	 * @returns {null}
	 */
	getEditDialog: function () {
		if ( this.dialog === null ) {
			this.dialog = new panels.dialog.widget( {
				model: this.model
			} );
			this.dialog.setBuilder( this.cell.row.builder );

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
	editHandler: function () {
		// Create a new dialog for editing this
		this.getEditDialog().openDialog();
		return this;
	},

	titleClickHandler: function(){
		if( ! this.cell.row.builder.supports( 'editWidget' ) || this.model.get( 'read_only' ) ) {
			return this;
		}
		this.editHandler();
		return this;
	},

	/**
	 * Handle clicking on duplicate.
	 *
	 * @returns {boolean}
	 */
	duplicateHandler: function () {
		// Add the history entry
		this.cell.row.builder.addHistoryEntry( 'widget_duplicated' );

		// Create the new widget and connect it to the widget collection for the current row
		var newWidget = this.model.clone( this.model.cell );

		this.cell.model.widgets.add( newWidget, {
			// Add this after the existing model
			at: this.model.collection.indexOf( this.model ) + 1
		} );

		this.cell.row.builder.model.refreshPanelsData();
		return this;
	},

	/**
	 * Handle clicking on delete.
	 *
	 * @returns {boolean}
	 */
	deleteHandler: function () {
		this.model.trigger( 'visual_destroy' );
		return this;
	},

	onModelChange: function () {
		// Update the description when ever the model changes
		this.$( '.description' ).html( this.model.getTitle() );
	},

	/**
	 * When the model is destroyed, fade it out
	 */
	onModelDestroy: function () {
		this.remove();
	},

	/**
	 * Visually destroy a model
	 */
	visualDestroyModel: function () {
		// Add the history entry
		this.cell.row.builder.addHistoryEntry( 'widget_deleted' );

		var thisView = this;
		this.$el.fadeOut( 'fast', function () {
			thisView.cell.row.resize();
			thisView.model.destroy();
			thisView.cell.row.builder.model.refreshPanelsData();
			thisView.remove();
		} );

		return this;
	},

	/**
	 * Build up the contextual menu for a widget
	 *
	 * @param e
	 * @param menu
	 */
	buildContextualMenu: function ( e, menu ) {
		var thisView = this;

		if( this.cell.row.builder.supports( 'addWidget' ) ) {
			menu.addSection(
				{
					sectionTitle: panelsOptions.loc.contextual.add_widget_below,
					searchPlaceholder: panelsOptions.loc.contextual.search_widgets,
					defaultDisplay: panelsOptions.contextual.default_widgets
				},
				panelsOptions.widgets,
				function ( c ) {
					thisView.cell.row.builder.addHistoryEntry( 'widget_added' );

					var widget = new panels.model.widget( {
						class: c
					} );
					widget.cell = thisView.cell.model;

					// Insert the new widget below
					thisView.cell.model.widgets.add( widget, {
						// Add this after the existing model
						at: thisView.model.collection.indexOf( thisView.model ) + 1
					} );

					thisView.cell.row.builder.model.refreshPanelsData();
				}
			);
		}

		var actions = {};
		if( this.cell.row.builder.supports( 'editWidget' ) && ! this.model.get( 'read_only' ) ) {
			actions.edit = { title: panelsOptions.loc.contextual.widget_edit };
		}
		if( this.cell.row.builder.supports( 'addWidget' ) ) {
			actions.duplicate = { title: panelsOptions.loc.contextual.widget_duplicate };
		}
		if( this.cell.row.builder.supports( 'deleteWidget' ) ) {
			actions.delete = { title: panelsOptions.loc.contextual.widget_delete, confirm: true };
		}

		if( ! _.isEmpty( actions ) ) {
			menu.addSection(
				{
					sectionTitle: panelsOptions.loc.contextual.widget_actions,
					search: false,
				},
				actions,
				function ( c ) {
					switch ( c ) {
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

					thisView.cell.row.builder.model.refreshPanelsData();
				}
			);
		}

		// Lets also add the contextual menu for the entire row
		this.cell.row.buildContextualMenu( e, menu );
	}

} );

},{}]},{},[12]);
