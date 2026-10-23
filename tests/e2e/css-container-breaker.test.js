/**
 * The CSS container breaker: a theme names its content container and its width,
 * and Page Builder stretches Full Width rows with CSS instead of the stretch
 * script. The breaker must keep a child combinator in the selector, let a
 * container with a fixed width go full width, and leave search and archive
 * pages alone when they render a layout.
 */
const { expect, test } = require( '@playwright/test' );
const { adminLogin, createPost, deletePost, seedLayout, siteUrl } = require( './builder-helpers' );

const TITLE = 'Breaker search target';
const layout = {
	widgets: [ {
		text: 'A full-width hero',
		panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0 },
	} ],
	grids: [ { cells: 1, style: { row_stretch: 'full', background: '#ff0066' } } ],
	grid_cells: [ { grid: 0, weight: 1 } ],
};

// Give the layout a theme-style container: a full-width wrapper, as a classic theme's main
// element would be, then a centered container with a fixed Bootstrap-style width. The
// wrapper escapes the test theme's constrained content layout.
const wrapLayout = async ( page, id ) => {
	await page.addStyleTag( { content: '.e2e-wrap{max-width:none!important;width:100vw;margin-left:calc(50% - 50vw)}.e2e-container{width:600px;margin:0 auto}' } );
	await page.locator( `#pl-${ id }` ).evaluate( ( layoutEl ) => {
		const wrap = document.createElement( 'div' );
		wrap.className = 'e2e-wrap';
		const container = document.createElement( 'div' );
		container.className = 'e2e-container';
		layoutEl.parentNode.insertBefore( wrap, layoutEl );
		wrap.appendChild( container );
		container.appendChild( layoutEl );
	} );
};

test.describe( 'CSS container breaker', () => {
	let admin;
	let id;

	test.beforeAll( async () => {
		admin = await adminLogin();
		id = await createPost( admin, 'post', { title: TITLE, status: 'publish' } );
		await seedLayout( admin, id, layout );
	} );

	test.afterAll( async () => {
		await deletePost( admin, 'post', id );
		await admin.context.dispose();
	} );

	test( 'a child combinator selector and a fixed-width container still stretch the row', async ( { browser } ) => {
		const context = await browser.newContext( { viewport: { width: 1280, height: 800 } } );
		try {
			const page = await context.newPage();
			await page.goto( siteUrl( `?p=${ id }&panels_e2e_css_container=1` ) );
			await expect( page.locator( 'body' ) ).toHaveClass( /siteorigin-panels-css-container/ );
			const html = await page.content();
			expect( html ).toMatch( /\.e2e-wrap > \.e2e-container[^{]*\{[^}]*max-width:none/ );
			expect( html ).not.toContain( '&gt; .e2e-container' );
			await wrapLayout( page, id );
			// The row fills the full-width wrapper, not the 600px container.
			const rect = await page.locator( `#pg-${ id }-0 > .panel-row-style` ).evaluate( ( row ) => {
				const bounds = row.getBoundingClientRect();
				const wrap = row.closest( '.e2e-wrap' ).getBoundingClientRect();
				return { left: bounds.left, right: bounds.right, wrapLeft: wrap.left, wrapRight: wrap.right, wrapWidth: wrap.width };
			} );
			expect( rect.wrapWidth ).toBeGreaterThan( 1000 );
			expect( rect.left ).toBeLessThanOrEqual( rect.wrapLeft + 1 );
			expect( rect.right ).toBeGreaterThanOrEqual( rect.wrapRight - 1 );
		} finally {
			await context.close();
		}
	} );

	test( 'a search result that renders the layout does not print the container override', async ( { browser } ) => {
		const context = await browser.newContext();
		try {
			const page = await context.newPage();
			await page.goto( siteUrl( `?s=${ encodeURIComponent( TITLE ) }&panels_e2e_css_container=1` ) );
			await expect( page.locator( 'body' ) ).toHaveClass( /search/ );
			await expect( page.locator( `#pl-${ id }` ) ).toHaveCount( 1 );
			expect( await page.content() ).not.toMatch( /\.e2e-wrap[^{]*\{[^}]*max-width:none/ );
		} finally {
			await context.close();
		}
	} );
} );
