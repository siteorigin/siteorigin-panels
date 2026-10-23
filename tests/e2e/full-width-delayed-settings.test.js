/**
 * Caching plugins that delay inline scripts can run styling.js before its
 * panelsStyles settings exist. The script must still clear the CSS fallback
 * and stretch full-width rows with the plugin defaults.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );

test( 'full-width rows stretch when the panelsStyles settings are missing', async ( { browser } ) => {
	const admin = await adminLogin();
	const id = await createPost( admin, 'page', { title: 'Full-width without settings', status: 'publish' } );
	const context = await browser.newContext( { viewport: { width: 1280, height: 800 }, storageState: { cookies: [], origins: [] } } );
	const rowId = `#pg-${ id }-0 > .panel-row-style`;

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
		let stripped = false;
		await context.route( target, async ( route ) => {
			const response = await route.fetch();
			let html = await response.text();
			// Model a centered theme container, as the initialization test does.
			html = html.replace( '</head>', `<style>#pl-${ id }{width:600px!important;max-width:none!important;margin:0 auto!important}</style></head>` );
			// Model a caching plugin that delays the inline settings past DOM ready.
			const before = html;
			html = html.replace( /<script[^>]*id="siteorigin-panels-front-styles-js-extra"[^>]*>[\s\S]*?<\/script>/, '' );
			stripped = html !== before;
			await route.fulfill( { response, body: html } );
		} );

		const page = await context.newPage();
		await page.goto( target );
		expect( stripped ).toBe( true );
		expect( await page.evaluate( () => typeof window.panelsStyles ) ).toBe( 'undefined' );
		await expect( page.locator( 'body' ) ).not.toHaveClass( /siteorigin-panels-before-js/ );
		const rect = await page.locator( rowId ).evaluate( ( row ) => {
			const bounds = row.getBoundingClientRect();
			return { left: bounds.left, right: bounds.right, viewport: document.documentElement.clientWidth };
		} );
		expect( rect.left ).toBeLessThanOrEqual( 1 );
		expect( rect.right ).toBeGreaterThanOrEqual( rect.viewport - 1 );
	} finally {
		await context.close();
		await deletePost( admin, 'page', id );
		await admin.context.dispose();
	}
} );
