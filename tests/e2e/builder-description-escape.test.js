/**
 * Classic editor builder: a widget's description line is printed as text. A stored widget class or
 * title that holds markup must show as letters, both on the first render and when the widget's
 * values change. Stored HTML entities are decoded once, as before, and the result is still printed
 * as text. Runs as an administrator.
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
// A literal `</textarea>` and `<img>` after an entity: decoding must not parse either one.
const PROBE = '&amp;</textarea><img src=/so-probe-y>';

const info = ( cls, id ) => ( { class: cls, grid: 0, cell: 0, id, style: {} } );

const layout = () => ( {
	widgets: [
		{ text: 'a', panels_info: info( PAYLOAD, 0 ) },
		{ title: '<b>bold</b>', text: 'b', panels_info: info( 'SiteOrigin_Widget_Editor_Widget', 1 ) },
		{ text: '<p>Fish &amp; chips&nbsp;now &amp;amp;</p>', panels_info: info( 'SiteOrigin_Widget_Editor_Widget', 2 ) },
		{ text: '&lt;img src=x onerror=window.soY=1&gt;', panels_info: info( 'SiteOrigin_Widget_Editor_Widget', 3 ) },
		{ text: 'e', panels_info: info( PROBE, 4 ) },
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
		const requests = [];
		page.on( 'request', ( request ) => requests.push( request.url() ) );
		const root = await openClassicBuilder( page, { postId } );
		const widgets = root.locator( '.so-widget' );
		await expect( widgets ).toHaveCount( 5 );

		const description = ( index ) => widgets.nth( index ).locator( '.description' );
		const descriptionText = async ( index ) => ( await description( index ).textContent() ).trim();

		// Unknown class: the stored class is shown as letters.
		expect( await descriptionText( 0 ) ).toBe( PAYLOAD );
		// Registered widget: cleanTitle() strips tags from title fields.
		expect( await descriptionText( 1 ) ).toBe( 'bold' );
		await expect( root.locator( '.so-widget .description img, .so-widget .description b' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => window.soX ) ).toBeUndefined();

		// Entities decode once, as before: `&nbsp;` is a no-break space, `&amp;amp;` becomes `&amp;`.
		expect( await descriptionText( 2 ) ).toBe( 'Fish & chips\u00A0now &amp;' );
		// An encoded tag decodes to letters and is still printed as text.
		expect( await descriptionText( 3 ) ).toBe( '<img src=x onerror=window.soY=1>' );
		// A literal tag in the stored value is never parsed, before or after decoding.
		expect( await descriptionText( 4 ) ).toBe( '&</textarea><img src=/so-probe-y>' );
		await expect( root.locator( '.so-widget .description img' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => window.soY ) ).toBeUndefined();
		expect( requests.filter( ( url ) => url.includes( 'so-probe-y' ) ) ).toEqual( [] );

		// Re-render path: change:values runs onModelChange, which writes the description again.
		await page.evaluate( () => {
			const view = window.jQuery( '#siteorigin-panels-metabox .so-widget' ).first().data( 'view' );
			view.model.set( 'values', { text: 'changed' } );
		} );
		expect( await descriptionText( 0 ) ).toBe( PAYLOAD );
		await expect( root.locator( '.so-widget .description img' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => window.soX ) ).toBeUndefined();

		await page.evaluate( () => {
			const view = window.jQuery( '#siteorigin-panels-metabox .so-widget' ).eq( 2 ).data( 'view' );
			view.model.set( 'values', { text: '<p>Salt &amp; vinegar</p>' } );
		} );
		expect( await descriptionText( 2 ) ).toBe( 'Salt & vinegar' );
		expect( requests.filter( ( url ) => url.includes( 'so-probe-y' ) ) ).toEqual( [] );
	} finally {
		if ( postId ) {
			await deletePost( admin, 'page', postId );
		}
		await ctx.context.close();
		await admin.context.dispose();
	}
} );
