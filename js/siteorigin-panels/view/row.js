var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-builder-row' ).html() ) ),

	events: {
		'click .so-row-settings': 'editSettingsHandler',
		'click .so-row-duplicate': 'duplicateHandler',
		'click .so-row-delete': 'confirmedDeleteHandler',
		'click .so-row-color': 'rowColorChangeHandler',
	},

	builder: null,
	dialog: null,

	/**
	 * Initialize the row view
	 */
	initialize: function () {

		var rowCells = this.model.get('cells');
		this.listenTo(rowCells, 'add', this.handleCellAdd );
		this.listenTo(rowCells, 'remove', this.handleCellRemove );

		this.listenTo( this.model, 'reweight_cells', this.resizeRow );
		this.listenTo( this.model, 'destroy', this.onModelDestroy );

		var thisView = this;
		rowCells.each( function ( cell ) {
			thisView.listenTo( cell.get('widgets'), 'add', thisView.resize );
		} );

		// When ever a new cell is added, listen to it for new widgets
		rowCells.on( 'add', function ( cell ) {
			thisView.listenTo( cell.get('widgets'), 'add', thisView.resize );
		}, this );

		this.listenTo( this.model, 'change:label', this.onLabelChange );
		this.listenTo( this.model, 'change:styles-row ', this.toggleVisibilityFade );
	},

	/**
	 * Render the row.
	 *
	 * @returns {panels.view.row}
	 */
	render: function () {
		var rowColorLabel = this.model.has( 'color_label' ) ? this.model.get( 'color_label' ) : panelsOptions.row_color.default;
		var rowLabel = this.model.has( 'label' ) ? this.model.get( 'label' ) : '';

		// Migrate legacy row color labels.
		if ( typeof rowColorLabel == 'number' && typeof panelsOptions.row_color.migrations[ rowColorLabel ] == 'string' ) {
			this.$el.removeClass( 'so-row-color-' + rowColorLabel );
			rowColorLabel = panelsOptions.row_color.migrations[ rowColorLabel ];
			this.$el.addClass( 'so-row-color-' + rowColorLabel );
			this.model.set( 'color_label', rowColorLabel );
		}

		this.setElement( this.template( {
			rowColorLabel: rowColorLabel,
			rowLabel: rowLabel
		} ) );
		this.$el.data( 'view', this );

		// Create views for the cells in this row
		var thisView = this;
		this.model.get('cells').each( function ( cell ) {
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
			if( ! this.builder.supports( 'editRow' ) ) {
				this.$('.so-row-toolbar .so-dropdown-links-wrapper .so-row-settings' ).parent().remove();
				this.$el.addClass('so-row-no-edit');
			}
			if( ! this.builder.supports( 'addRow' ) ) {
				this.$('.so-row-toolbar .so-dropdown-links-wrapper .so-row-duplicate' ).parent().remove();
				this.$el.addClass('so-row-no-duplicate');
			}
			if( ! this.builder.supports( 'deleteRow' ) ) {
				this.$('.so-row-toolbar .so-dropdown-links-wrapper .so-row-delete' ).parent().remove();
				this.$el.addClass('so-row-no-delete');
			}
		}
		if( ! this.builder.supports( 'moveRow' ) ) {
			this.$('.so-row-toolbar .so-row-move' ).remove();
			this.$el.addClass('so-row-no-move');
		}

		if ( ! this.$('.so-row-toolbar').html().trim().length ) {
			this.$('.so-row-toolbar' ).remove();
		}

		this.toggleVisibilityFade();

		// Resize the rows when ever the widget sortable moves
		this.listenTo( this.builder, 'widget_sortable_move', this.resizeRow );
		this.listenTo( this.builder, 'builder_resize', this.resizeRow );

		this.resizeRow();

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
			this.checkIfStyleExists( styles, 'disable_row' ) ||
			this.checkIfStyleExists( styles, 'disable_desktop' ) ||
			this.checkIfStyleExists( styles, 'disable_tablet' ) ||
			this.checkIfStyleExists( styles, 'disable_mobile' ) ||
			this.checkIfStyleExists( styles, 'disable_logged_in' ) ||
			this.checkIfStyleExists( styles, 'disable_logged_out' )
		) {
			this.$el.addClass( 'so-hidden-row' );
		} else {
			this.$el.removeClass( 'so-hidden-row' );
		}
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
	resizeRow: function( e ) {
		// Don't resize this
		if ( ! this.$el.is( ':visible' ) ) {
			return;
		}

		// Reset everything to have an automatic height
		this.$( '.so-cells .cell-wrapper' ).css( 'min-height', 0 );
		this.$( '.so-cells .resize-handle' ).css( 'height', 0 );
		this.$( '.so-cells' ).removeClass( 'so-action-icons' );

		// We'll tie the values to the row view, to prevent issue with values going to different rows
		var height = 0,
			cellWidth = 0,
			iconsShown = false,
			cell;

		this.$( '.so-cells .cell' ).each( function () {
			cell = $( this );

			$( this ).css(
				'width',
				( cell.data( 'view' ).model.get( 'weight' ) * 100) + "%"
			);

			cellWidth = cell.width();
			// Ensure this widget is large enough to allow for actions to appear.
			if ( cellWidth < 215 ) {
				cell.addClass( 'so-show-icon' );
				iconsShown = true;
				if ( cellWidth < 125 ) {
					cell.addClass( 'so-small-actions' );
				} else {
					cell.removeClass( 'so-small-actions' );
				}
			} else {
				cell.removeClass( 'so-show-icon so-small-actions' );
			}

			// Store cell height. This is used to determine the max height of cells.
			height = Math.max(
				height,
				cell.height()
			);
		} );

		// Resize all the grids and cell wrappers
		this.$( '.so-cells .cell-wrapper' ).css( 'min-height', Math.max( height, 63 ) + 'px' );
		// If action icons are visible in any cell, give the container a special class.
		if ( iconsShown ) {
			this.$( '.so-cells' ).addClass( 'so-action-icons' );
		}
		this.$( '.so-cells .resize-handle' ).css( 'height', this.$( '.so-cells .cell-wrapper' ).outerHeight() + 'px' );
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

	onLabelChange: function( model, text ) {
		if ( this.$('.so-row-label').length == 0 ) {
			this.$( '.so-row-toolbar' ).prepend( '<h3 class="so-row-label">' + text + '</h3>' );
		} else {
			this.$('.so-row-label').text( text );
		}
	},

	/**
	 * Duplicate this row.
	 *
	 * @return {boolean}
	 */
	duplicateHandler: function () {
		this.builder.addHistoryEntry( 'row_duplicated' );

		var duplicateRow = this.model.clone( this.builder.model );

		this.builder.model.get('rows').add( duplicateRow, {
			at: this.builder.model.get('rows').indexOf( this.model ) + 1
		} );

		this.builder.model.refreshPanelsData();
	},

	/**
	 * Copy the row to a localStorage
	 */
	copyHandler: function(){
		panels.helpers.clipboard.setModel( this.model );
	},

	/**
	 * Create a new row and insert it
	 */
	pasteHandler: function(){
		var pastedModel = panels.helpers.clipboard.getModel( 'row-model' );

		if( ! _.isEmpty( pastedModel ) && pastedModel instanceof panels.model.row ) {
			this.builder.addHistoryEntry( 'row_pasted' );
			pastedModel.builder = this.builder.model;
			this.builder.model.get('rows').add( pastedModel, {
				at: this.builder.model.get('rows').indexOf( this.model ) + 1
			} );
			this.builder.model.refreshPanelsData();
		}
	},

	/**
	 * Handles deleting the row with a confirmation.
	 */
	confirmedDeleteHandler: function ( e ) {
		var $$ = $( e.target );

		// The user clicked on the dashicon
		if ( $$.hasClass( 'dashicons' ) ) {
			$$ = $$.parent();
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
		if ( ! this.builder.supports( 'editRow' ) ) {
			return;
		}
		// Lets open up an instance of the settings dialog
		if ( this.dialog === null ) {
			// Create the dialog
			this.dialog = new panels.dialog.row();
			this.dialog.setBuilder( this.builder ).setRowModel( this.model );
			this.dialog.rowView = this;
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
	 * Change the row background color.
	 */
	rowColorChangeHandler: function ( event ) {
		this.$( '.so-row-color' ).removeClass( 'so-row-color-selected' );
		var clickedColorElem = $( event.target );
		var newColorLabel = clickedColorElem.data( 'color-label' );
		var oldColorLabel = this.model.has( 'color_label' ) ? this.model.get( 'color_label' ) : panelsOptions.row_color.default;
		clickedColorElem.addClass( 'so-row-color-selected' );
		this.$el.removeClass( 'so-row-color-' + oldColorLabel );
		this.$el.addClass( 'so-row-color-' + newColorLabel );
		this.model.set( 'color_label', newColorLabel );
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
		var options = [];
		for ( var i = 1; i < 5; i ++ ) {
			options.push( {
				title: i + ' ' + panelsOptions.loc.contextual.column
			} );
		}

		if( this.builder.supports( 'addRow' ) ) {
			menu.addSection(
				'add-row',
				{
					sectionTitle: panelsOptions.loc.contextual.add_row,
					search: false
				},
				options,
				function ( c ) {
					this.builder.addHistoryEntry( 'row_added' );

					var columns = Number( c ) + 1;
					var weights = [];
					for ( var i = 0; i < columns; i ++ ) {
						weights.push( {weight: 100 / columns } );
					}

					// Create the actual row
					var newRow = new panels.model.row( {
						collection: this.collection
					} );

					var cells = new panels.collection.cells(weights);
					cells.each(function (cell) {
						cell.row = newRow;
					});
					newRow.setCells(cells);
					newRow.builder = this.builder.model;

					this.builder.model.get('rows').add( newRow, {
						at: this.builder.model.get('rows').indexOf( this.model ) + 1
					} );

					this.builder.model.refreshPanelsData();
				}.bind( this )
			);
		}

		var actions = {};

		if( this.builder.supports( 'editRow' ) ) {
			actions.edit = { title: panelsOptions.loc.contextual.row_edit };
		}

		// Copy and paste functions
		if ( panels.helpers.clipboard.canCopyPaste() ) {
			actions.copy = { title: panelsOptions.loc.contextual.row_copy };
			if ( this.builder.supports( 'addRow' ) && panels.helpers.clipboard.isModel( 'row-model' ) ) {
				actions.paste = { title: panelsOptions.loc.contextual.row_paste };
			}
		}

		if( this.builder.supports( 'addRow' ) ) {
			actions.duplicate = { title: panelsOptions.loc.contextual.row_duplicate };
		}

		if( this.builder.supports( 'deleteRow' ) ) {
			actions.delete = { title: panelsOptions.loc.contextual.row_delete, confirm: true };
		}

		if( ! _.isEmpty( actions ) ) {
			menu.addSection(
				'row-actions',
				{
					sectionTitle: panelsOptions.loc.contextual.row_actions,
					search: false,
				},
				actions,
				function ( c ) {
					switch ( c ) {
						case 'edit':
							this.editSettingsHandler();
							break;
						case 'copy':
							this.copyHandler();
							break;
						case 'paste':
							this.pasteHandler();
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
	},
} );
