/**
 * Custom home page (Appearance > Home Page): build a layout, switch the home page on, save, and check
 * the stored page and options, the front page and a reload. Runs as an administrator. Every option
 * this file changes is restored after the run, and the page it creates is deleted.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	deletePost,
	expectNoPageErrors,
	fieldLayout,
	newLoggedInPage,
	rawStorage,
	setWidgetText,
	siteUrl,
	uiOption,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const TEXT = 'Home page text';
const OPTIONS = [ 'panels_e2e_home_page', 'show_on_front', 'page_on_front', 'siteorigin_panels_home_page_id' ];

const openHomeBuilder = async ( page ) => {
	await page.goto( siteUrl( 'wp-admin/themes.php?page=so_panels_home_page' ) );
	const root = page.locator( '#panels-home-page .siteorigin-panels-builder-container' );
	await expect( root.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 30000 } );

	return root;
};

test.describe( 'custom home page', () => {
	let admin;
	let ctx;
	let pageId;
	let rowsBefore;
	const saved = {};

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		for ( const name of OPTIONS ) {
			saved[ name ] = await uiOption( admin, name );
		}
		await uiOption( admin, 'panels_e2e_home_page', { value: 1 } );
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
	} );

	test.afterAll( async () => {
		if ( ctx ) {
			await ctx.context.close();
		}

		for ( const name of OPTIONS ) {
			const old = saved[ name ];
			await uiOption( admin, name, old.exists ? { value: old.value } : { exists: false } );
			const now = await uiOption( admin, name );
			expect( now, `${ name } is restored` ).toEqual( old );
		}

		if ( pageId && String( pageId ) !== String( saved.page_on_front.value ) ) {
			await deletePost( admin, 'page', pageId );
		}
		await admin.context.dispose();
	} );

	test( 'build the home page layout and save', async () => {
		const { page } = ctx;
		const root = await openHomeBuilder( page );

		// The builder can start with the default home layout; the new row is added after it.
		rowsBefore = await root.locator( '.so-row-container' ).count();
		const row = await addRow( root, 1 );
		const widget = await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget' );
		await setWidgetText( root, widget, TEXT );

		const toggle = page.locator( '#panels-home-page .so-toggle-switch-input' );
		if ( ! ( await toggle.isChecked() ) ) {
			await page.locator( '#panels-home-page .so-toggle-switch' ).click();
		}
		await expect( toggle ).toBeChecked();

		await Promise.all( [
			page.waitForLoadState( 'load' ),
			page.waitForURL( /so_panels_home_page/ ),
			page.locator( '#panels-save-home-page' ).click( { noWaitAfter: true } ),
		] );
		await expect( page.locator( '#message.updated' ) ).toBeVisible( { timeout: 30000 } );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'stored page and options', async () => {
		pageId = Number( ( await uiOption( admin, 'siteorigin_panels_home_page_id' ) ).value );
		expect( pageId ).toBeGreaterThan( 0 );
		expect( ( await uiOption( admin, 'show_on_front' ) ).value ).toBe( 'page' );
		expect( Number( ( await uiOption( admin, 'page_on_front' ) ).value ) ).toBe( pageId );

		const raw = await rawStorage( admin, pageId );
		expect( raw.meta_rows ).toBe( 1 );
		expect( raw.meta.grids ).toHaveLength( rowsBefore + 1 );
		const texts = raw.meta.widgets.filter( ( w ) => w.panels_info.class === 'Panels_E2E_Text_Widget' ).map( ( w ) => w.text );
		expect( texts ).toEqual( [ TEXT ] );
	} );

	test( 'front page', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( '' ) );
		await expect( page.locator( '.panels-e2e-text', { hasText: TEXT } ) ).toHaveCount( 1 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'reload shows the layout', async () => {
		const { page } = ctx;
		const root = await openHomeBuilder( page );
		await expect( root.locator( '.so-row-container' ) ).toHaveCount( rowsBefore + 1 );
		await expect( root.locator( '.so-widget', { hasText: TEXT } ) ).toHaveCount( 1 );
		const layout = await fieldLayout( page );
		expect( layout.widgets.filter( ( w ) => w.text === TEXT ) ).toHaveLength( 1 );
		expectNoPageErrors( ctx.errors );
	} );
} );
