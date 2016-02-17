module.exports = Backbone.Model.extend( {
	layoutPosition: {
		BEFORE: 'before',
		AFTER: 'after',
	},

    rows: {},

    defaults : {
        'data' : {
            'widgets' : [],
            'grids' : [],
            'grid_cells' : []
        }
    },

    initialize: function(){
        // These are the main rows in the interface
        this.rows = new panels.collection.rows();
    },

    /**
     * Add a new row to this builder.
     *
     * @param weights
     */
    addRow: function( weights, options ){
        options = _.extend({
            noAnimate : false
        }, options);
        // Create the actual row
        var row = new panels.model.row( {
            collection: this.rows
        } );

        row.setCells( weights );
        row.builder = this;

        this.rows.add(row, options);

        return row;
    },

	/**
	 * Load the panels data into the builder
	 *
	 * @param data Object the layout and widgets data to load.
	 * @param position string Where to place the new layout. Allowed options are 'before', 'after'. Anything else will
	 * 						  cause the new layout to replace the old one.
	 */
    loadPanelsData: function(data, position){

		if(position === this.layoutPosition.BEFORE) {
			data = this.concatPanelsData(data, this.getPanelsData());
		} else if(position === this.layoutPosition.AFTER) {
			data = this.concatPanelsData(this.getPanelsData(), data);
		}

        // Start by destroying any rows that currently exist. This will in turn destroy cells, widgets and all the associated views
		this.emptyRows();

		// This will empty out the current rows and reload the builder data.
		this.set( 'data', data, {silent: true} );

        var cit = 0;
        var rows = [];

        if( typeof data.grid_cells === 'undefined' ) {
            this.trigger('load_panels_data');
            return;
        }

        var gi;
        for(var ci = 0; ci < data.grid_cells.length; ci++) {
            gi = parseInt(data.grid_cells[ci].grid);
            if(typeof rows[gi] === 'undefined') {
                rows[gi] = [];
            }

            rows[gi].push( parseFloat( data.grid_cells[ci].weight ) );
        }

        var builderModel = this;
        _.each( rows, function(row, i){
            // This will create and add the row model and its cells
            var newRow = builderModel.addRow( row, { noAnimate: true } );

            if( typeof data.grids[i].style !== 'undefined' ) {
                newRow.set( 'style', data.grids[i].style );
            }
        } );


        if( typeof data.widgets === 'undefined' ) { return; }

        // Add the widgets
        _.each(data.widgets, function(widgetData){
            try {
                var panels_info = null;
                if (typeof widgetData.panels_info !== 'undefined') {
                    panels_info = widgetData.panels_info;
                    delete widgetData.panels_info;
                }
                else {
                    panels_info = widgetData.info;
                    delete widgetData.info;
                }

                var row = builderModel.rows.at( parseInt(panels_info.grid) );
                var cell = row.cells.at(parseInt(panels_info.cell));

                var newWidget = new panels.model.widget({
                    class: panels_info.class,
                    values: widgetData
                });

                if( typeof panels_info.style !== 'undefined' ) {
                    newWidget.set('style', panels_info.style );
                }

                newWidget.cell = cell;
                cell.widgets.add(newWidget, {noAnimate: true});
            }
            catch (err) {
            }
        } );

        this.trigger('load_panels_data');
    },

	/**
	 * Concatenate the second set of Page Builder data to the first. There is some validation of input, but for the most
	 * part it's up to the caller to ensure the Page Builder data is well formed.
	 */
	concatPanelsData: function(panelsDataA, panelsDataB) {

		if(_.isUndefined(panelsDataB) || _.isUndefined(panelsDataB.grids) || _.isEmpty(panelsDataB.grids) ||
			_.isUndefined(panelsDataB.grid_cells) || _.isEmpty(panelsDataB.grid_cells)) {
			return panelsDataA;
		}

		if(_.isUndefined(panelsDataA) || _.isUndefined(panelsDataA.grids) || _.isEmpty(panelsDataA.grids)) {
			return panelsDataB;
		}

		var gridsBOffset = panelsDataA.grids.length;
		var widgetsBOffset = !_.isUndefined(panelsDataA.widgets) ? panelsDataA.widgets.length : 0;
		var newPanelsData = {grids:[], 'grid_cells' : [], 'widgets' : []};

		// Concatenate grids (rows)
		newPanelsData.grids = panelsDataA.grids.concat(panelsDataB.grids);

		// Create a copy of panelsDataA grid_cells and widgets
		if(!_.isUndefined(panelsDataA.grid_cells)) {
			newPanelsData.grid_cells = panelsDataA.grid_cells.slice();
		}
		if(!_.isUndefined(panelsDataA.widgets)) {
			newPanelsData.widgets = panelsDataA.widgets.slice();
		}

		var i;
		// Concatenate grid cells (row columns)
		for(i = 0; i < panelsDataB.grid_cells.length; i++) {
			var gridCellB = panelsDataB.grid_cells[i];
			gridCellB.grid = parseInt(gridCellB.grid) + gridsBOffset;
			newPanelsData.grid_cells.push(gridCellB);
		}

		// Concatenate widgets
		if(!_.isUndefined(panelsDataB.widgets)) {
			for (i = 0; i < panelsDataB.widgets.length; i++) {
				var widgetB = panelsDataB.widgets[i];
				widgetB.panels_info.grid = parseInt(widgetB.panels_info.grid) + gridsBOffset;
				widgetB.panels_info.id = parseInt(widgetB.panels_info.id) + widgetsBOffset;
				newPanelsData.widgets.push(widgetB);
			}
		}

		return newPanelsData;
	},

    /**
     * Convert the content of the builder into a object that represents the page builder data
     */
    getPanelsData: function(){

        var data = {
            'widgets' : [],
            'grids' : [],
            'grid_cells' : []
        };
        var widgetId = 0;

        this.rows.each(function(row, ri){

            row.cells.each(function(cell, ci){

                cell.widgets.each(function(widget, wi){
                    // Add the data for the widget, including the panels_info field.
                    var values = _.extend( _.clone( widget.get('values') ), {
                        panels_info : {
                            class: widget.get('class'),
                            raw: widget.get('raw'),
                            grid: ri,
                            cell: ci,
                            id: widgetId++,
                            style: widget.get('style')
                        }
                    } );
                    data.widgets.push( values );
                });

                // Add the cell info
                data.grid_cells.push( {
                    grid: ri,
                    weight: cell.get('weight')
                } );

            });

            data.grids.push( {
                cells: row.cells.length,
                style: row.get('style')
            } );

        } );

        return data;

    },

    /**
     * This will check all the current entries and refresh the panels data
     */
    refreshPanelsData: function(){
        var oldData = JSON.stringify( this.get('data') );
        var newData = this.getPanelsData();
        this.set( 'data', newData, { silent: true } );

        if( JSON.stringify( newData ) !== oldData ) {
            // The default change event doesn't trigger on deep changes, so we'll trigger our own
            this.trigger('change');
            this.trigger('change:data');
        }
    },

    /**
     * Empty all the rows and the cells/widgets they contain.
     */
    emptyRows: function(){
        _.invoke(this.rows.toArray(), 'destroy');
        this.rows.reset();

        return this;
    }

} );
