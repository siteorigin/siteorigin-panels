module.exports = Backbone.Model.extend({
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
		this.set( 'rows', new panels.collection.rows() );
	},

	/**
	 * Add a new row to this builder.
	 *
	 * @param attrs
	 * @param cells
	 * @param options
	 */
	addRow: function (attrs, cells, options) {
		options = _.extend({
			noAnimate: false
		}, options);

		var cellCollection = new panels.collection.cells(cells);

		attrs = _.extend({
			collection: this.get('rows'),
			cells: cellCollection,
		}, attrs);

		// Create the actual row
		var row = new panels.model.row(attrs);
		row.builder = this;

		this.get('rows').add( row, options );

		return row;
	},

	/**
	 * Load the panels data into the builder
	 *
	 * @param data Object the layout and widgets data to load.
	 * @param position string Where to place the new layout. Allowed options are 'before', 'after'. Anything else will
	 *						  cause the new layout to replace the old one.
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

				rows[gi].push( data.grid_cells[ci] );
			}

			var builderModel = this;
			_.each( rows, function ( row, i ) {
				var rowAttrs = {};

				if ( ! _.isUndefined( data.grids[i].style ) ) {
					rowAttrs.style = data.grids[i].style;
				}

				if ( ! _.isUndefined( data.grids[i].ratio) ) {
					rowAttrs.ratio = data.grids[i].ratio;
				}

				if ( ! _.isUndefined( data.grids[i].ratio_direction) ) {
					rowAttrs.ratio_direction = data.grids[i].ratio_direction
				}

				if ( ! _.isUndefined( data.grids[i].color_label) ) {
					rowAttrs.color_label = data.grids[i].color_label;
				}

				if ( ! _.isUndefined( data.grids[i].label) ) {
					rowAttrs.label = data.grids[i].label;
				}
				// This will create and add the row model and its cells
				builderModel.addRow(rowAttrs, row, {noAnimate: true} );
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

				var row = builderModel.get('rows').at( parseInt( panels_info.grid ) );
				var cell = row.get('cells').at( parseInt( panels_info.cell ) );

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
					newWidget.set( 'widget_id', panels.helpers.utils.generateUUID() );
				}

				if ( ! _.isUndefined( panels_info.label ) ) {
					newWidget.set( 'label', panels_info.label );
				}

				newWidget.cell = cell;
				cell.get('widgets').add( newWidget, { noAnimate: true } );
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

		this.get('rows').each( function ( row, ri ) {

			row.get('cells').each( function ( cell, ci ) {

				cell.get('widgets').each( function ( widget, wi ) {
					// Add the data for the widget, including the panels_info field.
					var panels_info = {
						class: widget.get( 'class' ),
						raw: widget.get( 'raw' ),
						grid: ri,
						cell: ci,
						// Strictly this should be an index
						id: widgetId ++,
						widget_id: widget.get( 'widget_id' ),
						style: widget.get( 'style' ),
						label: widget.get( 'label' ),
					};

					if( _.isEmpty( panels_info.widget_id ) ) {
						panels_info.widget_id = panels.helpers.utils.generateUUID();
					}

					var values = _.extend( _.clone( widget.get( 'values' ) ), {
						panels_info: panels_info
					} );
					data.widgets.push( values );
				} );

				// Add the cell info
				data.grid_cells.push( {
					grid: ri,
					index: ci,
					weight: cell.get( 'weight' ),
					style: cell.get( 'style' ),
				} );

			} );

			data.grids.push( {
				cells: row.get('cells').length,
				style: row.get( 'style' ),
				ratio: row.get('ratio'),
				ratio_direction: row.get('ratio_direction'),
				color_label: row.get( 'color_label' ),
				label: row.get( 'label' ),
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
		_.invoke( this.get('rows').toArray(), 'destroy' );
		this.get('rows').reset();

		return this;
	},

	isValidLayoutPosition: function ( position ) {
		return position === this.layoutPosition.BEFORE ||
			   position === this.layoutPosition.AFTER ||
			   position === this.layoutPosition.REPLACE;
	},

	/**
	 * Convert HTML into Panels Data
	 * @param html
	 */
	getPanelsDataFromHtml: function( html, editorClass ){
		var thisModel = this;
		var $html = jQuery( '<div id="wrapper">' + html + '</div>' );

		if( $html.find('.panel-layout .panel-grid').length ) {
			// This looks like Page Builder html, lets try parse it
			var panels_data = {
				grids: [],
				grid_cells: [],
				widgets: [],
			};

			// The Regex object that'll match SiteOrigin widgets
			var re = new RegExp( panelsOptions.siteoriginWidgetRegex , "i" );
			var decodeEntities = (function() {
				// this prevents any overhead from creating the object each time
				var element = document.createElement('div');

				function decodeHTMLEntities (str) {
					if(str && typeof str === 'string') {
						// strip script/html tags
						str = str.replace(/<script[^>]*>([\S\s]*?)<\/script>/gmi, '');
						str = str.replace(/<\/?\w(?:[^"'>]|"[^"]*"|'[^']*')*>/gmi, '');
						element.innerHTML = str;
						str = element.textContent;
						element.textContent = '';
					}

					return str;
				}

				return decodeHTMLEntities;
			})();

			// Remove all wrapping divs from a widget to get its html
			var getTextWidgetContents = function( $el ){
				var $divs = $el.find( 'div' );
				if( ! $divs.length ) {
					return $el.html();
				}

				for ( var i = 0; i < $divs.length - 1; i++ ) {
					if ( $divs.eq( i ).text().trim() != $divs.eq( i + 1 ).text().trim() ) {
						break;
					}
				}

				var title = $divs.eq( i ).find( '.widget-title:header' ),
					titleText = '';

				if( title.length ) {
					titleText = title.html();
					title.remove();
				}

				return {
					title: titleText,
					text: $divs.eq(i).html(),
				};
			};

			var $layout = $html.find( '.panel-layout' ).eq(0);
			var filterNestedLayout = function( i, el ){
				return jQuery( el ).closest( '.panel-layout' ).is( $layout );
			};

			$html.find('> .panel-layout > .panel-grid').filter( filterNestedLayout ).each( function( ri, el ){
				var $row = jQuery( el ),
					$cells = $row.find( '.panel-grid-cell' ).filter( filterNestedLayout );

				panels_data.grids.push( {
					cells: $cells.length,
					style: $row.data( 'style' ),
					ratio: $row.data( 'ratio' ),
					ratio_direction: $row.data( 'ratio-direction' ),
					color_label: $row.data( 'color-label' ),
					label: $row.data( 'label' ),
				} );

				$cells.each( function( ci, el ){
					var $cell = jQuery( el ),
						$widgets = $cell.find( '.so-panel' ).filter( filterNestedLayout );

					panels_data.grid_cells.push( {
						grid: ri,
						weight: ! _.isUndefined( $cell.data( 'weight' ) ) ? parseFloat( $cell.data( 'weight' ) ) : 1,
						style: $cell.data( 'style' ),
					} );

					$widgets.each( function( wi, el ){
						var $widget = jQuery(el),
							widgetContent = $widget.find('.panel-widget-style').length ? $widget.find('.panel-widget-style').html() : $widget.html(),
							panels_info = {
								grid: ri,
								cell: ci,
								style: $widget.data( 'style' ),
								raw: false,
								label: $widget.data( 'label' )
							};

						widgetContent = widgetContent.trim();

						// Check if this is a SiteOrigin Widget
						var match = re.exec( widgetContent );
						if( ! _.isNull( match ) && widgetContent.replace( re, '' ).trim() === '' ) {
							try {
								var classMatch = /class="(.*?)"/.exec( match[3] ),
									dataInput = jQuery( match[5] ),
									data = JSON.parse( decodeEntities( dataInput.val( ) ) ),
									newWidget = data.instance;

								panels_info.class = classMatch[1].replace( /\\\\+/g, '\\' );
								panels_info.raw = false;

								newWidget.panels_info = panels_info;
								panels_data.widgets.push( newWidget );
							}
							catch ( err ) {
								// There was a problem, so treat this as a standard editor widget
								panels_info.class = editorClass;
								panels_data.widgets.push( _.extend( getTextWidgetContents( $widget ), {
									filter: "1",
									type: "visual",
									panels_info: panels_info
								} ) );
							}

							// Continue
							return true;
						}
						else if( widgetContent.indexOf( 'panel-layout' ) !== -1 ) {
							// Check if this is a layout widget
							var $widgetContent = jQuery( '<div>' + widgetContent + '</div>' );
							if( $widgetContent.find('.panel-layout .panel-grid').length ) {
								// This is a standard editor class widget
								panels_info.class = 'SiteOrigin_Panels_Widgets_Layout';
								panels_data.widgets.push( {
									panels_data: thisModel.getPanelsDataFromHtml( widgetContent, editorClass ),
									panels_info: panels_info
								} );

								// continue
								return true;
							}
						}

						// This is a standard editor class widget
						panels_info.class = editorClass;
						panels_data.widgets.push( _.extend( getTextWidgetContents( $widget ), {
							filter: "1",
							type: "visual",
							panels_info: panels_info
						} ) );
						return true;
					} );
				} );
			} );

			// Remove all the Page Builder content
			$html.find('.panel-layout').remove();
			$html.find('style[data-panels-style-for-post]').remove();

			// If there's anything left, add it to an editor widget at the end of panels_data
			if( $html.html().replace(/^\s+|\s+$/gm,'').length ) {
				panels_data.grids.push( {
					cells: 1,
					style: {},
				} );
				panels_data.grid_cells.push( {
					grid: panels_data.grids.length - 1,
					weight: 1,
				} );
				panels_data.widgets.push( {
					filter: "1",
					text: $html.html().replace(/^\s+|\s+$/gm,''),
					title: "",
					type: "visual",
					panels_info: {
						class: editorClass,
						raw: false,
						grid: panels_data.grids.length - 1,
						cell: 0
					}
				} );
			}

			return panels_data;
		}
		else {
			// This is probably just old school post content
			return {
				grid_cells: [ { grid: 0, weight: 1 } ],
				grids: [ { cells: 1 } ],
				widgets: [
					{
						filter: "1",
						text: html,
						title: "",
						type: "visual",
						panels_info: {
							class: editorClass,
							raw: false,
							grid: 0,
							cell: 0
						}
					}
				]
			};
		}
	}
} );
