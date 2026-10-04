/**
 * Live Editor in the classic editor: open it, edit a widget and see the preview change, save from the
 * Live Editor, and close it without saving. Runs as an administrator.
 *
 * Then the preview binding (hover, click-to-edit, disabled links, scroll), without and with the
 * Document-Isolation-Policy that WordPress 7.1 sends on the editor screens (#1400).
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	browserLogin,
	closeDialog,
	createPost,
	deletePost,
	expectNoPageErrors,
	expectOpenDialogs,
	fieldLayout,
	newLoggedInPage,
	openClassicBuilder,
	openDialog,
	rawStorage,
	seedLayout,
	setWidgetText,
	siteUrl,
	trackPageErrors,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const OLD = 'Live text old';
const NEW = 'Live text new';
const CLOSED = 'Live text closed';

const oneWidgetLayout = ( text ) => ( {
	widgets: [ {
		text,
		panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0, widget_id: 'a0e2e000-0000-4000-8000-000000000001', style: {} },
	} ],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

// The Live Editor wrapper has no size of its own (its parts are fixed), so its tools show whether it is open.
const liveEditorTools = ( page ) => page.locator( '.so-panels-live-editor .so-sidebar-tools' );

// The preview is an iframe that the Live Editor replaces on each refresh. Read the newest one.
const previewText = async ( page ) => {
	const frames = page.locator( '.so-panels-live-editor .so-preview iframe' );
	if ( ! ( await frames.count() ) ) {
		return '';
	}

	return frames.last().contentFrame().locator( 'body' ).innerText( { timeout: 2000 } ).catch( () => '' );
};

const openLiveEditor = async ( page ) => {
	const root = await openClassicBuilder( page, { postId } );
	await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
	await expect( liveEditorTools( page ) ).toBeVisible();

	// The builder moves into the Live Editor sidebar while it is open.
	const liveRoot = page.locator( '.so-panels-live-editor .so-live-editor-builder' );
	await expect( liveRoot.locator( '.so-widget' ) ).toHaveCount( 1 );

	return liveRoot;
};

let postId;

test.describe( 'Live Editor', () => {
	let admin;
	let ctx;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		postId = await createPost( admin, 'post', { title: 'Builder live editor', status: 'publish', content: '' } );
		await seedLayout( admin, postId, oneWidgetLayout( OLD ) );
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

	test( 'open shows the preview', async () => {
		const { page } = ctx;
		await openLiveEditor( page );
		await expect.poll( () => previewText( page ), { timeout: 30000 } ).toContain( OLD );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'an edit changes the preview', async () => {
		const { page } = ctx;
		const liveRoot = page.locator( '.so-panels-live-editor .so-live-editor-builder' );
		await setWidgetText( liveRoot, liveRoot.locator( '.so-widget' ).first(), NEW, page );
		await expect.poll( () => previewText( page ), { timeout: 30000 } ).toContain( NEW );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'save from the Live Editor', async () => {
		const { page } = ctx;
		await Promise.all( [
			page.waitForURL( /message=\d+/, { timeout: 60000 } ),
			page.locator( '.so-panels-live-editor .live-editor-save' ).click(),
		] );

		const raw = await rawStorage( admin, postId );
		expect( raw.meta_rows ).toBe( 1 );
		expect( raw.meta.widgets.map( ( w ) => w.text ) ).toEqual( [ NEW ] );

		await page.goto( siteUrl( `?p=${ postId }` ) );
		await expect( page.locator( '.panels-e2e-text', { hasText: NEW } ) ).toHaveCount( 1 );
		await expect( page.getByText( OLD ) ).toHaveCount( 0 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'close keeps the change in the builder and stores nothing', async () => {
		const { page } = ctx;
		const liveRoot = await openLiveEditor( page );
		await setWidgetText( liveRoot, liveRoot.locator( '.so-widget' ).first(), CLOSED, page );
		await page.locator( '.so-panels-live-editor .live-editor-close' ).click();
		await expect( liveEditorTools( page ) ).toBeHidden();

		const layout = await fieldLayout( page );
		expect( layout.widgets.map( ( w ) => w.text ) ).toEqual( [ CLOSED ] );

		const raw = await rawStorage( admin, postId );
		expect( raw.meta.widgets.map( ( w ) => w.text ) ).toEqual( [ NEW ] );
		expectNoPageErrors( ctx.errors );
	} );
} );

/*
 * Document-Isolation-Policy (#1400). WordPress 7.1 sends Document-Isolation-Policy on the editor screens to
 * Chromium 137+. A same-origin preview without the same policy is in another agent cluster, so the editor and
 * the preview cannot script each other. The Live Editor then cannot bind, highlight, scroll or disable links.
 *
 * Playground serves 127.0.0.1, which WordPress does not treat as a secure context. The panels_e2e_isolation
 * cookie turns the policy on (tests/playground/mu-plugins/panels-e2e-live-editor.php).
 */
const ISOLATION_POLICY = 'isolate-and-credentialless';
const ISO_OLD = 'Iso one';
const ISO_NEW = 'Iso two';

const wrappedWidget = ( text, grid, cell, id ) => ( {
	text,
	panels_info: { class: 'Panels_E2E_Wrapped_Text_Widget', grid, cell, id, widget_id: `a0e2e000-0000-4000-8000-0000000002${ id }0`, style: {} },
} );

// Two rows (2 cells, 1 cell) and three wrapped widgets. The first row's bottom margin makes the page tall enough to scroll.
const wrappedLayout = () => ( {
	widgets: [
		wrappedWidget( ISO_OLD, 0, 0, 0 ),
		wrappedWidget( 'Iso B', 0, 1, 1 ),
		wrappedWidget( 'Iso C', 1, 0, 2 ),
	],
	grids: [ { cells: 2, style: { bottom_margin: '1500px' } }, { cells: 1, style: {} } ],
	grid_cells: [
		{ grid: 0, index: 0, weight: 0.5, style: {} },
		{ grid: 0, index: 1, weight: 0.5, style: {} },
		{ grid: 1, index: 0, weight: 1, style: {} },
	],
} );

const isPreviewPost = ( response ) => response.request().method() === 'POST' && response.url().includes( 'siteorigin_panels_live_editor=true' );

/**
 * Whether the site's WordPress sends Document-Isolation-Policy (7.1 and later), read from the generator
 * meta tag of the home page. Unknown (no tag) counts as yes, so the I1 guard still runs and fails loudly.
 *
 * @return {Promise<{ supported: boolean, version: string }>}
 */
const isolationSupport = async ( session ) => {
	const html = await ( await session.context.get( siteUrl( '' ) ) ).text();
	const match = html.match( /<meta name="generator" content="WordPress ([0-9.]+)/ );
	if ( ! match ) {
		return { supported: true, version: 'unknown' };
	}

	const [ major, minor ] = match[ 1 ].split( '.' ).map( Number );

	return { supported: major > 7 || ( major === 7 && minor >= 1 ), version: match[ 1 ] };
};

const notIsolatedReason = ( version ) => `environment not isolated: WordPress ${ version } sends no Document-Isolation-Policy (7.1 and later do)`;

/**
 * A new browser context with the isolation cookie set before login.
 */
const newIsolatedPage = async ( browser ) => {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	await context.addCookies( [ { name: 'panels_e2e_isolation', value: '1', url: siteUrl( '' ) } ] );
	const site = new URL( siteUrl( '' ) ).host;
	await context.route( ( url ) => url.host !== site, ( route ) => route.abort() );
	const page = await context.newPage();
	const errors = trackPageErrors( page );
	await browserLogin( page, process.env.WP_USERNAME, process.env.WP_PASSWORD );

	return { context, page, errors };
};

// The newest preview iframe, as a Playwright frame.
const previewFrame = async ( page ) => {
	const handle = await page.locator( '.so-panels-live-editor .so-preview iframe' ).last().elementHandle();

	return handle.contentFrame();
};

// Wait until the newest preview shows the text and the loading overlay is gone.
const waitForPreview = async ( page, text ) => {
	await expect.poll( () => previewText( page ), { timeout: 30000 } ).toContain( text );
	await expect( page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );

	return previewFrame( page );
};

const liveBuilder = ( page ) => page.locator( '.so-panels-live-editor .so-live-editor-builder' );

/**
 * Open the Live Editor for a post and return the preview POST response.
 */
const openLiveEditorFor = async ( page, id ) => {
	const root = await openClassicBuilder( page, { postId: id } );
	const [ response ] = await Promise.all( [
		page.waitForResponse( isPreviewPost, { timeout: 30000 } ),
		root.locator( '.so-builder-toolbar .so-live-editor' ).click(),
	] );
	await expect( liveEditorTools( page ) ).toBeVisible();
	await expect( liveBuilder( page ).locator( '.so-widget' ) ).toHaveCount( 3 );

	return response;
};

// Per preview widget: H = highlighted, F = faded, - = neither.
const previewHighlights = ( frame ) => frame.evaluate( () => Array.from( document.querySelectorAll( '.so-panel' ) ).map( ( el ) => {
	if ( el.classList.contains( 'so-panels-highlighted' ) ) {
		return 'H';
	}

	return el.classList.contains( 'so-panels-faded' ) ? 'F' : '-';
} ) );

/**
 * Hover in both directions, the preview cursor, and click-to-edit (I3 and I4).
 */
const expectBinding = async ( page ) => {
	const frame = await previewFrame( page );
	const sidebarWidgets = liveBuilder( page ).locator( '.so-widget' );

	// Sidebar to preview.
	await sidebarWidgets.nth( 0 ).hover();
	await expect.poll( () => previewHighlights( frame ), { message: 'sidebar hover highlights the preview widget' } ).toEqual( [ 'H', 'F', 'F' ] );

	// Preview to sidebar.
	await frame.locator( '.so-panel' ).nth( 1 ).hover();
	await expect( sidebarWidgets.nth( 1 ).locator( 'xpath=..' ) ).toHaveClass( /so-hovered/ );
	expect( await frame.locator( '.so-panel' ).nth( 1 ).evaluate( ( el ) => getComputedStyle( el ).cursor ) ).toBe( 'pointer' );

	// Click-to-edit.
	await frame.locator( '.so-panel' ).first().click();
	const titleBars = page.locator( '.so-panels-dialog .so-title-bar:visible' );
	await expect( titleBars ).toHaveCount( 1 );
	await expect( openDialog( page ).locator( 'input.panels-e2e-text-field' ) ).toHaveValue( ISO_OLD, { timeout: 15000 } );
	await expect( openDialog( page ).locator( '.so-sidebar .so-visual-styles .style-section-wrapper' ).first() ).toBeAttached( { timeout: 15000 } );
	await closeDialog( page );
	await expectOpenDialogs( page, 0 );
};

test.describe( 'Live Editor without isolation', () => {
	let admin;
	let ctx;
	let page;
	let wrappedPostId;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		wrappedPostId = await createPost( admin, 'post', { title: 'Live editor not isolated', status: 'publish', content: '' } );
		await seedLayout( admin, wrappedPostId, wrappedLayout() );
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
		page = ctx.page;
	} );

	test.afterAll( async () => {
		if ( wrappedPostId ) {
			await deletePost( admin, 'post', wrappedPostId );
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	test( 'N1 the preview has no isolation policy', async () => {
		const response = await openLiveEditorFor( page, wrappedPostId );
		expect( response.headers()[ 'document-isolation-policy' ] ).toBeUndefined();
		await waitForPreview( page, ISO_OLD );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'N2 hover and click-to-edit work', async () => {
		await expectBinding( page );
		expectNoPageErrors( ctx.errors );
	} );
} );

test.describe( 'Live Editor under Document-Isolation-Policy', () => {
	let admin;
	let ctx;
	let page;
	let isoPostId;
	let blockPostId;

	let support;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		support = await isolationSupport( admin );
		if ( ! support.supported ) {
			return;
		}

		isoPostId = await createPost( admin, 'post', { title: 'Live editor isolated', status: 'publish', content: '' } );
		await seedLayout( admin, isoPostId, wrappedLayout() );
		ctx = await newIsolatedPage( browser );
		page = ctx.page;
	} );

	test.afterAll( async () => {
		for ( const id of [ isoPostId, blockPostId ] ) {
			if ( id ) {
				await deletePost( admin, 'post', id );
			}
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	// Below WordPress 7.1 nothing sends the policy: skip, do not fail.
	test.beforeEach( () => {
		test.skip( ! support.supported, notIsolatedReason( support.version ) );
	} );

	test( 'I1 the editor is isolated', async () => {
		const response = await page.goto( siteUrl( `wp-admin/post.php?post=${ isoPostId }&action=edit` ) );
		expect( response.headers()[ 'document-isolation-policy' ], 'environment not isolated' ).toBe( ISOLATION_POLICY );
		expect( await page.evaluate( () => self.crossOriginIsolated ), 'environment not isolated' ).toBe( true );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'I2 the preview has the same policy and frame access', async () => {
		const response = await openLiveEditorFor( page, isoPostId );
		expect( response.headers()[ 'document-isolation-policy' ] ).toBe( ISOLATION_POLICY );

		const frame = await waitForPreview( page, ISO_OLD );
		expect( await frame.evaluate( () => ( {
			frameElement: window.frameElement !== null,
			crossOriginIsolated: self.crossOriginIsolated,
		} ) ) ).toEqual( { frameElement: true, crossOriginIsolated: true } );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'I3 and I4 hover and click-to-edit work', async () => {
		await expectBinding( page );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'I5 preview links are disabled', async () => {
		const frame = await previewFrame( page );
		const pointerEvents = await frame.evaluate( () => Array.from( document.querySelectorAll( 'a[href]' ) ).map( ( a ) => getComputedStyle( a ).pointerEvents ) );
		expect( pointerEvents.length ).toBeGreaterThan( 0 );
		expect( pointerEvents.filter( ( value ) => value !== 'none' ) ).toEqual( [] );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'I6 an edit keeps the preview scroll position', async () => {
		const frame = await previewFrame( page );

		// Scroll so the first widget stays in view: a hover on a widget that is out of view scrolls the preview.
		const y = await frame.evaluate( () => {
			const max = document.documentElement.scrollHeight - window.innerHeight;
			const top = document.querySelector( '.so-panel' ).getBoundingClientRect().top + window.scrollY;
			const target = Math.min( max, Math.max( 0, Math.round( top - 50 ) ) );
			window.scrollTo( 0, target );

			return window.scrollY;
		} );
		expect( y, 'the preview can scroll' ).toBeGreaterThan( 20 );

		await setWidgetText( liveBuilder( page ), liveBuilder( page ).locator( '.so-widget' ).first(), ISO_NEW, page );
		const next = await waitForPreview( page, ISO_NEW );
		await expect.poll( () => next.evaluate( () => window.scrollY ), { message: 'scroll kept' } ).toBeGreaterThanOrEqual( y - 2 );
		expect( Math.abs( ( await next.evaluate( () => window.scrollY ) ) - y ) ).toBeLessThanOrEqual( 2 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'I7 the History preview has the same policy and frame access', async () => {
		const isHistoryPost = ( response ) => response.request().method() === 'POST' && response.frame().name().startsWith( 'siteorigin-panels-history-iframe' );

		await Promise.all( [
			page.waitForResponse( isHistoryPost, { timeout: 30000 } ),
			liveBuilder( page ).locator( '.so-builder-toolbar .so-history' ).click(),
		] );
		const dialog = openDialog( page );
		const entries = dialog.locator( '.history-entry' );
		await expect.poll( () => entries.count() ).toBeGreaterThan( 1 );

		// Mark the document that shows the current entry, then preview the oldest entry (it is last).
		const frame = page.frames().find( ( f ) => f.name().startsWith( 'siteorigin-panels-history-iframe' ) );
		await expect.poll( () => frame.evaluate( () => document.readyState ).catch( () => '' ), { timeout: 30000 } ).toBe( 'complete' );
		await frame.evaluate( () => {
			window.__soHistoryMark = 1;
		} );
		const [ response ] = await Promise.all( [
			page.waitForResponse( isHistoryPost, { timeout: 30000 } ),
			entries.last().click(),
		] );
		expect( response.headers()[ 'document-isolation-policy' ] ).toBe( ISOLATION_POLICY );

		await expect.poll( () => frame.evaluate( () => ! window.__soHistoryMark && document.readyState === 'complete' && document.body.classList.contains( 'siteorigin-panels-live-editor' ) ).catch( () => false ), { timeout: 30000 } ).toBe( true );
		expect( await frame.evaluate( () => window.frameElement !== null ) ).toBe( true );

		await dialog.locator( '.so-close' ).first().click();
		await expectOpenDialogs( page, 0 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'I8 the Layout Block preview has the same policy and frame access', async () => {
		// A new page: the classic editor above has unsaved builder changes.
		const blockPage = await ctx.context.newPage();
		const errors = trackPageErrors( blockPage );

		const editor = await blockPage.goto( siteUrl( 'wp-admin/post-new.php?block-editor' ) );
		expect( editor.headers()[ 'document-isolation-policy' ], 'environment not isolated' ).toBe( ISOLATION_POLICY );
		await blockPage.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/editor' ) && wp.data.select( 'core/editor' ).getCurrentPostId() && wp.data.select( 'core/block-editor' ) );
		await blockPage.evaluate( () => {
			const prefs = window.wp.data.dispatch( 'core/preferences' );
			if ( prefs ) {
				prefs.set( 'core/edit-post', 'welcomeGuide', false );
				prefs.set( 'core', 'enableChoosePatternModal', false );
			}
		} );
		const overlay = blockPage.locator( '.components-modal__screen-overlay' );
		for ( let i = 0; i < 5 && await overlay.count(); i++ ) {
			await blockPage.keyboard.press( 'Escape' );
			await blockPage.waitForTimeout( 500 );
		}
		await expect( overlay ).toHaveCount( 0 );

		blockPostId = await blockPage.evaluate( () => wp.data.select( 'core/editor' ).getCurrentPostId() );
		await blockPage.evaluate( () => {
			wp.data.dispatch( 'core/editor' ).editPost( { title: 'Live editor isolated block' } );
			wp.data.dispatch( 'core/block-editor' ).insertBlock( wp.blocks.createBlock( 'siteorigin-panels/layout-block' ) );
		} );

		const canvas = ( await blockPage.locator( 'iframe[name="editor-canvas"]' ).count() )
			? blockPage.frameLocator( 'iframe[name="editor-canvas"]' )
			: blockPage;
		const root = canvas.locator( '.siteorigin-panels-layout-block-container' );
		await expect( root.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 30000 } );
		const row = await addRow( root, 1, blockPage );
		// addWidget looks the widget up by its title.
		await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'Panels E2E Wrapped Text', blockPage );

		// A new post is saved as a draft first, then the Live Editor button shows.
		const button = root.locator( '.so-builder-toolbar .so-live-editor' );
		await expect( button ).toBeVisible( { timeout: 30000 } );
		const [ response ] = await Promise.all( [
			blockPage.waitForResponse( isPreviewPost, { timeout: 30000 } ),
			button.click(),
		] );
		expect( response.headers()[ 'document-isolation-policy' ] ).toBe( ISOLATION_POLICY );
		await expect( blockPage.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );

		const frame = await previewFrame( blockPage );
		await expect.poll( () => frame.evaluate( () => document.readyState ).catch( () => '' ), { timeout: 30000 } ).toBe( 'complete' );
		expect( await frame.evaluate( () => window.frameElement !== null ) ).toBe( true );
		await expect.poll( () => frame.evaluate( () => {
			const values = Array.from( document.querySelectorAll( 'a[href]' ) ).map( ( a ) => getComputedStyle( a ).pointerEvents );

			return values.length > 0 && values.every( ( value ) => value === 'none' );
		} ), { message: 'preview links are disabled' } ).toBe( true );

		expectNoPageErrors( errors );
		expectNoPageErrors( ctx.errors );
	} );
} );

/*
 * Preview errors (#1368). A firewall or security plugin that rejects the preview request used to leave a raw
 * response (for example "Forbidden") in the preview with no message. The Live Editor now shows a message with
 * the HTTP status, or the timeout, and Retry and Close keep working.
 *
 * The panels_e2e_preview_block cookie blocks the preview before WordPress loads plugins
 * (tests/playground/mu-plugins/panels-e2e-live-editor.php).
 */
const ERROR_TEXT = 'Error text';
const ERROR_TEXT_NEW = 'Error text new';

const oneWrappedLayout = ( text ) => ( {
	widgets: [ wrappedWidget( text, 0, 0, 0 ) ],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

const errorPanel = ( page ) => page.locator( '.so-panels-live-editor .so-preview-error' );

const setCookie = ( context, name, value ) => context.addCookies( [ { name, value, url: siteUrl( '' ) } ] );

/**
 * Open the Live Editor without waiting for the preview.
 */
const openLiveEditorOnly = async ( page, id ) => {
	const root = await openClassicBuilder( page, { postId: id } );
	await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
	await expect( liveEditorTools( page ) ).toBeVisible();
	await expect( liveBuilder( page ).locator( '.so-widget' ) ).toHaveCount( 1 );
};

// The newest preview frame shows a real Page Builder preview.
const expectRealPreview = async ( page, timeout = 30000 ) => {
	await expect.poll( async () => {
		const frame = await previewFrame( page );

		return frame.evaluate( () => !! document.body && document.body.classList.contains( 'siteorigin-panels-live-editor' ) ).catch( () => false );
	}, { timeout, message: 'the preview frame holds a Live Editor preview' } ).toBe( true );
};

// The panel shows the title, the hint and exactly this reason, over a hidden loading overlay.
const expectErrorPanel = async ( page, reason, timeout = 30000 ) => {
	const panel = errorPanel( page );
	await expect( panel ).toBeVisible( { timeout } );
	await expect( panel.locator( '.so-preview-error-reason' ) ).toHaveText( reason, { timeout } );
	await expect( panel.locator( '.so-preview-error-title' ) ).toHaveText( 'This page could not be previewed.' );
	await expect( panel.locator( '.so-preview-error-hint' ) ).toBeVisible();
	await expect( page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden();
	await expect( liveBuilder( page ).locator( '.so-widget' ) ).toHaveCount( 1 );
};

test.describe( 'Live Editor preview errors', () => {
	let admin;
	let ctx;
	let page;
	let errorPostId;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		errorPostId = await createPost( admin, 'post', { title: 'Live editor preview errors', status: 'publish', content: '' } );
		await seedLayout( admin, errorPostId, oneWrappedLayout( ERROR_TEXT ) );
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
		page = ctx.page;
	} );

	test.afterEach( async () => {
		await ctx.context.clearCookies( { name: 'panels_e2e_preview_block' } );
		await ctx.context.clearCookies( { name: 'panels_e2e_preview_timeout_ms' } );
	} );

	test.afterAll( async () => {
		if ( errorPostId ) {
			await deletePost( admin, 'post', errorPostId );
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	test( 'E1 a 403 shows the status; Retry and Close work', async () => {
		await setCookie( ctx.context, 'panels_e2e_preview_block', '403' );
		await openLiveEditorOnly( page, errorPostId );
		await expectErrorPanel( page, 'The preview request failed with HTTP status 403.' );

		await ctx.context.clearCookies( { name: 'panels_e2e_preview_block' } );
		await errorPanel( page ).locator( '.so-preview-error-retry' ).click();
		await expect( errorPanel( page ) ).toBeHidden( { timeout: 30000 } );
		await expectRealPreview( page );
		await waitForPreview( page, ERROR_TEXT );
		await expect( errorPanel( page ) ).toBeHidden();

		await page.locator( '.so-panels-live-editor .live-editor-close' ).click();
		await expect( page.locator( '.so-panels-live-editor' ) ).toBeHidden();
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E2 a 403 under isolation shows the status', async ( { browser } ) => {
		const support = await isolationSupport( admin );
		test.skip( ! support.supported, notIsolatedReason( support.version ) );

		const iso = await newIsolatedPage( browser );
		try {
			const response = await iso.page.goto( siteUrl( `wp-admin/post.php?post=${ errorPostId }&action=edit` ) );
			expect( response.headers()[ 'document-isolation-policy' ], 'environment not isolated' ).toBe( ISOLATION_POLICY );

			await setCookie( iso.context, 'panels_e2e_preview_block', '403' );
			await openLiveEditorOnly( iso.page, errorPostId );
			await expectErrorPanel( iso.page, 'The preview request failed with HTTP status 403.' );
			expectNoPageErrors( iso.errors );
		} finally {
			await iso.context.close();
		}
	} );

	test( 'E3 a 200 that is not a preview shows the plain reason', async () => {
		await setCookie( ctx.context, 'panels_e2e_preview_block', '200' );
		await openLiveEditorOnly( page, errorPostId );
		await expectErrorPanel( page, 'The preview request did not return a Page Builder preview.' );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E4 a slow preview shows the timeout, then the late preview replaces it', async () => {
		await setCookie( ctx.context, 'panels_e2e_preview_block', 'timeout' );
		await setCookie( ctx.context, 'panels_e2e_preview_timeout_ms', '2000' );
		await openLiveEditorOnly( page, errorPostId );
		await expectErrorPanel( page, 'The preview did not load within 2 seconds.', 5000 );

		// No click: the late load removes the message.
		await expect( errorPanel( page ) ).toBeHidden( { timeout: 20000 } );
		await expectRealPreview( page, 20000 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E5 a working preview never shows the panel', async () => {
		await openLiveEditorOnly( page, errorPostId );
		await waitForPreview( page, ERROR_TEXT );
		await page.waitForTimeout( 1000 );
		await expect( errorPanel( page ) ).toBeHidden();

		await setWidgetText( liveBuilder( page ), liveBuilder( page ).locator( '.so-widget' ).first(), ERROR_TEXT_NEW, page );
		await waitForPreview( page, ERROR_TEXT_NEW );
		await expectRealPreview( page );
		await expect( errorPanel( page ) ).toBeHidden();
		expectNoPageErrors( ctx.errors );
	} );
} );
