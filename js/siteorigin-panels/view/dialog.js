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
					if ( typeof tinyMCE !== 'undefined' ) {
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
