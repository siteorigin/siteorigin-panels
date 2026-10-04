/**
 * Layout Builder widget in a widget area, on the classic widgets screen: build a layout, save the
 * widget, and check the stored widget option, the front-end widget area and a reload. Runs as an
 * administrator. Every option this file changes is restored after the run.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	closeDialog,
	expectNoPageErrors,
	expectOpenDialogs,
	newLoggedInPage,
	openDialog,
	setWidgetText,
	siteUrl,
	uiOption,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const TEXT = 'Widget area text';
const SIDEBAR = 'panels-e2e-sidebar';
const OPTIONS = [ 'panels_e2e_classic_widgets', 'sidebars_widgets', 'widget_siteorigin-panels-builder' ];

const innerLayout = ( instance ) => ( typeof instance.panels_data === 'string' ? JSON.parse( instance.panels_data ) : instance.panels_data );

// The widget in the test widget area on the classic widgets screen.
const sidebarWidget = ( page ) => page.locator( `#${ SIDEBAR } .widget[id*="siteorigin-panels-builder"]` );

const openWidgetBuilder = async ( page ) => {
	const widget = sidebarWidget( page );
	const button = widget.locator( '.siteorigin-panels-display-builder' );

	// A newly added widget opens with a slide; an existing one is opened with its title.
	await button.waitFor( { state: 'visible', timeout: 3000 } ).catch( () => {} );
	if ( ! ( await button.isVisible() ) ) {
		await widget.locator( '.widget-top .widget-title' ).click();
	}
	await expect( button ).toBeVisible();
	// Let the slide finish before the click.
	await expect.poll( async () => {
		const a = await button.boundingBox();
		await page.waitForTimeout( 200 );
		const b = await button.boundingBox();
		return !! a && !! b && a.y === b.y;
	} ).toBe( true );
	await button.click();
	const dialog = openDialog( page );
	await expect( dialog.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 15000 } );

	return dialog.locator( '.so-content' );
};

test.describe( 'widget area', () => {
	let admin;
	let ctx;
	const saved = {};

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		for ( const name of OPTIONS ) {
			saved[ name ] = await uiOption( admin, name );
		}
		await uiOption( admin, 'panels_e2e_classic_widgets', { value: 1 } );
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
		await admin.context.dispose();
	} );

	test( 'add the Layout Builder widget and build a layout', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( 'wp-admin/widgets.php' ) );
		const available = page.locator( '#widget-list .widget[id*="siteorigin-panels-builder"]' );
		await available.locator( '.widget-title' ).click();
		const chooser = page.locator( '.widgets-chooser' );
		await chooser.locator( 'li', { hasText: 'Panels E2E Sidebar' } ).click();
		await chooser.locator( '.widgets-chooser-add' ).click();
		await expect( sidebarWidget( page ) ).toHaveCount( 1 );

		const root = await openWidgetBuilder( page );
		const row = await addRow( root, 1, page );
		const widget = await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget', page );
		await setWidgetText( root, widget, TEXT, page );
		await closeDialog( page );
		await expectOpenDialogs( page, 0 );

		const save = sidebarWidget( page ).locator( '.widget-control-save' );
		await save.click();
		await expect( sidebarWidget( page ).locator( '.spinner.is-active' ) ).toHaveCount( 0, { timeout: 30000 } );
		await expect( save ).toBeDisabled( { timeout: 30000 } );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'stored widget option', async () => {
		const option = ( await uiOption( admin, 'widget_siteorigin-panels-builder' ) ).value;
		const numbers = Object.keys( option ).filter( ( k ) => /^\d+$/.test( k ) );
		expect( numbers ).toHaveLength( 1 );

		const layout = innerLayout( option[ numbers[ 0 ] ] );
		expect( layout.grids ).toHaveLength( 1 );
		expect( layout.widgets.map( ( w ) => w.text ) ).toEqual( [ TEXT ] );

		const sidebars = ( await uiOption( admin, 'sidebars_widgets' ) ).value;
		expect( sidebars[ SIDEBAR ] ).toEqual( [ `siteorigin-panels-builder-${ numbers[ 0 ] }` ] );
	} );

	test( 'front-end widget area', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( '?p=1' ) );
		await expect( page.locator( `#${ SIDEBAR } .panels-e2e-text`, { hasText: TEXT } ) ).toHaveCount( 1 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'reload shows the layout', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( 'wp-admin/widgets.php' ) );
		const root = await openWidgetBuilder( page );
		await expect( root.locator( '.so-row-container' ) ).toHaveCount( 1 );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 1 );
		await expect( root.locator( '.so-widget', { hasText: TEXT } ) ).toHaveCount( 1 );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );
} );
