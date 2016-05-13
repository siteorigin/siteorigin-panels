module.exports = Backbone.Model.extend( {
	layoutPosition: {
		BEFORE: 'before',
		AFTER: 'after',
		REPLACE: 'replace',
	},

	rows: {},

	defaults: {
		'data': {
			'widgets': [],
			'grids': [],
			'grid_cells': []
		}
	},

	initialize: function () {
		// These are the main rows in the interface
		this.rows = new panels.collection.rows();
	},

	/**
	 * Add a new row to this builder.
	 *
	 * @param weights
	 */
	addRow: function ( weights, options ) {
		options = _.extend( {
			noAnimate: false
		}, options );
		// Create the actual row
		var row = new panels.model.row( {
			collection: this.rows
		} );

		row.setCells( weights );
		row.builder = this;

		this.rows.add( row, options );

		return row;
	},

	/**
	 * Load the panels data into the builder
	 *
	 * @param data Object the layout and widgets data to load.
	 * @param position string Where to place the new layout. Allowed options are 'before', 'after'. Anything else will
	 *                          cause the new layout to replace the old one.
	 */
	loadPanelsData: function ( data, position ) {
		try {
			if ( position === this.layoutPosition.BEFORE ) {
				data = this.concatPanelsData( data, this.getPanelsData() );
			} else if ( position === this.layoutPosition.AFTER ) {
				data = this.concatPanelsData( this.getPanelsData(), data );
			}

			// Start by destroying any rows that currently exist. This will in turn destroy cells, widgets and all the associated views
			this.emptyRows();

			// This will empty out the current rows and reload the builder data.
			this.set( 'data', JSON.parse( JSON.stringify( data ) ), {silent: true} );

			var cit = 0;
			var rows = [];

			if ( _.isUndefined( data.grid_cells ) ) {
				this.trigger( 'load_panels_data' );
				return;
			}

			var gi;
			for ( var ci = 0; ci < data.grid_cells.length; ci ++ ) {
				gi = parseInt( data.grid_cells[ci].grid );
				if ( _.isUndefined( rows[gi] ) ) {
					rows[gi] = [];
				}

				rows[gi].push( parseFloat( data.grid_cells[ci].weight ) );
			}

			var builderModel = this;
			_.each( rows, function ( row, i ) {
				// This will create and add the row model and its cells
				var newRow = builderModel.addRow( row, {noAnimate: true} );

				if ( ! _.isUndefined( data.grids[i].style ) ) {
					newRow.set( 'style', data.grids[i].style );
				}
			} );


			if ( _.isUndefined( data.widgets ) ) {
				return;
			}

			// Add the widgets
			_.each( data.widgets, function ( widgetData ) {
				var panels_info = null;
				if ( ! _.isUndefined( widgetData.panels_info ) ) {
					panels_info = widgetData.panels_info;
					delete widgetData.panels_info;
				} else {
					panels_info = widgetData.info;
					delete widgetData.info;
				}

				var row = builderModel.rows.at( parseInt( panels_info.grid ) );
				var cell = row.cells.at( parseInt( panels_info.cell ) );

				var newWidget = new panels.model.widget( {
					class: panels_info.class,
					values: widgetData
				} );

				if ( ! _.isUndefined( panels_info.style ) ) {
					newWidget.set( 'style', panels_info.style );
				}

				if ( ! _.isUndefined( panels_info.read_only ) ) {
					newWidget.set( 'read_only', panels_info.read_only );
				}
				if ( ! _.isUndefined( panels_info.widget_id ) ) {
					newWidget.set( 'widget_id', panels_info.widget_id );
				}
				else {
					newWidget.set( 'widget_id', builderModel.generateUUID() );
				}

				newWidget.cell = cell;
				cell.widgets.add( newWidget, { noAnimate: true } );
			} );

			this.trigger( 'load_panels_data' );
		}
		catch ( err ) {
			console.log( 'Error loading data: ' + err.message );

		}
	},

	/**
	 * Concatenate the second set of Page Builder data to the first. There is some validation of input, but for the most
	 * part it's up to the caller to ensure the Page Builder data is well formed.
	 */
	concatPanelsData: function ( panelsDataA, panelsDataB ) {

		if ( _.isUndefined( panelsDataB ) || _.isUndefined( panelsDataB.grids ) || _.isEmpty( panelsDataB.grids ) ||
		     _.isUndefined( panelsDataB.grid_cells ) || _.isEmpty( panelsDataB.grid_cells ) ) {
			return panelsDataA;
		}

		if ( _.isUndefined( panelsDataA ) || _.isUndefined( panelsDataA.grids ) || _.isEmpty( panelsDataA.grids ) ) {
			return panelsDataB;
		}

		var gridsBOffset = panelsDataA.grids.length;
		var widgetsBOffset = ! _.isUndefined( panelsDataA.widgets ) ? panelsDataA.widgets.length : 0;
		var newPanelsData = {grids: [], 'grid_cells': [], 'widgets': []};

		// Concatenate grids (rows)
		newPanelsData.grids = panelsDataA.grids.concat( panelsDataB.grids );

		// Create a copy of panelsDataA grid_cells and widgets
		if ( ! _.isUndefined( panelsDataA.grid_cells ) ) {
			newPanelsData.grid_cells = panelsDataA.grid_cells.slice();
		}
		if ( ! _.isUndefined( panelsDataA.widgets ) ) {
			newPanelsData.widgets = panelsDataA.widgets.slice();
		}

		var i;
		// Concatenate grid cells (row columns)
		for ( i = 0; i < panelsDataB.grid_cells.length; i ++ ) {
			var gridCellB = panelsDataB.grid_cells[i];
			gridCellB.grid = parseInt( gridCellB.grid ) + gridsBOffset;
			newPanelsData.grid_cells.push( gridCellB );
		}

		// Concatenate widgets
		if ( ! _.isUndefined( panelsDataB.widgets ) ) {
			for ( i = 0; i < panelsDataB.widgets.length; i ++ ) {
				var widgetB = panelsDataB.widgets[i];
				widgetB.panels_info.grid = parseInt( widgetB.panels_info.grid ) + gridsBOffset;
				widgetB.panels_info.id = parseInt( widgetB.panels_info.id ) + widgetsBOffset;
				newPanelsData.widgets.push( widgetB );
			}
		}

		return newPanelsData;
	},

	/**
	 * Convert the content of the builder into a object that represents the page builder data
	 */
	getPanelsData: function () {

		var builder = this;

		var data = {
			'widgets': [],
			'grids': [],
			'grid_cells': []
		};
		var widgetId = 0;

		this.rows.each( function ( row, ri ) {

			row.cells.each( function ( cell, ci ) {

				cell.widgets.each( function ( widget, wi ) {
					// Add the data for the widget, including the panels_info field.
					var panels_info = {
						class: widget.get( 'class' ),
						raw: widget.get( 'raw' ),
						grid: ri,
						cell: ci,
						// Strictly this should be an index
						id: widgetId ++,
						widget_id: widget.get( 'widget_id' ),
						style: widget.get( 'style' )
					};

					if( _.isEmpty( panels_info.widget_id ) ) {
						panels_info.widget_id = builder.generateUUID();
					}

					var values = _.extend( _.clone( widget.get( 'values' ) ), {
						panels_info: panels_info
					} );
					data.widgets.push( values );
				} );

				// Add the cell info
				data.grid_cells.push( {
					grid: ri,
					weight: cell.get( 'weight' )
				} );

			} );

			data.grids.push( {
				cells: row.cells.length,
				style: row.get( 'style' )
			} );

		} );

		return data;

	},

	/**
	 * This will check all the current entries and refresh the panels data
	 */
	refreshPanelsData: function ( args ) {
		args = _.extend( {
			silent: false
		}, args );

		var oldData = this.get( 'data' );
		var newData = this.getPanelsData();
		this.set( 'data', newData, {silent: true} );

		if ( ! args.silent && JSON.stringify( newData ) !== JSON.stringify( oldData ) ) {
			// The default change event doesn't trigger on deep changes, so we'll trigger our own
			this.trigger( 'change' );
			this.trigger( 'change:data' );
			this.trigger( 'refresh_panels_data', newData, args );
		}
	},

	/**
	 * Empty all the rows and the cells/widgets they contain.
	 */
	emptyRows: function () {
		_.invoke( this.rows.toArray(), 'destroy' );
		this.rows.reset();

		return this;
	},

	isValidLayoutPosition: function ( position ) {
		return position === this.layoutPosition.BEFORE ||
		       position === this.layoutPosition.AFTER ||
		       position === this.layoutPosition.REPLACE;
	},

	generateUUID: function(){
		var d = new Date().getTime();
		if( window.performance && typeof window.performance.now === "function" ){
			d += performance.now(); //use high-precision timer if available
		}
		var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function(c) {
			var r = (d + Math.random()*16)%16 | 0;
			d = Math.floor(d/16);
			return ( c == 'x' ? r : (r&0x3|0x8) ).toString(16);
		} );
		return uuid;
	}

} );
