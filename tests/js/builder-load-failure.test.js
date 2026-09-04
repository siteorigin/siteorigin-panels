'use strict';

/**
 * A layout that throws part-way through loadPanelsData() must never be
 * serialized from the rows that did load: the stored layout is what every
 * serializer hands out until a later load completes.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const panels = require( './bootstrap' );

const BOOM = 'SiteOrigin_Test_Boom_Widget';

// Widgets of the BOOM class throw while the builder constructs them, which is
// the shape of the null-style failure: an exception escaping the load loop
// after some rows and widgets already exist.
let boomArmed = true;
const originalInitialize = panels.model.widget.prototype.initialize;
panels.model.widget.prototype.initialize = function () {
	if ( boomArmed && this.get( 'class' ) === BOOM ) {
		throw new TypeError( "Cannot read properties of null (reading 'disable_widget')" );
	}

	return originalInitialize.apply( this, arguments );
};

function widget( id, grid, cell, klass ) {
	return {
		title: '',
		text: 'Widget ' + id + ' text',
		panels_info: {
			class: klass || 'SiteOrigin_Widget_Editor_Widget',
			raw: false,
			grid: grid,
			cell: cell,
			id: id,
			widget_id: 'w' + id,
			style: {}
		}
	};
}

function layout( options ) {
	options = Object.assign( { boomAt: -1, widgets: true }, options );

	const data = {
		grids: [
			{ cells: 2, style: {} },
			{ cells: 1, style: {} }
		],
		grid_cells: [
			{ grid: 0, index: 0, weight: 0.5, style: {} },
			{ grid: 0, index: 1, weight: 0.5, style: {} },
			{ grid: 1, index: 0, weight: 1, style: {} }
		]
	};

	if ( options.widgets ) {
		data.widgets = [
			widget( 0, 0, 0, options.boomAt === 0 ? BOOM : null ),
			widget( 1, 0, 1, options.boomAt === 1 ? BOOM : null ),
			widget( 2, 1, 0, options.boomAt === 2 ? BOOM : null )
		];
	}

	return data;
}

function clone( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

function builder() {
	return new panels.model.builder();
}

function countEvents( model ) {
	const counts = { change: 0, 'change:data': 0, refresh_panels_data: 0, load_panels_data: 0, load_panels_data_failed: 0 };
	Object.keys( counts ).forEach( function ( name ) {
		model.on( name, function () {
			counts[ name ] += 1;
		} );
	} );
	return counts;
}

test( 'a throw on widget N keeps the stored layout and never serializes the partial rows', function () {
	const model = builder();
	const events = countEvents( model );
	const input = layout( { boomAt: 2 } );
	const reference = clone( input );

	model.loadPanelsData( input );

	assert.equal( model.loadFailed, true );
	assert.equal( events.load_panels_data_failed, 1 );
	assert.equal( events.load_panels_data, 0, 'no success event, so the view never stores the model' );
	assert.deepEqual( model.get( 'data' ), reference, 'the data attribute is the layout as it was before the call' );
	assert.deepEqual( model.getPanelsData(), reference, 'the serializer hands out the stored layout' );

	// Sanity: the live rows really are incomplete.
	let liveWidgets = 0;
	model.get( 'rows' ).each( function ( row ) {
		row.get( 'cells' ).each( function ( cell ) {
			liveWidgets += cell.get( 'widgets' ).length;
		} );
	} );
	assert.equal( liveWidgets, 2, 'two widgets loaded before the third threw' );

	model.refreshPanelsData();

	assert.equal( events.change, 0 );
	assert.equal( events[ 'change:data' ], 0 );
	assert.equal( events.refresh_panels_data, 0 );
	assert.deepEqual( model.get( 'data' ), reference, 'refresh left the stored layout alone' );
} );

test( 'a throw on the first row leaves zero live widgets and still preserves the layout', function () {
	const model = builder();
	const input = layout( { boomAt: 0 } );
	const reference = clone( input );

	model.loadPanelsData( input );

	assert.equal( model.loadFailed, true );
	assert.deepEqual( model.getPanelsData(), reference );
} );

test( 'a later successful load clears the flag and serializes the live rows again', function () {
	const model = builder();
	const events = countEvents( model );

	model.loadPanelsData( layout( { boomAt: 1 } ) );
	assert.equal( model.loadFailed, true );

	const good = layout();
	model.loadPanelsData( good );

	assert.equal( model.loadFailed, false );
	assert.equal( model.trustedData, true );
	assert.equal( events.load_panels_data, 1 );
	assert.equal( model.getPanelsData().widgets.length, 3 );
	assert.equal( model.getPanelsData().grids.length, 2 );

	// Serialization is live again: a mutation shows up.
	model.get( 'rows' ).at( 1 ).destroy();
	assert.equal( model.getPanelsData().grids.length, 1 );
} );

test( 'a layout without a widgets key is a successful load that stores widgets as an empty list', function () {
	const model = builder();
	const events = countEvents( model );

	model.loadPanelsData( layout( { widgets: false } ) );

	assert.equal( model.loadFailed, false );
	assert.equal( events.load_panels_data, 1 );
	assert.equal( events.load_panels_data_failed, 0 );
	assert.deepEqual( model.get( 'data' ).widgets, [] );
	assert.equal( model.getPanelsData().grids.length, 2 );
} );

test( 'a failed load with no widgets key keeps the layout exactly as given', function () {
	const model = builder();
	const input = layout( { widgets: false } );
	input.grids[ 0 ] = null; // addRow reads data.grids[i].style and throws.
	const reference = clone( input );

	model.loadPanelsData( input );

	assert.equal( model.loadFailed, true );
	assert.deepEqual( model.get( 'data' ), reference, 'no widgets key was added to a layout that failed' );
} );

test( 'concatenating after a failed load builds on the stored layout, not the partial rows', function () {
	const model = builder();

	model.loadPanelsData( layout( { boomAt: 2 } ) );
	assert.equal( model.loadFailed, true );

	// The stored layout still holds the widget that threw. Once that widget
	// loads cleanly, the concatenation must start from all three stored
	// widgets, not from the two that survived the first attempt.
	boomArmed = false;
	try {
		model.loadPanelsData( layout(), model.layoutPosition.AFTER );
	} finally {
		boomArmed = true;
	}

	assert.equal( model.loadFailed, false );
	const result = model.getPanelsData();
	assert.equal( result.grids.length, 4, 'two stored rows plus two appended rows' );
	assert.equal( result.widgets.length, 6, 'three stored widgets plus three appended widgets' );
} );

test( 'concatenating after a failed load fails again while the stored layout still throws', function () {
	const model = builder();
	const failing = layout( { boomAt: 2 } );
	const reference = clone( failing );

	model.loadPanelsData( failing );
	model.loadPanelsData( layout(), model.layoutPosition.AFTER );

	assert.equal( model.loadFailed, true );
	assert.equal( model.get( 'data' ).grids.length, 4, 'the attempted concatenation is what the model now holds' );
	assert.deepEqual( model.get( 'data' ).widgets.slice( 0, 3 ), reference.widgets, 'and it still carries every stored widget' );
} );

test( 'an unparseable source hands out nothing and leaves the default layout untouched', function () {
	const model = builder();
	const events = countEvents( model );
	const defaults = clone( model.get( 'data' ) );

	model.markSourceUnparseable( new SyntaxError( 'Unexpected token' ) );

	assert.equal( model.loadFailed, true );
	assert.equal( model.trustedData, false );
	assert.equal( events.load_panels_data_failed, 1 );
	assert.equal( model.getPanelsData(), null );

	model.refreshPanelsData();

	assert.equal( events.change, 0 );
	assert.equal( events[ 'change:data' ], 0 );
	assert.deepEqual( model.get( 'data' ), defaults, 'the default empty layout is still there for the view to read' );
} );

test( 'loading false (Revert to Editor) is a successful load', function () {
	const model = builder();
	const events = countEvents( model );

	model.loadPanelsData( layout() );
	model.loadPanelsData( false );

	assert.equal( model.loadFailed, false );
	assert.equal( events.load_panels_data, 2 );
	assert.equal( events.load_panels_data_failed, 0 );
	assert.equal( model.get( 'rows' ).length, 0 );
} );

test( 'concatenating onto an unparseable source loads the new layout alone', function () {
	const model = builder();

	model.markSourceUnparseable( new SyntaxError( 'Unexpected token' ) );
	model.loadPanelsData( layout(), model.layoutPosition.AFTER );

	assert.equal( model.loadFailed, false );
	assert.equal( model.trustedData, true );
	assert.equal( model.getPanelsData().grids.length, 2 );
	assert.equal( model.getPanelsData().widgets.length, 3 );
} );
