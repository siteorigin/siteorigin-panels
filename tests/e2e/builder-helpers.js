/**
 * Browser helpers for the builder tests.
 *
 * The builder writes its whole state to the hidden panels_data field on every change, so a test drives
 * the real interface with the mouse and then reads the field, the database row and the front-end page.
 *
 * Dialogs are appended to the document body, not to the builder (js/siteorigin-panels/view/dialog.js),
 * so every dialog control is located in the visible .so-panels-dialog-wrapper of the document that
 * holds the builder, never under the builder root.
 *
 * Option reads and writes and layout seeding go through the test-only routes in
 * tests/playground/mu-plugins/panels-e2e-builder.php.
 */
const { expect } = require( '@playwright/test' );
const helpers = require( './helpers' );

const { rest, siteUrl } = helpers;

/**
 * Log a browser page in through the login form.
 */
const browserLogin = async ( page, username, password ) => {
	await page.goto( siteUrl( 'wp-login.php' ) );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [
		page.waitForURL( /wp-admin/, { waitUntil: 'commit', timeout: 60000 } ),
		page.locator( '#wp-submit' ).click(),
	] );

	// The first wp-admin request after activation can redirect to the Page Builder welcome page.
	await page.goto( siteUrl( 'wp-admin/index.php' ), { waitUntil: 'domcontentloaded' } );
};

/**
 * A new browser context with no stored session, logged in as the user.
 */
const newLoggedInPage = async ( browser, username, password ) => {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	// Only the test site: a slow outside request (avatars, fonts, news feeds) must not decide a result.
	const site = new URL( siteUrl( '' ) ).host;
	await context.route( ( url ) => url.host !== site, ( route ) => route.abort() );
	const page = await context.newPage();
	const errors = trackPageErrors( page );
	await browserLogin( page, username, password );

	return { context, page, errors };
};

/**
 * Create a user with the given role for a browser login. The caller deletes it with deleteUser().
 *
 * @return {Promise<{username: string, password: string, userId: number}>}
 */
const createBrowserUser = async ( adminSession, role ) => {
	const suffix = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;
	const username = `panels-e2e-ui-${ role }-${ suffix }`;
	const password = `panels-e2e-${ suffix }!A1`;
	const created = await rest( adminSession, 'POST', '/wp/v2/users', {
		data: { username, email: `${ username }@example.com`, password, roles: [ role ] },
	} );
	expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );

	return { username, password, userId: created.body.id };
};

/**
 * Collect every uncaught page error. expectNoPageErrors() fails the test when the list is not empty.
 */
const trackPageErrors = ( page ) => {
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( String( ( error && error.message ) || error ) ) );

	return errors;
};

const expectNoPageErrors = ( errors ) => {
	expect( errors, 'uncaught page errors' ).toEqual( [] );
};

/**
 * Open the classic editor with the builder, for an existing post or a new post of a type.
 */
const openClassicBuilder = async ( page, { postId, postType } ) => {
	const url = postId
		? `wp-admin/post.php?post=${ postId }&action=edit`
		: `wp-admin/post-new.php?post_type=${ postType || 'page' }&siteorigin-page-builder`;
	await page.goto( siteUrl( url ) );
	const root = builderRoot( page );
	await expect( root.locator( '.so-builder-toolbar' ).first() ).toBeVisible( { timeout: 30000 } );

	return root;
};

/**
 * The classic editor builder.
 */
const builderRoot = ( scope ) => scope.locator( '#siteorigin-panels-metabox' );

/**
 * The open dialog (visible .so-panels-dialog-wrapper) of the document or frame that holds the builder.
 */
const openDialog = ( scope ) => scope.locator( '.so-panels-dialog-wrapper' ).filter( { has: scope.locator( '.so-panels-dialog .so-title-bar:visible' ) } ).last();

const openTitleBars = ( scope ) => scope.locator( '.so-panels-dialog .so-title-bar:visible' );

/**
 * Wait until the number of open dialogs is back to `count` (0 = no dialog open).
 */
const expectOpenDialogs = ( scope, count = 0 ) => expect( openTitleBars( scope ) ).toHaveCount( count );

/**
 * The document or frame that holds a builder root, for dialog lookups.
 */
const docOf = ( root ) => root.page ? root.page() : root;

/**
 * Add a row with the given number of cells.
 */
const addRow = async ( root, cells, doc ) => {
	const scope = doc || root.page();
	const dialogsBefore = await openTitleBars( scope ).count();
	const rowsBefore = await root.locator( '.so-row-container' ).count();
	await root.locator( '.so-builder-toolbar .so-row-add' ).first().click();
	const dialog = openDialog( scope );
	await expect( dialog.locator( '#so-row-count-input' ) ).toBeVisible();
	await dialog.locator( '#so-row-count-input' ).fill( String( cells ) );
	await dialog.locator( '#so-row-count-input' ).dispatchEvent( 'change' );
	await dialog.locator( '.so-insert' ).click();
	await expect( root.locator( '.so-row-container' ) ).toHaveCount( rowsBefore + 1 );
	await expectOpenDialogs( scope, dialogsBefore );

	return root.locator( '.so-row-container' ).nth( rowsBefore );
};

/**
 * Add a widget of the given class to a cell. Returns the widget's locator in the builder.
 */
const addWidget = async ( root, cellLocator, widgetClass, doc ) => {
	const scope = doc || root.page();
	const dialogsBefore = await openTitleBars( scope ).count();
	const widgetsBefore = await cellLocator.locator( '.so-widget' ).count();

	// A click on the cell makes it the active cell: the next widget is added there.
	await cellLocator.locator( '.cell-wrapper' ).click( { position: { x: 5, y: 5 } } );
	await expect( cellLocator ).toHaveClass( /cell-selected/ );
	await root.locator( '.so-builder-toolbar .so-widget-add' ).first().click();
	const dialog = openDialog( scope );
	const type = dialog.locator( '.widget-type' ).filter( { has: scope.locator( `h3:text-is("${ WIDGET_TITLES[ widgetClass ] || widgetClass }")` ) } );
	await expect( type ).toBeVisible();
	await type.click();
	await expect( cellLocator.locator( '.so-widget' ) ).toHaveCount( widgetsBefore + 1 );

	// With instant open on (the default) the widget's edit dialog opens by itself. Close it untouched.
	await expect.poll( () => openTitleBars( scope ).count(), { timeout: 3000 } ).toBeGreaterThan( dialogsBefore ).catch( () => {} );
	if ( await openTitleBars( scope ).count() > dialogsBefore ) {
		await closeDialog( scope );
		await expectOpenDialogs( scope, dialogsBefore );
	}

	return cellLocator.locator( '.so-widget' ).nth( widgetsBefore );
};

/**
 * Close the open dialog with its Done (or Close) button.
 */
const closeDialog = async ( scope ) => {
	await openDialog( scope ).locator( '.so-toolbar .so-close' ).first().click();
};

const WIDGET_TITLES = {
	Panels_E2E_Text_Widget: 'Panels E2E Text',
	SiteOrigin_Panels_Widgets_Layout: 'Layout Builder',
};

/**
 * Open a widget's edit dialog with its Edit link and return the dialog.
 * In a narrow cell the action links cover the widget title, so the title is not clicked.
 */
const openWidgetDialog = async ( widgetLocator, scope ) => {
	const dialogsBefore = await openTitleBars( scope ).count();
	await widgetLocator.hover();
	await widgetLocator.locator( '.actions .widget-edit' ).click();
	await expect( openTitleBars( scope ) ).toHaveCount( dialogsBefore + 1 );

	return openDialog( scope );
};

/**
 * Open a widget's edit dialog, set the text field and close the dialog with Done.
 */
const setWidgetText = async ( root, widgetLocator, text, doc ) => {
	const scope = doc || root.page();
	const dialogsBefore = await openTitleBars( scope ).count();
	const dialog = await openWidgetDialog( widgetLocator, scope );
	const field = dialog.locator( 'input.panels-e2e-text-field' );
	await expect( field ).toBeVisible( { timeout: 15000 } );
	await field.fill( text );
	await closeDialog( scope );
	await expectOpenDialogs( scope, dialogsBefore );
};

/**
 * Drag with the mouse: press on the source, move to the target in steps, release.
 * jQuery UI sortable needs real intermediate mouse moves to start and to find the target.
 */
const dragTo = async ( page, source, target, { steps = 15, offsetY } = {} ) => {
	await source.scrollIntoViewIfNeeded();
	const from = await source.boundingBox();
	const to = await target.boundingBox();
	const fx = from.x + Math.min( 20, from.width / 2 );
	const fy = from.y + Math.min( 10, from.height / 2 );
	const tx = to.x + to.width / 2;
	const ty = offsetY === undefined ? to.y + to.height / 2 : to.y + offsetY;
	await page.mouse.move( fx, fy );
	await page.mouse.down();
	await page.mouse.move( fx + 5, fy + 5, { steps: 3 } );
	await page.mouse.move( tx, ty, { steps } );
	await page.mouse.move( tx, ty + 1, { steps: 2 } );
	await page.mouse.up();
};

/**
 * The builder's panels_data field, parsed.
 */
const fieldLayout = async ( scope ) => {
	const value = await scope.locator( 'input[name="panels_data"], textarea[name="panels_data"]' ).first().inputValue();

	return JSON.parse( value );
};

/**
 * Where each widget sits: one entry per widget, in layout order.
 */
const placement = ( layout ) => ( layout.widgets || [] ).map( ( widget ) => {
	const info = widget.panels_info || {};

	return {
		widget_id: info.widget_id,
		grid: Number( info.grid ),
		cell: Number( info.cell ),
		id: Number( info.id ),
		text: widget.text,
	};
} );

/**
 * Read (value undefined) or write an allow-listed option. Returns { exists, value }.
 * Writing { exists: false } deletes the option.
 */
const uiOption = async ( session, name, value ) => {
	const response = value === undefined
		? await rest( session, 'GET', '/panels-e2e/v1/ui/option', { params: { name } } )
		: await rest( session, 'POST', '/panels-e2e/v1/ui/option', { data: { name, ...value } } );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

	return response.body;
};

const seedLayout = async ( session, postId, panelsData ) => {
	const response = await rest( session, 'POST', '/panels-e2e/v1/ui/seed-layout', { data: { post_id: postId, panels_data: panelsData } } );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
};

/**
 * Wait until the builder field shows a layout that passes the check.
 */
const waitForField = async ( scope, check, message ) => {
	let last = {};
	try {
		await expect.poll( async () => check( ( last = await fieldLayout( scope ) ) ), { message, timeout: 10000 } ).toBe( true );
	} catch ( error ) {
		error.message += `\nField placement: ${ JSON.stringify( placement( last ) ) }`;
		throw error;
	}
};

module.exports = {
	...helpers,
	addRow,
	addWidget,
	browserLogin,
	builderRoot,
	createBrowserUser,
	docOf,
	dragTo,
	closeDialog,
	expectOpenDialogs,
	expectNoPageErrors,
	fieldLayout,
	newLoggedInPage,
	openClassicBuilder,
	openDialog,
	openWidgetDialog,
	placement,
	seedLayout,
	setWidgetText,
	trackPageErrors,
	uiOption,
	waitForField,
	WIDGET_TITLES,
};
