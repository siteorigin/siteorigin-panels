var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	directoryTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-directory-items' ).html() ) ),

	builder: null,
	dialogClass: 'so-panels-dialog-prebuilt-layouts',
	dialogIcon: 'layouts',

	currentTab: false,
	directoryPage: 1,
	itemsPer: 16,
	activeFilter: [],

	events: {
		'click .so-close': 'closeDialog',
		'click .so-sidebar-tabs li a': 'tabClickHandler',
		'click .so-content .layout': 'layoutClickHandler',
		'keyup .so-sidebar-search': 'searchHandler',

		// The directory items.
		'click .so-screenshot, .so-title': 'directoryItemClickHandler',
		'keyup .so-directory-item': 'clickTitleOnEnter',

		// Filtering.
		'click .so-directory-items-filter-categories li': 'filterCategory',
		'click .so-directory-items-filter-niches li': 'filterNiches',

		'click .so-previous': 'previousPage',
		'click .so-next': 'nextPage',
	},

	clickTitleOnEnter: function( e ) {
		if ( e.which == 13 ) {
			$( e.target ).find( '.so-title' ).trigger( 'click' );
		}
	},

	/**
	 * Initialize the prebuilt dialog.
	 */
	initializeDialog: function () {
		var thisView = this;

		this.on( 'open_dialog', function () {
			thisView.$( '.so-sidebar-tabs li a' ).first().trigger( 'click' );
			thisView.$( '.so-status' ).removeClass( 'so-panels-loading' );
		} );

		this.on( 'open_dialog_complete', function () {
			// Clear the search and re-filter the widgets when we open the dialog
			this.$( '.so-sidebar-search' ).val( '' ).trigger( 'focus' );
		} );
	},

	/**
	 * Render the prebuilt layouts dialog
	 */
	render: function () {
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-prebuilt' ).html(), {} ) );
		this.on( 'button_click', this.toolbarButtonClick, this );
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
		var uploadUi = thisView.$( '.import-upload-ui' );

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
					uploadUi.find( '.progress-precent' ).css( 'width', '0%' );
				},
				FilesAdded: function ( uploader ) {
					uploadUi.find( '.file-browse-button' ).trigger( 'blur' );
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

		if ( /Edge\/\d./i.test(navigator.userAgent) ){
			// A very dirty fix for a Microsoft Edge issue.
			// TODO find a more elegant fix if Edge gains market share
			setTimeout( function(){
				uploader.refresh();
			}, 250 );
		}

		// This is
		uploadUi.find( '.drag-upload-area' )
			.on( 'dragover', function () {
				$( this ).addClass( 'file-dragover' );
			} )
			.on( 'dragleave', function () {
				$( this ).removeClass( 'file-dragover' );
			} );

		// Handle exporting the file
		c.find( '.so-export' ).on( 'submit', function( e ) {
			var $$ = $( this );
			var panelsData = thisView.builder.model.getPanelsData();
			var postName = $( 'input[name="post_title"], .editor-post-title__input' ).val();
			if ( ( ! postName || postName === '' ) && $( '.block-editor-page' ).length ) {
				postName = $( '.wp-block-post-title' ).text();
			}
			panelsData.name = postName !== '' ? postName : $( 'input[name="post_ID"]' ).val();

			// Append block position id to filename.
			if ( $( '.block-editor-page' ).length ) {
				var currentBlockPosition = thisView.getCurrentBlockPosition();
				if ( currentBlockPosition >= 0 ) {
					panelsData.name += '-' + currentBlockPosition;
				}
			}
			$$.find( 'input[name="panels_export_data"]' ).val( JSON.stringify( panelsData ) );
		} );

	},

	/**
	 * Return current block index.
	 */
	getCurrentBlockPosition: function() {
		var selectedBlockClientId = wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
		return wp.data.select( 'core/block-editor' ).getBlocks().findIndex( function ( block ) {
			return block.clientId === selectedBlockClientId;
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
			type = 'directory-siteorigin';
		}

		if ( type.match( '^directory-' ) && ! panelsOptions.directory_enabled ) {
			// Display the button to enable the prebuilt layout
			c.removeClass( 'so-panels-loading' ).html( $( '#siteorigin-panels-directory-enable' ).html() );
			c.find( '.so-panels-enable-directory' ).on( 'click', function( e ) {
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
				thisView.displayLayoutDirectory( search, page, type );
			} );
			return;
		}

		// Get all the items for the current query
		$.get(
			panelsOptions.ajaxurl,
			{
				action: 'so_panels_layouts_query',
				page: page,
				type: type,
				search: search,
				builderType: this.builder.config.builderType,
			},
			function ( data ) {
				// Skip this if we're no longer viewing the layout directory
				if ( thisView.currentTab !== type ) {
					return;
				}

				// Add the directory items
				c.removeClass( 'so-panels-loading' ).html( thisView.directoryTemplate( data ) );

				// Depending on the active type, we need to handle things slightly differently.
				if ( type.match( '^directory-' ) ) {
					thisView.directoryPage = page;
					thisView.updatePagination();
				} else {
					// Lets setup the next and previous buttons
					var prev = c.find( '.so-previous' ), next = c.find( '.so-next' );

					if ( page <= 1 ) {
						prev.addClass( 'button-disabled' );
					} else {
						prev.on( 'click', function( e ) {
							e.preventDefault();
							thisView.displayLayoutDirectory( search, page - 1, thisView.currentTab );
						} );
					}

					if ( page === data.max_num_pages || data.max_num_pages === 0 ) {
						next.addClass( 'button-disabled' );
					} else {
						next.on( 'click', function( e ) {
							e.preventDefault();
							thisView.displayLayoutDirectory( search, page + 1, thisView.currentTab );
						} );
					}

					thisView.$( '.so-directory-items' ).toggleClass( 'so-empty', ! data.items.length );
				}

				if ( page <= 1 ) {
					c.find( '.so-previous' ).addClass( 'button-disabled' );
				}

				// Handle nice preloading of the screenshots
				c.find( '.so-screenshot' ).each( function () {
					var $$ = $( this ), $a = $$.find( '.so-screenshot-wrapper' );
					$a.css( 'height', ( $a.width() / 4 * 3 ) + 'px' ).addClass( 'so-loading' );

					if ( $$.data( 'src' ) !== '' ) {
						// Set the initial height
						var $img = $( '<img/>' ).attr( 'src', $$.data( 'src' ) ).on( 'load', function () {
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

	filterLayouts: function() {
		this.directoryPage = 1;
		this.$( '.so-directory-item ' ).removeClass( 'so-filter' );

		var category = this.$( '.so-directory-items-filter-categories li.so-active-filter' ).data( 'filter' );
		var niches = this.$( '.so-directory-items-filter-niches li.so-active-filter' ).map( function() {
		  return $( this ).data( 'filter' );
		} ).get();

		this.activeFilter = category + niches.map( function( filter ) {
			return filter;
		} ).join( '' );


		if ( this.activeFilter.length ) {
			this.$( '.so-directory-items-wrapper' ).find( this.activeFilter ).addClass( 'so-filter' );

		}

		this.updatePagination();

		this.$( '.so-directory-items' ).toggleClass(
			'so-empty',
			! this.$( '.so-directory-item' ).filter(':visible' ).length
		);
	},

	getItems: function() {
		if ( this.activeFilter.length ) {
			return this.$( '.so-directory-items-wrapper .so-filter:not(.so-search-hidden)' );
		} else {
			return this.$( '.so-directory-items-wrapper .so-directory-item:not(.so-search-hidden)' );
		}
	},

	updatePagination: function() {
		var $items = this.getItems();

		// Hide any items not on the current page.
		var startIndex = ( this.directoryPage - 1 ) * this.itemsPer;
		var endIndex = startIndex + this.itemsPer;

		this.$( '.so-directory-items-wrapper .so-directory-item' ).addClass( 'so-hidden' );
		$items.slice( startIndex, endIndex ).removeClass( 'so-hidden' );

		this.$( '.so-previous' ).toggleClass( 'button-disabled', this.directoryPage === 1 );
		this.$( '.so-next' ).toggleClass( 'button-disabled', this.directoryPage === Math.ceil( $items.length / this.itemsPer ) );
	},

	previousPage: function() {
		if (this.directoryPage > 1) {
			this.directoryPage--;
			this.updatePagination();
		}
	},

	nextPage: function() {
		var numPages = Math.ceil( this.getItems().length / this.itemsPer );
		if ( this.directoryPage < numPages ) {
			this.directoryPage++;
			this.updatePagination();
		}
	},

	filterCategory: function( e ) {
		this.$( '.so-directory-items-filter-categories li.so-active-filter' ).removeClass( 'so-active-filter' );
		$( e.target ).addClass( 'so-active-filter' );
		this.filterLayouts();
	},

	filterNiches: function( e ) {
		$( e.target ).toggleClass( 'so-active-filter' );
		this.filterLayouts();
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

		var args = _.extend(
			this.selectedLayoutItem,
			{
				action: 'so_panels_get_layout',
				builderType: this.builder.config.builderType
			}
		);
		var deferredLayout = new $.Deferred();

		$.get(
			panelsOptions.ajaxurl,
			args,
			function ( layout ) {
				var msg = '';
				if ( ! layout.success ) {
					msg = layout.data.message;
					deferredLayout.reject( layout.data );
				} else {
					deferredLayout.resolve( layout.data );
				}
				this.setStatusMessage( msg, false, ! layout.success );
				this.updateButtonState( true );
			}.bind( this )
		);
		return deferredLayout.promise();
	},

	filterByTitle: function( title ) {

		if ( title === '' ) {
			this.$( '.so-directory-items-wrapper .so-directory-item' ).removeClass( 'so-hidden so-search-hidden' );
		} else {
			this.$( '.so-directory-items' ).removeClass( 'so-search-hidden' );
			var $items = this.getItems();

			$items.each( function() {
				let $$ = $( this );
				let found = $$.find( '.so-title' ).text().toLowerCase().includes( title );

				if ( found && $$.hasClass( 'so-hidden' ) ) {
					$$.removeClass( 'so-hidden so-search-hidden' )
				} else if ( ! found ) {
					$$.addClass( 'so-hidden so-search-hidden' );
				}
			} );

		}

		// Show or hide the no results message.
		this.$( '.so-directory-items' ).toggleClass(
			'so-empty',
			! this.$('.so-directory-items-wrapper .so-directory-item:not(.so-hidden)').length
		);

		this.updatePagination();
	},

	/**
	 * Handle an update to the search
	 */
	searchHandler: function ( e ) {
		if ( e.keyCode === 13 ) {
			var search = $( e.currentTarget ).val().toLowerCase();
			if ( this.currentTab.match( '^directory-' ) ) {
				this.filterByTitle( search );
			} else {
				this.displayLayoutDirectory( search, 1, this.currentTab );
			}
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
