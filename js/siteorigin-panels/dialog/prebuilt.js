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
