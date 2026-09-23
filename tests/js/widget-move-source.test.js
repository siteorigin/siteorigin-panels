'use strict';

/*
 * A widget dragged between cells must not be serialized while it belongs to no
 * cell. The handlers that decide this are jQuery UI sortable callbacks in a view
 * module, and the suite has no jQuery, jQuery UI or DOM, so they cannot be loaded
 * or driven here.
 *
 * These assertions therefore read the source. They are a guard against the
 * removed refresh being typed back in, not a proof: they say nothing about
 * callback ordering, what any emission carries, the hidden field, or a move
 * between two builders. Those are covered by driving a real drag in the editor.
 * Making them automatic would mean a jsdom, jQuery and jQuery UI harness, not a
 * wider search of this file.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync(
	path.join( __dirname, '..', '..', 'js', 'siteorigin-panels', 'view', 'cell.js' ),
	'utf8'
);

/**
 * Return the body of one handler in the widget sortable's options, by brace
 * matching from its opening brace so a nested block cannot end it early.
 *
 * @param string name The handler's key, e.g. 'remove'.
 *
 * @return string The handler body.
 */
function handlerBody( name ) {
	const start = source.indexOf( name + ': function ( e, ui ) {' );
	assert.notEqual( start, -1, name + ' handler not found in view/cell.js' );

	let i = source.indexOf( '{', start );
	let depth = 0;

	for ( let j = i; j < source.length; j++ ) {
		if ( source[ j ] === '{' ) {
			depth++;
		} else if ( source[ j ] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return source.slice( i + 1, j );
			}
		}
	}

	assert.fail( name + ' handler body is unbalanced' );
}

test( 'the remove handler does not refresh the builder data', function () {
	assert.ok(
		! /refreshPanelsData/.test( handlerBody( 'remove' ) ),
		'remove must not serialize the layout: the dragged widget is in no cell at that point'
	);
} );

test( 'the stop handler refreshes when the widget has left this builder', function () {
	const body = handlerBody( 'stop' );
	const elseBranch = body.slice( body.indexOf( '} else {' ) );

	assert.ok(
		body.includes( '} else {' ),
		'stop needs an else branch, or a widget moved to another builder is never serialized'
	);
	assert.ok(
		/refreshPanelsData/.test( elseBranch ),
		'the else branch must refresh, so the origin builder records the departure'
	);
} );
