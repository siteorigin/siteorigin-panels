'use strict';

/**
 * The History dialog's Original entry must hold the layout that was loaded
 * into the builder. The dialog is created in attach(), before the stored
 * layout loads, so setData() has to record the entry again (#1397).
 */

const path = require( 'path' );
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const panels = require( './bootstrap' );

// The view modules read templates through jQuery when they load. Neither test
// renders anything, so empty templates are enough.
global.jQuery = () => ( { html: () => '' } );
panels.helpers.utils.processTemplate = ( s ) => s || '';
panels.view = { dialog: Backbone.View };

const base = path.join( __dirname, '..', '..', 'js', 'siteorigin-panels' );

panels.model.historyEntry = require( path.join( base, 'model', 'history-entry' ) );

const historyProto = require( path.join( base, 'dialog', 'history' ) ).prototype;
const builderProto = require( path.join( base, 'view', 'builder' ) ).prototype;

function widget( id, cell, text ) {
	return {
		text: text,
		panels_info: {
			class: 'SiteOrigin_Widget_Editor_Widget',
			raw: false,
			grid: 0,
			cell: cell,
			id: id,
			widget_id: 'history-w' + id,
			style: {}
		}
	};
}

function storedLayout() {
	return {
		widgets: [
			widget( 0, 0, 'Keep A' ),
			widget( 1, 1, 'Keep B' )
		],
		grids: [ { cells: 2, style: {} } ],
		grid_cells: [
			{ grid: 0, index: 0, weight: 0.5, style: {} },
			{ grid: 0, index: 1, weight: 0.5, style: {} }
		]
	};
}

function fakeView( dialogs ) {
	return {
		model: new panels.model.builder(),
		dialogs: dialogs,
		toggleWelcomeDisplay() {}
	};
}

test( 'setData() records the loaded layout as the Original history entry', () => {
	const history = { setRevertEntry: historyProto.setRevertEntry };
	const view = fakeView( { history: history } );

	// What attach() does, before the stored layout loads.
	history.setRevertEntry( view.model );

	builderProto.setData.call( view, storedLayout() );

	const original = JSON.parse( history.revertEntry.get( 'data' ) );
	// The entry is stored as JSON, which drops the model's undefined keys.
	assert.deepEqual( original, JSON.parse( JSON.stringify( view.model.getPanelsData() ) ) );
	assert.equal( original.widgets.length, 2 );
	assert.deepEqual( original.widgets.map( ( w ) => w.text ), [ 'Keep A', 'Keep B' ] );
} );

test( 'setData() loads the layout when the builder has no History dialog', () => {
	const view = fakeView( {} );

	assert.doesNotThrow( () => builderProto.setData.call( view, storedLayout() ) );
	assert.equal( view.model.getPanelsData().widgets.length, 2 );
} );
