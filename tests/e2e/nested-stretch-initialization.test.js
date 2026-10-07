/**
 * Nested Layout Builder widgets render Page Builder rows inside both classic
 * layouts and Layout Blocks. Stretching must measure every level after the
 * pre-JavaScript fallback is cleared, including rows inside other widgets.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );
const { layoutBlock } = require( './helpers' );

const grid = ( row_stretch = '' ) => ( { cells: 1, style: row_stretch ? { row_stretch } : {} } );
const cell = () => ( { grid: 0, index: 0, weight: 1, style: {} } );
const textWidget = () => ( {
	text: 'Deep nested content',
	panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0, style: {} },
} );
const layoutWidget = ( panels_data, id ) => ( {
	panels_data,
	builder_id: `nestedcls${ id }`,
	panels_info: { class: 'SiteOrigin_Panels_Widgets_Layout', raw: false, grid: 0, cell: 0, id, style: {} },
} );
const layout = ( widget, row_stretch = '' ) => ( {
	widgets: [ widget ],
	grids: [ grid( row_stretch ) ],
	grid_cells: [ cell() ],
} );

for ( const source of [ 'classic', 'block' ] ) {
	for ( const outerStretch of [ false, true ] ) {
		test( `${ source } layout with two nested layouts, outer stretch ${ outerStretch }`, async ( { browser } ) => {
			const admin = await adminLogin();
			const context = await browser.newContext( {
				viewport: { width: 1280, height: 800 },
				storageState: { cookies: [], origins: [] },
			} );
			let release;
			let entered;
			const held = new Promise( ( resolve ) => { release = resolve; } );
			const gateEntered = new Promise( ( resolve ) => { entered = resolve; } );
			let id;
			try {
				const deep = layout( textWidget(), 'full-width-stretch' );
				const middle = layout( layoutWidget( deep, 0 ), 'full' );
				const outer = layout( layoutWidget( middle, 0 ), outerStretch ? 'full-stretched' : '' );
				id = await createPost( admin, 'page', {
					title: `${ source } nested stretch ${ outerStretch }`,
					status: 'publish',
					...( source === 'block' ? { content: layoutBlock( outer ) } : {} ),
				} );
				if ( source === 'classic' ) {
					await seedLayout( admin, id, outer );
				}

				const target = siteUrl( `?page_id=${ id }&panels_e2e_legacy_container=1` );
				const gate = siteUrl( 'nested-cls-ready-gate.js' );
				await context.route( gate, async ( route ) => {
					entered();
					await held;
					await route.fulfill( { contentType: 'application/javascript', body: '' } );
				} );
				await context.route( target, async ( route ) => {
					const response = await route.fetch();
					let html = ( await response.text() ).replace(
						'</head>',
						'<style>.panel-layout{width:min(600px,100%)!important;max-width:none!important;margin-left:auto!important;margin-right:auto!important}.panels-e2e-text{height:120px}</style></head>'
					);
					html = html.replace( '</body>', `<script src="${ gate }"></script></body>` );
					await route.fulfill( { response, body: html } );
				} );
				const page = await context.newPage();
				const errors = [];
				page.on( 'pageerror', ( error ) => errors.push( error.message ) );
				const assertRows = async ( fallback = false ) => {
					const rows = await page.locator( '.siteorigin-panels-stretch.panel-row-style' ).evaluateAll( ( elements ) => elements.map( ( element ) => {
						const rect = element.getBoundingClientRect();
						return { type: element.dataset.stretchType, left: rect.left, right: rect.right, viewport: document.documentElement.clientWidth };
					} ) );
					expect( rows.map( ( row ) => row.type ) ).toEqual( outerStretch
						? [ source === 'block' ? 'full-width-stretch' : 'full-stretched', 'full', 'full-width-stretch' ]
						: [ 'full', 'full-width-stretch' ] );
					for ( const row of rows ) {
						if ( fallback ) {
							expect( row.left ).toBeLessThanOrEqual( 1 );
							expect( row.right ).toBeGreaterThanOrEqual( row.viewport - 1 );
						} else {
							expect( Math.abs( row.left ) ).toBeLessThanOrEqual( 1 );
							expect( Math.abs( row.right - row.viewport ) ).toBeLessThanOrEqual( 1 );
						}
					}
				};
				await page.goto( target, { waitUntil: 'commit' } );
			await gateEntered;
			// The footer has run, but DOM ready is blocked. Nested rows must
			// retain their full-width CSS fallback until JS initializes them.
			await expect( page.locator( 'body' ) ).toHaveClass( /siteorigin-panels-before-js/ );
			await assertRows( true );
				release();
				await page.waitForLoadState( 'domcontentloaded' );
				await expect( page.getByText( 'Deep nested content' ) ).toBeVisible();
				await expect( page.locator( 'body' ) ).not.toHaveClass( /siteorigin-panels-before-js/ );
				for ( const width of [ 1280, 390, 1280 ] ) {
					await page.setViewportSize( { width, height: 800 } );
					await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );
					await assertRows();
				}
				expect( errors ).toEqual( [] );
			} finally {
				release();
				await context.close();
				if ( id ) {
					await deletePost( admin, 'page', id );
				}
				await admin.context.dispose();
			}
		} );
	}
}
