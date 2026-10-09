/**
 * A layout first rendered by a wp_footer callback, as a popup, off-canvas panel or theme widget
 * area would render it, still gets its layout CSS and the front stylesheet. Covers priority 10,
 * where the footer CSS hook used to be already running, 20, where the footer scripts print, a later
 * priority and PHP_INT_MAX, which has no later priority, for each Layout CSS Output Location and
 * for a nested layout. With a body layout as well, each layout style element keeps a unique ID.
 * A layout rendered again with the same CSS doesn't print it twice; changed CSS is printed.
 * CSS a caller asks for as a string, or gets back with a layout at PHP_INT_MAX, never escapes that
 * caller, and throwing it away doesn't cost a later layout its front stylesheet.
 * The layout is printed by tests/playground/mu-plugins/panels-e2e-footer-layout.php.
 */
const { expect, request, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );

const textWidget = ( text, cell ) => ( {
	text,
	panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell, id: cell, style: {} },
} );

const twoCells = {
	widgets: [ textWidget( 'Footer cell one', 0 ), textWidget( 'Footer cell two', 1 ) ],
	grids: [ { cells: 2, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 0.5, style: {} }, { grid: 0, index: 1, weight: 0.5, style: {} } ],
};

const escape = ( text ) => text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

// Every Page Builder layout style element in the page, as { id, css }.
const styleBlocks = ( html ) => [ ...html.matchAll( /<style[^>]*id="(siteorigin-panels-layouts-[^"]*)"[^>]*>([\s\S]*?)<\/style>/g ) ].map( ( match ) => ( { id: match[ 1 ], css: match[ 2 ] } ) );

// The CSS of every Page Builder layout style element in the page.
const layoutStyles = ( html ) => styleBlocks( html ).map( ( block ) => block.css ).join( '\n' );

const layoutComment = ( layoutId ) => new RegExp( `/\\* Layout ${ escape( layoutId ) } \\*/` );

// With the layout CSS and the front stylesheet, the two cells sit side by side, each about half the row.
const expectCellsSideBySide = async ( page, layoutId ) => {
	const row = page.locator( `#pl-${ layoutId } .panel-grid` ).first();
	const cells = row.locator( '.panel-grid-cell' );
	await expect( cells ).toHaveCount( 2 );
	const rowBox = await row.boundingBox();
	const boxes = [ await cells.nth( 0 ).boundingBox(), await cells.nth( 1 ).boundingBox() ];
	for ( const box of boxes ) {
		expect( box.width / rowBox.width ).toBeGreaterThan( 0.4 );
		expect( box.width / rowBox.width ).toBeLessThanOrEqual( 0.5 );
	}
	expect( boxes[ 1 ].y ).toBe( boxes[ 0 ].y );
};

// The page HTML a logged-out visitor is served.
const anonymousHtml = async ( url ) => {
	const context = await request.newContext( { storageState: { cookies: [], origins: [] } } );
	try {
		const response = await context.get( url );
		expect( response.status() ).toBe( 200 );

		return await response.text();
	} finally {
		await context.dispose();
	}
};

test.describe( 'layouts rendered from wp_footer', () => {
	let admin;
	let postId;

	test.beforeAll( async () => {
		admin = await adminLogin();
		postId = await createPost( admin, 'page', { title: 'Footer layout page', status: 'publish' } );
		await seedLayout( admin, postId, twoCells );
	} );

	test.afterAll( async () => {
		if ( postId ) {
			await deletePost( admin, 'page', postId );
		}
		await admin.context.dispose();
	} );

	// What each kind prints from wp_footer: the layouts whose CSS must be printed, and the one
	// holding the two cells.
	const kinds = {
		widget: () => ( { layout: 'widget', ids: [ 'wpanelse2efooter' ], cells: 'wpanelse2efooter' } ),
		post: () => ( { layout: String( postId ), ids: [ String( postId ) ], cells: String( postId ) } ),
		nested: () => ( { layout: 'nested', ids: [ 'wpanelse2efooter', 'wpanelse2enested' ], cells: 'wpanelse2enested' } ),
	};

	for ( const location of [ 'auto', 'header', 'footer' ] ) {
		for ( const kind of Object.keys( kinds ) ) {
			// 20 is where WordPress prints the footer scripts and late styles.
			for ( const priority of [ 10, 20, 100, 'max' ] ) {
				test( `${ kind } layout at wp_footer ${ priority }, CSS location ${ location }`, async ( { browser } ) => {
					const { layout, ids, cells } = kinds[ kind ]();
					const url = siteUrl( `?panels_e2e_footer_layout=${ layout }&panels_e2e_footer_priority=${ priority }&panels_e2e_css_location=${ location }` );
					const context = await browser.newContext( {
						viewport: { width: 1280, height: 800 },
						storageState: { cookies: [], origins: [] },
					} );

					try {
						const response = await context.request.get( url );
						expect( response.status() ).toBe( 200 );
						const html = await response.text();
						for ( const layoutId of ids ) {
							expect( html ).toContain( `id="pl-${ layoutId }"` );
							expect( layoutStyles( html ) ).toMatch( layoutComment( layoutId ) );
						}
						if ( priority === 'max' ) {
							// No later priority exists, so the CSS comes back with the layout's HTML.
							const wrapper = html.indexOf( 'id="panels-e2e-footer-layout"' );
							const css = html.indexOf( `/* Layout ${ ids[ 0 ] } */` );
							expect( css ).toBeGreaterThan( wrapper );
							expect( css ).toBeLessThan( html.indexOf( `id="pl-${ ids[ 0 ] }"` ) );
						}
						expect( html ).toMatch( /<link[^>]*id=['"]siteorigin-panels-front-css['"]/ );

						const page = await context.newPage();
						await page.goto( url );
						await expectCellsSideBySide( page, cells );
					} finally {
						await context.close();
					}
				} );
			}
		}

		// A body layout prints its CSS in the footer at the default priority. A footer layout at
		// priority 10 renders before that print, so it shares the block; a later one prints a
		// second footer block. The first keeps its ID; every ID is unique.
		for ( const priority of [ 10, 100, 'max' ] ) {
			test( `body layout plus post layout at wp_footer ${ priority }, CSS location ${ location }`, async ( { browser } ) => {
				const bodyId = 'wpanelse2ebody';
				const footerId = String( postId );
				const url = siteUrl( `?panels_e2e_body_layout=1&panels_e2e_footer_layout=${ postId }&panels_e2e_footer_priority=${ priority }&panels_e2e_css_location=${ location }` );
				const context = await browser.newContext( {
					viewport: { width: 1280, height: 800 },
					storageState: { cookies: [], origins: [] },
				} );

				try {
					const response = await context.request.get( url );
					expect( response.status() ).toBe( 200 );
					const html = await response.text();
					expect( html ).toContain( `id="pl-${ bodyId }"` );
					expect( html ).toContain( `id="pl-${ footerId }"` );

					const blocks = styleBlocks( html );
					const ids = blocks.map( ( block ) => block.id );
					expect( new Set( ids ).size, ids.join( ', ' ) ).toBe( ids.length );
					const byId = Object.fromEntries( blocks.map( ( block ) => [ block.id, block.css ] ) );
					expect( byId[ 'siteorigin-panels-layouts-footer' ], ids.join( ', ' ) ).toMatch( layoutComment( bodyId ) );
					const footerBlock = priority === 10 ? 'siteorigin-panels-layouts-footer' : 'siteorigin-panels-layouts-footer-2';
					expect( byId[ footerBlock ], ids.join( ', ' ) ).toMatch( layoutComment( footerId ) );

					const page = await context.newPage();
					await page.goto( url );
					await expectCellsSideBySide( page, bodyId );
					await expectCellsSideBySide( page, footerId );
				} finally {
					await context.close();
				}
			} );
		}

		// The widget layout printed again under the same layout ID by a later wp_footer callback.
		// Only CSS the footer hook printed is skipped on a repeat. At PHP_INT_MAX the CSS prints
		// into the caller's output, which may not reach the page, so a repeat prints it again.
		const repeats = [
			{ repeat: 'same', priority: 10, repeatPriority: 150, widths: [ '50%' ] },
			{ repeat: 'same', priority: 'max', repeatPriority: 'max', widths: [ '50%', '50%' ] },
			{ repeat: 'changed', priority: 10, repeatPriority: 150, widths: [ '50%', '30%' ] },
			{ repeat: 'changed', priority: 'max', repeatPriority: 'max', widths: [ '50%', '30%' ] },
		];
		for ( const { repeat, priority, repeatPriority, widths } of repeats ) {
			test( `${ repeat } layout again at wp_footer ${ repeatPriority } after ${ priority }, CSS location ${ location }`, async ( { browser } ) => {
				const layoutId = 'wpanelse2efooter';
				const url = siteUrl( `?panels_e2e_footer_layout=widget&panels_e2e_footer_priority=${ priority }&panels_e2e_footer_repeat=${ repeat }&panels_e2e_repeat_priority=${ repeatPriority }&panels_e2e_css_location=${ location }` );
				const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );

				try {
					const response = await context.request.get( url );
					expect( response.status() ).toBe( 200 );
					const html = await response.text();
					expect( html.split( `id="pl-${ layoutId }"` ).length - 1 ).toBe( 2 );
					expect( html ).toMatch( /<link[^>]*id=['"]siteorigin-panels-front-css['"]/ );

					const blocks = styleBlocks( html );
					const ids = blocks.map( ( block ) => block.id );
					expect( new Set( ids ).size, ids.join( ', ' ) ).toBe( ids.length );

					// Each print is in its own footer block.
					const printed = blocks.filter( ( block ) => layoutComment( layoutId ).test( block.css ) );
					expect( printed.map( ( block ) => block.id ) ).toEqual( widths.map( ( width, i ) => `siteorigin-panels-layouts-footer${ i ? `-${ i + 1 }` : '' }` ) );
					printed.forEach( ( block, i ) => {
						// Equal cells share one rule.
						expect( block.css ).toMatch( new RegExp( `#pgc-${ layoutId }-0-0 (, #pgc-${ layoutId }-0-1 )?\\{ width:${ widths[ i ] }` ) );
					} );
				} finally {
					await context.close();
				}
			} );
		}

		// A caller that throws away a layout and the CSS it asked for as a string. The visible
		// layout printed later still gets the front stylesheet.
		test( `returned CSS thrown away at wp_footer 30, then widget layout at 40, CSS location ${ location }`, async () => {
			const url = siteUrl( `?panels_e2e_discard_returned=${ postId }&panels_e2e_footer_layout=widget&panels_e2e_footer_priority=40&panels_e2e_css_location=${ location }` );
			const html = await anonymousHtml( url );
			expect( html ).toMatch( /<link[^>]*id=['"]siteorigin-panels-front-css['"]/ );
			expect( layoutStyles( html ) ).toMatch( layoutComment( 'wpanelse2efooter' ) );
			expect( html ).not.toContain( `id="pl-${ postId }"` );
			expect( layoutStyles( html ) ).not.toMatch( layoutComment( String( postId ) ) );
		} );

		// A layout rendered at PHP_INT_MAX - 1 queues its CSS for a print at PHP_INT_MAX. A layout
		// rendered and thrown away by an earlier PHP_INT_MAX callback takes only its own CSS.
		test( `layout thrown away at wp_footer max before the print for a layout at max-1, CSS location ${ location }`, async () => {
			const html = await anonymousHtml( siteUrl( `?panels_e2e_discard_render=${ postId }&panels_e2e_footer_layout=widget&panels_e2e_footer_priority=max-1&panels_e2e_css_location=${ location }` ) );
			expect( html ).toContain( 'id="pl-wpanelse2efooter"' );
			expect( layoutStyles( html ) ).toMatch( layoutComment( 'wpanelse2efooter' ) );
			expect( html ).toMatch( /<link[^>]*id=['"]siteorigin-panels-front-css['"]/ );
			expect( html ).not.toContain( `/* Layout ${ postId } */` );
		} );

		// At PHP_INT_MAX, a render with its CSS turned off inside the outer render's filter leaves
		// the outer layout's CSS with the outer layout.
		test( `layout rendered without CSS inside the render filter at wp_footer max, CSS location ${ location }`, async () => {
			const html = await anonymousHtml( siteUrl( `?panels_e2e_render_filter_inner=1&panels_e2e_footer_layout=widget&panels_e2e_footer_priority=max&panels_e2e_css_location=${ location }` ) );
			const css = html.indexOf( '/* Layout wpanelse2efooter */' );
			expect( css ).toBeGreaterThan( html.indexOf( 'id="panels-e2e-footer-layout"' ) );
			expect( css ).toBeLessThan( html.indexOf( 'id="pl-wpanelse2efooter"' ) );
			expect( html ).toMatch( /<link[^>]*id=['"]siteorigin-panels-front-css['"]/ );
			expect( html ).not.toContain( '/* Layout wpanelse2einner */' );
		} );

		// At PHP_INT_MAX a layout's CSS goes with its HTML, so a layout thrown away leaves nothing.
		test( `layout thrown away at wp_footer max, CSS location ${ location }`, async () => {
			const html = await anonymousHtml( siteUrl( `?panels_e2e_discard_render=${ postId }&panels_e2e_css_location=${ location }` ) );
			expect( html ).not.toContain( `id="pl-${ postId }"` );
			expect( html ).not.toContain( `/* Layout ${ postId } */` );
		} );
	}
} );
