module.exports = Backbone.Model.extend( {
	/* A collection of the cells in this row */
	cells: {},

	/* The builder model */
	builder: null,

	defaults: {
		style: {}
	},

	indexes: null,

	/**
	 * Initialize the row model
	 */
	initialize: function () {
		this.cells = new panels.collection.cells();
		this.on( 'destroy', this.onDestroy, this );
	},

	/**
	 * Add cells to the model row
	 *
	 * @param cells an array of cells, where each object in the array has a weight value
	 */
	setCells: function ( cells ) {
		var thisModel = this;

		if ( _.isEmpty( this.cells ) ) {
			// We're adding the initial cells
			_.each( cells, function ( cellWeight ) {
				// Add the new cell to the row
				var cell = new panels.model.cell( {
					weight: cellWeight,
					collection: thisModel.cells
				} );
				cell.row = thisModel;
				thisModel.cells.add( cell );
			} );
		} else {

			if ( cells.length > this.cells.length ) {
				// We need to add cells
				for ( var i = this.cells.length; i < cells.length; i ++ ) {
					var cell = new panels.model.cell( {
						weight: cells[cells.length + i],
						collection: thisModel.cells
					} );
					cell.row = this;
					thisModel.cells.add( cell );
				}

			}
			else if ( cells.length < this.cells.length ) {
				var newParentCell = this.cells.at( cells.length - 1 );

				// We need to remove cells
				_.each( this.cells.slice( cells.length, this.cells.length ), function ( cell ) {
					var widgetsToMove = cell.widgets.models.slice( 0 );
					for ( var i = 0; i < widgetsToMove.length; i ++ ) {
						widgetsToMove[i].moveToCell( newParentCell, {silent: false} );
					}

					// First move all the widgets to the new cell
					cell.destroy();
				} );
			}

			// Now we need to change the weights of all the cells
			this.cells.each( function ( cell, i ) {
				cell.set( 'weight', cells[i] );
			} );
		}

		// Rescale the cells when we add or remove
		this.reweightCells();
	},

	/**
	 * Make sure that all the cell weights add up to 1
	 */
	reweightCells: function () {
		var totalWeight = 0;
		this.cells.each( function ( cell ) {
			totalWeight += cell.get( 'weight' );
		} );

		this.cells.each( function ( cell ) {
			cell.set( 'weight', cell.get( 'weight' ) / totalWeight );
		} );

		// This is for the row view to hook into and resize
		this.trigger( 'reweight_cells' );
	},

	/**
	 * Triggered when the model is destroyed
	 */
	onDestroy: function () {
		// Also destroy all the cells
		_.invoke( this.cells.toArray(), 'destroy' );
		this.cells.reset();
	},

	/**
	 * Create a clone of the row, along with all its cells
	 *
	 * @param {panels.model.builder} builder The builder model to attach this to.
	 *
	 * @return {panels.model.row} The cloned row.
	 */
	clone: function ( builder, cloneOptions ) {
		if ( _.isUndefined( builder ) ) {
			builder = this.builder;
		}
		cloneOptions = _.extend( {cloneCells: true}, cloneOptions );

		var clone = new this.constructor( this.attributes );
		clone.set( 'collection', builder.rows, {silent: true} );
		clone.builder = builder;

		if ( cloneOptions.cloneCells ) {
			// Clone all the rows
			this.cells.each( function ( cell ) {
				clone.cells.add( cell.clone( clone, cloneOptions ), {silent: true} );
			} );
		}

		return clone;
	}
} );
