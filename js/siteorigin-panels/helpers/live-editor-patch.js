/**
 * Plan in-place Live Editor preview patches for moves and resizes.
 *
 * snapshot() records the builder at the moment its data is posted to (or patched into) the preview: the
 * layout data and, by position, the Backbone cid of every row, cell and widget. Row, cell and widget models
 * keep their cid through a drag, so two snapshots show where each one moved.
 *
 * plan() returns the patch that turns the preview of one snapshot into the preview of the next, or null
 * when a full reload is needed. It returns a patch only when the change is a pure reorder of rows, a pure
 * reorder or move of widgets between non-empty cells, or a change of cell weights, and nothing else.
 *
 * structureChange() describes a change that only adds or removes widgets and rows, and positions() the
 * position of every row, cell and widget in a snapshot, as the server numbers them.
 *
 * Depends only on `_`, so the node unit tests can load it.
 */

/**
 * A copy of a value with the keys of every object sorted, for a stable JSON comparison.
 */
var sortKeys = function ( value ) {
	if ( _.isArray( value ) ) {
		return _.map( value, sortKeys );
	}

	if ( _.isObject( value ) && ! _.isFunction( value ) ) {
		var sorted = {};
		_.each( _.keys( value ).sort(), function ( key ) {
			sorted[ key ] = sortKeys( value[ key ] );
		} );

		return sorted;
	}

	return value;
};

var same = function ( a, b ) {
	return JSON.stringify( sortKeys( a ) ) === JSON.stringify( sortKeys( b ) );
};

var copy = function ( value ) {
	return value === undefined ? undefined : JSON.parse( JSON.stringify( value ) );
};

/**
 * A copy of an object without some keys.
 */
var without = function ( value, keys ) {
	return _.isObject( value ) ? _.omit( copy( value ), keys ) : value;
};

/**
 * Index a snapshot by cid. Null when the position lists do not match the data, so the snapshot cannot be trusted.
 *
 * @return {null|{ rowOrder: string[], rows: Object, cells: Object, widgets: Object, lastRow: string }}
 */
var index = function ( snap ) {
	if (
		! snap ||
		! _.isObject( snap.data ) ||
		! _.isArray( snap.rows ) ||
		! _.isArray( snap.data.grids ) ||
		! _.isArray( snap.data.grid_cells ) ||
		! _.isArray( snap.data.widgets ) ||
		snap.data.grids.length !== snap.rows.length
	) {
		return null;
	}

	var out = { rowOrder: [], rows: {}, cells: {}, widgets: {}, lastRow: null };
	var cellIndex = 0;
	var widgetIndex = 0;
	var valid = true;

	_.each( snap.rows, function ( row, ri ) {
		out.rowOrder.push( row.cid );
		out.rows[ row.cid ] = { grid: snap.data.grids[ ri ], cells: [] };

		_.each( row.cells, function ( cell, ci ) {
			var cellData = snap.data.grid_cells[ cellIndex++ ];
			if ( ! cellData || Number( cellData.grid ) !== ri || Number( cellData.index ) !== ci ) {
				valid = false;
				return;
			}

			out.rows[ row.cid ].cells.push( cell.cid );
			out.cells[ cell.cid ] = { row: row.cid, index: ci, data: cellData, widgets: [] };

			_.each( cell.widgets, function ( widgetCid ) {
				var widgetData = snap.data.widgets[ widgetIndex++ ];
				var info = widgetData && widgetData.panels_info;
				if ( ! info || Number( info.grid ) !== ri || Number( info.cell ) !== ci ) {
					valid = false;
					return;
				}

				out.cells[ cell.cid ].widgets.push( widgetCid );
				out.widgets[ widgetCid ] = widgetData;
			} );
		} );
	} );

	if (
		! valid ||
		cellIndex !== snap.data.grid_cells.length ||
		widgetIndex !== snap.data.widgets.length ||
		_.uniq( out.rowOrder ).length !== out.rowOrder.length ||
		_.size( out.cells ) !== cellIndex ||
		_.size( out.widgets ) !== widgetIndex
	) {
		return null;
	}

	out.lastRow = _.last( out.rowOrder ) || null;

	return out;
};

var sameKeys = function ( a, b ) {
	return same( _.keys( a ).sort(), _.keys( b ).sort() );
};

var round4 = function ( value ) {
	return Math.round( value * 10000 ) / 10000;
};

/**
 * A widget's data without `panels_info.widget_id`, which the builder makes up again on every read for a widget
 * that has none stored, and without more `panels_info` keys when given.
 */
var withoutWidgetId = function ( widgetData, keys ) {
	var out = copy( widgetData );
	if ( out && _.isObject( out.panels_info ) ) {
		out.panels_info = _.omit( out.panels_info, [ 'widget_id' ].concat( keys || [] ) );
	}

	return out;
};

/**
 * The `panels_info` keys of a widget that the builder fills in silently, with no refresh, shortly after the widget
 * is created or duplicated: its form loads in the background and saves default style values and `raw`. The data
 * changes and what is rendered does not. A caller passes the cids of the widgets it added in place as `quiet`.
 */
var quietKeys = [ 'raw', 'style' ];

/**
 * Where the server numbers each row, cell and widget of an indexed snapshot: `pg-{row}`, `pgc-{row}-{cell}`,
 * `panel-{row}-{cell}-{widget}` and the flat `data-index` of a widget.
 */
var positionsOf = function ( indexed ) {
	var out = { rows: {}, cells: {}, widgets: {} };
	var flat = 0;

	_.each( indexed.rowOrder, function ( rowCid, ri ) {
		out.rows[ rowCid ] = ri;

		_.each( indexed.rows[ rowCid ].cells, function ( cellCid, ci ) {
			out.cells[ cellCid ] = { row: ri, cell: ci };

			_.each( indexed.cells[ cellCid ].widgets, function ( widgetCid, wi ) {
				out.widgets[ widgetCid ] = { row: ri, cell: ci, widget: wi, index: flat++ };
			} );
		} );
	} );

	return out;
};

module.exports = {

	/**
	 * The new width of a cell, from the width the browser reports for the server's cell rule.
	 *
	 * The server writes `width: P%` with a zero gutter, else `width: calc(P% - ( (1 - w0) * g ) )`
	 * (SiteOrigin_Panels_Renderer::generate_css()). Chrome reports the calc as `calc(P% - Kpx)`. The
	 * rule must show the weight it was rendered with; any other value means a filter changed it.
	 *
	 * @param {string} reported The width the browser reports for the rule (CSSStyleRule.style.width).
	 * @param {number} renderedWeight The weight the preview was rendered with (w0).
	 * @param {number} weight The new weight (w).
	 * @return {string|null} The new width, or null when the value cannot be read safely.
	 */
	cellWidth: function ( reported, renderedWeight, weight ) {
		var value = String( reported || '' ).trim();
		var expected = round4( renderedWeight * 100 );
		var percent = round4( weight * 100 );
		var match;

		if ( ! isFinite( renderedWeight ) || ! isFinite( weight ) ) {
			return null;
		}

		match = /^(-?\d*\.?\d+)%$/.exec( value );
		if ( match ) {
			return Math.abs( parseFloat( match[1] ) - expected ) < 1e-6 ? percent + '%' : null;
		}

		match = /^calc\(\s*(-?\d*\.?\d+)%\s*([+-])\s*(\d*\.?\d+)([a-z]+)\s*\)$/i.exec( value );
		if ( ! match || Math.abs( parseFloat( match[1] ) - expected ) >= 1e-6 || 1 - renderedWeight <= 0 ) {
			return null;
		}

		var term = parseFloat( match[3] );
		var gutter = ( match[2] === '-' ? term : -term ) / ( 1 - renderedWeight );
		gutter = parseFloat( gutter.toPrecision( 12 ) );

		return 'calc(' + percent + '% - ( ' + ( 1 - weight ).toPrecision( 14 ) + ' * ' + gutter + match[4] + ' ) )';
	},

	/**
	 * Snapshot of the builder at the moment its data is posted to (or patched into) the preview.
	 *
	 * @param {panels.model.builder} builderModel
	 * @param {Object} [data] The data that was posted, when the caller already has it. Defaults to getPanelsData().
	 * @return {{ data: Object, rows: Array }}
	 */
	snapshot: function ( builderModel, data ) {
		var rows = [];

		builderModel.get( 'rows' ).each( function ( row ) {
			rows.push( {
				cid: row.cid,
				cells: row.get( 'cells' ).map( function ( cell ) {
					return {
						cid: cell.cid,
						weight: cell.get( 'weight' ),
						widgets: cell.get( 'widgets' ).map( function ( widget ) {
							return widget.cid;
						} ),
					};
				} ),
			} );
		} );

		return {
			data: copy( data === undefined ? builderModel.getPanelsData() : data ),
			rows: rows,
		};
	},

	/**
	 * The widgets whose data changed between two snapshots with exactly the same structure: the same rows
	 * in the same order, the same cells, the same widgets in the same places, and equal row and cell data
	 * (weights included). Null when the structure differs or a snapshot cannot be trusted.
	 *
	 * @param {Object} prev Snapshot the preview shows.
	 * @param {Object} next Snapshot of the builder now.
	 * @param {string[]} [quiet] Widgets whose `raw` and `style` are not compared (see quietKeys).
	 * @return {null|string[]} Widget cids, in layout order.
	 */
	changedWidgets: function ( prev, next, quiet ) {
		var a = index( prev );
		var b = index( next );

		if ( ! a || ! b || ! same( a.rowOrder, b.rowOrder ) ) {
			return null;
		}

		var structureSame = _.every( b.rowOrder, function ( rowCid ) {
			var before = a.rows[ rowCid ];
			var after = b.rows[ rowCid ];

			return same( before.grid, after.grid ) &&
				same( before.cells, after.cells ) &&
				_.every( after.cells, function ( cellCid ) {
					return same( a.cells[ cellCid ].data, b.cells[ cellCid ].data ) &&
						same( a.cells[ cellCid ].widgets, b.cells[ cellCid ].widgets );
				} );
		} );
		if ( ! structureSame ) {
			return null;
		}

		var changed = [];
		_.each( b.rowOrder, function ( rowCid ) {
			_.each( b.rows[ rowCid ].cells, function ( cellCid ) {
				_.each( b.cells[ cellCid ].widgets, function ( widgetCid ) {
					var skip = _.contains( quiet || [], widgetCid ) ? quietKeys : [];

					if ( ! same( withoutWidgetId( a.widgets[ widgetCid ], skip ), withoutWidgetId( b.widgets[ widgetCid ], skip ) ) ) {
						changed.push( widgetCid );
					}
				} );
			} );
		} );

		return changed;
	},

	/**
	 * The rows, cells and widgets whose style object changed between two snapshots that are otherwise the
	 * same: the same rows, cells and widgets in the same places, the same cell weights, and equal row, cell
	 * and widget settings apart from `style`. Null when anything else differs, when no style differs, or
	 * when a snapshot cannot be trusted.
	 *
	 * @param {Object} prev Snapshot the preview shows.
	 * @param {Object} next Snapshot of the builder now.
	 * @return {null|{ rows: string[], cells: string[], widgets: string[] }} Cids of the changed rows, cells and widgets.
	 */
	styleChange: function ( prev, next ) {
		var a = index( prev );
		var b = index( next );

		if ( ! a || ! b || ! same( a.rowOrder, b.rowOrder ) ) {
			return null;
		}

		var out = { rows: [], cells: [], widgets: [] };
		var structureSame = _.every( b.rowOrder, function ( rowCid ) {
			var before = a.rows[ rowCid ];
			var after = b.rows[ rowCid ];

			if ( ! same( before.cells, after.cells ) || ! same( without( before.grid, [ 'style' ] ), without( after.grid, [ 'style' ] ) ) ) {
				return false;
			}
			if ( ! same( ( before.grid || {} ).style, ( after.grid || {} ).style ) ) {
				out.rows.push( rowCid );
			}

			return _.every( after.cells, function ( cellCid ) {
				var cellBefore = a.cells[ cellCid ];
				var cellAfter = b.cells[ cellCid ];

				if (
					! same( cellBefore.widgets, cellAfter.widgets ) ||
					! same( without( cellBefore.data, [ 'style' ] ), without( cellAfter.data, [ 'style' ] ) )
				) {
					return false;
				}
				if ( ! same( cellBefore.data.style, cellAfter.data.style ) ) {
					out.cells.push( cellCid );
				}

				return _.every( cellAfter.widgets, function ( widgetCid ) {
					var widgetBefore = copy( a.widgets[ widgetCid ] );
					var widgetAfter = copy( b.widgets[ widgetCid ] );

					// `raw` marks form values the preview processes, and a widget without a stored `widget_id` gets a new
					// one on every read; the page compare sees any difference either makes.
					widgetBefore.panels_info = without( widgetBefore.panels_info, [ 'style', 'raw', 'widget_id' ] );
					widgetAfter.panels_info = without( widgetAfter.panels_info, [ 'style', 'raw', 'widget_id' ] );
					if ( ! same( widgetBefore, widgetAfter ) ) {
						return false;
					}
					if ( ! same( ( a.widgets[ widgetCid ].panels_info || {} ).style, ( b.widgets[ widgetCid ].panels_info || {} ).style ) ) {
						out.widgets.push( widgetCid );
					}

					return true;
				} );
			} );
		} );

		if ( ! structureSame || ( ! out.rows.length && ! out.cells.length && ! out.widgets.length ) ) {
			return null;
		}

		return out;
	},

	/**
	 * The position the server numbers each row, cell and widget with in a snapshot.
	 *
	 * @param {Object} snap
	 * @return {null|{ rows: Object, cells: Object, widgets: Object }} Rows by cid: the row number; cells: `{ row, cell }`;
	 *         widgets: `{ row, cell, widget, index }` (`index` is the flat data-index).
	 */
	positions: function ( snap ) {
		var indexed = index( snap );

		return indexed ? positionsOf( indexed ) : null;
	},

	/**
	 * The widgets and rows added and removed between two snapshots, when that is all that differs: the retained
	 * rows keep their order and the last row is the same row, retained rows and cells have the same settings, each
	 * retained widget stays in its cell in the same order with the same values (its position and `widget_id`
	 * apart), no retained cell becomes empty or stops being empty, and each retained cell that gains a widget keeps a
	 * retained widget to insert next to. Null for anything else, when nothing was added or removed, or when a
	 * snapshot cannot be trusted.
	 *
	 * @param {Object} prev Snapshot the preview shows.
	 * @param {Object} next Snapshot of the builder now.
	 * @param {string[]} [quiet] Widgets whose `raw` and `style` the builder fills in silently (see quietKeys): they may
	 *        differ, and a widget whose data does differ is listed as `refreshed`.
	 * @return {null|Object} Cids of `addedRows`, `removedRows`, `addedWidgets`, `removedWidgets`, `refreshed`; `affected`: the
	 *         removed widgets and the retained widgets whose position id or data-index changes; `before` and `after`: positions().
	 */
	structureChange: function ( prev, next, quiet ) {
		var a = index( prev );
		var b = index( next );

		if ( ! a || ! b || ! a.lastRow || a.lastRow !== b.lastRow ) {
			return null;
		}

		var keptRows = _.filter( a.rowOrder, function ( cid ) {
			return !! b.rows[ cid ];
		} );
		if ( ! same( keptRows, _.filter( b.rowOrder, function ( cid ) {
			return !! a.rows[ cid ];
		} ) ) ) {
			return null;
		}

		var ok = _.every( keptRows, function ( rowCid ) {
			// A row, cell or widget `style` is left to the page compare: a form that loads in the background (a new or
			// duplicated widget, a dialog) fills in default style values and `raw` silently, which changes the data without
			// changing what is rendered, and a real style change is in the layout CSS and markup the compare sees.
			if ( ! same( without( a.rows[ rowCid ].grid, [ 'style' ] ), without( b.rows[ rowCid ].grid, [ 'style' ] ) ) || ! same( a.rows[ rowCid ].cells, b.rows[ rowCid ].cells ) ) {
				return false;
			}

			return _.every( b.rows[ rowCid ].cells, function ( cellCid ) {
				var before = a.cells[ cellCid ];
				var after = b.cells[ cellCid ];
				var kept = function ( list, other ) {
					return _.filter( list, function ( cid ) {
						return !! other[ cid ];
					} );
				};
				var keptBefore = kept( before.widgets, b.widgets );
				var keptAfter = kept( after.widgets, a.widgets );

				// Cell settings, apart from the position, are unchanged; no cell becomes empty or stops being empty.
				if (
					! same( without( before.data, [ 'grid', 'index', 'style' ] ), without( after.data, [ 'grid', 'index', 'style' ] ) ) ||
					( before.widgets.length > 0 ) !== ( after.widgets.length > 0 ) ||
					! same( keptBefore, keptAfter )
				) {
					return false;
				}

				// A cell that gains a widget keeps a retained one to insert next to.
				return after.widgets.length === keptAfter.length || keptAfter.length > 0;
			} );
		} );
		if ( ! ok ) {
			return null;
		}

		// Cells never change row, and retained widgets keep their cell and values.
		var cellsOk = _.every( _.keys( b.cells ), function ( cellCid ) {
			return ! a.cells[ cellCid ] || a.cells[ cellCid ].row === b.cells[ cellCid ].row;
		} );
		var widgetsOk = _.every( _.keys( b.widgets ), function ( widgetCid ) {
			var before = a.widgets[ widgetCid ];
			var cellOf = function ( indexed ) {
				return _.find( _.keys( indexed.cells ), function ( cellCid ) {
					return _.contains( indexed.cells[ cellCid ].widgets, widgetCid );
				} );
			};

			var skip = [ 'grid', 'cell', 'id' ].concat( _.contains( quiet || [], widgetCid ) ? quietKeys : [] );

			return ! before || ( cellOf( a ) === cellOf( b ) && same( withoutWidgetId( before, skip ), withoutWidgetId( b.widgets[ widgetCid ], skip ) ) );
		} );
		if ( ! cellsOk || ! widgetsOk ) {
			return null;
		}

		var out = {
			addedRows: _.difference( b.rowOrder, a.rowOrder ),
			removedRows: _.difference( a.rowOrder, b.rowOrder ),
			addedWidgets: _.difference( _.keys( b.widgets ), _.keys( a.widgets ) ),
			removedWidgets: _.difference( _.keys( a.widgets ), _.keys( b.widgets ) ),
			refreshed: [],
			before: positionsOf( a ),
			after: positionsOf( b ),
		};
		if ( ! out.addedRows.length && ! out.removedRows.length && ! out.addedWidgets.length && ! out.removedWidgets.length ) {
			return null;
		}

		// Layout order, as the data lists them.
		var order = function ( cids, indexed ) {
			var all = _.flatten( _.map( indexed.rowOrder, function ( rowCid ) {
				return _.flatten( _.map( indexed.rows[ rowCid ].cells, function ( cellCid ) {
					return indexed.cells[ cellCid ].widgets;
				} ) );
			} ) );

			return _.filter( all, function ( cid ) {
				return _.contains( cids, cid );
			} );
		};
		out.addedWidgets = order( out.addedWidgets, b );
		out.removedWidgets = order( out.removedWidgets, a );
		out.refreshed = order( _.filter( _.keys( b.widgets ), function ( cid ) {
			return a.widgets[ cid ] && _.contains( quiet || [], cid ) &&
				! same( withoutWidgetId( a.widgets[ cid ], [ 'grid', 'cell', 'id' ] ), withoutWidgetId( b.widgets[ cid ], [ 'grid', 'cell', 'id' ] ) );
		} ), b );
		out.affected = out.removedWidgets.slice();
		_.each( order( _.keys( b.widgets ), b ), function ( cid ) {
			if ( a.widgets[ cid ] && ! same( out.before.widgets[ cid ], out.after.widgets[ cid ] ) ) {
				out.affected.push( cid );
			}
		} );

		return out;
	},

	/**
	 * The patch from the preview of `prev` to the preview of `next`. Null means a full reload.
	 *
	 * @param {Object} prev Snapshot the preview shows.
	 * @param {Object} next Snapshot of the builder now.
	 * @param {string[]} [quiet] Widgets whose `raw` and `style` are not compared (see quietKeys).
	 * @return {null|{ rowOrder: string[], cells: Object, weights: Object, reordered: boolean }} `reordered` is true when a
	 *         row or a widget changed place, false for a change of weights alone.
	 */
	plan: function ( prev, next, quiet ) {
		var a = index( prev );
		var b = index( next );

		if ( ! a || ! b ) {
			return null;
		}

		// (a) The same rows, cells and widgets.
		if ( ! sameKeys( a.rows, b.rows ) || ! sameKeys( a.cells, b.cells ) || ! sameKeys( a.widgets, b.widgets ) ) {
			return null;
		}

		// (g) The last row stays last: only the rows above it carry the row bottom margin.
		if ( a.lastRow !== b.lastRow ) {
			return null;
		}

		// (c) Row settings are unchanged.
		var rowsSame = _.every( _.keys( b.rows ), function ( cid ) {
			return same( a.rows[ cid ].grid, b.rows[ cid ].grid );
		} );
		if ( ! rowsSame ) {
			return null;
		}

		var ops = { rowOrder: b.rowOrder.slice(), cells: {}, weights: {}, reordered: false };
		var changed = ! same( a.rowOrder, b.rowOrder );
		ops.reordered = changed;

		var cellsOk = _.every( _.keys( b.cells ), function ( cid ) {
			var before = a.cells[ cid ];
			var after = b.cells[ cid ];

			// (b) Cells never move: the same row, the same index.
			if ( before.row !== after.row || before.index !== after.index ) {
				return false;
			}

			// (d) Cell settings are unchanged, apart from the position and the weight.
			if ( ! same( without( before.data, [ 'grid', 'weight' ] ), without( after.data, [ 'grid', 'weight' ] ) ) ) {
				return false;
			}

			// (f) No cell becomes empty or stops being empty.
			if ( ( before.widgets.length > 0 ) !== ( after.widgets.length > 0 ) ) {
				return false;
			}

			if ( after.widgets.length ) {
				ops.cells[ cid ] = after.widgets.slice();
				if ( ! same( before.widgets, after.widgets ) ) {
					changed = true;
					ops.reordered = true;
				}
			}

			if ( Number( before.data.weight ) !== Number( after.data.weight ) ) {
				ops.weights[ cid ] = Number( after.data.weight );
				changed = true;
			}

			return true;
		} );
		if ( ! cellsOk ) {
			return null;
		}

		// (e) Widget values and settings are unchanged, apart from the position.
		var widgetsSame = _.every( _.keys( b.widgets ), function ( cid ) {
			var before = copy( a.widgets[ cid ] );
			var after = copy( b.widgets[ cid ] );
			var skip = [ 'grid', 'cell', 'id', 'widget_id' ].concat( _.contains( quiet || [], cid ) ? quietKeys : [] );
			before.panels_info = without( before.panels_info, skip );
			after.panels_info = without( after.panels_info, skip );

			return same( before, after );
		} );
		if ( ! widgetsSame ) {
			return null;
		}

		// (h) Something changed.
		return changed ? ops : null;
	},
};
