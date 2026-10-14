/**
 * Classic editor builder: a widget's description line is printed as text. A stored widget class or
 * title that holds markup must show as letters, both on the first render and when the widget's
 * values change. Runs as an administrator.
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createPost,
	deletePost,
	newLoggedInPage,
	openClassicBuilder,
	seedLayout,
} = require( './builder-helpers' );

// The unknown-class branch of getTitle() turns every underscore into a space, so the payload has none.
const PAYLOAD = '<img src=x onerror=window.soX=1>';

const info = ( cls, id ) => ( { class: cls, grid: 0, cell: 0, id, style: {} } );

const layout = () => ( {
	widgets: [
		{ text: 'a', panels_info: info( PAYLOAD, 0 ) },
		{ title: '<b>bold</b>', text: 'b', panels_info: info( 'SiteOrigin_Widget_Editor_Widget', 1 ) },
	],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

test( 'builder prints widget descriptions as text', async ( { browser } ) => {
	const admin = await adminLogin();
	const ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
	let postId;

	try {
		postId = await createPost( admin, 'page', { title: 'Description escape' } );
		await seedLayout( admin, postId, layout(), { sanitize: false } );

		const { page } = ctx;
		const root = await openClassicBuilder( page, { postId } );
		const widgets = root.locator( '.so-widget' );
		await expect( widgets ).toHaveCount( 2 );

		const description = ( index ) => widgets.nth( index ).locator( '.description' );
		const descriptionText = async ( index ) => ( await description( index ).textContent() ).trim();

		// Unknown class: the stored class is shown as letters.
		expect( await descriptionText( 0 ) ).toBe( PAYLOAD );
		// Registered widget: cleanTitle() strips tags from title fields.
		expect( await descriptionText( 1 ) ).toBe( 'bold' );
		await expect( root.locator( '.so-widget .description img, .so-widget .description b' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => window.soX ) ).toBeUndefined();

		// Re-render path: change:values runs onModelChange, which writes the description again.
		await page.evaluate( () => {
			const view = window.jQuery( '#siteorigin-panels-metabox .so-widget' ).first().data( 'view' );
			view.model.set( 'values', { text: 'changed' } );
		} );
		expect( await descriptionText( 0 ) ).toBe( PAYLOAD );
		await expect( root.locator( '.so-widget .description img' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => window.soX ) ).toBeUndefined();
	} finally {
		if ( postId ) {
			await deletePost( admin, 'page', postId );
		}
		await ctx.context.close();
		await admin.context.dispose();
	}
} );
