/**
 * DOM helpers for adding and removing widgets and rows in the Live Editor preview without a reload.
 *
 * The server numbers every row, cell and widget by position (`pg-{post}-{row}`, `pgc-{post}-{row}-{cell}`,
 * `panel-{post}-{row}-{cell}-{widget}`, the flat `data-index`) and writes position classes (first and last
 * child, empty cell, style wrapper classes). A change that adds or removes entities renumbers every entity
 * after it, so syncStructure() finds the entities by their old position ids first, changes the tree through
 * the elements it holds, and then gives each retained entity the attributes the new render has.
 *
 * `change` is the result of liveEditorPatch.structureChange(): the cids of the added and removed rows and
 * widgets and, for the snapshot the preview shows (`before`) and the new one (`after`), where the server
 * numbers each row, cell and widget.
 */

// The attributes the server derives from position.
var POSITION_ATTRIBUTES = [ 'id', 'class', 'data-index' ];

// Classes the Live Editor adds to a preview widget on hover.
var EDITOR_CLASSES = [ 'so-panels-faded', 'so-panels-highlighted' ];

// The style wrappers between a row, cell or widget element and what it holds.
var WRAPPER_CLASSES = [ 'panel-row-style', 'panel-cell-style', 'panel-widget-style' ];

// A cell or widget element id.
var ENTITY_ID = /^(pgc|panel)-/;

var postIdOf = function ( wrapper ) {
	return String( wrapper.id ).replace( /^pl-/, '' );
};

var rowId = function ( post, position ) {
	return 'pg-' + post + '-' + position;
};

var cellId = function ( post, position ) {
	return 'pgc-' + post + '-' + position.row + '-' + position.cell;
};

var widgetId = function ( post, position ) {
	return 'panel-' + post + '-' + position.row + '-' + position.cell + '-' + position.widget;
};

/**
 * The elements under a layout wrapper by id, so a repeated id is seen.
 */
var idsIn = function ( wrapper ) {
	var found = {};

	Array.prototype.forEach.call( wrapper.querySelectorAll( '[id]' ), function ( el ) {
		( found[ el.id ] = found[ el.id ] || [] ).push( el );
	} );

	return found;
};

var one = function ( found, id ) {
	if ( ! found[ id ] || found[ id ].length !== 1 ) {
		throw new Error( 'The element ' + id + ' is missing or not unique.' );
	}

	return found[ id ][ 0 ];
};

/**
 * Every row, cell and widget element of a snapshot's positions, by cid, found by id in a layout wrapper.
 */
var resolve = function ( wrapper, positions ) {
	var post = postIdOf( wrapper ),
		found = idsIn( wrapper ),
		out = { rows: {}, cells: {}, widgets: {} };

	Object.keys( positions.rows ).forEach( function ( cid ) {
		out.rows[ cid ] = one( found, rowId( post, positions.rows[ cid ] ) );
	} );
	Object.keys( positions.cells ).forEach( function ( cid ) {
		out.cells[ cid ] = one( found, cellId( post, positions.cells[ cid ] ) );
	} );
	Object.keys( positions.widgets ).forEach( function ( cid ) {
		out.widgets[ cid ] = one( found, widgetId( post, positions.widgets[ cid ] ) );
	} );

	return out;
};

var wrapperChild = function ( el ) {
	return Array.prototype.find.call( el.children, function ( child ) {
		return WRAPPER_CLASSES.some( function ( name ) {
			return child.classList.contains( name );
		} );
	} ) || null;
};

/**
 * Give an element, and the style wrappers inside it, the position attributes of the same element in the new
 * render. The editor's hover classes stay.
 */
var syncAttributes = function ( live, served ) {
	var keep = EDITOR_CLASSES.filter( function ( name ) {
		return live.classList.contains( name );
	} );

	POSITION_ATTRIBUTES.forEach( function ( name ) {
		if ( served.hasAttribute( name ) ) {
			live.setAttribute( name, served.getAttribute( name ) );
		} else {
			live.removeAttribute( name );
		}
	} );
	keep.forEach( function ( name ) {
		live.classList.add( name );
	} );

	var liveWrapper = wrapperChild( live ),
		servedWrapper = wrapperChild( served );
	if ( !! liveWrapper !== !! servedWrapper ) {
		throw new Error( 'The style wrappers differ at ' + live.id + '.' );
	}
	if ( liveWrapper ) {
		syncAttributes( liveWrapper, servedWrapper );
	}
};

/**
 * The server-rendered `style` attribute values of an element and what it holds, in document order. Cells and
 * widgets inside a row or cell are other entities: they are left out unless `whole` is true.
 */
var inlineStyles = function ( el, whole ) {
	var out = [];

	var walk = function ( node ) {
		if ( node.hasAttribute( 'style' ) ) {
			out.push( node.getAttribute( 'style' ) );
		}
		Array.prototype.forEach.call( node.children, function ( child ) {
			if ( whole || ! ENTITY_ID.test( child.id || '' ) ) {
				walk( child );
			}
		} );
	};
	walk( el );

	return out;
};

var retained = function ( before, after ) {
	return Object.keys( before ).filter( function ( cid ) {
		return Object.prototype.hasOwnProperty.call( after, cid );
	} );
};

module.exports = {

	/**
	 * The layout wrapper as markup, without what the Live Editor and scripts add: the highlight classes and
	 * inline style attributes (the cursor, stretched rows, widget scripts that size on load and resize).
	 *
	 * @param {Element} wrapper
	 * @return {string}
	 */
	normalisedWrapper: function ( wrapper ) {
		var copy = wrapper.cloneNode( true );

		[ copy ].concat( Array.prototype.slice.call( copy.querySelectorAll( '*' ) ) ).forEach( function ( el ) {
			EDITOR_CLASSES.forEach( function ( name ) {
				el.classList.remove( name );
			} );
			if ( el.hasAttribute( 'class' ) && ! el.getAttribute( 'class' ) ) {
				el.removeAttribute( 'class' );
			}

			// Inline sizes are written by scripts (stretch, sliders, editor cursor); they re-measure on resize.
			el.removeAttribute( 'style' );
		} );

		return copy.outerHTML;
	},

	/**
	 * The row and widget elements the change adds, as they are in the new render. Throws when one is missing.
	 *
	 * @param {Element} afterWrapper The layout wrapper of the new render.
	 * @param {Object} change
	 * @return {{ rows: Element[], widgets: Element[] }} `widgets` holds the added widgets of retained rows and the
	 *         refreshed widgets.
	 */
	addedElements: function ( afterWrapper, change ) {
		var served = resolve( afterWrapper, change.after ),
			addedRows = change.addedRows;

		return {
			rows: addedRows.map( function ( cid ) {
				return served.rows[ cid ];
			} ),
			widgets: change.addedWidgets.filter( function ( cid ) {
				return addedRows.indexOf( rowCidOf( change, cid ) ) === -1;
			} ).concat( change.refreshed ).map( function ( cid ) {
				return served.widgets[ cid ];
			} ),
		};
	},

	/**
	 * True when the server rendered another inline `style` attribute for a retained row, cell or widget in the
	 * two responses. A widget compares its whole subtree; a row or cell compares itself and its style
	 * wrappers. Renderer and widget filters can put position-dependent inline styles anywhere, and the
	 * normalised wrapper ignores inline styles because scripts write them in the live preview, so this
	 * reads the two server responses.
	 *
	 * @param {Element} beforeWrapper Layout wrapper of the response for the data the preview shows.
	 * @param {Element} afterWrapper Layout wrapper of the response for the new data.
	 * @param {Object} change
	 * @return {boolean}
	 */
	serverStylesDiffer: function ( beforeWrapper, afterWrapper, change ) {
		var before = resolve( beforeWrapper, change.before ),
			after = resolve( afterWrapper, change.after );

		var differ = function ( group, whole ) {
			return retained( before[ group ], after[ group ] ).some( function ( cid ) {
				if ( group === 'widgets' && change.refreshed.indexOf( cid ) !== -1 ) {
					return false;
				}

				return JSON.stringify( inlineStyles( before[ group ][ cid ], whole ) ) !== JSON.stringify( inlineStyles( after[ group ][ cid ], whole ) );
			} );
		};

		return differ( 'rows', false ) || differ( 'cells', false ) || differ( 'widgets', true );
	},

	/**
	 * Add and remove the rows and widgets of `change` in a layout wrapper, then give every retained row, cell
	 * and widget the position attributes of the new render. Run it on a clone of the live wrapper first; it
	 * throws when an element is missing or the markup is not what it expects, and the caller reloads.
	 *
	 * Phase 1 finds every element by its old position id, inside the wrapper it is given. Phase 2 changes the
	 * tree through those elements only: a middle insert gives the new element the old id of the next one, so an
	 * id lookup after the first change would find the wrong element.
	 *
	 * @param {Element} wrapper The layout wrapper to change (the live one, or a clone of it).
	 * @param {Element} afterWrapper The layout wrapper of the render of the new data.
	 * @param {Object} change liveEditorPatch.structureChange()
	 */
	syncStructure: function ( wrapper, afterWrapper, change ) {
		var live = resolve( wrapper, change.before ),
			served = resolve( afterWrapper, change.after ),
			doc = wrapper.ownerDocument,
			rowAt = function ( cid ) {
				return change.after.rows[ cid ];
			},
			afterRows = Object.keys( change.after.rows ).sort( function ( a, b ) {
				return rowAt( a ) - rowAt( b );
			} );

		// A widget whose data the builder filled in after it was added is rendered again by the server: replace its
		// element with the new render.
		change.refreshed.forEach( function ( cid ) {
			var node = doc.importNode( served.widgets[ cid ], true );

			live.widgets[ cid ].parentNode.replaceChild( node, live.widgets[ cid ] );
			live.widgets[ cid ] = node;
		} );

		// Removed widgets and rows.
		change.removedWidgets.forEach( function ( cid ) {
			live.widgets[ cid ].parentNode.removeChild( live.widgets[ cid ] );
		} );
		change.removedRows.forEach( function ( cid ) {
			live.rows[ cid ].parentNode.removeChild( live.rows[ cid ] );
		} );

		// Added rows, before the next retained row. The last row is retained, so there is one.
		change.addedRows.slice().sort( function ( a, b ) {
			return rowAt( a ) - rowAt( b );
		} ).forEach( function ( cid ) {
			var next = afterRows.slice( afterRows.indexOf( cid ) + 1 ).filter( function ( other ) {
				return Object.prototype.hasOwnProperty.call( live.rows, other );
			} )[ 0 ];
			if ( ! next ) {
				throw new Error( 'There is no row to insert before.' );
			}

			live.rows[ next ].parentNode.insertBefore( doc.importNode( served.rows[ cid ], true ), live.rows[ next ] );
		} );

		// Added widgets of retained rows, next to a retained widget of the same cell.
		var present = {};
		Object.keys( live.widgets ).forEach( function ( cid ) {
			if ( Object.prototype.hasOwnProperty.call( change.after.widgets, cid ) ) {
				present[ cid ] = live.widgets[ cid ];
			}
		} );

		var inCell = function ( position ) {
			return Object.keys( change.after.widgets ).filter( function ( cid ) {
				var other = change.after.widgets[ cid ];

				return other.row === position.row && other.cell === position.cell;
			} ).sort( function ( a, b ) {
				return change.after.widgets[ a ].widget - change.after.widgets[ b ].widget;
			} );
		};

		change.addedWidgets.forEach( function ( cid ) {
			var position = change.after.widgets[ cid ];
			if ( change.addedRows.indexOf( rowCidOf( change, cid ) ) !== -1 ) {
				return;
			}

			var list = inCell( position ),
				at = list.indexOf( cid ),
				node = doc.importNode( served.widgets[ cid ], true ),
				earlier = list.slice( 0, at ).filter( function ( other ) {
					return !! present[ other ];
				} ).pop(),
				later = list.slice( at + 1 ).filter( function ( other ) {
					return !! present[ other ];
				} )[ 0 ];

			if ( earlier ) {
				present[ earlier ].parentNode.insertBefore( node, present[ earlier ].nextSibling );
			} else if ( later ) {
				present[ later ].parentNode.insertBefore( node, present[ later ] );
			} else {
				throw new Error( 'There is no widget to insert next to.' );
			}
			present[ cid ] = node;
		} );

		// The retained rows, cells and widgets take the position attributes of the new render.
		[ 'rows', 'cells', 'widgets' ].forEach( function ( group ) {
			retained( live[ group ], served[ group ] ).forEach( function ( cid ) {
				if ( group === 'widgets' && change.refreshed.indexOf( cid ) !== -1 ) {
					return;
				}
				syncAttributes( live[ group ][ cid ], served[ group ][ cid ] );
			} );
		} );
	},
};

/**
 * The cid of the row an added widget is in, in the new snapshot.
 */
function rowCidOf( change, widgetCid ) {
	var row = change.after.widgets[ widgetCid ].row;

	return Object.keys( change.after.rows ).filter( function ( cid ) {
		return change.after.rows[ cid ] === row;
	} )[ 0 ];
}
