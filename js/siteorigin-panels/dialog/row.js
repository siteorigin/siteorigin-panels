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
			this.styles.render( 'row', this.builder.config.postId, {
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
