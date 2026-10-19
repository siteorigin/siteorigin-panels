'use strict';

/*
 * panels.helpers.utils.isolatedPreviewUrl() asks for the editor's
 * Document-Isolation-Policy on a Live Editor or History preview URL, and only
 * when the editor is cross-origin isolated (#1400).
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'path' );

global.window = global.window || {};

const utils = require( path.join( __dirname, '..', '..', 'js', 'siteorigin-panels', 'helpers', 'utils' ) );

const ARG = 'siteorigin_panels_live_editor_isolated=1';

const withIsolation = ( value, fn ) => {
	const before = global.window.crossOriginIsolated;
	global.window.crossOriginIsolated = value;
	try {
		fn();
	} finally {
		global.window.crossOriginIsolated = before;
	}
};

test( 'an editor that is not isolated gets the URL unchanged', () => {
	for ( const value of [ undefined, false, 'true', 1 ] ) {
		withIsolation( value, () => {
			const url = 'https://example.com/page/?siteorigin_panels_live_editor=true&_panelsnonce=abc';
			assert.equal( utils.isolatedPreviewUrl( url ), url );
		} );
	}
} );

test( 'an isolated editor adds the argument with & after a query', () => {
	withIsolation( true, () => {
		assert.equal(
			utils.isolatedPreviewUrl( 'https://example.com/page/?siteorigin_panels_live_editor=true&_panelsnonce=abc' ),
			'https://example.com/page/?siteorigin_panels_live_editor=true&_panelsnonce=abc&' + ARG
		);
		assert.equal(
			utils.isolatedPreviewUrl( 'https://example.com/wp-admin/admin-ajax.php?action=so_panels_live_editor_preview&siteorigin_panels_live_editor=true' ),
			'https://example.com/wp-admin/admin-ajax.php?action=so_panels_live_editor_preview&siteorigin_panels_live_editor=true&' + ARG
		);
	} );
} );

test( 'an isolated editor adds the argument with ? when there is no query, before a fragment', () => {
	withIsolation( true, () => {
		assert.equal( utils.isolatedPreviewUrl( 'https://example.com/page/' ), 'https://example.com/page/?' + ARG );
		assert.equal( utils.isolatedPreviewUrl( 'https://example.com/page/#top' ), 'https://example.com/page/?' + ARG + '#top' );
		assert.equal( utils.isolatedPreviewUrl( 'https://example.com/?p=1#top' ), 'https://example.com/?p=1&' + ARG + '#top' );
	} );
} );

test( 'an empty or missing URL is returned unchanged', () => {
	withIsolation( true, () => {
		assert.equal( utils.isolatedPreviewUrl( '' ), '' );
		assert.equal( utils.isolatedPreviewUrl( undefined ), undefined );
	} );
} );
