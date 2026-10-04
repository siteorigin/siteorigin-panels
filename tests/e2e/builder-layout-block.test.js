/**
 * SiteOrigin Layout Block in the block editor: add a row and widgets in the block's builder, save, and
 * check the stored block attribute, the front-end page and a reload. Runs as an administrator and as
 * an author.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	createBrowserUser,
	deletePost,
	deleteUser,
	expectNoPageErrors,
	newLoggedInPage,
	rawStorage,
	setWidgetText,
	siteUrl,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const TEXTS = [ 'Block text one', 'Block text two' ];

// The Layout Block render id is made with uniqid() when no builder_id is stored.
const withoutRenderId = ( value ) => JSON.stringify( value ).replace( /\bgb(\d*)-[0-9a-f]{13}\b/g, 'gb$1-UNIQID' );

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

// The builder sends each change to the server and locks saving until the answer is in the block.
const waitForBlockLayout = ( page, widgetCount ) => page.waitForFunction( ( count ) => {
	const block = wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === 'siteorigin-panels/layout-block' );
	const pd = block && block.attributes.panelsData;
	return !! pd && Array.isArray( pd.widgets ) && pd.widgets.filter( ( w ) => w.text ).length === count && ! wp.data.select( 'core/editor' ).isPostSavingLocked();
}, widgetCount, { timeout: 20000 } );

const waitForSaved = ( page ) => page.waitForFunction( () => {
	const editor = wp.data.select( 'core/editor' );
	return ! editor.isSavingPost() && ! editor.isAutosavingPost() && editor.didPostSaveRequestSucceed() && ! editor.isEditedPostDirty();
}, null, { timeout: 30000 } );

for ( const role of [ 'administrator', 'author' ] ) {
	test.describe( `Layout Block as ${ role }`, () => {
		let admin;
		let user = null;
		let ctx;
		let postId;
		let stored;

		test.beforeAll( async ( { browser } ) => {
			admin = await adminLogin();
			let username = process.env.WP_USERNAME;
			let password = process.env.WP_PASSWORD;

			if ( role !== 'administrator' ) {
				user = await createBrowserUser( admin, role );
				( { username, password } = user );
			}

			ctx = await newLoggedInPage( browser, username, password );
		} );

		test.afterAll( async () => {
			if ( postId ) {
				await deletePost( admin, 'post', postId );
			}

			if ( user ) {
				await deleteUser( admin, user.userId );
			}

			if ( ctx ) {
				await ctx.context.close();
			}
			await admin.context.dispose();
		} );

		test( 'build a layout in the block and publish', async () => {
			const { page } = ctx;
			await page.goto( siteUrl( 'wp-admin/post-new.php?block-editor' ) );
			await waitForEditor( page );
			await closeEditorModals( page );
			postId = await page.evaluate( () => wp.data.select( 'core/editor' ).getCurrentPostId() );
			await page.evaluate( ( title ) => {
				wp.data.dispatch( 'core/editor' ).editPost( { title } );
				wp.data.dispatch( 'core/block-editor' ).insertBlock( wp.blocks.createBlock( 'siteorigin-panels/layout-block' ) );
			}, `Layout Block ${ role }` );

			const canvas = await canvasOf( page );
			const root = canvas.locator( '.siteorigin-panels-layout-block-container' );
			await expect( root.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 30000 } );

			// Dialogs open in the editor's top document, not in the canvas.
			const row = await addRow( root, 2, page );
			for ( let i = 0; i < TEXTS.length; i++ ) {
				const widget = await addWidget( root, row.locator( '.so-cells .cell' ).nth( i ), 'Panels_E2E_Text_Widget', page );
				await setWidgetText( root, widget, TEXTS[ i ], page );
			}
			await waitForBlockLayout( page, 2 );

			await page.locator( '.editor-post-publish-panel__toggle, .editor-post-publish-button' ).first().click();
			const confirm = page.locator( '.editor-post-publish-panel__header-publish-button button, .editor-post-publish-panel__header-publish-button .components-button' ).first();
			await confirm.click();
			await waitForSaved( page );
			expectNoPageErrors( ctx.errors );
		} );

		test( 'stored block attribute', async () => {
			const raw = await rawStorage( admin, postId );
			expect( raw.meta_rows, 'no panels_data post meta' ).toBe( 0 );
			expect( raw.blocks ).toHaveLength( 1 );
			stored = raw.blocks[ 0 ];
			expect( stored.grids ).toHaveLength( 1 );
			expect( stored.grid_cells ).toHaveLength( 2 );
			expect( stored.widgets.map( ( w ) => w.text ) ).toEqual( TEXTS );
			expect( stored.widgets.map( ( w ) => Number( w.panels_info.cell ) ) ).toEqual( [ 0, 1 ] );
		} );

		test( 'front-end page', async () => {
			const { page } = ctx;
			await page.goto( siteUrl( `?p=${ postId }` ) );
			for ( const text of TEXTS ) {
				await expect( page.locator( '.panels-e2e-text', { hasText: text } ) ).toHaveCount( 1 );
			}
			expectNoPageErrors( ctx.errors );
		} );

		test( 'reload, valid block, save again', async () => {
			const { page } = ctx;
			await page.goto( siteUrl( `wp-admin/post.php?post=${ postId }&action=edit` ) );
			await waitForEditor( page );
			await page.waitForFunction( () => wp.data.select( 'core/block-editor' ).getBlocks().length > 0 );

			const blocks = await page.evaluate( () => wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => ( { name: b.name, isValid: b.isValid } ) ) );
			expect( blocks ).toEqual( [ { name: 'siteorigin-panels/layout-block', isValid: true } ] );

			const canvas = await canvasOf( page );
			const root = canvas.locator( '.siteorigin-panels-layout-block-container' );
			await expect( root.locator( '.so-row-container' ) ).toHaveCount( 1, { timeout: 30000 } );
			await expect( root.locator( '.so-cells .cell' ) ).toHaveCount( 2 );
			await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
			for ( const text of TEXTS ) {
				await expect( root.locator( '.so-widget', { hasText: text } ) ).toHaveCount( 1 );
			}
			await expect( canvas.locator( '.block-editor-warning' ) ).toHaveCount( 0 );
			await expect( page.locator( '.block-editor-warning' ) ).toHaveCount( 0 );

			// Make the post dirty with a title change, then save with the editor's save control.
			await page.evaluate( () => wp.data.dispatch( 'core/editor' ).editPost( { title: wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) + ' again' } ) );
			await page.locator( '.editor-post-publish-button' ).first().click();
			await waitForSaved( page );

			const raw = await rawStorage( admin, postId );
			expect( raw.meta_rows ).toBe( 0 );
			expect( raw.blocks ).toHaveLength( 1 );
			expect( withoutRenderId( raw.blocks[ 0 ] ) ).toBe( withoutRenderId( stored ) );
			expectNoPageErrors( ctx.errors );
		} );
	} );
}
