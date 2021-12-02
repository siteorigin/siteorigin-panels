var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-builder-widget' ).html() ) ),

	// The cell view that this widget belongs to
	cell: null,

	// The edit dialog
	dialog: null,

	events: {
		'click .widget-edit': 'editHandler',
		'touchend .widget-edit': 'editHandler',
		'click .title h4': 'editHandler',
		'touchend .title h4': 'editHandler',
		'click .actions .widget-duplicate': 'duplicateHandler',
		'click .actions .widget-delete': 'deleteHandler',
		'keyup .actions a': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
	},

	/**
	 * Initialize the widget
	 */
	initialize: function () {
		this.listenTo(this.model, 'destroy', this.onModelDestroy);
		this.listenTo(this.model, 'change:values', this.onModelChange);
		this.listenTo( this.model, 'change:styles ', this.toggleVisibilityFade );
		this.listenTo(this.model, 'change:label', this.onLabelChange);
	},

	/**
	 * Render the widget
	 */
	render: function ( options ) {
		options = _.extend( {'loadForm': false}, options );

		this.setElement( this.template( {
			title: this.model.getWidgetField( 'title' ),
			description: this.model.getTitle(),
			widget_class: this.model.attributes.class
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

		if ( ! this.$('.actions').html().trim().length ) {
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

		this.toggleVisibilityFade();

		// Add the global builder listeners
		this.listenTo( this.cell.row.builder, 'after_user_adds_widget', this.afterUserAddsWidgetHandler );

		return this;
	},

	checkIfStyleExists: function( styles, setting ) {
		return typeof styles[ setting ] !== 'undefined' && styles[ setting ] == 'on';
	},

	/**
	 * Toggle Visibility: Check if row is hidden and apply fade as needed.
	 */
	toggleVisibilityFade: function() {
		var styles = this.model.attributes.style;
		if ( typeof styles == 'undefined' ) {
			return;
		}
		if (
			this.checkIfStyleExists( styles, 'disable_widget' ) ||
			this.checkIfStyleExists( styles, 'disable_desktop' ) ||
			this.checkIfStyleExists( styles, 'disable_tablet' ) ||
			this.checkIfStyleExists( styles, 'disable_mobile' ) ||
			this.checkIfStyleExists( styles, 'disable_logged_in' ) ||
			this.checkIfStyleExists( styles, 'disable_logged_out' )
		) {
			this.$el.addClass( 'so-hidden-widget' );
		} else {
			this.$el.removeClass( 'so-hidden-widget' );
		}
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
	 */
	editHandler: function () {
		// Create a new dialog for editing this
		if ( ! this.cell.row.builder.supports( 'editWidget' ) || this.model.get( 'read_only' ) ) {
			return this;
		}

		this.getEditDialog().openDialog();
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

		this.cell.model.get('widgets').add( newWidget, {
			// Add this after the existing model
			at: this.model.collection.indexOf( this.model ) + 1
		} );

		this.cell.row.builder.model.refreshPanelsData();
		return this;
	},

	/**
	 * Copy the row to a cookie based clipboard
	 */
	copyHandler: function(){
		panels.helpers.clipboard.setModel( this.model );
	},

	/**
	 * Handle clicking on delete.
	 *
	 * @returns {boolean}
	 */
	deleteHandler: function () {
		this.visualDestroyModel();
		return this;
	},

	onModelChange: function () {
		// Update the description when ever the model changes
		this.$( '.description' ).html( this.model.getTitle() );
	},

	onLabelChange: function( model ) {
		this.$( '.title > h4' ).text( model.getWidgetField( 'title' ) );
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

		this.$el.fadeOut( 'fast', function () {
			this.cell.row.resizeRow();
			this.model.destroy();
			this.cell.row.builder.model.refreshPanelsData();
			this.remove();
		}.bind(this) );

		return this;
	},

	/**
	 * Build up the contextual menu for a widget
	 *
	 * @param e
	 * @param menu
	 */
	buildContextualMenu: function ( e, menu ) {
		if( this.cell.row.builder.supports( 'addWidget' ) ) {
			menu.addSection(
				'add-widget-below',
				{
					sectionTitle: panelsOptions.loc.contextual.add_widget_below,
					searchPlaceholder: panelsOptions.loc.contextual.search_widgets,
					defaultDisplay: panelsOptions.contextual.default_widgets
				},
				panelsOptions.widgets,
				function ( c ) {
					this.cell.row.builder.trigger('before_user_adds_widget');
					this.cell.row.builder.addHistoryEntry( 'widget_added' );

					var widget = new panels.model.widget( {
						class: c
					} );
					widget.cell = this.cell.model;

					// Insert the new widget below
					this.cell.model.get('widgets').add( widget, {
						// Add this after the existing model
						at: this.model.collection.indexOf( this.model ) + 1
					} );

					this.cell.row.builder.model.refreshPanelsData();

					this.cell.row.builder.trigger('after_user_adds_widget', widget);
				}.bind( this )
			);
		}

		var actions = {};

		if( this.cell.row.builder.supports( 'editWidget' ) && ! this.model.get( 'read_only' ) ) {
			actions.edit = { title: panelsOptions.loc.contextual.widget_edit };
		}

		// Copy and paste functions
		if ( panels.helpers.clipboard.canCopyPaste() ) {
			actions.copy = {title: panelsOptions.loc.contextual.widget_copy};
		}

		if( this.cell.row.builder.supports( 'addWidget' ) ) {
			actions.duplicate = { title: panelsOptions.loc.contextual.widget_duplicate };
		}

		if( this.cell.row.builder.supports( 'deleteWidget' ) ) {
			actions.delete = { title: panelsOptions.loc.contextual.widget_delete, confirm: true };
		}

		if( ! _.isEmpty( actions ) ) {
			menu.addSection(
				'widget-actions',
				{
					sectionTitle: panelsOptions.loc.contextual.widget_actions,
					search: false,
				},
				actions,
				function ( c ) {
					switch ( c ) {
						case 'edit':
							this.editHandler();
							break;
						case 'copy':
							this.copyHandler();
							break;
						case 'duplicate':
							this.duplicateHandler();
							break;
						case 'delete':
							this.visualDestroyModel();
							break;
					}
				}.bind( this )
			);
		}

		// Lets also add the contextual menu for the entire row
		this.cell.buildContextualMenu( e, menu );
	},

	/**
	 * Handler for any action after the user adds a new widget.
	 * @param widget
	 */
	afterUserAddsWidgetHandler: function( widget ) {
		if( this.model === widget && panelsOptions.instant_open ) {
			setTimeout(this.editHandler.bind(this), 350);
		}
	}

} );
