/**
 * A full-width row must retain its CSS fallback until the stretching script
 * initializes it. Hold the parser after wp_footer to expose the DOM-ready gap,
 * which a fast ordinary load can hide, then release it and check final geometry.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );

test( 'full-width rows do not collapse between footer parsing and DOM ready', async ( { browser } ) => {
	const admin = await adminLogin();
	const id = await createPost( admin, 'page', { title: 'Full-width initialization', status: 'publish' } );
	const context = await browser.newContext( { viewport: { width: 1280, height: 800 }, storageState: { cookies: [], origins: [] } } );
	const rowId = `#pg-${ id }-0 > .panel-row-style`;
	let release;
	let entered;
	let releaseLoad;
	let loadEntered;
	const held = new Promise( ( resolve ) => { release = resolve; } );
	const gateEntered = new Promise( ( resolve ) => { entered = resolve; } );
	const heldLoad = new Promise( ( resolve ) => { releaseLoad = resolve; } );
	const loadGateEntered = new Promise( ( resolve ) => { loadEntered = resolve; } );

	try {
		await seedLayout( admin, id, {
			widgets: [ {
				text: 'A full-width hero',
				panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0 },
			} ],
			grids: [ { cells: 1, style: { row_stretch: 'full-width-stretch', background: '#ff0066' } } ],
			grid_cells: [ { grid: 0, weight: 1 } ],
		} );

		const target = siteUrl( `?page_id=${ id }&panels_e2e_legacy_container=1` );
		const gate = siteUrl( 'cls-ready-gate.js' );
		const loadGate = siteUrl( 'cls-load-gate.png' );
		await context.route( loadGate, async ( route ) => {
			loadEntered();
			await heldLoad;
			await route.fulfill( { contentType: 'image/png', body: Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jU1cAAAAASUVORK5CYII=', 'base64' ) } );
		} );
		await context.route( gate, async ( route ) => {
			entered();
			await held;
			await route.fulfill( { contentType: 'application/javascript', body: '' } );
		} );
		await context.route( target, async ( route ) => {
			const response = await route.fetch();
			let html = await response.text();
			// Model a centered theme container, without depending on the active
			// test theme's widths. The blocker comes after all real footer hooks.
			html = html.replace( '</head>', `<style>#pl-${ id }{width:600px!important;max-width:none!important;margin:0 auto!important}.panels-e2e-text{height:600px}</style></head>` );
			html = html.replace( '</body>', `<img src="${ loadGate }" alt="" style="display:none"><script src="${ gate }"></script></body>` );
			await route.fulfill( { response, body: html } );
		} );

		const page = await context.newPage();
		await page.goto( target, { waitUntil: 'commit' } );
		await Promise.all( [ gateEntered, loadGateEntered ] );
		const geometry = async () => page.locator( rowId ).evaluate( ( row ) => {
			const rect = row.getBoundingClientRect();
			return { left: rect.left, right: rect.right, viewport: document.documentElement.clientWidth };
		} );
		const assertFullWidth = ( rect ) => {
			expect( rect.left ).toBeLessThanOrEqual( 1 );
			expect( rect.right ).toBeGreaterThanOrEqual( rect.viewport - 1 );
		};

		// DOM ready is blocked: only the CSS fallback can stretch this row.
		assertFullWidth( await geometry() );
		await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );
		release();
		await page.waitForLoadState( 'domcontentloaded' );
		await expect( page.locator( 'body' ) ).not.toHaveClass( /siteorigin-panels-before-js/ );
		assertFullWidth( await geometry() );
		await page.evaluate( () => new Promise( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );
		// Window load remains blocked: it must not be needed to correct an
		// offset measured while the CSS fallback was still active.
		assertFullWidth( await geometry() );
		releaseLoad();
		await page.waitForLoadState( 'load' );
		assertFullWidth( await geometry() );
	} finally {
		release();
		releaseLoad();
		await context.close();
		await deletePost( admin, 'page', id );
		await admin.context.dispose();
	}
} );

test( 'pages without stretched rows clear the fallback in the footer', async ( { browser } ) => {
	const admin = await adminLogin();
	const id = await createPost( admin, 'page', { title: 'Ordinary row initialization', status: 'publish' } );
	const context = await browser.newContext();

	try {
		await seedLayout( admin, id, {
			widgets: [ {
				text: 'An ordinary row',
				panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0 },
			} ],
			grids: [ { cells: 1 } ],
			grid_cells: [ { grid: 0, weight: 1 } ],
		} );

		const page = await context.newPage();
		await page.goto( siteUrl( `?page_id=${ id }` ) );
		await expect( page.locator( 'body' ) ).not.toHaveClass( /siteorigin-panels-before-js/ );
		await expect( page.locator( 'body' ) ).toHaveClass( /siteorigin-panels/ );
	} finally {
		await context.close();
		await deletePost( admin, 'page', id );
		await admin.context.dispose();
	}
} );

test( 'mixed stretch modes keep their final geometry across desktop and mobile resizes', async ( { browser } ) => {
	const admin = await adminLogin();
	const id = await createPost( admin, 'page', { title: 'Mixed full-width rows', status: 'publish' } );
	const context = await browser.newContext( { viewport: { width: 1280, height: 800 } } );
	const modes = [ 'full', 'full-stretched', 'full-width-stretch', 'full-stretched-padded' ];

	try {
		await seedLayout( admin, id, {
			widgets: modes.map( ( mode, grid ) => ( {
				text: `Row ${ mode }`,
				panels_info: { class: 'Panels_E2E_Text_Widget', grid, cell: 0, id: grid },
			} ) ),
			grids: modes.map( ( row_stretch ) => ( { cells: 1, style: { row_stretch } } ) ),
			grid_cells: modes.map( ( grid ) => ( { grid, weight: 1 } ) ),
		} );

		const target = siteUrl( `?page_id=${ id }&panels_e2e_legacy_container=1` );
		await context.route( target, async ( route ) => {
			const response = await route.fetch();
			const html = ( await response.text() ).replace(
				'</head>',
				`<style>#pl-${ id }{width:min(600px,100%)!important;max-width:none!important;margin:0 auto!important}.panels-e2e-text{height:60px}</style></head>`
			);
			await route.fulfill( { response, body: html } );
		} );

		const page = await context.newPage();
		await page.goto( target );
		for ( const width of [ 1280, 768, 390 ] ) {
			await page.setViewportSize( { width, height: 800 } );
			await expect.poll( async () => page.locator( '.siteorigin-panels-stretch.panel-row-style' ).count() ).toBe( modes.length );
			const rows = await page.locator( '.siteorigin-panels-stretch.panel-row-style' ).evaluateAll( ( elements ) => elements.map( ( element ) => {
				const rect = element.getBoundingClientRect();
				return { left: rect.left, right: rect.right, type: element.dataset.stretchType };
			} ) );
			for ( const [ index, row ] of rows.entries() ) {
				expect( row.type ).toBe( modes[ index ] );
				expect( row.left ).toBeLessThanOrEqual( 1 );
				expect( row.right ).toBeGreaterThanOrEqual( width - 1 );
			}
			await expect( page.locator( 'body' ) ).not.toHaveClass( /siteorigin-panels-before-js/ );
		}
	} finally {
		await context.close();
		await deletePost( admin, 'page', id );
		await admin.context.dispose();
	}
} );
