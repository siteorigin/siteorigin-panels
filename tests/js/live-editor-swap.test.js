'use strict';

/*
 * panels.helpers.liveEditorSwap: the route, allow-list and document-policy checks that gate a single-widget
 * swap in the Live Editor preview. shell() and target() need a DOM; the e2e tests cover them.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'path' );

const swap = require( path.join( __dirname, '..', '..', 'js', 'siteorigin-panels', 'helpers', 'live-editor-swap' ) );

const ORIGIN = 'https://example.com';

test( 'isSwapRoute accepts only the same-origin front-end permalink preview', () => {
	assert.equal( swap.isSwapRoute( 'https://example.com/hello/?siteorigin_panels_live_editor=true&_panelsnonce=abc', ORIGIN ), true );
	assert.equal( swap.isSwapRoute( 'https://example.com/?p=12&siteorigin_panels_live_editor=true&_panelsnonce=abc&siteorigin_panels_live_editor_isolated=1', ORIGIN ), true );

	// The admin-ajax preview has no edit_post check.
	assert.equal( swap.isSwapRoute( 'https://example.com/wp-admin/admin-ajax.php?action=so_panels_live_editor_preview&siteorigin_panels_live_editor=true', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( 'https://example.com/sub/wp-admin/admin-ajax.php?action=x', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( 'https://example.com/wp-admin/post.php?post=1', ORIGIN ), false );

	// Another origin, a protocol mismatch, a protocol-relative or relative URL.
	assert.equal( swap.isSwapRoute( 'https://evil.example/hello/', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( 'http://example.com/hello/', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( 'https://example.com:8443/hello/', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( '//example.com/hello/', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( '/hello/', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( 'javascript:alert(1)', ORIGIN ), false );
	assert.equal( swap.isSwapRoute( undefined, ORIGIN ), false );
	assert.equal( swap.isSwapRoute( 'https://example.com/hello/', undefined ), false );
} );

test( 'isSwapWidget needs the exact class in the allow list', () => {
	const allow = [ 'WP_Widget_Text', 'WP_Widget_Custom_HTML' ];
	assert.equal( swap.isSwapWidget( { panels_info: { class: 'WP_Widget_Text' } }, allow ), true );
	assert.equal( swap.isSwapWidget( { panels_info: { class: 'WP_Widget_Custom_HTML' } }, allow ), true );
	assert.equal( swap.isSwapWidget( { panels_info: { class: 'wp_widget_text' } }, allow ), false );
	assert.equal( swap.isSwapWidget( { panels_info: { class: 'SiteOrigin_Widget_Editor_Widget' } }, allow ), false );
	assert.equal( swap.isSwapWidget( { panels_info: {} }, allow ), false );
	assert.equal( swap.isSwapWidget( {}, allow ), false );
	assert.equal( swap.isSwapWidget( null, allow ), false );
	assert.equal( swap.isSwapWidget( { panels_info: { class: 'WP_Widget_Text' } }, 'WP_Widget_Text' ), false );
	assert.equal( swap.isSwapWidget( { panels_info: { class: 'WP_Widget_Text' } }, undefined ), false );
} );

test( 'hasDocumentPolicy sees a CSP header, a report-only CSP header and a CSP meta tag', () => {
	assert.equal( swap.hasDocumentPolicy( new Headers( { 'Content-Type': 'text/html' } ), null ), false );
	assert.equal( swap.hasDocumentPolicy( new Headers( { 'Content-Security-Policy': "script-src 'self'" } ), null ), true );
	assert.equal( swap.hasDocumentPolicy( new Headers( { 'content-security-policy-report-only': "default-src 'self'" } ), null ), true );
	assert.equal( swap.hasDocumentPolicy( new Headers( { 'Document-Isolation-Policy': 'isolate-and-credentialless' } ), null ), false );

	const doc = ( values ) => ( {
		querySelectorAll: () => values.map( ( value ) => ( { getAttribute: () => value } ) ),
	} );
	assert.equal( swap.hasDocumentPolicy( new Headers(), doc( [ 'X-UA-Compatible' ] ) ), false );
	assert.equal( swap.hasDocumentPolicy( new Headers(), doc( [ ' Content-Security-Policy ' ] ) ), true );
	assert.equal( swap.hasDocumentPolicy( new Headers(), doc( [ 'content-security-policy-report-only' ] ) ), true );

	// No headers object: treat as a policy, so the swap is refused.
	assert.equal( swap.hasDocumentPolicy( null, null ), true );
} );

test( 'shell and target refuse an id that is not a widget wrapper id', () => {
	const doc = { documentElement: {}, querySelectorAll: () => {
		throw new Error( 'must not query' );
	} };
	assert.equal( swap.shell( doc, 'panel-1-0-0-0"],script[x="' ), null );
	assert.equal( swap.target( doc, 'x' ), null );
} );

test( 'hasDeferredContent refuses shortcodes and auto-embed URLs in any widget value', () => {
	const widget = ( values ) => ( { ...values, panels_info: { class: 'WP_Widget_Text', style: { class: 'x[1]' } } } );
	assert.equal( swap.hasDeferredContent( widget( { title: 'Hello', text: '<p>Plain <a href="https://example.com/">link</a></p>' } ) ), false );
	assert.equal( swap.hasDeferredContent( widget( { title: '', text: 'Text with a URL https://example.com/ inside' } ) ), false );

	assert.equal( swap.hasDeferredContent( widget( { text: '[video src="a.mp4"]' } ) ), true );
	assert.equal( swap.hasDeferredContent( widget( { text: 'Before [contact-form-7 id="1"] after' } ) ), true );
	assert.equal( swap.hasDeferredContent( widget( { title: 'A [b]', text: '' } ) ), true, 'any value, the title too' );
	assert.equal( swap.hasDeferredContent( widget( { text: '<p>Intro</p>\nhttps://www.youtube.com/watch?v=x\n<p>More</p>' } ) ), true );
	assert.equal( swap.hasDeferredContent( widget( { text: '  http://example.com/post/  ' } ) ), true );
	assert.equal( swap.hasDeferredContent( widget( { text: '<p>https://example.com/post/</p>' } ) ), true );
	assert.equal( swap.hasDeferredContent( widget( { content: { nested: [ 'x', '[gallery]' ] } } ) ), true, 'nested values' );

	// panels_info is ignored; missing data refuses.
	assert.equal( swap.hasDeferredContent( { text: 'plain', panels_info: { label: '[x]' } } ), false );
	assert.equal( swap.hasDeferredContent( null ), true );
} );

// A minimal element tree for needsDocumentSetup() and target().
const el = ( tag, classes = [], children = [] ) => {
	const node = { tagName: tag.toUpperCase(), classList: classes, children };
	node.querySelectorAll = ( selector ) => {
		const all = [];
		const walk = ( n ) => n.children.forEach( ( c ) => {
			all.push( c );
			walk( c );
		} );
		walk( node );
		if ( selector === '*' ) {
			return all;
		}
		const id = /^\[id="(.+)"\]$/.exec( selector )[1];
		return all.filter( ( c ) => c.id === id );
	};
	return node;
};

test( 'needsDocumentSetup finds scripts, media, embeds, noscript and template at any depth', () => {
	assert.equal( swap.needsDocumentSetup( el( 'div', [ 'so-panel' ], [ el( 'p' ), el( 'a' ) ] ) ), false );
	for ( const tag of [ 'script', 'iframe', 'video', 'audio', 'object', 'embed', 'noscript', 'template' ] ) {
		assert.equal( swap.needsDocumentSetup( el( 'div', [], [ el( 'p', [], [ el( tag ) ] ) ] ) ), true, tag );
	}
	assert.equal( swap.needsDocumentSetup( el( 'script' ) ), true, 'the element itself' );
	assert.equal( swap.needsDocumentSetup( el( 'div', [], [ el( 'blockquote', [ 'wp-embedded-content' ] ) ] ) ), true );
	assert.equal( swap.needsDocumentSetup( el( 'div', [], [ el( 'figure', [ 'wp-block-embed', 'is-type-video' ] ) ] ) ), true );
	assert.equal( swap.needsDocumentSetup( el( 'div', [ 'my-wp-embed' ] ) ), false, 'the prefix must start the class name' );
	assert.deepEqual( swap.documentSetupMarkup.classPrefixes, [ 'wp-embed', 'wp-block-embed' ] );
} );

test( 'target refuses a widget that needs document setup', () => {
	const plain = el( 'div', [ 'so-panel' ], [ el( 'p' ) ] );
	plain.id = 'panel-1-0-0-0';
	const docPlain = el( 'body', [], [ plain ] );
	assert.equal( swap.target( docPlain, 'panel-1-0-0-0' ), plain );

	const video = el( 'div', [ 'so-panel' ], [ el( 'video' ) ] );
	video.id = 'panel-1-0-0-0';
	assert.equal( swap.target( el( 'body', [], [ video ] ), 'panel-1-0-0-0' ), null );

	const twin = el( 'div' );
	twin.id = 'panel-1-0-0-0';
	assert.equal( swap.target( el( 'body', [], [ plain, twin ] ), 'panel-1-0-0-0' ), null, 'not unique' );
} );
