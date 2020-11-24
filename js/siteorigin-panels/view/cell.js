var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-builder-cell' ).html() ) ),
	events: {
		'click .cell-wrapper': 'handleCellClick'
	},

	/* The row view that this cell is a part of */
	row: null,
	widgetSortable: null,

	initialize: function () {
		this.listenTo(this.model.get('widgets'), 'add', this.onAddWidget );
	},

	/**
	 * Render the actual cell
	 */
	render: function () {
		var templateArgs = {
			weight: this.model.get( 'weight' ),
			totalWeight: this.row.model.get('cells').totalWeight()
		};

		this.setElement( this.template( templateArgs ) );
		this.$el.data( 'view', this );

		// Now lets render any widgets that are currently in the row
		var thisView = this;
		this.model.get('widgets').each( function ( widget ) {
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
		var builder = cellView.row.builder;

		// Go up the view hierarchy until we find the ID attribute
		var builderID = builder.$el.attr( 'id' );
		var builderModel = builder.model;

		// Create a widget sortable that's connected with all other cells
		this.widgetSortable = this.$( '.widgets-container' ).sortable( {
			placeholder: "so-widget-sortable-highlight",
			connectWith: '#' + builderID + ' .so-cells .cell .widgets-container,.block-editor .so-cells .cell .widgets-container',
			tolerance: 'pointer',
			scroll: false,
			over: function ( e, ui ) {
				// This will make all the rows in the current builder resize
				cellView.row.builder.trigger( 'widget_sortable_move' );
			},
			remove: function ( e, ui ) {
				cellView.model.get( 'widgets' ).remove(
					$( ui.item ).data( 'view' ).model,
					{ silent: true }
				);
				builderModel.refreshPanelsData();
			},
			receive: function ( e, ui ) {
				var widgetView = $( ui.item ).data( 'view' );
				widgetView.cell = cellView;
				var widgetModel = widgetView.model;
				widgetModel.cell = cellView.model;
				cellView.model.get( 'widgets' ).add(
					widgetModel,
					{ silent: true, at: $( ui.item ).index() }
				);
				builderModel.refreshPanelsData();
			},
			stop: function ( e, ui ) {
				var $$ =  $( ui.item ),
					widget = $$.data( 'view' ),
					targetCell = $$.closest( '.cell' ).data( 'view' );


				// If this hasn't already been removed and added to a different builder.
				if ( cellView.model.get( 'widgets' ).get( widget.model ) ) {

					cellView.row.builder.addHistoryEntry( 'widget_moved' );

					// Move the model and the view to the new cell
					widget.model.moveToCell( targetCell.model, {}, $$.index() );
					widget.cell = targetCell;

					builderModel.refreshPanelsData();
				}
			},
			helper: function ( e, el ) {
				var helper = el.clone()
					.css( {
						'width': el.outerWidth() + 'px',
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
					width: previousCell.$el.outerWidth() + 'px',
					right: 5,
					height: previousCell.$el.outerHeight() + 'px',
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

				$( this ).data( 'newCellClone' ).css( 'width', containerWidth * ncw + 'px'  )
					.find( '.preview-cell-weight' ).html( Math.round( ncw * 1000 ) / 10 );

				$( this ).data( 'prevCellClone' ).css( 'width', containerWidth * pcw + 'px' )
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
					cellView.row.resizeRow();
				}

				ui.helper.css( 'left', - handle.outerWidth() / 2  + 'px' );

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
		this.row.resizeRow();
		this.row.builder.trigger( 'widget_added', view );
	},

	/**
	 * Handle this cell being clicked on
	 *
	 * @param e
	 * @returns {boolean}
	 */
	handleCellClick: function ( e ) {
		// Remove all existing selected cell indication for this builder
		this.row.builder.$el.find( '.so-cells .cell' ).removeClass( 'cell-selected' );

		if( this.row.builder.activeCell === this && ! this.model.get('widgets').length ) {
			// This is a click on an empty cell
			this.row.builder.activeCell = null;
		}
		else {
			this.$el.addClass( 'cell-selected' );
			this.row.builder.activeCell = this;
		}
	},

	/**
	 * Insert a widget from the clipboard
	 */
	pasteHandler: function(){
		var pastedModel = panels.helpers.clipboard.getModel( 'widget-model' );
		if( ! _.isEmpty( pastedModel ) && pastedModel instanceof panels.model.widget ) {
			this.row.builder.addHistoryEntry( 'widget_pasted' );
			pastedModel.cell = this.model;
			this.model.get('widgets').add( pastedModel );
			this.row.builder.model.refreshPanelsData();
		}
	},

	/**
	 * Build up the contextual menu for a cell
	 *
	 * @param e
	 * @param menu
	 */
	buildContextualMenu: function ( e, menu ) {
		var thisView = this;

		if( ! menu.hasSection( 'add-widget-below' ) ) {
			menu.addSection(
				'add-widget-cell',
				{
					sectionTitle: panelsOptions.loc.contextual.add_widget_cell,
					searchPlaceholder: panelsOptions.loc.contextual.search_widgets,
					defaultDisplay: panelsOptions.contextual.default_widgets
				},
				panelsOptions.widgets,
				function ( c ) {
					thisView.row.builder.trigger('before_user_adds_widget')
					thisView.row.builder.addHistoryEntry( 'widget_added' );

					var widget = new panels.model.widget( {
						class: c
					} );

					// Add the widget to the cell model
					widget.cell = thisView.model;
					widget.cell.get('widgets').add( widget );

					thisView.row.builder.model.refreshPanelsData();
					thisView.row.builder.trigger('after_user_adds_widget', widget);
				}
			);
		}

		var actions = {};
		if ( this.row.builder.supports('addWidget') && panels.helpers.clipboard.isModel( 'widget-model' ) ) {
			actions.paste = {title: panelsOptions.loc.contextual.cell_paste_widget};
		}

		if( ! _.isEmpty( actions ) ) {
			menu.addSection(
				'cell-actions',
				{
					sectionTitle: panelsOptions.loc.contextual.cell_actions,
					search: false,
				},
				actions,
				function ( c ) {
					switch ( c ) {
						case 'paste':
							this.pasteHandler();
							break;
					}

					this.row.builder.model.refreshPanelsData();
				}.bind( this )
			);
		}

		// Add the contextual menu for the parent row
		this.row.buildContextualMenu( e, menu );
	}
} );
