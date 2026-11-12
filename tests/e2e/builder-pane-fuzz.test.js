/**
 * Pane setup fuzz (#1464). Generated field values go through pane setup
 * (jQuery.fn.soPanelsSetupBuilderWidget) on the base revision's builder script and on this one,
 * inside a real Page Builder widget dialog, the place a third-party pane widget's pane is set up, and
 * under a .widget-content ancestor, the branch pane setup takes on the widgets screen.
 *
 * Only one value may behave differently: a field holding exactly `""` opens as an empty, unlocked
 * builder. Every other value must load, lock or stay empty exactly as it does on the base revision:
 * the same outcome, the same field value and state, and the same layout handed out. A failed load
 * must keep its field and hand out exactly the layout it was given.
 *
 * The base script is tests/cache/fuzz/base.js, built from the base revision (CI builds it from the
 * pull request's base; locally: git archive <ref> js/siteorigin-panels, then browserify its main.js).
 * Without that file the spec is skipped. PANELS_FUZZ_COUNT sets the number of generated values
 * (default 2000) and PANELS_FUZZ_SEED the seed.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createPost,
	deletePost,
	expectNoPageErrors,
	newLoggedInPage,
	openClassicBuilder,
	openWidgetDialog,
	seedLayout,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const BASE_SCRIPT = path.join( __dirname, '..', 'cache', 'fuzz', 'base.js' );
const COUNT = Number( process.env.PANELS_FUZZ_COUNT || 2000 );
const SEED = Number( process.env.PANELS_FUZZ_SEED || 20261112 );
const BATCH = 500;
const WIDGETS_SCREEN_COUNT = 300;

// mulberry32: a small seeded generator, so a failing value can be reproduced from its seed.
const random = ( seed ) => () => {
	seed |= 0;
	seed = ( seed + 0x6D2B79F5 ) | 0;
	let t = Math.imul( seed ^ ( seed >>> 15 ), 1 | seed );
	t = ( t + Math.imul( t ^ ( t >>> 7 ), 61 | t ) ) ^ t;

	return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
};

const BOOM = 'Panels_E2E_Boom_Widget';

const layoutOf = ( cells, widgets ) => {
	const layout = { grids: cells.map( ( count ) => ( { cells: count, style: {} } ) ), grid_cells: [], widgets };
	cells.forEach( ( count, grid ) => {
		for ( let index = 0; index < count; index++ ) {
			layout.grid_cells.push( { grid, index, weight: 1 / count, style: {} } );
		}
	} );

	return layout;
};

const widgetAt = ( id, grid, cell, klass = 'Panels_E2E_Text_Widget' ) => ( {
	text: `Widget ${ id } text`,
	panels_info: { class: klass, raw: false, grid, cell, id, widget_id: `fuzz-${ id }`, style: {} },
} );

// The page shape that lost widgets in 2.36.0: five rows, six cells, seven widgets.
const PLACES = [ [ 0, 0 ], [ 1, 0 ], [ 1, 0 ], [ 2, 0 ], [ 3, 0 ], [ 3, 1 ], [ 4, 0 ] ];
const pageLayout = ( boomAt = -1 ) => layoutOf( [ 1, 1, 1, 2, 1 ], PLACES.map( ( [ g, c ], i ) => widgetAt( i, g, c, i === boomAt ? BOOM : undefined ) ) );

const BASE_LAYOUTS = [
	pageLayout(),
	pageLayout( 2 ),
	pageLayout( 0 ),
	layoutOf( [ 1 ], [ widgetAt( 0, 0, 0 ) ] ),
	layoutOf( [ 2, 1 ], [ widgetAt( 0, 0, 0 ), widgetAt( 1, 0, 1 ), widgetAt( 2, 1, 0 ) ] ),
	layoutOf( [ 1 ], [ { panels_data: '""', builder_id: 'fuzz', panels_info: { class: 'SiteOrigin_Panels_Widgets_Layout', raw: false, grid: 0, cell: 0, id: 0, widget_id: 'fuzz-layout', style: {} } } ] ),
	layoutOf( [ 1 ], [] ),
	{ widgets: [], grids: [], grid_cells: [] },
];

// Values that look like `""` but are not exactly it. Each must behave as on the base revision.
const NEAR_MISSES = [ ' ""', '"" ', ' "" ', '\t""', '""\n', '\n""', '﻿""', ' ""', '"" ', '"\\"\\""', '""""', '"', '\'\'', '""x', '[""]', '{"":""}' ];

const FIXED = [
	'', '""', 'null', '"null"', 'false', 'true', '0', '1', '-1', '{}', '[]', '[[]]', '"x"', 'undefined', '{bad', '   ', '\t',
	'{"widgets":[]}', '{"widgets":[],"grids":[]}', '{"widgets":[],"grids":[],"grid_cells":{}}', '{"grid_cells":null}',
	...NEAR_MISSES,
	...BASE_LAYOUTS.map( ( layout ) => JSON.stringify( layout ) ),
];

const randomJson = ( next, depth = 0 ) => {
	const pick = Math.floor( next() * ( depth > 3 ? 5 : 8 ) );
	switch ( pick ) {
		case 0: return null;
		case 1: return next() < 0.5;
		case 2: return Math.floor( next() * 2000 ) - 1000;
		case 3: return [ '', '""', 'null', 'grid', 'widgets', 'é中😀', 'x'.repeat( Math.floor( next() * 40 ) ) ][ Math.floor( next() * 7 ) ];
		case 4: return next() * 10;
		case 5: return Array.from( { length: Math.floor( next() * 4 ) }, () => randomJson( next, depth + 1 ) );
		default: {
			const keys = [ 'widgets', 'grids', 'grid_cells', 'panels_info', 'style', 'cells', 'grid', 'cell', 'x' ];
			const object = {};
			for ( let i = Math.floor( next() * 4 ); i > 0; i-- ) {
				object[ keys[ Math.floor( next() * keys.length ) ] ] = randomJson( next, depth + 1 );
			}

			return object;
		}
	}
};

const clone = ( value ) => JSON.parse( JSON.stringify( value ) );

const mutate = ( next ) => {
	const layout = clone( BASE_LAYOUTS[ Math.floor( next() * BASE_LAYOUTS.length ) ] );
	const members = [ 'widgets', 'grids', 'grid_cells' ];
	const member = members[ Math.floor( next() * 3 ) ];
	const list = layout[ member ];
	const index = list && list.length ? Math.floor( next() * list.length ) : 0;
	const odd = [ null, '', '""', 'x', 0, true, {}, [] ][ Math.floor( next() * 8 ) ];

	switch ( Math.floor( next() * 12 ) ) {
		case 0: delete layout[ member ]; break;
		case 1: layout[ member ] = odd; break;
		case 2: if ( list && list.length ) list[ index ] = odd; break;
		case 3: if ( layout.widgets[ index ] ) delete layout.widgets[ index ].panels_info; break;
		case 4: if ( layout.widgets[ index ] ) layout.widgets[ index ].panels_info.style = odd; break;
		case 5: if ( layout.widgets[ index ] ) layout.widgets[ index ].panels_info.grid = odd; break;
		case 6: if ( layout.widgets[ index ] ) delete layout.widgets[ index ].panels_info.widget_id; break;
		case 7: layout[ member + '_x' ] = layout[ member ]; delete layout[ member ]; break;
		case 8: if ( layout.widgets[ index ] ) layout.widgets[ index ].text = 'é中😀 "quoted" \\ </script>'; break;
		case 9: layout.widgets = Array.from( { length: 40 }, ( _, i ) => widgetAt( 100 + i, 0, 0 ) ); break;
		case 10: if ( layout.widgets[ index ] ) layout.widgets[ index ].info = layout.widgets[ index ].panels_info, delete layout.widgets[ index ].panels_info; break;
		default: if ( layout.widgets[ index ] ) layout.widgets[ index ].panels_info.class = BOOM;
	}

	let text = JSON.stringify( layout );
	switch ( Math.floor( next() * 8 ) ) {
		case 0: text = text.slice( 0, Math.floor( next() * text.length ) ); break;
		case 1: text = JSON.stringify( text ); break;
		case 2: text = [ ' ', '\n', '\t', '﻿', ' ' ][ Math.floor( next() * 5 ) ] + text; break;
		default:
	}

	return text;
};

const generate = ( count, seed ) => {
	const next = random( seed );
	const values = [ ...FIXED ];
	while ( values.length < count ) {
		const roll = next();
		if ( roll < 0.45 ) {
			values.push( mutate( next ) );
		} else if ( roll < 0.85 ) {
			values.push( JSON.stringify( randomJson( next ) ) );
		} else if ( roll < 0.95 ) {
			values.push( NEAR_MISSES[ Math.floor( next() * NEAR_MISSES.length ) ] );
		} else {
			// Raw text that is not JSON.
			values.push( Array.from( { length: Math.floor( next() * 12 ) }, () => String.fromCharCode( 32 + Math.floor( next() * 95 ) ) ).join( '' ) );
		}
	}

	return values.slice( 0, Math.max( count, FIXED.length ) );
};

/**
 * Runs in the page: set up one pane per value inside `host` and record what the builder made of it.
 */
function runInPage( { values, context } ) {
	const $ = window.jQuery;
	const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
	const host = context === 'dialog'
		? $( '.so-panels-dialog-wrapper:visible .panels-e2e-panes' ).first()
		: $( '<div class="widget-content"></div>' ).appendTo( 'body' );
	if ( ! host.length ) {
		throw new Error( 'No pane host in ' + context );
	}

	return values.map( ( field ) => {
		const pane = $( '<div class="siteorigin-page-builder-widget siteorigin-panels-builder" data-type="layout_widget"><p><a href="#" class="button-secondary siteorigin-panels-display-builder">Open Builder</a></p><input type="hidden" data-panels-filter="json_parse" class="panels-data" /></div>' );
		const input = pane.find( 'input' );
		input.val( field );
		host.append( pane );
		if ( input.val() !== field ) {
			return { error: 'field did not hold the value' };
		}

		let record;
		try {
			pane.soPanelsSetupBuilderWidget();
			const view = input.data( 'builder' );
			const model = view.model;
			const data = model.getPanelsData();
			const generated = [];
			let unknownIds = 0;
			if ( data && Array.isArray( data.widgets ) ) {
				data.widgets.forEach( ( widget ) => {
					const info = widget && ( widget.panels_info || widget.info );
					if ( info && typeof info.widget_id === 'string' && field.indexOf( info.widget_id ) === -1 ) {
						if ( uuid.test( info.widget_id ) ) {
							generated.push( info.widget_id );
							info.widget_id = '<generated>';
						} else {
							unknownIds++;
						}
					}
				} );
			}
			let fieldAfter = input.val();
			generated.forEach( ( id ) => {
				fieldAfter = fieldAfter.split( id ).join( '<generated>' );
			} );
			let parsed;
			let parses = true;
			try {
				parsed = JSON.parse( field );
			} catch ( e ) {
				parses = false;
			}
			const rows = model.get( 'rows' );
			record = {
				failed: model.loadFailed,
				trusted: model.trustedData !== false,
				rows: rows.length,
				widgets: rows.reduce( ( n, row ) => n + row.get( 'cells' ).reduce( ( k, cell ) => k + cell.get( 'widgets' ).length, 0 ), 0 ),
				data: data === null ? null : JSON.stringify( data ),
				field: fieldAfter,
				disabled: input.prop( 'disabled' ),
				notice: pane.find( '.so-panels-load-failed-notice' ).length > 0,
				unknownIds,
				// A failed load of an object hands out exactly that object.
				keepsInput: model.loadFailed && parses && parsed !== null && typeof parsed === 'object'
					? JSON.stringify( model.getPanelsData() ) === JSON.stringify( parsed )
					: null,
			};
			view.remove();
			model.emptyRows().destroy();
		} catch ( e ) {
			record = { threw: String( e && e.message ).slice( 0, 120 ) };
		}
		pane.remove();

		return record;
	} );
}

test.describe( 'pane setup fuzz against the base revision', () => {
	let admin;
	let ctx;
	let postId;
	const values = generate( COUNT, SEED );

	// CI builds the base script for pull requests only: elsewhere there is no earlier revision to
	// compare with, and comparing the script with itself would prove nothing.
	test.skip( ! fs.existsSync( BASE_SCRIPT ), `No base revision script at ${ BASE_SCRIPT }.` );

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
		postId = await createPost( admin, 'page', { title: 'Pane fuzz' } );
		await seedLayout( admin, postId, layoutOf( [ 1 ], [ {
			mode: 'default-empty',
			items: [],
			panels_info: { class: 'Panels_E2E_Pane_Widget', raw: false, grid: 0, cell: 0, id: 0, widget_id: 'fuzz-pane-widget', style: {} },
		} ] ) );
	} );

	test.afterAll( async () => {
		if ( postId ) {
			await deletePost( admin, 'page', postId );
		}
		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	/**
	 * Open the editor on one revision, with the pane widget's dialog open when the context needs it.
	 * A slow request can leave the dialog without its form, so a page load is tried up to three times.
	 */
	const openRevision = async ( revision, context ) => {
		const { page } = ctx;
		for ( let attempt = 1; ; attempt++ ) {
			try {
				await openClassicBuilder( page, { postId } );
				if ( revision === 'base' ) {
					await page.goto( page.url() + '&panels_e2e_bundle=base' );
					await expect( page.locator( '#siteorigin-panels-metabox .so-builder-toolbar' ).first() ).toBeVisible( { timeout: 30000 } );
				}
				if ( context === 'dialog' ) {
					const dialog = await openWidgetDialog( page.locator( '#siteorigin-panels-metabox .so-widget' ).first(), page );
					await expect( dialog.locator( '.panels-e2e-add-pane' ) ).toBeVisible( { timeout: 15000 } );
				}
				break;
			} catch ( error ) {
				if ( attempt === 3 ) {
					throw error;
				}
			}
		}
		const script = await page.evaluate( () => ( document.getElementById( 'so-panels-admin-js' ) || {} ).src || '' );
		expect( script, revision ).toContain( revision === 'base' ? 'tests/cache/fuzz/base.js' : 'js/siteorigin-panels' );
	};

	/**
	 * Run the values a batch at a time, each batch on the base revision and then on this one, and
	 * compare as it goes. Returns the problems and the candidate's records.
	 */
	const runAll = async ( list, context ) => {
		const { page } = ctx;
		const problems = [];
		const candidates = [];
		for ( let start = 0; start < list.length; start += BATCH ) {
			const batch = list.slice( start, start + BATCH );
			await openRevision( 'base', context );
			const base = await page.evaluate( runInPage, { values: batch, context } );
			await openRevision( 'candidate', context );
			const candidate = await page.evaluate( runInPage, { values: batch, context } );
			problems.push( ...compare( batch, base, candidate, start ) );
			candidates.push( ...candidate );
			if ( problems.length ) {
				break;
			}
		}

		return { problems, candidates };
	};

	const compare = ( list, base, candidate, offset = 0 ) => {
		const problems = [];
		list.forEach( ( value, i ) => {
			const b = base[ i ];
			const c = candidate[ i ];
			const show = ( message ) => problems.push( { seed: SEED, index: offset + i, value: JSON.stringify( value ).slice( 0, 160 ), message, base: b, candidate: c } );

			if ( c.error || c.threw || c.unknownIds ) {
				show( 'the candidate record is not usable' );
			} else if ( value === '""' ) {
				if ( c.failed || c.disabled || c.notice || c.rows !== 0 || c.field !== '' ) {
					show( '`""` must open as an empty, unlocked builder' );
				}
			} else if ( JSON.stringify( b ) !== JSON.stringify( c ) ) {
				show( 'differs from the base revision' );
			} else if ( c.failed && ( c.field !== value || ! c.disabled || c.keepsInput === false ) ) {
				show( 'a failed load must keep its field and hand out the layout it was given' );
			}
		} );

		return problems;
	};

	test( `${ values.length } values in a widget dialog behave as on the base revision, except \`""\``, async () => {
		test.setTimeout( Math.max( 120000, values.length * 60 ) );
		const { problems, candidates } = await runAll( values, 'dialog' );

		expect( problems.slice( 0, 5 ), `${ problems.length } problems, first at value ${ problems.length ? problems[ 0 ].index : '-' } of ${ values.length } (seed ${ SEED })` ).toEqual( [] );
		expect( candidates ).toHaveLength( values.length );
		// Sanity: the run saw every outcome.
		const kinds = new Set( candidates.map( ( r ) => ( r.failed ? ( r.trusted ? 'locked' : 'unparseable' ) : ( r.rows ? 'loaded' : 'empty' ) ) ) );
		expect( [ ...kinds ].sort() ).toEqual( [ 'empty', 'loaded', 'locked', 'unparseable' ] );
		expectNoPageErrors( ctx.errors );
	} );

	test( `${ Math.min( WIDGETS_SCREEN_COUNT, values.length ) } values set up under a .widget-content ancestor (the widgets screen branch) behave as on the base revision, except \`""\``, async () => {
		const list = values.slice( 0, WIDGETS_SCREEN_COUNT );
		const { problems, candidates } = await runAll( list, 'widgets-screen' );

		expect( problems.slice( 0, 5 ), `${ problems.length } problems of ${ list.length } values (seed ${ SEED })` ).toEqual( [] );
		expect( candidates ).toHaveLength( list.length );
		expectNoPageErrors( ctx.errors );
	} );
} );
