/**
 * Layout Builder widget inside the classic editor builder: build an inner layout, save, and check the
 * stored inner layout, the front-end page and a reload. Runs as an administrator.
 *
 * Opened from a builder dialog, the Layout Builder widget shows its builder in the dialog itself.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	closeDialog,
	deletePost,
	expectNoPageErrors,
	expectOpenDialogs,
	newLoggedInPage,
	openClassicBuilder,
	openWidgetDialog,
	rawStorage,
	setWidgetText,
	siteUrl,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const TEXT = 'Nested text';

const innerLayout = ( widget ) => ( typeof widget.panels_data === 'string' ? JSON.parse( widget.panels_data ) : widget.panels_data );

test.describe( 'nested Layout Builder widget', () => {
	let admin;
	let ctx;
	let postId;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
	} );

	test.afterAll( async () => {
		if ( postId ) {
			await deletePost( admin, 'post', postId );
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	test( 'build an inner layout and publish', async () => {
		const { page } = ctx;
		const root = await openClassicBuilder( page, { postType: 'post' } );
		postId = Number( await page.locator( '#post_ID' ).inputValue() );
		await page.locator( '#title' ).fill( 'Builder nested' );

		const row = await addRow( root, 1 );
		const outer = await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'SiteOrigin_Panels_Widgets_Layout' );

		const dialog = await openWidgetDialog( outer, page );
		const inner = dialog.locator( '.siteorigin-page-builder-widget' );
		await expect( inner.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 15000 } );

		const innerRow = await addRow( inner, 1, page );
		const widget = await addWidget( inner, innerRow.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget', page );
		await setWidgetText( inner, widget, TEXT, page );
		await expect( inner.locator( '.so-widget', { hasText: TEXT } ) ).toHaveCount( 1 );

		await closeDialog( page );
		await expectOpenDialogs( page, 0 );

		await Promise.all( [
			page.waitForURL( /message=\d+/, { timeout: 60000 } ),
			page.locator( '#publish' ).click( { noWaitAfter: true } ),
		] );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'stored inner layout', async () => {
		const raw = await rawStorage( admin, postId );
		expect( raw.meta_rows ).toBe( 1 );
		expect( raw.meta.widgets ).toHaveLength( 1 );
		expect( raw.meta.widgets[ 0 ].panels_info.class ).toBe( 'SiteOrigin_Panels_Widgets_Layout' );

		const layout = innerLayout( raw.meta.widgets[ 0 ] );
		expect( layout.grids ).toHaveLength( 1 );
		expect( layout.widgets ).toHaveLength( 1 );
		expect( layout.widgets[ 0 ].text ).toBe( TEXT );
	} );

	test( 'front-end page', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( `?p=${ postId }` ) );
		await expect( page.locator( '[id^="pl-w"] .panels-e2e-text', { hasText: TEXT } ) ).toHaveCount( 1 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'reload shows the inner layout', async () => {
		const { page } = ctx;
		const root = await openClassicBuilder( page, { postId } );
		const outer = root.locator( '.so-widget' ).first();
		const dialog = await openWidgetDialog( outer, page );
		const inner = dialog.locator( '.siteorigin-page-builder-widget' );
		await expect( inner.locator( '.so-row-container' ) ).toHaveCount( 1, { timeout: 15000 } );
		await expect( inner.locator( '.so-widget' ) ).toHaveCount( 1 );
		await expect( inner.locator( '.so-widget', { hasText: TEXT } ) ).toHaveCount( 1 );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );
} );
