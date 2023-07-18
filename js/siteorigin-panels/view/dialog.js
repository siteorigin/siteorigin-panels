var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	dialogTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-dialog' ).html() ) ),
	dialogTabTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-dialog-tab' ).html() ) ),

	tabbed: false,
	rendered: false,
	builder: false,
	className: 'so-panels-dialog-wrapper',
	dialogClass: '',
	dialogIcon: '',
	parentDialog: false,
	dialogOpen: false,
	editableLabel: false,

	events: {
		'click .so-close': 'closeDialog',
		'keyup .so-close': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-nav.so-previous': 'navToPrevious',
		'keyup .so-nav.so-previous': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-nav.so-next': 'navToNext',
		'keyup .so-nav.so-next': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
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
		
		_.bindAll( this, 'initSidebars', 'hasSidebar', 'onResize', 'toggleLeftSideBar', 'toggleRightSideBar' );
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
			_.template( panels.helpers.utils.processTemplate( html ) )
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
		attributes = _.extend( {
			editableLabel: this.editableLabel,
			dialogIcon: this.dialogIcon,
		}, attributes );

		this.$el.html( this.dialogTemplate( attributes ) ).hide();
		this.$el.data( 'view', this );
		this.$el.addClass( 'so-panels-dialog-wrapper' );

		if ( this.parentDialog !== false ) {
			// Add a link to the parent dialog as a sort of crumbtrail.
			var dialogParent = $( '<h3 class="so-parent-link"></h3>' ).html( this.parentDialog.text + '<div class="so-separator"></div>' );
			dialogParent.on( 'click', function( e ) {
				e.preventDefault();
				this.closeDialog();
				this.parentDialog.dialog.openDialog();
			}.bind(this) );
			this.$( '.so-title-bar .so-title' ).before( dialogParent );
		}

		if( this.$( '.so-title-bar .so-title-editable' ).length ) {
			// Added here because .so-edit-title is only available after the template has been rendered.
			this.initEditableLabel();
		}
		
		setTimeout( this.initSidebars, 1 );

		return this;
	},
	
	initSidebars: function () {
		var $leftButton = this.$( '.so-show-left-sidebar' ).hide();
		var $rightButton = this.$( '.so-show-right-sidebar' ).hide();
		var hasLeftSidebar = this.hasSidebar( 'left' );
		var hasRightSidebar = this.hasSidebar( 'right' );
		// Set up resize handling
		if ( hasLeftSidebar || hasRightSidebar ) {
			$( window ).on( 'resize', this.onResize );
			if ( hasLeftSidebar ) {
				$leftButton.show();
				$leftButton.on( 'click', this.toggleLeftSideBar );
			}
			if ( hasRightSidebar ) {
				$rightButton.show();
				$rightButton.on( 'click', this.toggleRightSideBar );
			}
		}
		
		this.onResize();
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
		tabs.on( 'click', function( e ) {
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
		this.$( '.so-sidebar-tabs li a' ).first().trigger( 'click' );
		return this;
	},

	initToolbar: function () {
		// Trigger simplified click event for elements marked as toolbar buttons.
		var buttons = this.$( '.so-toolbar .so-buttons .so-toolbar-button' );
		buttons.on( 'click keyup', function( e ) {
			e.preventDefault();

			if ( e.type == 'keyup' && e.which != 13 ) {
				return;
			}

			this.trigger( 'button_click', $( e.currentTarget ) );
		}.bind( this ) );

		// Handle showing and hiding the dropdown list items
		var $dropdowns = this.$( '.so-toolbar .so-buttons .so-dropdown-button' );
		$dropdowns.on( 'click', function( e ) {
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
		$( 'html' ).on( 'click', function( e ) {
			this.$( '.so-dropdown-links-wrapper' ).not( '.hidden' ).each( function ( index, el ) {
				var $dropdownList = $( el );
				var $trgt = $( e.target );
				if ( $trgt.length === 0 || !(
						(
							$trgt.is('.so-needs-confirm') && !$trgt.is('.so-confirmed')
						) || $trgt.is('.so-dropdown-button')
					) ) {
					$dropdownList.addClass('hidden');
				}
			} );
		}.bind( this ) );
	},

	/**
	 * Initialize the editable dialog title
	 */
	initEditableLabel: function(){
		var $editElt = this.$( '.so-title-bar .so-title-editable' );

		$editElt.on( 'keypress', function ( event ) {
				var enterPressed = event.type === 'keypress' && event.keyCode === 13;
				if ( enterPressed ) {
					// Need to make sure tab focus is on another element, otherwise pressing enter multiple times refocuses
					// the element and allows newlines.
					var tabbables = $( ':tabbable' );
					var curTabIndex = tabbables.index( $editElt );
					tabbables.eq( curTabIndex + 1 ).trigger( 'focus' );
					// After the above, we're somehow left with the first letter of text selected,
					// so this removes the selection.
					window.getSelection().removeAllRanges();
				}
				return ! enterPressed;
			} )
			.on( 'blur', function () {
				var newValue = $editElt.text().replace( /^\s+|\s+$/gm, '' );
				var oldValue = $editElt.data( 'original-value' ).replace( /^\s+|\s+$/gm, '' );
				if ( newValue !== oldValue ) {
					$editElt.text( newValue );
					this.trigger( 'edit_label', newValue );
				}

			}.bind( this ) )
			.on( 'focus', function() {
				$editElt.data( 'original-value', $editElt.text() );
				panels.helpers.utils.selectElementContents( this );
			} );
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
		} else if ( nextDialog === false ) {
			nextButton.addClass( 'so-disabled' );
			nextButton.attr( 'tabindex', -1 );
		} else {
			nextButton.attr( 'tabindex', 0 );
		}

		if ( prevDialog === null ) {
			prevButton.hide();
		} else if ( prevDialog === false ) {
			prevButton.addClass( 'so-disabled' );
			prevButton.attr( 'tabindex', -1 );
		} else {
			prevButton.attr( 'tabindex', 0 );
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
		panels.helpers.pageScroll.lock();

		this.onResize();

		this.$el.show();

		if ( ! options.silent ) {
			// This triggers once everything is visible
			this.trigger( 'open_dialog_complete' );
			this.builder.trigger( 'open_dialog', this );
			$( document ).trigger( 'open_dialog', this );
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

		// Allow for external field validation when attempting to close a widget.
		if ( typeof this.widgetView == 'object' ) {
			var values = this.getFormValues();
			if ( typeof values.widgets == 'object' ) {
				validSave = $( document ).triggerHandler(
					'close_dialog_validation',
					[
						// Widget values.
						values.widgets[ this.model.cid ],
						// Widget Class
						this.model.attributes.class,
						// Model instance - used for finding field markup.
						this.model.cid,
						// Instance.
						this
					]
				);
			}

			if ( typeof validSave == 'boolean' && ! validSave ) {
				return false;
			}
		}

		if ( ! options.silent ) {
			this.trigger( 'close_dialog' );
		}

		this.dialogOpen = false;

		this.$el.hide();
		panels.helpers.pageScroll.unlock();

		if ( ! options.silent ) {
			// This triggers once everything is hidden
			this.trigger( 'close_dialog_complete' );
			this.builder.trigger( 'close_dialog', this );
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

				// Is this field an ACF Repeater?
				if ( $$.parents( '.acf-repeater' ).length ) {
					// If field is empty, skip it - this is to avoid indexes which are admin only.
					if ( fieldValue == '' ) {
						return;
					}

					// Ensure only the standard PB fields are set up.
					// This allows for the rest of the ACF fields to be handled
					// as objects rather than an array.
					parts.slice( parts[2], parts.length );
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
	setStatusMessage: function ( message, loading, error ) {
		var msg = error ? '<span class="dashicons dashicons-warning"></span>' + message : message;
		this.$( '.so-toolbar .so-status' ).html( msg );
		if ( ! _.isUndefined( loading ) && loading ) {
			this.$( '.so-toolbar .so-status' ).addClass( 'so-panels-loading' );
		} else {
			this.$( '.so-toolbar .so-status' ).removeClass( 'so-panels-loading' );
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
	},
	
	onResize: function () {
		var mediaQuery = window.matchMedia( '(max-width: 980px)' );
		var sides = [ 'left', 'right' ];
		
		sides.forEach( function ( side ) {
			var $sideBar = this.$( '.so-' + side + '-sidebar' );
			var $showSideBarButton = this.$( '.so-show-' + side + '-sidebar' );
			if ( this.hasSidebar( side ) ) {
				$showSideBarButton.hide();
				if ( mediaQuery.matches ) {
					$showSideBarButton.show();
					$showSideBarButton.closest( '.so-title-bar' ).addClass( 'so-has-' + side + '-button' );
					$sideBar.hide();
					$sideBar.closest( '.so-panels-dialog' ).removeClass( 'so-panels-dialog-has-' + side + '-sidebar' );
				} else {
					$showSideBarButton.hide();
					$showSideBarButton.closest( '.so-title-bar' ).removeClass( 'so-has-' + side + '-button' );
					$sideBar.show();
					$sideBar.closest( '.so-panels-dialog' ).addClass( 'so-panels-dialog-has-' + side + '-sidebar' );
				}
			} else {
				$sideBar.hide();
				$showSideBarButton.hide();
			}
		}.bind( this ) );
	},
	
	hasSidebar: function ( side ) {
		return this.$( '.so-' + side + '-sidebar' ).children().length > 0;
	},
	
	toggleLeftSideBar: function () {
		this.toggleSidebar( 'left' );
	},
	
	toggleRightSideBar: function () {
		this.toggleSidebar( 'right' );
	},
	
	toggleSidebar: function ( side ) {
		var sidebar = this.$( '.so-' + side + '-sidebar' );
		
		if ( sidebar.is( ':visible' ) ) {
			sidebar.hide();
		} else {
			sidebar.show();
		}
	},
	
} );
