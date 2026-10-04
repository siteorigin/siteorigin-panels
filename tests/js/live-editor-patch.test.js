'use strict';

/*
 * panels.helpers.liveEditorPatch plans in-place Live Editor patches. plan() must return a patch only for a
 * pure reorder of rows (the last row stays last), a pure move of widgets between non-empty cells, or a
 * weight change, and null (a full reload) for anything else.
 *
 * The changes are made on real Backbone models, the way the builder views make them.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'path' );

const panels = require( './bootstrap' );

const patch = require( path.join( __dirname, '..', '..', 'js', 'siteorigin-panels', 'helpers', 'live-editor-patch' ) );

const widget = ( text, grid, cell, id ) => ( {
	text,
	panels_info: { class: 'Panels_E2E_Text_Widget', raw: false, grid, cell, id, widget_id: 'w-' + text, style: {} },
} );

// row0 [A] [B, C]; row1 [D]; row2 [E].
const layout = () => ( {
	widgets: [
		widget( 'A', 0, 0, 0 ),
		widget( 'B', 0, 1, 1 ),
		widget( 'C', 0, 1, 2 ),
		widget( 'D', 1, 0, 3 ),
		widget( 'E', 2, 0, 4 ),
	],
	grids: [ { cells: 2, style: {} }, { cells: 1, style: {} }, { cells: 1, style: {} } ],
	grid_cells: [
		{ grid: 0, index: 0, weight: 0.5, style: {} },
		{ grid: 0, index: 1, weight: 0.5, style: {} },
		{ grid: 1, index: 0, weight: 1, style: {} },
		{ grid: 2, index: 0, weight: 1, style: {} },
	],
} );

const setup = () => {
	const model = new panels.model.builder();
	model.loadPanelsData( layout() );
	assert.ok( ! model.loadFailed );

	const rows = model.get( 'rows' );
	const row = ( i ) => rows.at( i );
	const cell = ( r, c ) => row( r ).get( 'cells' ).at( c );
	const byText = ( text ) => {
		let found = null;
		rows.each( ( r ) => r.get( 'cells' ).each( ( c ) => c.get( 'widgets' ).each( ( w ) => {
			if ( w.get( 'values' ).text === text ) {
				found = w;
			}
		} ) ) );
		return found;
	};

	return { model, rows, row, cell, byText, prev: patch.snapshot( model ) };
};

// The widget sortable stop handler: move the model to the target cell at an index.
const moveWidget = ( w, targetCell, at ) => w.moveToCell( targetCell, {}, at );

// The row sortable stop handler: remove the row and add it back at an index.
const moveRow = ( rows, r, at ) => {
	rows.remove( r, { silent: true } );
	rows.add( r, { silent: true, at } );
};

const cidsOf = ( collection ) => collection.map( ( m ) => m.cid );

test( 'a widget move inside a cell is a patch', () => {
	const s = setup();
	moveWidget( s.byText( 'C' ), s.cell( 0, 1 ), 0 );
	const ops = patch.plan( s.prev, patch.snapshot( s.model ) );

	assert.ok( ops );
	assert.deepEqual( ops.rowOrder, cidsOf( s.rows ) );
	assert.deepEqual( ops.cells[ s.cell( 0, 1 ).cid ], [ s.byText( 'C' ).cid, s.byText( 'B' ).cid ] );
	assert.deepEqual( ops.cells[ s.cell( 0, 0 ).cid ], [ s.byText( 'A' ).cid ] );
	assert.equal( Object.keys( ops.cells ).length, 4, 'every non-empty cell is listed' );
	assert.deepEqual( ops.weights, {} );
} );

test( 'a widget move between cells is a patch', () => {
	const s = setup();
	moveWidget( s.byText( 'B' ), s.cell( 0, 0 ), 1 );
	const ops = patch.plan( s.prev, patch.snapshot( s.model ) );

	assert.ok( ops );
	assert.deepEqual( ops.cells[ s.cell( 0, 0 ).cid ], [ s.byText( 'A' ).cid, s.byText( 'B' ).cid ] );
	assert.deepEqual( ops.cells[ s.cell( 0, 1 ).cid ], [ s.byText( 'C' ).cid ] );
} );

test( 'a row swap that keeps the last row is a patch', () => {
	const s = setup();
	const [ r0, r1, r2 ] = [ s.row( 0 ), s.row( 1 ), s.row( 2 ) ];
	moveRow( s.rows, r1, 0 );
	const ops = patch.plan( s.prev, patch.snapshot( s.model ) );

	assert.ok( ops );
	assert.deepEqual( ops.rowOrder, [ r1.cid, r0.cid, r2.cid ] );
	assert.deepEqual( ops.weights, {} );
} );

test( 'a weight change is a patch with only the changed cells', () => {
	const s = setup();
	s.cell( 0, 0 ).set( 'weight', 0.3 );
	s.cell( 0, 1 ).set( 'weight', 0.7 );
	const ops = patch.plan( s.prev, patch.snapshot( s.model ) );

	assert.ok( ops );
	assert.deepEqual( ops.weights, { [ s.cell( 0, 0 ).cid ]: 0.3, [ s.cell( 0, 1 ).cid ]: 0.7 } );
	assert.deepEqual( ops.rowOrder, cidsOf( s.rows ) );
} );

test( 'a patch chains: the next plan starts from the patched snapshot', () => {
	const s = setup();
	moveWidget( s.byText( 'C' ), s.cell( 0, 1 ), 0 );
	const mid = patch.snapshot( s.model );
	assert.ok( patch.plan( s.prev, mid ) );

	moveWidget( s.byText( 'C' ), s.cell( 0, 1 ), 1 );
	const ops = patch.plan( mid, patch.snapshot( s.model ) );
	assert.ok( ops );
	assert.deepEqual( ops.cells[ s.cell( 0, 1 ).cid ], [ s.byText( 'B' ).cid, s.byText( 'C' ).cid ] );
} );

test( '(a) an added or removed widget means a reload', () => {
	const s = setup();
	const extra = new panels.model.widget( { class: 'Panels_E2E_Text_Widget', values: { text: 'F' } } );
	s.cell( 1, 0 ).get( 'widgets' ).add( extra );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );

	const t = setup();
	t.cell( 0, 1 ).get( 'widgets' ).remove( t.byText( 'B' ) );
	assert.equal( patch.plan( t.prev, patch.snapshot( t.model ) ), null );
} );

test( '(b) a cell that changes its index means a reload', () => {
	const s = setup();
	const cells = s.row( 0 ).get( 'cells' );
	const first = cells.at( 0 );
	cells.remove( first, { silent: true } );
	cells.add( first, { silent: true, at: 1 } );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );
} );

test( '(c) a row style change means a reload', () => {
	const s = setup();
	s.row( 0 ).set( 'style', { bottom_margin: '99px' } );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );
} );

test( '(d) a cell style change means a reload', () => {
	const s = setup();
	s.cell( 0, 1 ).set( 'style', { padding: '10px' } );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );
} );

test( '(e) a widget value or style change means a reload, also with a move', () => {
	const s = setup();
	s.byText( 'B' ).set( 'values', { text: 'B changed' } );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );

	const t = setup();
	t.byText( 'B' ).set( 'style', { class: 'extra' } );
	assert.equal( patch.plan( t.prev, patch.snapshot( t.model ) ), null );

	const u = setup();
	moveWidget( u.byText( 'C' ), u.cell( 0, 1 ), 0 );
	u.byText( 'C' ).set( 'values', { text: 'C changed' } );
	assert.equal( patch.plan( u.prev, patch.snapshot( u.model ) ), null );
} );

test( '(f) a cell that becomes empty, or stops being empty, means a reload', () => {
	const s = setup();
	moveWidget( s.byText( 'A' ), s.cell( 0, 1 ), 0 );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );
} );

test( '(g) moving the last row means a reload', () => {
	const s = setup();
	moveRow( s.rows, s.row( 2 ), 0 );
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );

	const t = setup();
	moveRow( t.rows, t.row( 0 ), 2 );
	assert.equal( patch.plan( t.prev, patch.snapshot( t.model ) ), null );
} );

test( '(h) no change means no patch', () => {
	const s = setup();
	assert.equal( patch.plan( s.prev, patch.snapshot( s.model ) ), null );
} );

test( 'a snapshot whose positions do not match its data means a reload', () => {
	const s = setup();
	moveWidget( s.byText( 'C' ), s.cell( 0, 1 ), 0 );
	const next = patch.snapshot( s.model );
	next.data.widgets.pop();
	assert.equal( patch.plan( s.prev, next ), null );

	assert.equal( patch.plan( null, patch.snapshot( s.model ) ), null );
	assert.equal( patch.plan( s.prev, { data: {}, rows: [] } ), null );
} );

test( 'a snapshot is a copy: later model changes do not change it', () => {
	const s = setup();
	const before = JSON.stringify( s.prev );
	s.row( 0 ).get( 'style' ).bottom_margin = '1px';
	s.byText( 'A' ).get( 'values' ).text = 'mutated';
	assert.equal( JSON.stringify( s.prev ), before );
} );

test( 'cellWidth rebuilds the server width for a new weight', () => {
	// Chrome reports the server's calc(50% - ( 0.5 * 30px ) ) as calc(50% - 15px).
	assert.equal( patch.cellWidth( 'calc(50% - 15px)', 0.5, 0.3 ), 'calc(30% - ( 0.70000000000000 * 30px ) )' );
	assert.equal( patch.cellWidth( 'calc(33.3333% - 20px)', 1 / 3, 0.5 ), 'calc(50% - ( 0.50000000000000 * 30px ) )' );
	assert.equal( patch.cellWidth( 'calc(50% - 1em)', 0.5, 0.25 ), 'calc(25% - ( 0.75000000000000 * 2em ) )' );
	// A zero gutter is a bare percent.
	assert.equal( patch.cellWidth( '50%', 0.5, 0.123456789 ), '12.3457%' );
} );

test( 'cellWidth refuses a value it cannot read or a weight a filter changed', () => {
	assert.equal( patch.cellWidth( 'calc(40% - 15px)', 0.5, 0.3 ), null, 'percent does not match the rendered weight' );
	assert.equal( patch.cellWidth( '40%', 0.5, 0.3 ), null );
	assert.equal( patch.cellWidth( 'calc(100% + 0px)', 1, 0.5 ), null, 'a full-width cell has no gutter term to read' );
	assert.equal( patch.cellWidth( 'min(50%, 300px)', 0.5, 0.3 ), null );
	assert.equal( patch.cellWidth( '', 0.5, 0.3 ), null );
	assert.equal( patch.cellWidth( 'calc(50% - 15px)', NaN, 0.3 ), null );
} );
