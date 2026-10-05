/**
 * History dialog: the Original entry holds the layout that was loaded into the builder, so Restore
 * Version brings back the stored layout (#1397). Covers the classic editor builder, a new page, a
 * classic layout with a nested Layout Builder widget, and the SiteOrigin Layout Block.
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	addRow,
	createPost,
	deletePost,
	expectNoPageErrors,
	fieldLayout,
	layoutBlock,
	newLoggedInPage,
	openClassicBuilder,
	openWidgetDialog,
	placement,
	rawStorage,
	seedLayout,
	siteUrl,
	waitForField,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const textWidget = ( id, cell, text, widgetId = `history-e2e-${ id }` ) => ( {
	text,
	panels_info: {
		class: 'Panels_E2E_Text_Widget',
		raw: false,
		grid: 0,
		cell,
		id,
		widget_id: widgetId,
		style: {},
	},
} );

// One row, two cells, one widget in each.
const SEED = {
	widgets: [ textWidget( 0, 0, 'Keep A' ), textWidget( 1, 1, 'Keep B' ) ],
	grids: [ { cells: 2, style: {} } ],
	grid_cells: [
		{ grid: 0, index: 0, weight: 0.5, style: {} },
		{ grid: 0, index: 1, weight: 0.5, style: {} },
	],
};

// One row, one cell: a text widget and a Layout Builder widget with an inner layout.
const NESTED_SEED = {
	widgets: [
		textWidget( 0, 0, 'Keep outer' ),
		{
			panels_data: {
				widgets: [ textWidget( 0, 0, 'Keep inner', 'history-e2e-inner' ) ],
				grids: [ { cells: 1, style: {} } ],
				grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
			},
			builder_id: 'historye2e',
			panels_info: {
				class: 'SiteOrigin_Panels_Widgets_Layout',
				raw: false,
				grid: 0,
				cell: 0,
				id: 1,
				widget_id: 'history-e2e-layout',
				style: {},
			},
		},
	],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
};

const innerLayout = ( widget ) => ( typeof widget.panels_data === 'string' ? JSON.parse( widget.panels_data ) : widget.panels_data );

/**
 * The document that holds the block canvas: the editor-canvas iframe when the editor is iframed.
 */
const canvasOf = async ( page ) => ( await page.locator( 'iframe[name="editor-canvas"]' ).count() )
	? page.frameLocator( 'iframe[name="editor-canvas"]' )
	: page;

// A first visit can open the welcome guide or another editor modal over the canvas. Close them.
const closeEditorModals = async ( page ) => {
	await page.evaluate( () => {
		const prefs = window.wp.data.dispatch( 'core/preferences' );
		if ( prefs ) {
			prefs.set( 'core/edit-post', 'welcomeGuide', false );
			prefs.set( 'core', 'enableChoosePatternModal', false );
		}
	} );
	const overlay = page.locator( '.components-modal__screen-overlay' );
	for ( let i = 0; i < 5; i++ ) {
		await page.waitForTimeout( 500 );
		if ( ! ( await overlay.count() ) ) {
			return;
		}
		await page.keyboard.press( 'Escape' );
	}
	await expect( overlay ).toHaveCount( 0 );
};

const waitForEditor = ( page ) => page.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/editor' ) && wp.data.select( 'core/editor' ).getCurrentPostId() && wp.data.select( 'core/block-editor' ) );

const historyDialog = ( page ) => page.locator( '.so-panels-dialog-history' );

const entryTitles = ( page ) => historyDialog( page ).locator( '.history-entry h3' );

/**
 * Select the Original entry in the open History dialog and restore it.
 */
const restoreOriginal = async ( page ) => {
	const dialog = historyDialog( page );
	await dialog.locator( '.history-entry', { hasText: 'Original' } ).click();
	await expect( dialog.locator( '.so-restore' ) ).not.toHaveClass( /disabled/ );
	await dialog.locator( '.so-restore' ).click();
};

/**
 * Delete a widget with its Delete action and wait until it is gone.
 */
const deleteWidget = async ( root, widget ) => {
	const before = await root.locator( '.so-widget' ).count();
	await widget.hover();
	await widget.locator( '.actions .widget-delete' ).click( { force: true } );
	await expect( root.locator( '.so-widget' ) ).toHaveCount( before - 1 );
};

test.describe( 'History Original entry', () => {
	let admin;
	let ctx;
	const posts = [];

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
	} );

	test.afterAll( async () => {
		for ( const [ type, id ] of posts ) {
			await deletePost( admin, type, id );
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	test( 'H1 classic: restore Original after a delete brings back the stored layout', async () => {
		const { page } = ctx;
		const classicId = await createPost( admin, 'page', { title: 'History classic' } );
		posts.push( [ 'page', classicId ] );
		await seedLayout( admin, classicId, SEED );

		const root = await openClassicBuilder( page, { postId: classicId } );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
		await deleteWidget( root, root.locator( '.so-widget' ).first() );

		await root.locator( '.so-builder-toolbar .so-history' ).click();
		await expect( entryTitles( page ) ).toHaveText( [ 'Current', 'Widget deleted', 'Original' ] );
		await restoreOriginal( page );

		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
		await waitForField( page, ( l ) => ( l.widgets || [] ).length === 2, 'two widgets in the field' );
		expect( placement( await fieldLayout( page ) ) ).toEqual( placement( SEED ) );

		await Promise.all( [
			page.waitForURL( /message=\d+/, { timeout: 60000 } ),
			page.locator( '#publish' ).click( { noWaitAfter: true } ),
		] );
		const raw = await rawStorage( admin, classicId );
		expect( placement( raw.meta ) ).toEqual( placement( SEED ) );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'H2 classic: an unedited builder lists only Current', async () => {
		const { page } = ctx;
		const classicId = await createPost( admin, 'page', { title: 'History unedited' } );
		posts.push( [ 'page', classicId ] );
		await seedLayout( admin, classicId, SEED );

		const root = await openClassicBuilder( page, { postId: classicId } );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );

		await root.locator( '.so-builder-toolbar .so-history' ).click();
		await expect( entryTitles( page ) ).toHaveText( [ 'Current' ] );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'H3 classic, new page: Original stays empty', async () => {
		const { page } = ctx;
		const root = await openClassicBuilder( page, { postType: 'page' } );
		await addRow( root, 1 );

		await root.locator( '.so-builder-toolbar .so-history' ).click();
		await expect( entryTitles( page ) ).toHaveText( [ 'Current', 'Row added', 'Original' ] );
		await restoreOriginal( page );

		await expect( root.locator( '.so-row-container' ) ).toHaveCount( 0 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'H4 classic with a nested Layout Builder widget: Original keeps the inner layout', async () => {
		const { page } = ctx;
		const nestedId = await createPost( admin, 'page', { title: 'History nested' } );
		posts.push( [ 'page', nestedId ] );
		await seedLayout( admin, nestedId, NESTED_SEED );

		const root = await openClassicBuilder( page, { postId: nestedId } );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
		await deleteWidget( root, root.locator( '.so-widget' ).first() );

		await root.locator( '.so-builder-toolbar .so-history' ).click();
		await expect( entryTitles( page ) ).toHaveText( [ 'Current', 'Widget deleted', 'Original' ] );
		await restoreOriginal( page );

		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
		await waitForField( page, ( l ) => ( l.widgets || [] ).length === 2, 'two widgets in the field' );
		const layout = await fieldLayout( page );
		expect( layout.widgets[ 0 ].text ).toBe( 'Keep outer' );
		expect( innerLayout( layout.widgets[ 1 ] ).widgets.map( ( w ) => w.text ) ).toEqual( [ 'Keep inner' ] );

		// The nested builder shows its layout and has no History browser of its own.
		const dialog = await openWidgetDialog( root.locator( '.so-widget' ).nth( 1 ), page );
		const inner = dialog.locator( '.siteorigin-page-builder-widget' );
		await expect( inner.locator( '.so-widget', { hasText: 'Keep inner' } ) ).toHaveCount( 1, { timeout: 15000 } );
		await expect( inner.locator( '.so-builder-toolbar .so-history' ) ).toBeHidden();
		expectNoPageErrors( ctx.errors );
	} );

	test( 'H5 Layout Block: restore Original after a delete brings back the stored layout', async () => {
		const { page } = ctx;
		const blockId = await createPost( admin, 'post', { title: 'History block', status: 'publish', content: layoutBlock( SEED ) } );
		posts.push( [ 'post', blockId ] );

		await page.goto( siteUrl( `wp-admin/post.php?post=${ blockId }&action=edit` ) );
		await waitForEditor( page );
		await closeEditorModals( page );

		const canvas = await canvasOf( page );
		const root = canvas.locator( '.siteorigin-panels-layout-block-container' );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2, { timeout: 30000 } );

		// Playwright pointer actions on the builder in the canvas are not reliable: trigger with jQuery.
		const trigger = ( selector ) => page.evaluate( ( sel ) => {
			const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
			window.jQuery( frame ? frame.contentDocument : document ).find( sel ).first().trigger( 'click' );
		}, selector );

		await trigger( '.so-widget .actions .widget-delete' );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 1 );

		// Dialogs open in the editor's top document, not in the canvas.
		await trigger( '.so-builder-toolbar .so-history' );
		await expect( entryTitles( page ) ).toHaveText( [ 'Current', 'Widget deleted', 'Original' ] );
		await restoreOriginal( page );

		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
		await page.waitForFunction( () => {
			const block = wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === 'siteorigin-panels/layout-block' );
			const pd = block && block.attributes.panelsData;
			return !! pd && Array.isArray( pd.widgets ) && pd.widgets.length === 2;
		}, null, { timeout: 20000 } ).catch( () => {} );
		const texts = await page.evaluate( () => {
			const block = wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === 'siteorigin-panels/layout-block' );
			const pd = block && block.attributes.panelsData;
			return pd && Array.isArray( pd.widgets ) ? pd.widgets.map( ( w ) => w.text ) : pd;
		} );
		expect( texts ).toEqual( [ 'Keep A', 'Keep B' ] );
		expectNoPageErrors( ctx.errors );
	} );
} );
