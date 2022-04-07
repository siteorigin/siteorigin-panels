var panels = window.panels, $ = jQuery;
var jsWidget = require( '../view/widgets/js-widget' );

module.exports = panels.view.dialog.extend( {

	builder: null,
	sidebarWidgetTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-dialog-widget-sidebar-widget' ).html() ) ),

	dialogClass: 'so-panels-dialog-edit-widget',
    dialogIcon: 'add-widget',

	widgetView: false,
	savingWidget: false,
	editableLabel: true,

	events: {
		'click .so-close': 'saveHandler',
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

		// Action handlers
		'click .so-toolbar .so-delete': 'deleteHandler',
		'keyup .so-toolbar .so-delete': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-toolbar .so-duplicate': 'duplicateHandler',
		'keyup .so-toolbar .so-duplicate': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
	},

	initializeDialog: function () {
		var thisView = this;
		this.listenTo( this.model, 'change:values', this.handleChangeValues );
		this.listenTo( this.model, 'destroy', this.remove );

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

		this.on( 'edit_label', function ( text ) {
			// If text is set to default value, just clear it.
			if ( text === panelsOptions.widgets[ this.model.get( 'class' ) ][ 'title' ] ) {
				text = '';
			}
			this.model.set( 'label', text );
			if ( _.isEmpty( text ) ) {
				this.$( '.so-title' ).text( this.model.getWidgetField( 'title' ) );
			}
		}.bind( this ) );

		this.on( 'open_dialog_complete', function() {
			// The form isn't always ready when this event fires.
			setTimeout( function() {
				var focusTarget = $( '.so-content .siteorigin-widget-field-repeater-item-top, .so-content input, .so-content select' ).first();
				if ( focusTarget.length ) {
					focusTarget.trigger( 'focus' );
				} else {
					$( '.so-panels-dialog-wrapper .so-title' ).trigger( 'focus' );
				}
			}, 1250 )
		} );
	},

	/**
	 * Render the widget dialog.
	 */
	render: function () {
		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-widget' ).html(), {} ) );
		this.loadForm();

		var title = this.model.getWidgetField( 'title' );
		this.$( '.so-title .widget-name' ).html( title );
		this.$( '.so-edit-title' ).val( title );

		if( ! this.builder.supports( 'addWidget' ) ) {
			this.$( '.so-buttons .so-duplicate' ).remove();
		}
		if( ! this.builder.supports( 'deleteWidget' ) ) {
			this.$( '.so-buttons .so-delete' ).remove();
		}

		// Now we need to attach the style window
		this.styles = new panels.view.styles();
		this.styles.model = this.model;
		this.styles.render( 'widget', this.builder.config.postId, {
			builderType: this.builder.config.builderType,
			dialog: this
		} );

		var $rightSidebar = this.$( '.so-sidebar.so-right-sidebar' );
		this.styles.attach( $rightSidebar );

		// Handle the loading class
		this.styles.on( 'styles_loaded', function ( hasStyles ) {
			// If we don't have styles remove the empty sidebar.
			if ( ! hasStyles ) {
				$rightSidebar.closest( '.so-panels-dialog' ).removeClass( 'so-panels-dialog-has-right-sidebar' );
				$rightSidebar.remove();
			}
		}, this );
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
			var widgetView;
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

		var currentIndex = widgets.index( this.widgetView.$el );

		if ( currentIndex === widgets.length - 1 ) {
			return false;
		} else {
			var widgetView;
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

		this.$( '.so-content' ).addClass( 'so-panels-loading' );

		var data = {
			'action': 'so_panels_widget_form',
			'widget': this.model.get( 'class' ),
			'instance': JSON.stringify( this.model.get( 'values' ) ),
			'raw': this.model.get( 'raw' ),
			'postId': this.builder.config.postId
		};

		var $soContent = this.$( '.so-content' );

		$.post( panelsOptions.ajaxurl, data, null, 'html' )
		.done( function ( result ) {
			// Add in the CID of the widget model
			var html = result.replace( /{\$id}/g, this.model.cid );

			// Load this content into the form
			$soContent
			.removeClass( 'so-panels-loading' )
			.html( html );

			// Trigger all the necessary events
			this.trigger( 'form_loaded', this );

			// For legacy compatibility, trigger a panelsopen event
			this.$( '.panel-dialog' ).trigger( 'panelsopen' );

			// If the main dialog is closed from this point on, save the widget content
			this.on( 'close_dialog', this.updateModel, this );

			var widgetContent = $soContent.find( '> .widget-content' );
			// If there's a widget content wrapper, this is one of the new widgets in WP 4.8 which need some special
			// handling in JS.
			if ( widgetContent.length > 0 ) {
				jsWidget.addWidget( $soContent, this.model.widget_id );
			}

		}.bind( this ) )
		.fail( function ( error ) {
			var html;
			if ( error && error.responseText ) {
				html = error.responseText;
			} else {
				html = panelsOptions.forms.loadingFailed;
			}

			$soContent
			.removeClass( 'so-panels-loading' )
			.html( html );
		} );
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
			var newStyles = {};
			try {
				newStyles = this.getFormValues( '.so-sidebar .so-visual-styles' ).style;
			}
			catch ( e ) {
			}

			// Have there been any Style changes?
			if ( JSON.stringify( this.model.attributes.style ) !== JSON.stringify( newStyles ) ) {
				this.model.set( 'style', newStyles );
				this.model.trigger( 'change:styles' );
			}
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
		this.widgetView.visualDestroyModel();
		this.closeDialog( {silent: true} );
		this.builder.model.refreshPanelsData();

		return false;
	},

	duplicateHandler: function () {
		// Call the widget duplicate handler directly
		this.widgetView.duplicateHandler();

		this.closeDialog( {silent: true} );
		this.builder.model.refreshPanelsData();

		return false;
	}

} );
