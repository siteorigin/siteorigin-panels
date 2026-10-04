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
	 * The patch from the preview of `prev` to the preview of `next`. Null means a full reload.
	 *
	 * @param {Object} prev Snapshot the preview shows.
	 * @param {Object} next Snapshot of the builder now.
	 * @return {null|{ rowOrder: string[], cells: Object, weights: Object }}
	 */
	plan: function ( prev, next ) {
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

		var ops = { rowOrder: b.rowOrder.slice(), cells: {}, weights: {} };
		var changed = ! same( a.rowOrder, b.rowOrder );

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
			before.panels_info = without( before.panels_info, [ 'grid', 'cell', 'id' ] );
			after.panels_info = without( after.panels_info, [ 'grid', 'cell', 'id' ] );

			return same( before, after );
		} );
		if ( ! widgetsSame ) {
			return null;
		}

		// (h) Something changed.
		return changed ? ops : null;
	},
};
