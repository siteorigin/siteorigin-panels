'use strict';

/*
 * A widget dragged between cells must not be serialized while it belongs to no
 * cell, so the widget sortable's remove handler does not refresh and stop does.
 *
 * These assertions read the source, because the handlers are jQuery UI callbacks
 * in a view module and this suite has no jQuery, jQuery UI or DOM to run them
 * in. They catch the call being put back, and prove nothing about ordering,
 * emissions or the stored layout; a real drag covers those.
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
 * The source with comments removed, so a call named in a comment can never
 * satisfy an assertion about code.
 *
 * @param string text The source to strip.
 *
 * @return string The source without block or line comments.
 */
function withoutComments( text ) {
	return text.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /\/\/.*$/gm, '' );
}

/**
 * The text of one widget sortable handler, taken between its own opening line
 * and the next handler's. Slicing between two known anchors rather than matching
 * braces keeps this out of the business of parsing JavaScript.
 *
 * @param string name The handler's key, e.g. 'remove'.
 * @param string next The following handler's key, e.g. 'receive'.
 *
 * @return string The handler text, comments removed.
 */
function handlerText( name, next ) {
	const from = source.indexOf( name + ': function ( e, ui ) {' );
	assert.notEqual( from, -1, name + ' handler not found in view/cell.js' );

	const to = source.indexOf( next + ': function (', from );
	assert.notEqual( to, -1, next + ' handler not found after ' + name );

	return withoutComments( source.slice( from, to ) );
}

test( 'the remove handler does not serialize the layout', function () {
	assert.ok(
		! handlerText( 'remove', 'receive' ).includes( 'refreshPanelsData' ),
		'remove must not serialize: the dragged widget is in no cell at that point'
	);
} );

test( 'the stop handler serializes when the widget has left this builder', function () {
	const body = handlerText( 'stop', 'helper' );
	const marker = body.indexOf( '} else {' );

	assert.notEqual(
		marker,
		-1,
		'stop needs an else branch, or a widget moved to another builder is never serialized'
	);
	assert.ok(
		body.slice( marker ).includes( 'refreshPanelsData();' ),
		'the else branch must serialize, so the origin builder records the departure'
	);
} );
