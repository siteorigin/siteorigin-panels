module.exports = Backbone.Model.extend( {
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
		if ( _.isEmpty(this.get('cells') ) ) {
			this.set('cells', new panels.collection.cells());
		}
		else {
			// Make sure that the cells have this row set as their parent
			this.get('cells').each( function( cell ){
				cell.row = this;
			}.bind( this ) );
		}
		this.on( 'destroy', this.onDestroy, this );
	},

	/**
	 * Add cells to the model row
	 *
	 * @param newCells the updated collection of cell models
	 */
	setCells: function ( newCells ) {
		var currentCells = this.get('cells') || new panels.collection.cells();
		var cellsToRemove = [];

		currentCells.each(function (cell, i) {
			var newCell = newCells.at(i);
			if(newCell) {
				cell.set('weight', newCell.get('weight'));
			} else {
				var newParentCell = currentCells.at( newCells.length - 1 );

				// First move all the widgets to the new cell
				var widgetsToMove = cell.get('widgets').models.slice();
				for ( var j = 0; j < widgetsToMove.length; j++ ) {
					widgetsToMove[j].moveToCell( newParentCell, { silent: false } );
				}

				cellsToRemove.push(cell);
			}
		});

		_.each(cellsToRemove, function(cell) {
			currentCells.remove(cell);
		});

		if( newCells.length > currentCells.length) {
			_.each(newCells.slice(currentCells.length, newCells.length), function (newCell) {
				// TODO: make sure row and collection is set correctly when cell is created then we can just add new cells
				newCell.set({collection: currentCells});
				newCell.row = this;
				currentCells.add(newCell);
			}.bind(this));
		}

		// Rescale the cells when we add or remove
		this.reweightCells();
	},

	/**
	 * Make sure that all the cell weights add up to 1
	 */
	reweightCells: function () {
		var totalWeight = 0;
		var cells = this.get('cells');
		cells.each( function ( cell ) {
			totalWeight += cell.get( 'weight' );
		} );

		cells.each( function ( cell ) {
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
		_.invoke( this.get('cells').toArray(), 'destroy' );
		this.get('cells').reset();
	},

	/**
	 * Create a clone of the row, along with all its cells
	 *
	 * @param {panels.model.builder} builder The builder model to attach this to.
	 *
	 * @return {panels.model.row} The cloned row.
	 */
	clone: function ( builder ) {
		if ( _.isUndefined( builder ) ) {
			builder = this.builder;
		}

		var clone = new this.constructor( this.attributes );
		clone.set( 'collection', builder.get('rows'), {silent: true} );
		clone.builder = builder;

		var cellClones = new panels.collection.cells();
		this.get('cells').each( function ( cell ) {
			cellClones.add( cell.clone( clone ), {silent: true} );
		} );

		clone.set( 'cells', cellClones );

		return clone;
	}
} );
