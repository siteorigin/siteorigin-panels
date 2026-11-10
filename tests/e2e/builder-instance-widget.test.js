/**
 * A widget registered as an object (register_widget( new X() )), the way Polylang 3.9 registers its
 * Language switcher. From WordPress 7.1 the widget factory keys such a widget by spl_object_id(),
 * which changes between requests, so Page Builder must list and store it under its class. Add it,
 * check its form loads, save, check the stored class and the front end, then open it again.
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
	fieldLayout,
	newLoggedInPage,
	openClassicBuilder,
	openWidgetDialog,
	rawStorage,
	setWidgetText,
	siteUrl,
	waitForField,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const WIDGET_CLASS = 'Panels_E2E\\Instance_Widget';
const WIDGET_TITLE = 'Panels E2E Instance';
const TEXT = 'Instance widget text';

test.describe( 'widget registered as an object', () => {
	let admin;
	let ctx;
	let root;
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

	test( 'T1 add the widget and fill its form', async () => {
		const { page } = ctx;
		root = await openClassicBuilder( page, { postType: 'post' } );
		postId = Number( await page.locator( '#post_ID' ).inputValue() );
		await page.locator( '#title' ).fill( 'Builder instance widget' );

		// The object keys this test covers exist from WordPress 7.1. On an older site the
		// widget is keyed by a hash Page Builder already handles, so a pass would prove nothing.
		const version = ( await page.locator( '#footer-upgrade' ).innerText() ).match( /\d+(\.\d+)+/ );
		expect( version, 'the admin footer shows the WordPress version' ).not.toBeNull();
		const [ major, minor ] = version[ 0 ].split( '.' ).map( Number );
		expect( major > 7 || ( major === 7 && minor >= 1 ), `WordPress ${ version[ 0 ] } is 7.1 or later` ).toBe( true );

		const row = await addRow( root, 1 );
		const widget = await addWidget( root, row.locator( '.so-cells .cell' ).first(), WIDGET_CLASS );
		await expect( widget.locator( '.title h4' ) ).toHaveText( WIDGET_TITLE );

		const dialog = await openWidgetDialog( widget, page );
		await expect( dialog.locator( 'input.panels-e2e-text-field' ) ).toBeVisible( { timeout: 15000 } );
		await expect( dialog ).not.toContainText( 'is not available' );
		await closeDialog( page );
		await expectOpenDialogs( page, 0 );

		await setWidgetText( root, widget, TEXT );
		await waitForField( page, ( l ) => ( l.widgets || [] ).some( ( w ) => w.text === TEXT ), 'the widget text is in the field' );
		const layout = await fieldLayout( page );
		expect( layout.widgets ).toHaveLength( 1 );
		expect( layout.widgets[ 0 ].panels_info.class ).toBe( WIDGET_CLASS );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'T2 save stores the class', async () => {
		const { page } = ctx;
		await Promise.all( [
			page.waitForURL( /message=\d+/, { timeout: 60000 } ),
			page.locator( '#publish' ).click( { noWaitAfter: true } ),
		] );

		const raw = await rawStorage( admin, postId );
		expect( raw.meta_rows ).toBe( 1 );
		expect( raw.meta.widgets ).toHaveLength( 1 );
		expect( raw.meta.widgets[ 0 ].panels_info.class ).toBe( WIDGET_CLASS );
		expect( raw.meta.widgets[ 0 ].text ).toBe( TEXT );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'T3 front-end page', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( `?p=${ postId }` ) );
		await expect( page.locator( `#pl-${ postId } .panels-e2e-instance` ) ).toHaveText( TEXT );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'T4 open the saved widget again', async () => {
		const { page } = ctx;
		root = await openClassicBuilder( page, { postId } );
		const widget = root.locator( '.so-widget' );
		await expect( widget ).toHaveCount( 1 );
		await expect( widget.locator( '.title h4' ) ).toHaveText( WIDGET_TITLE );

		const dialog = await openWidgetDialog( widget, page );
		await expect( dialog.locator( 'input.panels-e2e-text-field' ) ).toHaveValue( TEXT, { timeout: 15000 } );
		await expect( dialog ).not.toContainText( 'is not available' );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );
} );
