var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend({

	cellPreviewTemplate: _.template( panels.helpers.utils.processTemplate( $('#siteorigin-panels-dialog-row-cell-preview').html() ) ),

	editableLabel: true,

	events: {
		'click .so-close': 'closeDialog',
		'keyup .so-close': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},

		// Toolbar buttons
		'click .so-close': 'saveHandler',
		'click .so-toolbar .so-saveinline': function( e ) {
			this.saveHandler( true );
		},
		'click .so-mode': 'switchModeShow',
		'click .so-saveinline-mode': function() {
			this.switchMode( true );
		},
		'keyup .so-mode-list li': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-close-mode': function() {
			this.switchMode( false );
		},
		'keyup .so-close-mode': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-toolbar .so-save': 'saveHandler',
		'click .so-toolbar .so-saveinline': function( e ) {
			this.saveHandler( true );
		},
		'click .so-toolbar .so-insert': 'insertHandler',
		'click .so-toolbar .so-delete': 'deleteHandler',
		'keyup .so-toolbar .so-delete': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
		'click .so-toolbar .so-duplicate': 'duplicateHandler',
		'keyup .so-toolbar .so-duplicate': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},

		// Changing the row.
		'click .row-set-form .so-row-field': 'changeCellTotal',
		'click .cell-resize-sizing span': 'changeCellRatio',
	},

	rowView: null,
	dialogIcon: 'add-row',
	dialogClass: 'so-panels-dialog-row-edit',
	styleType: 'row',
	columnResizeData: [],

	dialogType: 'edit',

	/**
	 * The current settings, not yet saved to the model
	 */
	row: {
		// This will be a clone of cells collection.
		cells: null,
		// The style settings of the row
		style: {}
	},

	cellStylesCache: [],

	initializeDialog: function () {
		this.on('open_dialog', function () {
			if (!_.isUndefined(this.model) && !_.isEmpty(this.model.get('cells'))) {
				this.setRowModel(this.model);
			} else {
				this.setRowModel(null);
			}

			this.columnResizeData = this.$( '.cell-resize').data( 'resize' );
			this.regenerateRowPreview();
			this.drawCellResizers( parseInt( this.$('.row-set-form input[name="cells"]').val() ) );
			this.updateActiveCellClass();
			this.renderStyles();
			this.openSelectedCellStyles();
		}, this);

		this.on( 'open_dialog_complete', function() {
			$( '.so-panels-dialog-wrapper .so-title' ).trigger( 'focus' );
		} );

		// This is the default row layout
		this.row = {
			cells: new panels.collection.cells( panelsOptions.default_columns ),
			style: {}
		};

		// Refresh panels data after both dialog form components are loaded
		this.dialogFormsLoaded = 0;
		var thisView = this;
		this.on('form_loaded styles_loaded', function () {
			this.dialogFormsLoaded++;
			if (this.dialogFormsLoaded === 2) {
				thisView.updateModel({
					refreshArgs: {
						silent: true
					}
				});
			}
		});

		this.on('close_dialog', this.closeHandler);

		this.on( 'edit_label', function ( text ) {
			// If text is set to default values, just clear it.
			if ( text === panelsOptions.loc.row.add || text === panelsOptions.loc.row.edit ) {
				text = '';
			}
			this.model.set( 'label', text );
			if ( _.isEmpty( text ) ) {
				var title = this.dialogType === 'create' ? panelsOptions.loc.row.add : panelsOptions.loc.row.edit;
				this.$( '.so-title').text( title );
			}
		}.bind( this ) );
	},

	/**
	 *
	 * @param dialogType Either "edit" or "create"
	 */
	setRowDialogType: function (dialogType) {
		this.dialogType = dialogType;
	},

	/**
	 * Render the new row dialog
	 */
	render: function () {
		var title = this.dialogType === 'create' ? panelsOptions.loc.row.add : panelsOptions.loc.row.edit;
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-row' ).html(), {
			title: title,
			dialogType: this.dialogType
		} ) );

		var titleElt = this.$( '.so-title' );

		if ( this.model.has( 'label' ) && ! _.isEmpty( this.model.get( 'label' ) ) ) {
			titleElt.text( this.model.get( 'label' ) );
		}
		this.$( '.so-edit-title' ).val( titleElt.text() );

		if (!this.builder.supports('addRow')) {
			this.$('.so-buttons .so-duplicate').remove();
		}
		if (!this.builder.supports('deleteRow')) {
			this.$('.so-buttons .so-delete').remove();
		}

		if ( ! _.isUndefined( this.model ) && this.dialogType == 'edit' ) {
			// Set the initial value of the
			this.$( 'input[name="cells"].so-row-field' ).val( this.model.get( 'cells' ).length );
		}

		this.$( 'input.so-row-field' ).on( 'keyup', function() {
			$(this).trigger('change');
		});

		return this;
	},
	
	renderStyles: function () {
		if ( this.styles ) {
			this.styles.off( 'styles_loaded' );
			this.styles.remove();
		}
		
		// Now we need to attach the style window
		this.styles = new panels.view.styles();
		this.styles.model = this.model;
		this.styles.render('row', this.builder.config.postId, {
			builderType: this.builder.config.builderType,
			dialog: this
		});
		
		var $rightSidebar = this.$('.so-sidebar.so-right-sidebar');
		this.styles.attach( $rightSidebar );
		
		// Handle the loading class
		this.styles.on('styles_loaded', function (hasStyles) {
			if ( ! hasStyles ) {
				// If we don't have styles remove the view.
				this.styles.remove();
				
				// If the sidebar is empty, hide it.
				if ( $rightSidebar.children().length === 0 ) {
					$rightSidebar.closest('.so-panels-dialog').removeClass('so-panels-dialog-has-right-sidebar');
					$rightSidebar.hide();
				}
			}
		}, this);
	},

	/**
	 * Set the row model we'll be using for this dialog.
	 *
	 * @param model
	 */
	setRowModel: function (model) {
		this.model = model;

		if (_.isEmpty(this.model)) {
			return this;
		}

		// Set the rows to be a copy of the model
		this.row = {
			cells: this.model.get('cells').clone(),
			style: {},
		};

		// Set the initial value of the cell field.
		if ( this.dialogType == 'edit' ) {
			this.$( 'input[name="cells"].so-row-field' ).val( this.model.get( 'cells' ).length );
		}

		this.clearCellStylesCache();

		return this;
	},

	/**
	 * Regenerate the row preview and resizing interface.
	 */
	regenerateRowPreview: function () {
		var thisDialog = this;
		var rowPreview = this.$('.row-preview');

		// If no selected cell, select the first cell.
		var selectedIndex = this.getSelectedCellIndex();

		rowPreview.empty();

		var timeout;

		// Represent the cells
		this.row.cells.each(function (cellModel, i) {
			var newCell = $(this.cellPreviewTemplate({weight: cellModel.get('weight')}));
			rowPreview.append(newCell);

			if(i == selectedIndex) {
				newCell.find('.preview-cell-in').addClass('cell-selected');
			}

			var prevCell = newCell.prev();
			var handle;

			if (prevCell.length) {
				handle = $('<div class="resize-handle"></div>');
				handle
					.appendTo(newCell)
					.on( 'dblclick', function () {
						var prevCellModel = thisDialog.row.cells.at(i - 1);
						var t = cellModel.get('weight') + prevCellModel.get('weight');
						cellModel.set('weight', t / 2);
						prevCellModel.set('weight', t / 2);
						thisDialog.scaleRowWidths();
					});

				handle.draggable({
					axis: 'x',
					containment: rowPreview,
					start: function (e, ui) {

						// Create the clone for the current cell
						var newCellClone = newCell.clone().appendTo(ui.helper).css({
							position: 'absolute',
							top: '0',
							width: newCell.outerWidth(),
							left: 6,
							height: newCell.outerHeight()
						});
						newCellClone.find('.resize-handle').remove();

						// Create the clone for the previous cell
						var prevCellClone = prevCell.clone().appendTo(ui.helper).css({
							position: 'absolute',
							top: '0',
							width: prevCell.outerWidth(),
							right: 6,
							height: prevCell.outerHeight()
						});
						prevCellClone.find('.resize-handle').remove();

						$(this).data({
							'newCellClone': newCellClone,
							'prevCellClone': prevCellClone
						});

						// Hide the
						newCell.find('> .preview-cell-in').css('visibility', 'hidden');
						prevCell.find('> .preview-cell-in').css('visibility', 'hidden');
					},
					drag: function (e, ui) {
						// Calculate the new cell and previous cell widths as a percent
						var cellWeight = thisDialog.row.cells.at(i).get('weight');
						var prevCellWeight = thisDialog.row.cells.at(i - 1).get('weight');
						var ncw = cellWeight - (
								(
									ui.position.left + 6
								) / rowPreview.width()
							);
						var pcw = prevCellWeight + (
								(
									ui.position.left + 6
								) / rowPreview.width()
							);

						var helperLeft = ui.helper.offset().left - rowPreview.offset().left - 6;

						$( this ).data( 'newCellClone' ).css( 'width', rowPreview.width() * ncw + 'px' )
							.find('.preview-cell-weight').html(Math.round(ncw * 1000) / 10);

						$( this ).data( 'prevCellClone' ).css( 'width', rowPreview.width() * pcw + 'px' )
							.find('.preview-cell-weight').html(Math.round(pcw * 1000) / 10);
					},
					stop: function (e, ui) {
						// Remove the clones
						$(this).data('newCellClone').remove();
						$(this).data('prevCellClone').remove();

						// Reshow the main cells
						newCell.find('.preview-cell-in').css('visibility', 'visible');
						prevCell.find('.preview-cell-in').css('visibility', 'visible');

						// Calculate the new cell weights
						var offset = ui.position.left + 6;
						var percent = offset / rowPreview.width();

						// Ignore this if any of the cells are below 2% in width.
						var cellModel = thisDialog.row.cells.at(i);
						var prevCellModel = thisDialog.row.cells.at(i - 1);
						if (cellModel.get('weight') - percent > 0.02 && prevCellModel.get('weight') + percent > 0.02) {
							cellModel.set('weight', cellModel.get('weight') - percent);
							prevCellModel.set('weight', prevCellModel.get('weight') + percent);
						}

						thisDialog.scaleRowWidths();
						ui.helper.css('left', -6);
					}
				});
			}

			newCell.on( 'click', function( event ) {

				if ( ! ( $(event.target).is('.preview-cell') || $(event.target).is('.preview-cell-in') ) ) {
					return;
				}

				var cell = $(event.target);
				cell.closest('.row-preview').find('.preview-cell .preview-cell-in').removeClass('cell-selected');
				cell.addClass('cell-selected');

				this.openSelectedCellStyles();

			}.bind(this));

			// Make this row weight click editable
			newCell.find( '.preview-cell-weight' ).on( 'click', function( ci ) {

				// Disable the draggable while entering values
				thisDialog.$( '.resize-handle' ).css( 'pointer-event', 'none' ).draggable( 'disable' );

				var resizeCells = function( refocusIndex = false ) {
					timeout = setTimeout( function() {
						var rowPreviewInputs = rowPreview.find( '.preview-cell-weight-input' );
						// If there are no weight inputs, then skip this
						if ( rowPreviewInputs.length === 0 ) {
							return false;
						}

						// Go through all the inputs
						var rowWeights = [],
							rowChanged = [],
							changedSum = 0,
							unchangedSum = 0;

						rowPreviewInputs.each(function( i, el ) {
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
							for ( var i = 0; i < rowWeights.length; i++ ) {
								if ( ! rowChanged[ i ] ) {
									rowWeights[ i ] = (
											rowWeights[ i ] / unchangedSum
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

						rowWeights = rowWeights.map( function( w ) {
							return w / sum;
						} );

						// Set the new cell weights and regenerate the preview.
						if ( Math.min.apply( Math, rowWeights ) > 0.01 ) {
							thisDialog.row.cells.each( function( cell, i ) {
								cell.set( 'weight', rowWeights[ i ] );
							} );
						}

						// Now lets animate the cells into their new widths
						rowPreview.find( '.preview-cell' ).each( function ( i, el ) {
							var cellWeight = thisDialog.row.cells.at( i ).get( 'weight');
							$( el ).animate( { 'width': Math.round( cellWeight * 1000 ) / 10 + "%" }, 250 );
							$( el ).find( '.preview-cell-weight-input' ).val( Math.round( cellWeight * 1000 ) / 10 );
						});

						rowPreview.find( '.preview-cell' ).css( 'overflow', 'visible' );
						setTimeout( function() {
							if ( typeof refocusIndex === 'number' ) {
								rowPreviewInputs.get( refocusIndex ).focus();
							}

							// So the draggable handle is not hidden.
							thisDialog.regenerateRowPreview.bind( thisDialog )
						}, 260 );

					}, 100 );
				}

				rowPreview.find( '.preview-cell-weight' ).each( function() {
					var $$ = jQuery( this ).hide();
					$( '<input type="number" class="preview-cell-weight-input no-user-interacted" />' )
						.val( parseFloat( $$.html() ) ).insertAfter( $$ )
						.on( 'focus', function() {
							clearTimeout( timeout );
						} )
						.on( 'keyup', function( e ) {
							if ( e.keyCode !== 9 ) {
								// Only register the interaction if the user didn't press tab
								$( this ).removeClass( 'no-user-interacted' );
							}

							// Enter is clicked
							if ( e.keyCode === 13 ) {
								e.preventDefault();
								resizeCells();
							}

							// Up or down is clicked
							if ( e.keyCode === 38 || e.keyCode === 40 ) {
								e.preventDefault();
								// During the row regeneration, the inputs are removed and re-added so we need the id to refocus.
								var parent = $( e.target ).parents( '.preview-cell' ).index();

								resizeCells( parent );
							}
						} )
						.on( 'keydown', function( e ) {
							if ( e.keyCode === 9 ) {
								e.preventDefault();

								// Tab will always cycle around the row inputs
								var inputs = rowPreview.find( '.preview-cell-weight-input' );
								var i = inputs.index( $( this ) );
								if ( i === inputs.length - 1 ) {
									inputs.eq( 0 ).trigger( 'focus' ).trigger( 'select' );
								} else {
									inputs.eq( i + 1 ).trigger( 'focus' ).trigger( 'select' );
								}
							}
						} )
						.on( 'click', function () {
							// If the input is already focused, the user has clicked a step.
							if ( $( this ).is( ':focus' ) ) {
								resizeCells();
							}
							$( this ).trigger( 'select' );
						} );
				} );

				$( this ).siblings( '.preview-cell-weight-input' ).trigger( 'select' );
			} );

		}, this);

		this.updateActiveCellClass();

		this.trigger('form_loaded', this);
	},

	getSelectedCellIndex: function() {
		var selectedIndex = -1;
		this.$('.preview-cell .preview-cell-in').each(function(index, el) {
			if($(el).is('.cell-selected')) {
				selectedIndex = index;
			}
		});
		return selectedIndex;
	},

	openSelectedCellStyles: function() {
		if (!_.isUndefined(this.cellStyles)) {
			if (this.cellStyles.stylesLoaded) {
				var style = {};
				try {
					style = this.getFormValues('.so-sidebar .so-visual-styles.so-cell-styles').style;
				}
				catch (err) {
					console.log('Error retrieving cell styles - ' + err.message);
				}

				this.cellStyles.model.set('style', style);
			}
			this.cellStyles.detach();
		}

		this.cellStyles = this.getSelectedCellStyles();

		if ( this.cellStyles ) {
			var $rightSidebar = this.$( '.so-sidebar.so-right-sidebar' );
			this.cellStyles.attach( $rightSidebar );
			this.cellStyles.on( 'styles_loaded', function ( hasStyles ) {
				if ( hasStyles ) {
					$rightSidebar.closest('.so-panels-dialog').addClass('so-panels-dialog-has-right-sidebar');
					$rightSidebar.show();
				}
			} );
		}
	},

	getSelectedCellStyles: function () {
		var cellIndex = this.getSelectedCellIndex();
		if ( cellIndex > -1 ) {
			var cellStyles = this.cellStylesCache[cellIndex];
			if ( !cellStyles ) {
				cellStyles = new panels.view.styles();
				cellStyles.model = this.row.cells.at( cellIndex );
				cellStyles.render( 'cell', this.builder.config.postId, {
					builderType: this.builder.config.builderType,
					dialog: this,
					index: cellIndex,
				} );
				this.cellStylesCache[cellIndex] = cellStyles;
			}
		}

		return cellStyles;
	},

	clearCellStylesCache: function () {
		// Call remove() on all cell styles to remove data, event listeners etc.
		this.cellStylesCache.forEach(function (cellStyles) {
			cellStyles.remove();
			cellStyles.off( 'styles_loaded' );
		});
		this.cellStylesCache = [];
	},

	/**
	 * Visually scale the row widths based on the cell weights
	 */
	scaleRowWidths: function () {
		var thisDialog = this;
		this.$('.row-preview .preview-cell').each(function (i, el) {
			var cell = thisDialog.row.cells.at(i);
			$(el)
				.css('width', cell.get('weight') * 100 + "%")
				.find('.preview-cell-weight').html(Math.round(cell.get('weight') * 1000) / 10);
		});
	},

	drawCellResizers: function() {
		this.$( '.cell-resize' ).empty();
		var cellsCount = parseInt( this.$( '.row-set-form input[name="cells"]' ).val() );
		var currentCellSizes = this.columnResizeData[ cellsCount ];
		if ( cellsCount > 1 && typeof currentCellSizes !== 'undefined' ) {
			this.$( '.cell-resize-container' ).show();
			for ( ci = 0; ci < currentCellSizes.length; ci++ ) {
				this.$( '.cell-resize' ).append( '<span class="cell-resize-sizing"></span>' );
				var $lastCell = this.$( '.cell-resize' ).find( '.cell-resize-sizing' ).last();
				$lastCell.data( 'cells', currentCellSizes[ ci ] );
				for ( cs = 0; cs < currentCellSizes[ ci ].length; cs++ ) {
					$lastCell.append( '<span style="width: ' + currentCellSizes[ ci ][ cs ] + '%;">' + currentCellSizes[ ci ][ cs ] + '%</span>' );
				}
			}
		} else {
			this.$( '.cell-resize-container' ).hide();
		}
	},

	updateActiveCellClass: function() {
		$( '.so-active-ratio' ).removeClass( 'so-active-ratio' );
		var activeCellRatio = this.$( '.preview-cell-weight' ).map( function() {
			return Math.trunc( Number( $( this ).text() ) );
		} ).get();

		$.each( this.columnResizeData[ parseInt( this.$( '.row-set-form input[name="cells"]' ).val() ) ], function( i, ratio ) {
			if ( ratio.toString() === activeCellRatio.toString() ) {
				activeCellRatio = i;
				return false;
			}
		} );

		if ( typeof activeCellRatio == 'number' ) {
			$( $( '.cell-resize-sizing' ).get( activeCellRatio ) ).addClass( 'so-active-ratio' );
		}
	},

	changeCellRatio: function( e ) {
		var $current = $( e.target );
		if ( ! $current.hasClass( 'cell-resize-sizing' ) ) {
			$current = $current.parent();
		}

		if ( ! $current.hasClass( 'so-active-ratio' ) ) {
			$( '.so-active-ratio' ).removeClass( 'so-active-ratio' );
			$current.addClass( 'so-active-ratio' );
			this.changeCellTotal( $current.data('cells' ) )
		}
	},

	changeCellTotal: function ( cellRatio = 0 ) {
			 try {
				var cellsCount = parseInt( this.$('.row-set-form input[name="cells"]').val() );
				this.drawCellResizers( cellsCount );
	
				if (_.isNaN( cellsCount )) {
					cellsCount = 1;
				} else {
					if ( cellsCount < 1 ) {
						cellsCount = 1;
						this.$( '.row-set-form input[name="cells"]' ).val( cellsCount );
					} else if ( cellsCount > 12 ) {
						cellsCount = 12;
					}
				}
				this.$( '.row-set-form input[name="cells"]' ).val( cellsCount );
	
				var cells = [];
				var cellCountChanged = (
					this.row.cells.length !== cellsCount
				);
	
				// Create some cells
				var currentWeight = 1;
				for ( var i = 0; i < cellsCount; i++ ) {
					cells.push(1);
				}

				// Lets make sure that the row weights add up to 1.
				var totalRowWeight = _.reduce( cells, function( memo, weight ) {
					return memo + weight;
				} );

				cells = _.map (cells, function( cell ) {
					return cell / totalRowWeight;
				} );
	
				// Don't return cells that are too small
				cells = _.filter( cells, function( cell ) {
					return cell > 0.01;
				} );

				// Discard deleted cells.
				this.row.cells = new panels.collection.cells( this.row.cells.first( cells.length ) );
	
				_.each( cells, function( cellWeight, index ) {
					var cell = this.row.cells.at( index );
					if ( ! cell ) {
						cell = new panels.model.cell( {
							weight: cellWeight,
							row: this.model
						} );
						this.row.cells.add( cell );
					} else {
						cell.set(
							'weight',
							cellRatio.length ? cellRatio[ index ] / 100 : cellWeight
						);
					}
				}.bind( this ) );
	
				if ( cellCountChanged ) {
					this.regenerateRowPreview();
				} else {
					var thisDialog = this;
	
					// // Now lets animate the cells into their new widths
					this.$( '.preview-cell' ).each( function( i, el ) {
						var width = Math.round( thisDialog.row.cells.at( i ).get( 'weight' ) * 1000 ) / 10;
						var $previewCellWeight = $( el ).find( '.preview-cell-weight' );
						// To prevent a jump, don't animate cells that haven't changed size.
						if ( parseInt( $previewCellWeight.text() ) != width ) {
							$( el ).animate( { 'width': width + "%" }, 250 );
							$previewCellWeight.html( width );
						}
					} );
	
					// So the draggable handle is not hidden.
					this.$( '.preview-cell' ).css( 'overflow', 'visible' );
	
					setTimeout( thisDialog.regenerateRowPreview.bind( thisDialog ), 260 );
				}
			}
			catch ( err ) {
				console.log( 'Error setting cells - ' + err.message );
			}
	
	
			// Remove the button primary class
			this.$('.row-set-form .so-button-row-set').removeClass('button-primary');
		},

	/**
	 * Handle a click on the dialog left bar tab
	 */
	tabClickHandler: function ($t) {
		if ($t.attr('href') === '#row-layout') {
			this.$('.so-panels-dialog').addClass('so-panels-dialog-has-right-sidebar');
		} else {
			this.$('.so-panels-dialog').removeClass('so-panels-dialog-has-right-sidebar');
		}
	},

	/**
	 * Update the current model with what we have in the dialog
	 */
	updateModel: function (args) {
		args = _.extend({
			refresh: true,
			refreshArgs: null
		}, args);

		// Set the cells
		if (!_.isEmpty(this.model)) {
			this.model.setCells( this.row.cells );
			this.model.set( 'ratio', this.row.ratio );
		}

		// Update the row styles if they've loaded
		if ( ! _.isUndefined( this.styles ) && this.styles.stylesLoaded ) {
			// This is an edit dialog, so there are styles
			var newStyles = {};
			try {
				newStyles = this.getFormValues( '.so-sidebar .so-visual-styles.so-row-styles' ).style;
			}
			catch (err) {
				console.log( 'Error retrieving row styles - ' + err.message );
			}

			// Have there been any Style changes?
			if ( JSON.stringify( this.model.attributes.style ) !== JSON.stringify( newStyles ) ) {
				this.model.set( 'style', newStyles );
				this.model.trigger( 'change:styles' );
				this.model.trigger( 'change:styles-row' );
			}
		}

		// Update the cell styles if any are showing.
		if ( !_.isUndefined( this.cellStyles ) && this.cellStyles.stylesLoaded ) {

			var newStyles = {};
			try {
				newStyles = this.getFormValues( '.so-sidebar .so-visual-styles.so-cell-styles' ).style;
			}
			catch (err) {
				console.log('Error retrieving cell styles - ' + err.message);
			}

			// Has there been any Style changes?
			if ( JSON.stringify( this.model.attributes.style ) !== JSON.stringify( newStyles ) ) {
				this.cellStyles.model.set( 'style', newStyles );
				this.model.trigger( 'change:styles' );
				this.model.trigger( 'change:styles-cell' );
			}
		}

		if (args.refresh) {
			this.builder.model.refreshPanelsData(args.refreshArgs);
		}
	},

	/**
	 * Insert the new row
	 */
	insertHandler: function () {
		this.builder.addHistoryEntry('row_added');

		this.updateModel();

		var activeCell = this.builder.getActiveCell({
			createCell: false,
		});

		var options = {};
		if (activeCell !== null) {
			options.at = this.builder.model.get('rows').indexOf(activeCell.row) + 1;
		}

		// Set up the model and add it to the builder
		this.model.collection = this.builder.model.get('rows');
		this.builder.model.get('rows').add(this.model, options);

		this.closeDialog();

		this.builder.model.refreshPanelsData();

		return false;
	},

	/**
	 * We'll just save this model and close the dialog
	 */
	saveHandler: function( savePage = false, e ) {
		this.builder.addHistoryEntry( 'row_edited' );
		this.updateModel();
		if ( typeof savePage == 'boolean' ) {
			panels.helpers.utils.saveHeartbeat( this );
		} else {
			this.builder.model.refreshPanelsData();
			this.closeDialog();
		}

		return false;
	},

	/**
	 * The user clicks delete, so trigger deletion on the row model
	 */
	deleteHandler: function () {
		// Trigger a destroy on the model that will happen with a visual indication to the user
		this.rowView.visualDestroyModel();
		this.closeDialog({silent: true});

		return false;
	},

	/**
	 * Duplicate this row
	 */
	duplicateHandler: function () {
		this.builder.addHistoryEntry('row_duplicated');

		var duplicateRow = this.model.clone(this.builder.model);

		this.builder.model.get('rows').add( duplicateRow, {
			at: this.builder.model.get('rows').indexOf(this.model) + 1
		} );

		this.closeDialog({silent: true});

		return false;
	},

	closeHandler: function() {
		this.clearCellStylesCache();
		if( ! _.isUndefined(this.cellStyles) ) {
			this.cellStyles = undefined;
		}
	},

	switchModeShow: function() {
		this.$( '.so-toolbar .so-mode-list' ).show();
		this.$( '.so-toolbar .button-primary:visible' ).addClass( 'so-active-mode' );
		this.$( '.so-toolbar .button-primary' ).hide();
		setTimeout( function() {
			$( document ).one( 'click', function( e ) {
				var $$ = jQuery( e.target );

				if ( ! $$.hasClass( 'so-saveinline-mode' ) && ! $$.hasClass( 'so-close-mode' ) ) {
					$( '.so-mode-list' ).hide();
					$( '.so-toolbar .so-active-mode' ).show()
				}
			} );
		}, 100 );
	},

	switchMode: function( inline = false ) {
		this.$( '.so-toolbar .so-mode-list' ).hide();
		this.$( '.so-toolbar .button-primary' ).removeClass( 'so-active-mode' );
		if ( inline ) {
			this.$( '.so-toolbar .so-saveinline' ).show();
		} else {
			this.$( '.so-toolbar .so-save' ).show();
		}

		window.panelsMode = inline ? 'inline' : 'dialog';
	},

});
