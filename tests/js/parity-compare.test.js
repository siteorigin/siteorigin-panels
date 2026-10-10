/**
 * Parity compares the ability round trip (layout-get, change widget 2, layout-update) with the
 * unchanged widgets stored as they were. Only a cell_index with the value layout-get adds, and the
 * sidebars emulator pair right before or right after panels_info, are left out of the comparison;
 * every other byte counts.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert' );

const s = ( v ) => `s:${ Buffer.byteLength( v, 'utf8' ) }:"${ v }";`;
const i = ( n ) => `i:${ n };`;
const a = ( pairs ) => `a:${ pairs.length }:{${ pairs.map( ( [ k, v ] ) => ( typeof k === 'number' ? i( k ) : s( k ) ) + v ).join( '' ) }}`;

const metaInfo = ( cell, cellIndex ) => a( [
	[ 'class', s( 'WP_Widget_Custom_HTML' ) ],
	[ 'grid', i( 0 ) ],
	[ 'cell', i( cell ) ],
	...( cellIndex === undefined ? [] : [ [ 'cell_index', i( cellIndex ) ] ] ),
] );
const emulator = [ [ 'so_sidebar_emulator_id', s( 'custom_html-1' ) ], [ 'option_name', s( 'widget_custom_html' ) ] ];
const metaWidget = ( content, cell, { cellIndex, emulatorFirst = false, extra = [] } = {} ) => a( [
	[ 'content', s( content ) ],
	...( emulatorFirst ? emulator : [] ),
	[ 'panels_info', metaInfo( cell, cellIndex ) ],
	...( emulatorFirst ? [] : emulator ),
	...extra,
] );
const metaRow = ( widgets ) => ( { meta_key: 'panels_data', meta_value: a( [ [ 'widgets', a( widgets.map( ( w, n ) => [ n, w ] ) ) ] ] ) } );

const blockWidget = ( content, cell, cellIndex ) => ( {
	content,
	panels_info: { class: 'WP_Widget_Custom_HTML', grid: 0, cell, ...( cellIndex === undefined ? {} : { cell_index: cellIndex } ) },
} );
// WordPress escapes < and > in block attributes; the stored text must reach the comparison as stored.
const blockContent = ( widgets ) => `<!-- wp:siteorigin-panels/layout-block ${ JSON.stringify( { panelsData: { widgets } } ).replace( /</g, '\\u003c' ) } /-->`;

test( 'Layout Block: a read-added cell_index on an unchanged widget is not a difference', async () => {
	const { roundTripContent } = await import( '../parity/compare.mjs' );
	const used = {};
	const old = blockContent( [ blockWidget( '<p>A</p>', 0, 0 ), blockWidget( 'B', 0, 1 ), blockWidget( 'C', 1, 0 ) ] );
	const now = blockContent( [ blockWidget( '<p>A</p>', 0 ), blockWidget( 'B', 0 ), blockWidget( 'C', 1, 0 ) ] );

	assert.strictEqual( roundTripContent( old, used ), roundTripContent( now, used ) );
	assert.strictEqual( roundTripContent( now, used ), now, 'nothing else in the stored text changes' );
	assert.ok( roundTripContent( old, used ).includes( '\\u003cp>A' ), 'escaping is kept as stored' );
	assert.strictEqual( used[ 'panels:unchanged-widget-stored-as-is' ], 4 );

	const spacer = '\n\n<!-- wp:spacer {"height":"10px"} /-->';
	assert.strictEqual( roundTripContent( old + spacer, used ), roundTripContent( now + spacer, used ), 'a block with attributes after the Layout Block' );
} );

test( 'Layout Block: other differences still count', async () => {
	const { roundTripContent } = await import( '../parity/compare.mjs' );
	const used = {};
	const now = blockContent( [ blockWidget( 'A', 0 ), blockWidget( 'B', 0 ), blockWidget( 'C', 1, 0 ) ] );
	const cases = {
		'a cell_index layout-get would not add': [ blockWidget( 'A', 0, 5 ), blockWidget( 'B', 0 ), blockWidget( 'C', 1, 0 ) ],
		'a cell_index change on the changed widget 2': [ blockWidget( 'A', 0 ), blockWidget( 'B', 0 ), blockWidget( 'C', 1 ) ],
		'other content': [ blockWidget( 'A!', 0, 0 ), blockWidget( 'B', 0 ), blockWidget( 'C', 1, 0 ) ],
	};
	for ( const [ name, widgets ] of Object.entries( cases ) ) {
		assert.notStrictEqual( roundTripContent( blockContent( widgets ), used ), roundTripContent( now, used ), name );
	}
	const twice = now.replace( '"cell":0}', '"cell":0,"cell_index":0,"cell_index":0}' );
	assert.notStrictEqual( twice, now );
	assert.notStrictEqual( roundTripContent( twice, used ), roundTripContent( now, used ), 'a cell_index stored twice' );
	const escaped = now.replace( '"A"', '"\\u0041"' );
	assert.notStrictEqual( roundTripContent( escaped, used ), roundTripContent( now, used ), 'a different escaping of the same text' );
} );

test( 'classic layout: a read-added cell_index and the emulator pair before or after panels_info are not differences', async () => {
	const { roundTripMeta } = await import( '../parity/compare.mjs' );
	const used = {};
	const old = metaRow( [ metaWidget( 'A', 0, { cellIndex: 0, emulatorFirst: true } ), metaWidget( 'B', 1, { cellIndex: 0, emulatorFirst: true, extra: [ [ 'nothing', 'N;' ] ] } ), metaWidget( 'C', 1, { cellIndex: 1 } ) ] );
	const now = metaRow( [ metaWidget( 'A', 0 ), metaWidget( 'B', 1, { extra: [ [ 'nothing', 'N;' ] ] } ), metaWidget( 'C', 1, { cellIndex: 1 } ) ] );

	assert.deepStrictEqual( roundTripMeta( old, used ), roundTripMeta( now, used ), 'a null value inside the layout is read too' );
	assert.deepStrictEqual( roundTripMeta( now, used ), now, 'nothing else in the stored value changes' );
	assert.strictEqual( used[ 'panels:unchanged-widget-stored-as-is' ], 2 );
} );

test( 'classic layout: other differences still count', async () => {
	const { roundTripMeta } = await import( '../parity/compare.mjs' );
	const used = {};
	const now = metaRow( [ metaWidget( 'A', 0 ), metaWidget( 'B', 1 ), metaWidget( 'C', 1, { cellIndex: 1 } ) ] );
	const cases = {
		'a cell_index layout-get would not add': [ metaWidget( 'A', 0, { cellIndex: 3 } ), metaWidget( 'B', 1 ), metaWidget( 'C', 1, { cellIndex: 1 } ) ],
		'a cell_index change on the changed widget 2': [ metaWidget( 'A', 0 ), metaWidget( 'B', 1 ), metaWidget( 'C', 1 ) ],
		'other content': [ metaWidget( 'A!', 0 ), metaWidget( 'B', 1 ), metaWidget( 'C', 1, { cellIndex: 1 } ) ],
		'an added key': [ metaWidget( 'A', 0, { extra: [ [ 'x', 'N;' ] ] } ), metaWidget( 'B', 1 ), metaWidget( 'C', 1, { cellIndex: 1 } ) ],
	};
	const widgetA = ( ...pairs ) => a( pairs );
	const info = [ 'panels_info', metaInfo( 0 ) ];
	const content = [ 'content', s( 'A' ) ];
	const reorders = {
		'the emulator pair moved to the front': widgetA( ...emulator, content, info ),
		'the emulator pair swapped before panels_info': widgetA( content, emulator[ 1 ], emulator[ 0 ], info ),
		'one emulator key before panels_info and one after': widgetA( content, emulator[ 0 ], info, emulator[ 1 ] ),
	};
	for ( const [ name, widget ] of Object.entries( reorders ) ) {
		cases[ name ] = [ widget, metaWidget( 'B', 1 ), metaWidget( 'C', 1, { cellIndex: 1 } ) ];
	}
	for ( const [ name, widgets ] of Object.entries( cases ) ) {
		assert.notDeepStrictEqual( roundTripMeta( metaRow( widgets ), used ), roundTripMeta( now, used ), name );
	}

	const unreadable = { meta_key: 'panels_data', meta_value: 'O:8:"stdClass":0:{}' };
	assert.deepStrictEqual( roundTripMeta( unreadable, used ), unreadable, 'a value the parser cannot read is compared as stored' );
} );
