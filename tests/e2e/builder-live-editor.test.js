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
	builderRoot,
	closeDialog,
	createPost,
	deletePost,
	dragTo,
	expectNoPageErrors,
	expectOpenDialogs,
	fieldLayout,
	newLoggedInPage,
	openClassicBuilder,
	openDialog,
	openWidgetDialog,
	rawStorage,
	seedLayout,
	setWidgetText,
	siteUrl,
	trackPageErrors,
	waitForField,
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
			page.locator( '.so-panels-live-editor .live-editor-save' ).click( { noWaitAfter: true } ),
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

/**
 * In a window too short for the panel, everything in it can still be reached by scrolling with the mouse
 * wheel, as a user does (a script can set scrollTop on a panel the user cannot scroll). Content that
 * overflows at the top of a scroll container cannot be scrolled to, so the title must not start above the
 * panel's top edge; that is the check that fails for centred overflow. Content that overflows at the bottom
 * is always reachable, so Retry's position at the bottom only states that the panel scrolls that far. Then
 * Retry works (the blocking cookie is cleared first, as in E1, or Retry would get another 403).
 */
const expectErrorPanelReachable = async ( page, context ) => {
	const panel = errorPanel( page );
	const { height } = page.viewportSize();

	const size = await panel.evaluate( ( el ) => ( { scroll: el.scrollHeight, client: el.clientHeight } ) );
	expect( size.scroll, 'the window is short enough for the panel to overflow' ).toBeGreaterThan( size.client );

	const panelBox = await panel.boundingBox();
	await page.mouse.move( panelBox.x + panelBox.width / 2, panelBox.y + panelBox.height / 2 );
	await page.mouse.wheel( 0, -5000 );
	await expect.poll( () => panel.evaluate( ( el ) => el.scrollTop ), 'the wheel scrolls the panel to its top' ).toBe( 0 );
	const titleBox = await panel.locator( '.so-preview-error-title' ).boundingBox();
	expect( titleBox, 'the title is rendered' ).not.toBeNull();
	expect( titleBox.y, 'the title is not clipped above the panel' ).toBeGreaterThanOrEqual( panelBox.y );

	await page.mouse.wheel( 0, 5000 );
	await expect.poll( () => panel.evaluate( ( el ) => el.scrollHeight - el.clientHeight - el.scrollTop ), 'the wheel scrolls the panel to its bottom' ).toBeLessThanOrEqual( 1 );
	const retryBox = await panel.locator( '.so-preview-error-retry' ).boundingBox();
	expect( retryBox.y + retryBox.height, 'Retry is inside the window at the bottom of the panel' ).toBeLessThanOrEqual( height );

	await context.clearCookies( { name: 'panels_e2e_preview_block' } );
	await panel.locator( '.so-preview-error-retry' ).click( { timeout: 5000 } );
	await expect( panel ).toBeHidden( { timeout: 30000 } );
	await expectRealPreview( page );
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
			// The blocked response is in another agent cluster: the editor cannot read it.
			expect( await iso.page.locator( '.so-panels-live-editor .so-preview iframe' ).last().evaluate( ( el ) => el.contentDocument === null ) ).toBe( true );
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

	test( 'E6 at 980px and below the panel never covers Close and Save', async () => {
		await page.setViewportSize( { width: 800, height: 720 } );
		try {
			await setCookie( ctx.context, 'panels_e2e_preview_block', '403' );
			await openLiveEditorOnly( page, errorPostId );

			// The preview failed (the reason is set), but at this width the preview area, and so the panel, is hidden.
			await expect( errorPanel( page ).locator( '.so-preview-error-reason' ) ).toHaveText( 'The preview request failed with HTTP status 403.', { timeout: 30000 } );

			// A click only lands when nothing covers the button.
			await page.locator( '.so-panels-live-editor .live-editor-close' ).click( { timeout: 5000 } );
			await expect( page.locator( '.so-panels-live-editor' ) ).toBeHidden();
			await expect( errorPanel( page ) ).toBeHidden();
		} finally {
			await page.setViewportSize( { width: 1280, height: 720 } );
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E8 in a short window the panel can be scrolled to its title and Retry', async () => {
		try {
			await setCookie( ctx.context, 'panels_e2e_preview_block', '403' );
			await openLiveEditorOnly( page, errorPostId );
			await expectErrorPanel( page, 'The preview request failed with HTTP status 403.' );

			await page.setViewportSize( { width: 1280, height: 200 } );
			await expectErrorPanelReachable( page, ctx.context );
		} finally {
			await page.setViewportSize( { width: 1280, height: 720 } );
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E9 with the sidebar collapsed in a narrow, short window the panel can be scrolled to its title and Retry', async () => {
		await page.setViewportSize( { width: 800, height: 720 } );
		try {
			await setCookie( ctx.context, 'panels_e2e_preview_block', '403' );
			await openLiveEditorOnly( page, errorPostId );
			await expect( errorPanel( page ).locator( '.so-preview-error-reason' ) ).toHaveText( 'The preview request failed with HTTP status 403.', { timeout: 30000 } );

			// Collapsed, the preview area, and so the panel, shows at this width (E6 covers it hidden).
			await page.locator( '.so-panels-live-editor .live-editor-collapse' ).click();
			await expect( errorPanel( page ) ).toBeVisible();

			await page.setViewportSize( { width: 800, height: 200 } );
			await expectErrorPanelReachable( page, ctx.context );

			// The collapsed sidebar hides its own tools on purpose: reopen it, and Close works.
			await page.setViewportSize( { width: 800, height: 720 } );
			await page.locator( '.so-panels-live-editor .live-editor-collapse' ).click();
			await page.locator( '.so-panels-live-editor .live-editor-close' ).click( { timeout: 5000 } );
			await expect( page.locator( '.so-panels-live-editor' ) ).toBeHidden();
		} finally {
			await page.setViewportSize( { width: 1280, height: 720 } );
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E7 a preview without body classes still binds and shows no error', async () => {
		// Like a theme that does not call body_class(): the preview has no siteorigin-panels-live-editor class.
		await setCookie( ctx.context, 'panels_e2e_no_body_class', '1' );
		await setCookie( ctx.context, 'panels_e2e_preview_timeout_ms', '2000' );
		try {
			await openLiveEditorOnly( page, errorPostId );
			const frame = await waitForPreview( page, ERROR_TEXT );
			expect( await frame.evaluate( () => document.body.className ) ).toBe( '' );
			await page.waitForTimeout( 3000 );
			await expect( errorPanel( page ) ).toBeHidden();
			await expect( errorPanel( page ) ).not.toHaveClass( /so-active/ );

			// Bound: sidebar hover highlights the preview widget, which has the pointer cursor.
			await liveBuilder( page ).locator( '.so-widget' ).first().hover();
			await expect( frame.locator( '.so-panel' ).first() ).toHaveClass( /so-panels-highlighted/ );
			expect( await frame.locator( '.so-panel' ).first().evaluate( ( el ) => getComputedStyle( el ).cursor ) ).toBe( 'pointer' );
		} finally {
			await ctx.context.clearCookies( { name: 'panels_e2e_no_body_class' } );
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'E5 a working preview never shows the panel, and its load timer is cancelled', async () => {
		// A 2 s timer: if a working preview did not cancel it, the panel would show within the waits below.
		await setCookie( ctx.context, 'panels_e2e_preview_timeout_ms', '2000' );
		await openLiveEditorOnly( page, errorPostId );
		expect( await page.evaluate( () => window.panelsOptions.live_editor_preview_timeout ) ).toBe( 2000 );
		await waitForPreview( page, ERROR_TEXT );
		await page.waitForTimeout( 3000 );
		await expect( errorPanel( page ) ).toBeHidden();
		await expect( errorPanel( page ) ).not.toHaveClass( /so-active/ );

		await setWidgetText( liveBuilder( page ), liveBuilder( page ).locator( '.so-widget' ).first(), ERROR_TEXT_NEW, page );
		await waitForPreview( page, ERROR_TEXT_NEW );
		await expectRealPreview( page );
		await page.waitForTimeout( 3000 );
		await expect( errorPanel( page ) ).toBeHidden();
		await expect( errorPanel( page ) ).not.toHaveClass( /so-active/ );
		expectNoPageErrors( ctx.errors );
	} );
} );

/*
 * In-place changes (Phase 2). A move of rows or widgets, or a cell resize, changes the preview without a
 * reload when nothing else changed. Anything else reloads as before. In place = no new preview POST and the
 * preview window keeps a mark set before the action.
 */
const MOVE_TEXTS = [ 'Move A', 'Move B', 'Move C', 'Move D', 'Move E' ];

// row0 [A] [B, C]; row1 [D]; row2 [E].
// Row D (moved in P3) and widget B (moved in P2) have styles, so P5 shows their id-keyed CSS moves with them.
const MOVE_ROW_STYLE = { padding: '20px', bottom_margin: '45px' };
const MOVE_WIDGET_STYLE = { padding: '12px', margin: '0 0 25px 0' };

const moveLayout = () => {
	const styledB = wrappedWidget( 'Move B', 0, 1, 1 );
	styledB.panels_info.style = { ...MOVE_WIDGET_STYLE };

	return {
		widgets: [
			wrappedWidget( 'Move A', 0, 0, 0 ),
			styledB,
			wrappedWidget( 'Move C', 0, 1, 2 ),
			wrappedWidget( 'Move D', 1, 0, 3 ),
			wrappedWidget( 'Move E', 2, 0, 4 ),
		],
		grids: [ { cells: 2, style: {} }, { cells: 1, style: { ...MOVE_ROW_STYLE } }, { cells: 1, style: {} } ],
		grid_cells: [
			{ grid: 0, index: 0, weight: 0.5, style: {} },
			{ grid: 0, index: 1, weight: 0.5, style: {} },
			{ grid: 1, index: 0, weight: 1, style: {} },
			{ grid: 2, index: 0, weight: 1, style: {} },
		],
	};
};

/**
 * The preview layout, read from the DOM (ids go stale after an in-place change): per row, per cell, the
 * widget texts, and the first/last classes per widget.
 */
const previewLayout = ( frame, postId ) => frame.evaluate( ( id ) => {
	const wrapper = document.getElementById( `pl-${ id }` );

	return Array.from( wrapper.children ).filter( ( el ) => el.classList.contains( 'panel-grid' ) ).map( ( row ) => (
		Array.from( row.querySelectorAll( '.panel-grid-cell' ) ).map( ( cell ) => Array.from( cell.querySelectorAll( '.so-panel' ) ).map( ( panel, i, all ) => {
			const text = panel.querySelector( '.panels-e2e-text' ).textContent;
			const first = panel.classList.contains( 'panel-first-child' ) === ( i === 0 );
			const last = panel.classList.contains( 'panel-last-child' ) === ( i === all.length - 1 );

			return first && last ? text : `${ text } (bad first/last class)`;
		} ) )
	) );
}, postId );

// The field layout in the same shape as previewLayout().
const fieldShape = ( layout ) => layout.grids.map( ( grid, ri ) => layout.grid_cells.filter( ( c ) => Number( c.grid ) === ri ).map( ( cell ) => (
	layout.widgets.filter( ( w ) => Number( w.panels_info.grid ) === ri && Number( w.panels_info.cell ) === Number( cell.index ) ).map( ( w ) => w.text )
) ) );

/**
 * Count preview POSTs on a page.
 */
const trackPreviewPosts = ( page ) => {
	const counter = { count: 0 };
	page.on( 'request', ( request ) => {
		if ( request.method() === 'POST' && request.url().includes( 'siteorigin_panels_live_editor=true' ) ) {
			counter.count++;
		}
	} );

	return counter;
};

const markPreview = async ( page ) => ( await previewFrame( page ) ).evaluate( () => {
	window.__soMark = 1;
} );

const previewMarked = async ( page ) => ( await previewFrame( page ) ).evaluate( () => window.__soMark === 1 ).catch( () => false );

/**
 * Run an action and wait until the field passes `check`. Returns whether the preview changed in place.
 */
const actAndSettle = async ( page, counter, action, check, message ) => {
	await markPreview( page );
	const before = counter.count;
	await action();
	await waitForField( page, check, message );
	// A reload, if any, starts at once: give it time to show.
	await page.waitForTimeout( 1500 );
	await expect( page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );

	return { posts: counter.count - before, marked: await previewMarked( page ) };
};

const expectInPlace = ( result ) => expect( result, 'changed in place (no preview POST, same preview window)' ).toEqual( { posts: 0, marked: true } );
const expectReloaded = ( result ) => {
	expect( result.posts, 'a preview POST' ).toBeGreaterThan( 0 );
	expect( result.marked, 'a new preview window' ).toBe( false );
};

// Field placement of one row as texts per cell.
const fieldRow = ( layout, ri ) => fieldShape( layout )[ ri ];

/**
 * Per widget: row, cell and index in the DOM, box relative to the layout wrapper, and margin-bottom.
 * Per cell: width. Per row: margin-bottom.
 */
const previewGeometry = ( frame, postId ) => frame.evaluate( ( id ) => {
	const wrapper = document.getElementById( `pl-${ id }` );
	const origin = wrapper.getBoundingClientRect();
	const box = ( el ) => {
		const r = el.getBoundingClientRect();
		return { x: r.left - origin.left, y: r.top - origin.top, w: r.width, h: r.height };
	};
	// Padding of the style wrapper (row and widget styles render one), else of the element.
	const pad = ( el, wrapperClass ) => {
		const target = Array.from( el.children ).find( ( child ) => child.classList.contains( wrapperClass ) ) || el;
		const cs = getComputedStyle( target );
		return [ cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft ].map( parseFloat );
	};
	const out = { widgets: {}, cells: [], rows: [] };
	Array.from( wrapper.children ).filter( ( el ) => el.classList.contains( 'panel-grid' ) ).forEach( ( row, ri ) => {
		out.rows.push( { margin: parseFloat( getComputedStyle( row ).marginBottom ), pad: pad( row, 'panel-row-style' ), box: box( row ) } );
		Array.from( row.querySelectorAll( '.panel-grid-cell' ) ).forEach( ( cell, ci ) => {
			out.cells.push( { ri, ci, width: cell.getBoundingClientRect().width } );
			Array.from( cell.querySelectorAll( '.so-panel' ) ).forEach( ( panel, wi ) => {
				out.widgets[ panel.querySelector( '.panels-e2e-text' ).textContent ] = {
					ri, ci, wi, box: box( panel ), margin: parseFloat( getComputedStyle( panel ).marginBottom ), pad: pad( panel, 'panel-widget-style' ),
				};
			} );
		} );
	} );

	return out;
}, postId );

const expectGeometryClose = ( actual, expected, path = 'geometry' ) => {
	if ( typeof expected === 'number' ) {
		expect( Math.abs( actual - expected ), `${ path }: ${ actual } vs ${ expected }` ).toBeLessThanOrEqual( 1 );
		return;
	}

	if ( expected && typeof expected === 'object' ) {
		expect( Object.keys( actual ).sort(), path ).toEqual( Object.keys( expected ).sort() );
		for ( const key of Object.keys( expected ) ) {
			expectGeometryClose( actual[ key ], expected[ key ], `${ path }.${ key }` );
		}
		return;
	}

	expect( actual, path ).toEqual( expected );
};

const sidebarWidget = ( page, text ) => liveBuilder( page ).locator( '.so-widget' ).filter( { hasText: text } );
const sidebarRows = ( page ) => liveBuilder( page ).locator( '.so-row-container' );

// Drop on the upper part of the target (lands above it) or the lower part (lands below it).
const dragWidget = async ( page, text, targetText, where ) => {
	const target = sidebarWidget( page, targetText );
	const height = ( await target.boundingBox() ).height;
	await dragTo( page, sidebarWidget( page, text ).locator( '.title h4' ), target, { offsetY: where === 'below' ? height - 3 : 4 } );
};

test.describe( 'Live Editor in-place changes', () => {
	let admin;
	let ctx;
	let page;
	let movePostId;
	let counter;
	let support;
	let patched;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		support = await isolationSupport( admin );
		movePostId = await createPost( admin, 'post', { title: 'Live editor in place', status: 'publish', content: '' } );
		await seedLayout( admin, movePostId, moveLayout() );
		ctx = support.supported ? await newIsolatedPage( browser ) : await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
		page = ctx.page;
		counter = trackPreviewPosts( page );
	} );

	test.afterAll( async () => {
		if ( movePostId ) {
			await deletePost( admin, 'post', movePostId );
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	test( 'P0 open', async () => {
		const root = await openClassicBuilder( page, { postId: movePostId } );
		await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
		await expect( liveEditorTools( page ) ).toBeVisible();
		await expect( liveBuilder( page ).locator( '.so-widget' ) ).toHaveCount( 5 );
		const frame = await waitForPreview( page, 'Move E' );
		expect( await previewLayout( frame, movePostId ) ).toEqual( [ [ [ 'Move A' ], [ 'Move B', 'Move C' ] ], [ [ 'Move D' ] ], [ [ 'Move E' ] ] ] );

		// The seeded styles render: row D's padding and bottom margin, widget B's padding and margin.
		const geometry = await previewGeometry( frame, movePostId );
		expect( geometry.rows[ 1 ].pad ).toEqual( [ 20, 20, 20, 20 ] );
		expect( geometry.rows[ 1 ].margin ).toBe( 45 );
		expect( geometry.widgets[ 'Move B' ].pad ).toEqual( [ 12, 12, 12, 12 ] );
		expect( geometry.widgets[ 'Move B' ].margin ).toBe( 25 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P1 a widget moved inside its cell changes in place', async () => {
		const result = await actAndSettle( page, counter,
			() => dragWidget( page, 'Move C', 'Move B', 'above' ),
			( l ) => fieldRow( l, 0 ).join( '|' ) === 'Move A|Move C,Move B', 'C is above B' );
		expectInPlace( result );
		const frame = await previewFrame( page );
		expect( await previewLayout( frame, movePostId ) ).toEqual( fieldShape( await fieldLayout( page ) ) );
		expect( await previewLayout( frame, movePostId ) ).toEqual( [ [ [ 'Move A' ], [ 'Move C', 'Move B' ] ], [ [ 'Move D' ] ], [ [ 'Move E' ] ] ] );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P2 a widget moved to another cell changes in place', async () => {
		const result = await actAndSettle( page, counter,
			() => dragWidget( page, 'Move B', 'Move A', 'below' ),
			( l ) => fieldRow( l, 0 ).join( '|' ) === 'Move A,Move B|Move C', 'B is below A' );
		expectInPlace( result );
		const frame = await previewFrame( page );
		expect( await previewLayout( frame, movePostId ) ).toEqual( [ [ [ 'Move A', 'Move B' ], [ 'Move C' ] ], [ [ 'Move D' ] ], [ [ 'Move E' ] ] ] );

		// Hover still works both ways on the widget that changed cells. (A click opens the dialog, which
		// updates the widget model silently (#1414), so the click check is in P7.)
		const panelB = frame.locator( '.so-panel' ).filter( { hasText: 'Move B' } );
		await sidebarWidget( page, 'Move B' ).hover();
		await expect( panelB ).toHaveClass( /so-panels-highlighted/ );
		await panelB.hover();
		await expect( sidebarWidget( page, 'Move B' ).locator( 'xpath=..' ) ).toHaveClass( /so-hovered/ );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P3 a row moved above another, with the last row kept, changes in place', async () => {
		const result = await actAndSettle( page, counter,
			() => dragTo( page, sidebarRows( page ).nth( 1 ).locator( '.so-row-move' ), sidebarRows( page ).nth( 0 ), { offsetY: 4 } ),
			( l ) => JSON.stringify( fieldShape( l ) ) === JSON.stringify( [ [ [ 'Move D' ] ], [ [ 'Move A', 'Move B' ], [ 'Move C' ] ], [ [ 'Move E' ] ] ] ), 'row D is first' );
		expectInPlace( result );
		expect( await previewLayout( await previewFrame( page ), movePostId ) ).toEqual( [ [ [ 'Move D' ] ], [ [ 'Move A', 'Move B' ], [ 'Move C' ] ], [ [ 'Move E' ] ] ] );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P4 a cell resize changes in place with the model widths', async () => {
		const handle = sidebarRows( page ).nth( 1 ).locator( '.so-cells .cell' ).nth( 1 ).locator( '.resize-handle' );
		const result = await actAndSettle( page, counter, async () => {
			const box = await handle.boundingBox();
			const x = box.x + box.width / 2;
			const y = box.y + box.height / 2;
			await page.mouse.move( x, y );
			await page.mouse.down();
			await page.mouse.move( x - 10, y, { steps: 3 } );
			await page.mouse.move( x - 50, y, { steps: 10 } );
			await page.mouse.up();
		}, ( l ) => Math.abs( Number( l.grid_cells.find( ( c ) => Number( c.grid ) === 1 && Number( c.index ) === 0 ).weight ) - 0.5 ) > 0.05, 'row 2 weights changed' );
		expectInPlace( result );

		const layout = await fieldLayout( page );
		const weights = layout.grid_cells.filter( ( c ) => Number( c.grid ) === 1 ).map( ( c ) => Number( c.weight ) );
		const frame = await previewFrame( page );
		const widths = await frame.evaluate( ( id ) => {
			const row = Array.from( document.getElementById( `pl-${ id }` ).children ).filter( ( el ) => el.classList.contains( 'panel-grid' ) )[ 1 ];
			const rowWidth = row.getBoundingClientRect().width;
			return { rowWidth, cells: Array.from( row.querySelectorAll( '.panel-grid-cell' ) ).map( ( c ) => c.getBoundingClientRect().width ) };
		}, movePostId );
		// The server's width: calc(w% - ( (1 - w) * 30px ) ) with the default 30px gutter.
		weights.forEach( ( w, i ) => {
			const expected = widths.rowWidth * w - ( 1 - w ) * 30;
			expect( Math.abs( widths.cells[ i ] - expected ), `cell ${ i }: ${ widths.cells[ i ] } vs ${ expected }` ).toBeLessThanOrEqual( 1 );
		} );
		patched = await previewGeometry( frame, movePostId );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P5 the patched preview equals a fresh render of the same layout', async () => {
		expect( patched, 'P4 recorded the patched geometry' ).toBeTruthy();
		await page.locator( '.so-panels-live-editor .live-editor-close' ).click();
		await expect( liveEditorTools( page ) ).toBeHidden();
		await builderRoot( page ).locator( '.so-builder-toolbar .so-live-editor' ).click();
		await expect( liveEditorTools( page ) ).toBeVisible();
		const frame = await waitForPreview( page, 'Move E' );
		await expect.poll( async () => ( await previewFrame( page ) ).evaluate( () => window.__soMark === undefined ) ).toBe( true );

		expectGeometryClose( await previewGeometry( frame, movePostId ), patched );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P6a moving the only widget out of a cell reloads', async () => {
		const result = await actAndSettle( page, counter,
			() => dragWidget( page, 'Move C', 'Move B', 'below' ),
			( l ) => fieldRow( l, 1 ).join( '|' ) === 'Move A,Move B,Move C|', 'C is below B' );
		expectReloaded( result );
		await waitForPreview( page, 'Move C' );
		expect( await previewLayout( await previewFrame( page ), movePostId ) ).toEqual( fieldShape( await fieldLayout( page ) ) );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P6b moving the last row reloads', async () => {
		const result = await actAndSettle( page, counter,
			() => dragTo( page, sidebarRows( page ).nth( 2 ).locator( '.so-row-move' ), sidebarRows( page ).nth( 0 ), { offsetY: 4 } ),
			( l ) => fieldShape( l )[ 0 ].join( '|' ) === 'Move E', 'row E is first' );
		expectReloaded( result );
		await waitForPreview( page, 'Move E' );
		expect( await previewLayout( await previewFrame( page ), movePostId ) ).toEqual( fieldShape( await fieldLayout( page ) ) );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P6c a widget style change reloads', async () => {
		const result = await actAndSettle( page, counter, async () => {
			const dialog = await openWidgetDialog( sidebarWidget( page, 'Move A' ), page );
			await expect( dialog.locator( 'input.panels-e2e-text-field' ) ).toBeVisible( { timeout: 15000 } );
			const classField = dialog.locator( 'input[name="style[class]"]' );
			await expect( classField ).toBeAttached( { timeout: 15000 } );
			await classField.evaluate( ( el ) => {
				el.value = 'panels-e2e-extra';
				el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
			await closeDialog( page );
			await expectOpenDialogs( page, 0 );
		}, ( l ) => l.widgets.some( ( w ) => w.text === 'Move A' && w.panels_info.style && w.panels_info.style.class === 'panels-e2e-extra' ), 'A has the class' );
		expectReloaded( result );
		const frame = await waitForPreview( page, 'Move A' );
		// The style class is on the widget's style wrapper inside .so-panel.
		await expect( frame.locator( '.so-panel .panels-e2e-extra' ) ).toHaveCount( 1 );
		expectNoPageErrors( ctx.errors );
	} );

	// After P6c: opening a widget dialog updates its model silently (#1414), so the next move would reload.
	test( 'P7 after an in-place move, hover and click work on the moved widget', async () => {
		const result = await actAndSettle( page, counter,
			() => dragWidget( page, 'Move C', 'Move B', 'above' ),
			( l ) => fieldShape( l )[ 2 ].join( '|' ) === 'Move A,Move C,Move B|', 'C is above B' );
		expectInPlace( result );

		const frame = await previewFrame( page );
		const panelC = frame.locator( '.so-panel' ).filter( { hasText: 'Move C' } );

		await sidebarWidget( page, 'Move C' ).hover();
		await expect( panelC ).toHaveClass( /so-panels-highlighted/ );

		await panelC.hover();
		await expect( sidebarWidget( page, 'Move C' ).locator( 'xpath=..' ) ).toHaveClass( /so-hovered/ );

		await panelC.click();
		await expect( page.locator( '.so-panels-dialog .so-title-bar:visible' ) ).toHaveCount( 1 );
		await expect( openDialog( page ).locator( 'input.panels-e2e-text-field' ) ).toHaveValue( 'Move C', { timeout: 15000 } );
		await expect( openDialog( page ).locator( '.so-sidebar .so-visual-styles .style-section-wrapper' ).first() ).toBeAttached( { timeout: 15000 } );
		await closeDialog( page );
		await expectOpenDialogs( page, 0 );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P6d with inline styles on, a move reloads', async () => {
		// A new page: the classic editor above has unsaved builder changes. It loads the stored layout.
		await setCookie( ctx.context, 'panels_e2e_inline_styles', '1' );
		const inlinePage = await ctx.context.newPage();
		const errors = trackPageErrors( inlinePage );
		const inlineCounter = trackPreviewPosts( inlinePage );
		try {
			const root = await openClassicBuilder( inlinePage, { postId: movePostId } );
			await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
			await expect( liveEditorTools( inlinePage ) ).toBeVisible();
			await waitForPreview( inlinePage, 'Move E' );
			expect( await inlinePage.evaluate( () => !! window.panelsOptions.live_editor_inline_styles ) ).toBe( true );

			const result = await actAndSettle( inlinePage, inlineCounter,
				() => dragWidget( inlinePage, 'Move C', 'Move B', 'above' ),
				( l ) => fieldRow( l, 0 ).join( '|' ) === 'Move A|Move C,Move B', 'C is above B' );
			expectReloaded( result );
			expectNoPageErrors( errors );
		} finally {
			await ctx.context.clearCookies( { name: 'panels_e2e_inline_styles' } );
			await inlinePage.close();
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'P8 with a zero gutter, a resize changes in place and equals a fresh render', async () => {
		// A new page that loads the stored layout, with the column gutter (margin-sides) set to 0.
		await setCookie( ctx.context, 'panels_e2e_zero_gutter', '1' );
		const zeroPage = await ctx.context.newPage();
		const errors = trackPageErrors( zeroPage );
		const zeroCounter = trackPreviewPosts( zeroPage );
		try {
			const root = await openClassicBuilder( zeroPage, { postId: movePostId } );
			await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
			await expect( liveEditorTools( zeroPage ) ).toBeVisible();
			await waitForPreview( zeroPage, 'Move E' );

			const handle = sidebarRows( zeroPage ).nth( 0 ).locator( '.so-cells .cell' ).nth( 1 ).locator( '.resize-handle' );
			const result = await actAndSettle( zeroPage, zeroCounter, async () => {
				const box = await handle.boundingBox();
				const x = box.x + box.width / 2;
				const y = box.y + box.height / 2;
				await zeroPage.mouse.move( x, y );
				await zeroPage.mouse.down();
				await zeroPage.mouse.move( x - 10, y, { steps: 3 } );
				await zeroPage.mouse.move( x - 50, y, { steps: 10 } );
				await zeroPage.mouse.up();
			}, ( l ) => Math.abs( Number( l.grid_cells[ 0 ].weight ) - 0.5 ) > 0.05, 'row 1 weights changed' );
			expectInPlace( result );

			// The patch wrote a bare percent: width = w * row width.
			const frame = await previewFrame( zeroPage );
			const patchCss = await frame.evaluate( () => ( document.getElementById( 'so-live-editor-patch' ) || {} ).textContent || '' );
			expect( patchCss ).not.toContain( 'calc(' );
			const weights = ( await fieldLayout( zeroPage ) ).grid_cells.filter( ( c ) => Number( c.grid ) === 0 ).map( ( c ) => Number( c.weight ) );
			const widths = await frame.evaluate( ( id ) => {
				const row = document.getElementById( `pl-${ id }` ).querySelector( '.panel-grid' );
				return { rowWidth: row.getBoundingClientRect().width, cells: Array.from( row.querySelectorAll( '.panel-grid-cell' ) ).map( ( c ) => c.getBoundingClientRect().width ) };
			}, movePostId );
			weights.forEach( ( w, i ) => {
				expect( Math.abs( widths.cells[ i ] - widths.rowWidth * w ), `cell ${ i }` ).toBeLessThanOrEqual( 1 );
			} );
			const zeroPatched = await previewGeometry( frame, movePostId );

			await zeroPage.locator( '.so-panels-live-editor .live-editor-close' ).click();
			await expect( liveEditorTools( zeroPage ) ).toBeHidden();
			await builderRoot( zeroPage ).locator( '.so-builder-toolbar .so-live-editor' ).click();
			const fresh = await waitForPreview( zeroPage, 'Move E' );
			await expect.poll( async () => ( await previewFrame( zeroPage ) ).evaluate( () => window.__soMark === undefined ) ).toBe( true );
			expectGeometryClose( await previewGeometry( fresh, movePostId ), zeroPatched );
			expectNoPageErrors( errors );
		} finally {
			await ctx.context.clearCookies( { name: 'panels_e2e_zero_gutter' } );
			await zeroPage.close();
		}
		expectNoPageErrors( ctx.errors );
	} );
} );

/*
 * Single-widget update (Phase 3). After an edit to one allow-listed widget, the Live Editor fetches the
 * preview twice (before and after), and replaces only that widget when the two pages are the same apart
 * from it. Anything else reloads. In place = no new preview navigation and the same preview window.
 *
 * Cookies (tests/playground/mu-plugins/panels-e2e-live-editor.php): panels_e2e_swap=1 allow-lists the
 * wrapped text widget; panels_e2e_csp=1 adds a CSP to preview responses; panels_e2e_preview_block=slow
 * delays preview responses.
 */
const SWAP_PROBE = 'panels-e2e-inert-probe';

// row0 [T1, T2]; row1 [H: Custom HTML with an image that requests the probe].
const swapLayout = () => ( {
	widgets: [
		wrappedWidget( 'Swap one', 0, 0, 0 ),
		wrappedWidget( 'Swap two', 0, 0, 1 ),
		{
			title: '',
			content: `<p class="panels-e2e-html">Swap html</p><img src="/?${ SWAP_PROBE }=1" alt="">`,
			panels_info: { class: 'WP_Widget_Custom_HTML', grid: 1, cell: 0, id: 2, widget_id: 'a0e2e000-0000-4000-8000-000000000320', style: {} },
		},
	],
	grids: [ { cells: 1, style: {} }, { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} }, { grid: 1, index: 0, weight: 1, style: {} } ],
} );

// row0 [T1, R: a widget whose output changes on every render].
const randomLayout = () => ( {
	widgets: [
		wrappedWidget( 'Swap one', 0, 0, 0 ),
		{ text: '', panels_info: { class: 'Panels_E2E_Random_Widget', grid: 0, cell: 0, id: 1, widget_id: 'a0e2e000-0000-4000-8000-000000000330', style: {} } },
	],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

// row0 [CT: core Text widget, legacy mode (a plain textarea), with a link; T2].
const coreLayout = () => ( {
	widgets: [
		{
			title: '',
			text: '<p>Core one <a href="/">core link</a></p>',
			filter: true,
			visual: false,
			panels_info: { class: 'WP_Widget_Text', grid: 0, cell: 0, id: 0, widget_id: 'a0e2e000-0000-4000-8000-000000000340', style: {} },
		},
		wrappedWidget( 'Core two', 0, 0, 1 ),
	],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

/**
 * Count, on a page: preview navigations (the iframe form POST), preview fetches (the swap requests) and
 * probe image requests.
 */
const trackSwapRequests = ( page ) => {
	const counts = { nav: 0, fetch: 0, probe: 0 };
	page.on( 'request', ( request ) => {
		const url = request.url();
		if ( url.includes( `${ SWAP_PROBE }=1` ) ) {
			counts.probe++;
		}
		if ( request.method() === 'POST' && url.includes( 'siteorigin_panels_live_editor=true' ) ) {
			if ( request.isNavigationRequest() ) {
				counts.nav++;
			} else if ( request.resourceType() === 'fetch' ) {
				counts.fetch++;
			}
		}
	} );

	return counts;
};

// The preview widget whose text is `text`, as an element handle locator in the newest preview frame.
const previewPanel = async ( page, text ) => ( await previewFrame( page ) ).locator( '.so-panel' ).filter( { hasText: text } );

// outerHTML without what the Live Editor adds (the pointer cursor and the highlight classes).
const normalisedOuterHtml = ( locator ) => locator.evaluate( ( el ) => {
	const copy = el.cloneNode( true );
	copy.style.removeProperty( 'cursor' );
	if ( ! copy.getAttribute( 'style' ) ) {
		copy.removeAttribute( 'style' );
	}
	copy.classList.remove( 'so-panels-highlighted', 'so-panels-faded' );

	return copy.outerHTML;
} );

test.describe( 'Live Editor single-widget update', () => {
	let admin;
	let ctx;
	let swapPostId;
	let randomPostId;
	let corePostId;
	let support;
	let s1;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		support = await isolationSupport( admin );
		swapPostId = await createPost( admin, 'post', { title: 'Live editor swap', status: 'publish', content: '' } );
		await seedLayout( admin, swapPostId, swapLayout() );
		randomPostId = await createPost( admin, 'post', { title: 'Live editor swap random', status: 'publish', content: '' } );
		await seedLayout( admin, randomPostId, randomLayout() );
		corePostId = await createPost( admin, 'post', { title: 'Live editor swap core text', status: 'publish', content: '' } );
		await seedLayout( admin, corePostId, coreLayout() );
		ctx = support.supported ? await newIsolatedPage( browser ) : await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
	} );

	test.afterAll( async () => {
		for ( const id of [ swapPostId, randomPostId, corePostId ] ) {
			if ( id ) {
				await deletePost( admin, 'post', id );
			}
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	/**
	 * A new page with the cookies set, the Live Editor open on a post and its preview ready.
	 * The previous page is closed (it may hold unsaved builder changes).
	 */
	const openSwapPage = async ( id, cookies, readyText ) => {
		if ( s1 && s1.page && ! s1.keep ) {
			await s1.page.close();
			s1 = null;
		}
		for ( const name of [ 'panels_e2e_swap', 'panels_e2e_csp', 'panels_e2e_preview_block' ] ) {
			await ctx.context.clearCookies( { name } );
		}
		for ( const [ name, value ] of Object.entries( cookies ) ) {
			await setCookie( ctx.context, name, value );
		}

		const page = await ctx.context.newPage();
		const errors = trackPageErrors( page );
		const counts = trackSwapRequests( page );
		const root = await openClassicBuilder( page, { postId: id } );
		await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
		await expect( liveEditorTools( page ) ).toBeVisible();
		await waitForPreview( page, readyText );
		await markPreview( page );

		return { page, errors, counts };
	};

	// Edit a wrapped text widget, wait for the new text, then report what happened.
	const editAndSettle = async ( session, fromText, toText ) => {
		const before = { ...session.counts };
		await setWidgetText( liveBuilder( session.page ), sidebarWidget( session.page, fromText ), toText, session.page );
		await expect.poll( () => previewText( session.page ), { timeout: 30000 } ).toContain( toText );
		// A reload, if any, has started by now: wait for it and for any fetch to settle.
		await session.page.waitForTimeout( 1500 );
		await expect( session.page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );

		return {
			nav: session.counts.nav - before.nav,
			fetch: session.counts.fetch - before.fetch,
			probe: session.counts.probe - before.probe,
			marked: await previewMarked( session.page ),
		};
	};

	const expectSwapped = ( result ) => {
		expect( result.nav, 'no preview navigation' ).toBe( 0 );
		expect( result.marked, 'the same preview window' ).toBe( true );
		expect( result.fetch, 'the swap fetched the preview' ).toBeGreaterThan( 0 );
	};

	const expectReloadedNav = ( result ) => {
		expect( result.nav, 'a preview navigation' ).toBeGreaterThan( 0 );
		expect( result.marked, 'a new preview window' ).toBe( false );
	};

	// The core Text widget is the first widget in coreLayout().
	const coreSidebarWidget = ( page ) => liveBuilder( page ).locator( '.so-widget' ).first();

	// Set the core Text widget's text in its dialog (legacy mode: a plain textarea), then report like editAndSettle().
	const editCoreTextAndSettle = async ( session, text, marker ) => {
		const before = { ...session.counts };
		const dialog = await openWidgetDialog( coreSidebarWidget( session.page ), session.page );
		await expect( dialog.locator( '.so-sidebar .so-visual-styles .style-section-wrapper' ).first() ).toBeAttached( { timeout: 15000 } );
		const field = dialog.locator( 'textarea[name$="[text]"]' );
		await expect( field ).toBeVisible( { timeout: 15000 } );
		await field.fill( text );
		await closeDialog( session.page );
		await expectOpenDialogs( session.page, 0 );

		await expect.poll( () => previewText( session.page ), { timeout: 30000 } ).toContain( marker );
		await session.page.waitForTimeout( 1500 );
		await expect( session.page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );

		return {
			nav: session.counts.nav - before.nav,
			fetch: session.counts.fetch - before.fetch,
			marked: await previewMarked( session.page ),
		};
	};

	test( 'S3 an edit to a widget that is not allow-listed reloads', async () => {
		const session = await openSwapPage( swapPostId, {}, 'Swap html' );
		s1 = { page: session.page };
		expect( await session.page.evaluate( () => window.panelsOptions.live_editor_swap_widgets ) ).toEqual( [ 'WP_Widget_Text', 'WP_Widget_Custom_HTML' ] );

		const result = await editAndSettle( session, 'Swap one', 'Swap one S3' );
		expectReloadedNav( result );
		expect( result.fetch, 'refused by the allow list, before any request' ).toBe( 0 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S1 an edit to an allow-listed widget replaces only that widget', async () => {
		const session = await openSwapPage( swapPostId, { panels_e2e_swap: '1' }, 'Swap html' );
		s1 = { page: session.page, keep: true };

		// Duplicates and non-strings from the filter are dropped.
		expect( await session.page.evaluate( () => window.panelsOptions.live_editor_swap_widgets ) ).toEqual( [ 'WP_Widget_Text', 'WP_Widget_Custom_HTML', 'Panels_E2E_Wrapped_Text_Widget' ] );

		// The sibling keeps an expando: the swap leaves other nodes alone.
		await ( await previewPanel( session.page, 'Swap two' ) ).evaluate( ( el ) => {
			el.__soKeep = 1;
		} );

		expectSwapped( await editAndSettle( session, 'Swap one', 'Swap one S1' ) );
		const swapped = await previewPanel( session.page, 'Swap one S1' );
		await expect( swapped ).toHaveCount( 1 );
		expect( await ( await previewPanel( session.page, 'Swap two' ) ).evaluate( ( el ) => el.__soKeep ) ).toBe( 1 );
		s1.html = await normalisedOuterHtml( swapped );

		// The swapped node is bound: hover both ways, the pointer cursor, click to edit.
		await sidebarWidget( session.page, 'Swap one S1' ).hover();
		await expect( swapped ).toHaveClass( /so-panels-highlighted/ );
		await swapped.hover();
		await expect( sidebarWidget( session.page, 'Swap one S1' ).locator( 'xpath=..' ) ).toHaveClass( /so-hovered/ );
		expect( await swapped.evaluate( ( el ) => getComputedStyle( el ).cursor ) ).toBe( 'pointer' );
		await swapped.click();
		await expect( session.page.locator( '.so-panels-dialog .so-title-bar:visible' ) ).toHaveCount( 1 );
		await expect( openDialog( session.page ).locator( 'input.panels-e2e-text-field' ) ).toHaveValue( 'Swap one S1', { timeout: 15000 } );
		await expect( openDialog( session.page ).locator( '.so-sidebar .so-visual-styles .style-section-wrapper' ).first() ).toBeAttached( { timeout: 15000 } );
		await closeDialog( session.page );
		await expectOpenDialogs( session.page, 0 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S2 the swapped widget equals a fresh render', async () => {
		expect( s1 && s1.html, 'S1 recorded the swapped widget' ).toBeTruthy();
		const { page } = s1;
		await page.locator( '.so-panels-live-editor .live-editor-close' ).click();
		await expect( liveEditorTools( page ) ).toBeHidden();
		await builderRoot( page ).locator( '.so-builder-toolbar .so-live-editor' ).click();
		await waitForPreview( page, 'Swap one S1' );
		expect( await previewMarked( page ), 'a fresh render' ).toBe( false );

		expect( await normalisedOuterHtml( await previewPanel( page, 'Swap one S1' ) ) ).toBe( s1.html );
		s1.keep = false;
	} );

	test( 'S8 the parsed responses load nothing', async () => {
		const session = await openSwapPage( swapPostId, { panels_e2e_swap: '1' }, 'Swap html' );
		s1 = { page: session.page };
		expect( session.counts.probe, 'the probe image loads with the preview' ).toBeGreaterThan( 0 );

		const result = await editAndSettle( session, 'Swap one', 'Swap one S8' );
		expectSwapped( result );
		expect( result.fetch, 'two renders were fetched' ).toBe( 2 );
		expect( result.probe, 'no probe request from the parsed responses' ).toBe( 0 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S4 a Custom HTML edit that adds a script reloads', async () => {
		const session = await openSwapPage( swapPostId, { panels_e2e_swap: '1' }, 'Swap html' );
		s1 = { page: session.page };
		const before = { ...session.counts };

		const dialog = await openWidgetDialog( liveBuilder( session.page ).locator( '.so-widget' ).nth( 2 ), session.page );
		await expect( dialog.locator( '.so-sidebar .so-visual-styles .style-section-wrapper' ).first() ).toBeAttached( { timeout: 15000 } );
		const content = dialog.locator( 'textarea[name$="[content]"]' );
		await expect( content ).toBeAttached( { timeout: 15000 } );
		await content.evaluate( ( el ) => {
			el.value = '<p class="panels-e2e-html">Swap html S4</p><script>window.__soS4 = 1;</script>';
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await closeDialog( session.page );
		await expectOpenDialogs( session.page, 0 );

		await expect.poll( () => previewText( session.page ), { timeout: 30000 } ).toContain( 'Swap html S4' );
		await expect( session.page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );
		expectReloadedNav( { nav: session.counts.nav - before.nav, marked: await previewMarked( session.page ) } );
		expect( session.counts.fetch - before.fetch, 'fetched both renders, then refused the script' ).toBe( 2 );
		expect( await ( await previewFrame( session.page ) ).evaluate( () => window.__soS4 ), 'the script ran in the reloaded preview' ).toBe( 1 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S5 a preview response with a Content-Security-Policy reloads', async () => {
		const session = await openSwapPage( swapPostId, { panels_e2e_swap: '1', panels_e2e_csp: '1' }, 'Swap html' );
		s1 = { page: session.page };
		const result = await editAndSettle( session, 'Swap one', 'Swap one S5' );
		expectReloadedNav( result );
		expect( result.fetch, 'the swap was tried' ).toBeGreaterThan( 0 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S6 a page whose other output changes per render reloads', async () => {
		const session = await openSwapPage( randomPostId, { panels_e2e_swap: '1' }, 'Swap one' );
		s1 = { page: session.page };
		const result = await editAndSettle( session, 'Swap one', 'Swap one S6' );
		expectReloadedNav( result );
		expect( result.fetch, 'the swap was tried' ).toBeGreaterThan( 0 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S7 two quick edits end with the second text', async () => {
		// Slow responses keep the first swap in flight while the second edit starts.
		const session = await openSwapPage( swapPostId, { panels_e2e_swap: '1', panels_e2e_preview_block: 'slow' }, 'Swap html' );
		s1 = { page: session.page };
		const sidebar = liveBuilder( session.page );
		await setWidgetText( sidebar, sidebarWidget( session.page, 'Swap one' ), 'Quick A', session.page );
		await setWidgetText( sidebar, sidebarWidget( session.page, 'Quick A' ), 'Quick B', session.page );

		await expect.poll( () => previewText( session.page ), { timeout: 60000 } ).toContain( 'Quick B' );
		await session.page.waitForTimeout( 6000 );
		const text = await previewText( session.page );
		expect( text ).toContain( 'Quick B' );
		expect( text ).not.toContain( 'Quick A' );
		await expect( await previewPanel( session.page, 'Quick B' ) ).toHaveCount( 1 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S10 two quick edits on the reload path with 5 s responses settle on the second text', async () => {
		// A hang here must fail fast (S7 once hung for 16.8 min on the reload path).
		test.setTimeout( 100000 );
		const session = await openSwapPage( swapPostId, { panels_e2e_preview_block: 'slow5' }, 'Swap html' );
		s1 = { page: session.page };
		const before = { ...session.counts };
		const sidebar = liveBuilder( session.page );
		await setWidgetText( sidebar, sidebarWidget( session.page, 'Swap one' ), 'Slow A', session.page );
		await setWidgetText( sidebar, sidebarWidget( session.page, 'Slow A' ), 'Slow B', session.page );

		await expect.poll( () => previewText( session.page ), { timeout: 45000 } ).toContain( 'Slow B' );
		await expect( session.page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 20000 } );
		await session.page.waitForTimeout( 6000 );
		const text = await previewText( session.page );
		expect( text ).toContain( 'Slow B' );
		expect( text ).not.toContain( 'Slow A' );
		await expect( errorPanel( session.page ) ).not.toHaveClass( /so-active/ );
		expect( session.counts.fetch - before.fetch, 'the wrapped widget is not allow-listed: no swap' ).toBe( 0 );
		expect( session.counts.nav - before.nav, 'reloads' ).toBeGreaterThan( 0 );
		expectNoPageErrors( session.errors );
	} );

	test( 'S11 a core Text widget (default allow list) swaps, with links disabled and hover working', async () => {
		const session = await openSwapPage( corePostId, {}, 'Core two' );
		s1 = { page: session.page };
		expect( await session.page.evaluate( () => window.panelsOptions.live_editor_swap_widgets ) ).toEqual( [ 'WP_Widget_Text', 'WP_Widget_Custom_HTML' ] );

		const first = await editCoreTextAndSettle( session, '<p>Core one S11 <a href="/">core link</a></p>', 'Core one S11' );
		expectSwapped( first );
		expect( first.fetch, 'the first swap fetches both renders' ).toBe( 2 );

		const swapped = await previewPanel( session.page, 'Core one S11' );
		await expect( swapped ).toHaveCount( 1 );
		expect( await swapped.locator( 'a' ).evaluate( ( a ) => getComputedStyle( a ).pointerEvents ), 'the swapped link is disabled' ).toBe( 'none' );
		await coreSidebarWidget( session.page ).hover();
		await expect( swapped ).toHaveClass( /so-panels-highlighted/ );
		await swapped.hover();
		await expect( coreSidebarWidget( session.page ).locator( 'xpath=..' ) ).toHaveClass( /so-hovered/ );

		// A second edit reuses the last swapped-in render as its "before".
		const second = await editCoreTextAndSettle( session, '<p>Core one S11 again <a href="/">core link</a></p>', 'Core one S11 again' );
		expectSwapped( second );
		expect( second.fetch, 'the second swap fetches only the new render' ).toBe( 1 );
		const html = await normalisedOuterHtml( await previewPanel( session.page, 'Core one S11 again' ) );

		// Equal to a fresh render.
		await session.page.locator( '.so-panels-live-editor .live-editor-close' ).click();
		await expect( liveEditorTools( session.page ) ).toBeHidden();
		await builderRoot( session.page ).locator( '.so-builder-toolbar .so-live-editor' ).click();
		await waitForPreview( session.page, 'Core one S11 again' );
		expect( await previewMarked( session.page ), 'a fresh render' ).toBe( false );
		expect( await normalisedOuterHtml( await previewPanel( session.page, 'Core one S11 again' ) ) ).toBe( html );
		expectNoPageErrors( session.errors );
	} );

	test( 'S12 a shortcode, a bare URL, an iframe or noscript in a core Text widget reloads', async () => {
		const session = await openSwapPage( corePostId, {}, 'Core two' );
		s1 = { page: session.page };
		const embedUrl = siteUrl( `?p=${ swapPostId }` );
		// The check also reads the values the preview shows, so a case after a shortcode or a URL starts from plain text.
		const cases = [
			// Refused after both renders: the new widget markup needs document setup.
			{ name: 'iframe', text: '<p>Core one iframe</p><iframe src="about:blank" title="x"></iframe>', marker: 'Core one iframe', fetch: 2 },
			{ name: 'noscript', text: '<p>Core one noscript</p><noscript><p>Core noscript body</p></noscript>', marker: 'Core one noscript', fetch: 2 },
			// Refused before any request: the new values hold a shortcode.
			{ name: 'shortcode', text: '<p>Core one shortcode</p>[video src="/wp-content/panels-e2e.mp4"]', marker: 'Core one shortcode', fetch: 0 },
			// Back to plain text: refused before any request, because the preview shows the shortcode.
			{ name: 'plain after shortcode', text: '<p>Core one plain</p>', marker: 'Core one plain', fetch: 0 },
			// Refused before any request: the new values hold a URL alone on a line (an auto-embed).
			{ name: 'bare URL', text: `<p>Core one url</p>\n${ embedUrl }`, marker: 'Core one url', fetch: 0 },
		];

		for ( const item of cases ) {
			await markPreview( session.page );
			const result = await editCoreTextAndSettle( session, item.text, item.marker );
			expect( { name: item.name, nav: result.nav > 0, marked: result.marked, fetch: result.fetch } ).toEqual( { name: item.name, nav: true, marked: false, fetch: item.fetch } );
		}
		expectNoPageErrors( session.errors );
	} );

	test( 'S9 after an in-place move, an edit reloads', async () => {
		const session = await openSwapPage( swapPostId, { panels_e2e_swap: '1' }, 'Swap html' );
		s1 = { page: session.page };
		const before = { ...session.counts };
		await dragWidget( session.page, 'Swap one', 'Swap two', 'below' );
		await waitForField( session.page, ( l ) => fieldShape( l )[ 0 ][ 0 ].join( ',' ) === 'Swap two,Swap one', 'T1 is below T2' );
		await session.page.waitForTimeout( 1500 );
		expect( { nav: session.counts.nav - before.nav, marked: await previewMarked( session.page ) }, 'the move was in place' ).toEqual( { nav: 0, marked: true } );

		const result = await editAndSettle( session, 'Swap one', 'Swap one S9' );
		expectReloadedNav( result );
		expect( result.fetch, 'refused after the in-place move, before any request' ).toBe( 0 );
		expectNoPageErrors( session.errors );
		await session.page.close();
		s1 = null;
	} );
} );

/*
 * Style-only update (#1426). After a style edit to a row, cell or widget, the Live Editor fetches the
 * preview twice (before and after), and when the two pages are the same apart from the layout CSS it
 * replaces that CSS in the open preview. Anything else reloads.
 *
 * In place = no preview navigation and the same preview window; the two fetches are the compare's own.
 * Every in-place result is compared with a fresh render of the same data: the computed style of every
 * element under the layout wrapper, at a desktop, a tablet and a mobile width.
 *
 * Cookies (tests/playground/mu-plugins/panels-e2e-live-editor.php): panels_e2e_tablet_layout,
 * panels_e2e_css_footer, panels_e2e_panels_style, panels_e2e_inline_styles, panels_e2e_csp,
 * panels_e2e_preview_block.
 */
const STYLE_COOKIES = [ 'panels_e2e_tablet_layout', 'panels_e2e_css_footer', 'panels_e2e_panels_style', 'panels_e2e_inline_styles', 'panels_e2e_csp', 'panels_e2e_preview_block' ];
const PAD = '12px 12px 12px 12px';
const PAD2 = '30px 30px 30px 30px';

// row0 [S1 (styled widget) and U3 (plain), U1 (plain widget)]; row1 cell padded; row1 [S2] with a styled row; row2 [U2] plain.
const styleLayout = ( widgetClass = 'Panels_E2E_Wrapped_Text_Widget' ) => {
	const w = ( text, grid, cell, id, style = {} ) => {
		const out = wrappedWidget( text, grid, cell, id );
		out.panels_info.class = widgetClass;
		out.panels_info.style = style;

		return out;
	};

	return {
		widgets: [
			w( 'Style S1', 0, 0, 0, { padding: PAD, background: '#ddeeff', background_display: 'color' } ),
			w( 'Style U3', 0, 0, 1 ),
			w( 'Style U1', 0, 1, 2 ),
			w( 'Style S2', 1, 0, 3 ),
			w( 'Style U2', 2, 0, 4 ),
		],
		grids: [
			{ cells: 2, style: {} },
			{ cells: 1, style: { padding: PAD, background: '#eeeeee', background_display: 'color' } },
			{ cells: 1, style: {} },
		],
		grid_cells: [
			{ grid: 0, index: 0, weight: 0.5, style: {} },
			{ grid: 0, index: 1, weight: 0.5, style: {} },
			{ grid: 1, index: 0, weight: 1, style: { padding: '8px 8px 8px 8px' } },
			{ grid: 2, index: 0, weight: 1, style: {} },
		],
	};
};

test.describe( 'Live Editor style-only update', () => {
	let admin;
	let ctx;
	let session = null;
	const posts = {};

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
	} );

	test.afterAll( async () => {
		for ( const id of Object.values( posts ) ) {
			await deletePost( admin, 'post', id );
		}

		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	// A post seeded once per name.
	const post = async ( name, layout ) => {
		if ( ! posts[ name ] ) {
			posts[ name ] = await createPost( admin, 'post', { title: `Live editor style ${ name }`, status: 'publish', content: '' } );
			await seedLayout( admin, posts[ name ], layout );
		}

		return posts[ name ];
	};

	// A new page with the cookies set and the Live Editor open on the post. The previous page is closed.
	const open = async ( name, layout, cookies = {}, readyText = 'Style U2' ) => {
		if ( session ) {
			await session.page.close();
			session = null;
		}
		for ( const cookie of STYLE_COOKIES ) {
			await ctx.context.clearCookies( { name: cookie } );
		}
		for ( const [ cookie, value ] of Object.entries( cookies ) ) {
			await setCookie( ctx.context, cookie, value );
		}

		const id = await post( name, layout );
		const page = await ctx.context.newPage();
		const errors = trackPageErrors( page );
		const counts = trackSwapRequests( page );
		let url = null;
		page.on( 'request', ( request ) => {
			if ( ! url && request.method() === 'POST' && request.url().includes( 'siteorigin_panels_live_editor=true' ) ) {
				url = request.url();
			}
		} );
		const root = await openClassicBuilder( page, { postId: id } );
		await root.locator( '.so-builder-toolbar .so-live-editor' ).click();
		await expect( liveEditorTools( page ) ).toBeVisible();
		await waitForPreview( page, readyText );
		await markPreview( page );
		session = { page, errors, counts, id, getUrl: () => url };

		return session;
	};

	// Set one style field of a row, cell or widget in its dialog, then close it. kind: widget, row or cell.
	const setStyle = async ( s, kind, which, field, value ) => {
		const { page } = s;
		let dialog;
		if ( kind === 'widget' ) {
			dialog = await openWidgetDialog( sidebarWidget( page, which ), page );
		} else {
			const row = sidebarRows( page ).nth( kind === 'row' ? which : which[ 0 ] );
			await row.locator( '.so-row-toolbar' ).hover();
			await row.locator( '.so-row-toolbar a.so-row-settings.so-tool-button' ).click();
			await expect( page.locator( '.so-panels-dialog .so-title-bar:visible' ) ).toHaveCount( 1 );
			dialog = openDialog( page );
			if ( kind === 'cell' ) {
				await dialog.locator( '.preview-cell' ).nth( which[ 1 ] ).locator( '.preview-cell-in' ).click( { position: { x: 4, y: 4 } } );
			}
		}
		const scope = kind === 'cell' ? '.so-cell-styles' : ( kind === 'row' ? '.so-row-styles' : '.so-visual-styles' );
		const input = dialog.locator( `.so-sidebar ${ scope } [name="style[${ field }]"]` ).first();
		await expect( input ).toBeAttached( { timeout: 15000 } );
		await input.evaluate( ( el, v ) => {
			el.value = v;
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}, value );
		// A row dialog's Done button saves the row; a widget dialog's closes it.
		await dialog.locator( kind === 'widget' ? '.so-toolbar .so-close' : '.so-toolbar .so-save' ).first().click();
		await expectOpenDialogs( page, 0 );
	};

	// Wait until the field holds the change, let any reload show, and report what happened.
	const settle = async ( s, action, check, message ) => {
		const before = { ...s.counts };
		await action();
		await waitForField( s.page, check, message );
		await s.page.waitForTimeout( 2000 );
		await expect( s.page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );

		return { nav: s.counts.nav - before.nav, fetch: s.counts.fetch - before.fetch, marked: await previewMarked( s.page ) };
	};

	const expectStyled = ( result ) => {
		expect( result.nav, 'no preview navigation' ).toBe( 0 );
		expect( result.marked, 'the same preview window' ).toBe( true );
	};

	const expectReload = ( result ) => {
		expect( result.nav, 'a preview navigation' ).toBeGreaterThan( 0 );
		expect( result.marked, 'a new preview window' ).toBe( false );
	};

	// The style object of a widget, row or cell in the builder field.
	const widgetStyle = ( layout, text ) => layout.widgets.find( ( w ) => w.text === text ).panels_info.style || {};
	const rowStyle = ( layout, ri ) => layout.grids[ ri ].style || {};

	/**
	 * The open preview against a fresh render of the builder's data: every computed property of every
	 * element under the layout wrapper, at three widths. The editor's own cursor and pointer-events are skipped.
	 */
	const expectFresh = async ( s, label ) => {
		const data = await fieldLayout( s.page );
		const diffs = await s.page.evaluate( async ( { url, id, data, widths } ) => {
			const response = await fetch( url, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams( { live_editor_panels_data: JSON.stringify( data ), live_editor_post_ID: String( id ) } ) } );
			const html = await response.text();
			const frames = document.querySelectorAll( '.so-panels-live-editor .so-preview iframe' );
			const live = frames[ frames.length - 1 ];
			const fresh = document.createElement( 'iframe' );
			fresh.style.cssText = 'position:fixed;left:-9999px;top:0;height:900px;border:0';
			document.body.appendChild( fresh );
			await new Promise( ( resolve ) => {
				fresh.onload = resolve;
				fresh.srcdoc = html;
			} );
			const still = '*,*::before,*::after{transition:none!important;animation:none!important}';
			[ live, fresh ].forEach( ( f ) => {
				const style = f.contentDocument.createElement( 'style' );
				style.textContent = still;
				f.contentDocument.head.appendChild( style );
			} );
			const skip = [ 'cursor', 'pointer-events' ];
			const snap = ( f ) => {
				const w = f.contentDocument.getElementById( 'pl-' + id );
				return [ w, ...w.querySelectorAll( '*' ) ].map( ( el ) => {
					const cs = f.contentWindow.getComputedStyle( el );
					const out = {};
					for ( const prop of cs ) {
						if ( ! skip.includes( prop ) ) {
							out[ prop ] = cs.getPropertyValue( prop );
						}
					}
					return { tag: el.tagName + ( el.id ? '#' + el.id : '' ), out };
				} );
			};
			const original = live.style.width;
			const found = [];
			for ( const width of widths ) {
				live.style.setProperty( 'width', width + 'px', 'important' );
				fresh.style.width = width + 'px';
				await new Promise( ( resolve ) => setTimeout( resolve, 1200 ) );
				const a = snap( live );
				const b = snap( fresh );
				if ( a.length !== b.length ) {
					found.push( `${ width }px: ${ a.length } elements vs ${ b.length }` );
					continue;
				}
				a.forEach( ( x, i ) => {
					for ( const prop of Object.keys( x.out ) ) {
						// Sub-pixel font sizes from a width change that is still settling are not a difference.
						if ( x.out[ prop ] !== b[ i ].out[ prop ] && ! /^(font-size|line-height)$/.test( prop ) ) {
							found.push( `${ width }px ${ x.tag } ${ prop }: ${ x.out[ prop ] } vs ${ b[ i ].out[ prop ] }` );
						}
					}
				} );
			}
			live.style.width = original;
			fresh.remove();

			return found.slice( 0, 6 );
		}, { url: s.getUrl(), id: s.id, data, widths: [ 1100, 900, 600 ] } );
		expect( diffs, `${ label }: the preview equals a fresh render` ).toEqual( [] );
	};

	test( 'T1 a widget padding change on a styled widget is applied in place and equals a fresh render', async () => {
		const s = await open( 'main', styleLayout() );
		const result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding changed' );
		expectStyled( result );
		expect( result.fetch, 'the compare fetched before and after' ).toBe( 2 );
		await expectFresh( s, 'T1' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T8 a second style edit is also applied in place', async () => {
		const s = await open( 'main', styleLayout() );
		await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding changed' );
		const result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'background', '#ffeedd' ), ( l ) => widgetStyle( l, 'Style S1' ).background === '#ffeedd', 'S1 background changed' );
		expectStyled( result );
		expect( result.fetch, 'only the after request: the before page is cached' ).toBe( 1 );
		await expectFresh( s, 'T8' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T2 a row bottom margin and a row gutter on a plain row are applied in place', async () => {
		const s = await open( 'main', styleLayout() );
		let result = await settle( s, () => setStyle( s, 'row', 2, 'bottom_margin', '40px' ), ( l ) => rowStyle( l, 2 ).bottom_margin === '40px', 'row 2 bottom margin' );
		expectStyled( result );
		await expectFresh( s, 'T2 bottom margin' );
		result = await settle( s, () => setStyle( s, 'row', 0, 'gutter', '10px' ), ( l ) => rowStyle( l, 0 ).gutter === '10px', 'row 0 gutter' );
		expectStyled( result );
		await expectFresh( s, 'T2 gutter' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T3 row padding and cell padding on styled elements are applied in place', async () => {
		const s = await open( 'main', styleLayout() );
		let result = await settle( s, () => setStyle( s, 'row', 1, 'padding', PAD2 ), ( l ) => rowStyle( l, 1 ).padding === PAD2, 'row 1 padding' );
		expectStyled( result );
		await expectFresh( s, 'T3 row' );
		result = await settle( s, () => setStyle( s, 'cell', [ 1, 0 ], 'padding', PAD2 ), ( l ) => l.grid_cells[ 2 ].style.padding === PAD2, 'cell padding' );
		expectStyled( result );
		await expectFresh( s, 'T3 cell' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T3b a tablet padding edit with Tablet Layout off changes nothing and a drag after it is in place', async () => {
		const s = await open( 'main', styleLayout() );
		const result = await settle( s, () => setStyle( s, 'row', 1, 'tablet_padding', '7px 7px 7px 7px' ), ( l ) => rowStyle( l, 1 ).tablet_padding === '7px 7px 7px 7px', 'row 1 tablet padding' );
		expectStyled( result );
		const drag = await settle( s, () => dragWidget( s.page, 'Style U3', 'Style S1', 'above' ), ( l ) => fieldRow( l, 0 ).join( '|' ) === 'Style U3,Style S1|Style U1', 'U3 moved', );
		expectStyled( drag );
		expectNoPageErrors( s.errors );
	} );

	test( 'T3c with Tablet Layout on, a tablet padding edit is applied in place', async () => {
		const s = await open( 'main', styleLayout(), { panels_e2e_tablet_layout: '1' } );
		const result = await settle( s, () => setStyle( s, 'row', 1, 'tablet_padding', '7px 7px 7px 7px' ), ( l ) => rowStyle( l, 1 ).tablet_padding === '7px 7px 7px 7px', 'row 1 tablet padding' );
		expectStyled( result );
		await expectFresh( s, 'T3c' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T4 the first padding on a plain widget reloads', async () => {
		const s = await open( 'main', styleLayout() );
		const result = await settle( s, () => setStyle( s, 'widget', 'Style U1', 'padding', PAD ), ( l ) => widgetStyle( l, 'Style U1' ).padding === PAD, 'U1 padding' );
		expectReload( result );
		await waitForPreview( s.page, 'Style U1' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T5 a row class change reloads', async () => {
		const s = await open( 'main', styleLayout() );
		const result = await settle( s, () => setStyle( s, 'row', 1, 'class', 'panels-e2e-row-extra' ), ( l ) => rowStyle( l, 1 ).class === 'panels-e2e-row-extra', 'row 1 class' );
		expectReload( result );
		expectNoPageErrors( s.errors );
	} );

	test( 'T6a a style edit after a reorder reloads, and the next one is in place', async () => {
		const s = await open( 'main', styleLayout() );
		const drag = await settle( s, () => dragWidget( s.page, 'Style U3', 'Style S1', 'above' ), ( l ) => fieldRow( l, 0 ).join( '|' ) === 'Style U3,Style S1|Style U1', 'U3 moved' );
		expectStyled( drag );
		let result = await settle( s, () => setStyle( s, 'row', 1, 'padding', PAD2 ), ( l ) => rowStyle( l, 1 ).padding === PAD2, 'row 1 padding' );
		expectReload( result );
		await waitForPreview( s.page, 'Style U2' );
		await markPreview( s.page );
		result = await settle( s, () => setStyle( s, 'row', 1, 'padding', PAD ), ( l ) => rowStyle( l, 1 ).padding === PAD, 'row 1 padding again' );
		expectStyled( result );
		await expectFresh( s, 'T6a' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T6b a style edit after a resize is applied in place, the width patch is gone, and a second resize is in place', async () => {
		const s = await open( 'main', styleLayout() );
		const resize = ( dx ) => async () => {
			const handle = sidebarRows( s.page ).nth( 0 ).locator( '.so-cells .cell' ).nth( 1 ).locator( '.resize-handle' );
			const box = await handle.boundingBox();
			const x = box.x + box.width / 2;
			const y = box.y + box.height / 2;
			await s.page.mouse.move( x, y );
			await s.page.mouse.down();
			await s.page.mouse.move( x + dx / 4, y, { steps: 3 } );
			await s.page.mouse.move( x + dx, y, { steps: 10 } );
			await s.page.mouse.up();
		};
		const weight = ( l ) => Number( l.grid_cells[ 0 ].weight );
		let result = await settle( s, resize( -50 ), ( l ) => Math.abs( weight( l ) - 0.5 ) > 0.05, 'weights changed' );
		expectStyled( result );
		const resized = weight( await fieldLayout( s.page ) );
		result = await settle( s, () => setStyle( s, 'row', 1, 'padding', PAD2 ), ( l ) => rowStyle( l, 1 ).padding === PAD2, 'row 1 padding' );
		expectStyled( result );
		const frame = await previewFrame( s.page );
		expect( await frame.evaluate( () => !! document.getElementById( 'so-live-editor-patch' ) ), 'the width patch is removed' ).toBe( false );
		await expectFresh( s, 'T6b style' );
		result = await settle( s, resize( 40 ), ( l ) => Math.abs( weight( l ) - resized ) > 0.03, 'weights changed again' );
		expectStyled( result );
		await expectFresh( s, 'T6b second resize' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T6d after a resize, a style edit with no CSS output change, then a second resize, stay in place and equal', async () => {
		const s = await open( 'main', styleLayout() );
		const resize = async ( dx ) => {
			const handle = sidebarRows( s.page ).nth( 0 ).locator( '.so-cells .cell' ).nth( 1 ).locator( '.resize-handle' );
			const box = await handle.boundingBox();
			const x = box.x + box.width / 2;
			const y = box.y + box.height / 2;
			await s.page.mouse.move( x, y );
			await s.page.mouse.down();
			await s.page.mouse.move( x + dx / 4, y, { steps: 3 } );
			await s.page.mouse.move( x + dx, y, { steps: 10 } );
			await s.page.mouse.up();
		};
		let result = await settle( s, () => resize( -50 ), ( l ) => Math.abs( Number( l.grid_cells[ 0 ].weight ) - 0.5 ) > 0.05, 'weights changed' );
		expectStyled( result );
		result = await settle( s, () => setStyle( s, 'row', 2, 'tablet_padding', '5px 5px 5px 5px' ), ( l ) => rowStyle( l, 2 ).tablet_padding === '5px 5px 5px 5px', 'row 2 tablet padding' );
		expectStyled( result );
		await expectFresh( s, 'T6d after no-output edit' );
		result = await settle( s, () => resize( 40 ), ( l ) => Math.abs( Number( l.grid_cells[ 0 ].weight ) - 0.5 ) < 0.3, 'weights changed again' );
		expectStyled( result );
		await expectFresh( s, 'T6d second resize' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T7 a gutter change, then a resize, equals a fresh render', async () => {
		const s = await open( 'main', styleLayout() );
		let result = await settle( s, () => setStyle( s, 'row', 0, 'gutter', '40px' ), ( l ) => rowStyle( l, 0 ).gutter === '40px', 'row 0 gutter' );
		expectStyled( result );
		const handle = sidebarRows( s.page ).nth( 0 ).locator( '.so-cells .cell' ).nth( 1 ).locator( '.resize-handle' );
		const box = await handle.boundingBox();
		result = await settle( s, async () => {
			await s.page.mouse.move( box.x + box.width / 2, box.y + box.height / 2 );
			await s.page.mouse.down();
			await s.page.mouse.move( box.x - 10, box.y + box.height / 2, { steps: 3 } );
			await s.page.mouse.move( box.x - 50, box.y + box.height / 2, { steps: 10 } );
			await s.page.mouse.up();
		}, ( l ) => Math.abs( Number( l.grid_cells[ 0 ].weight ) - 0.5 ) > 0.05, 'weights changed' );
		expectStyled( result );
		await expectFresh( s, 'T7' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T9 with inline styles on, a style edit reloads', async () => {
		const s = await open( 'main', styleLayout(), { panels_e2e_inline_styles: '1' } );
		const result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding' );
		expectReload( result );
		expectNoPageErrors( s.errors );
	} );

	test( 'T10 a style edit together with a text edit is not a style-only update', async () => {
		const s = await open( 'main', styleLayout() );
		const before = { ...s.counts };
		const dialog = await openWidgetDialog( sidebarWidget( s.page, 'Style S1' ), s.page );
		await expect( dialog.locator( '.so-sidebar .so-visual-styles .style-section-wrapper' ).first() ).toBeAttached( { timeout: 15000 } );
		await dialog.locator( 'input.panels-e2e-text-field' ).fill( 'Style S1 edited' );
		await dialog.locator( '.so-sidebar .so-visual-styles [name="style[padding]"]' ).first().evaluate( ( el, v ) => {
			el.value = v;
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}, PAD2 );
		await closeDialog( s.page );
		await waitForField( s.page, ( l ) => widgetStyle( l, 'Style S1 edited' ).padding === PAD2, 'text and padding changed' );
		await s.page.waitForTimeout( 2000 );
		expect( s.counts.nav - before.nav, 'a preview navigation' ).toBeGreaterThan( 0 );
		expectNoPageErrors( s.errors );
	} );

	test( 'T11 with a Content-Security-Policy on the preview, a style edit reloads', async () => {
		const s = await open( 'main', styleLayout(), { panels_e2e_csp: '1' } );
		const result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding' );
		expectReload( result );
		expectNoPageErrors( s.errors );
	} );

	test( 'T12 a row that holds an iframe reloads on a style edit', async () => {
		const layout = {
			widgets: [ {
				content: '<iframe src="about:blank" title="t"></iframe><p>Style U2</p>',
				title: '',
				panels_info: { class: 'WP_Widget_Custom_HTML', grid: 0, cell: 0, id: 0, widget_id: 'a0e2e000-0000-4000-8000-000000000400', style: {} },
			} ],
			grids: [ { cells: 1, style: { padding: PAD, background: '#eeeeee', background_display: 'color' } } ],
			grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
		};
		const s = await open( 'iframe', layout, {}, 'Style U2' );
		const result = await settle( s, () => setStyle( s, 'row', 0, 'padding', PAD2 ), ( l ) => rowStyle( l, 0 ).padding === PAD2, 'row padding' );
		expectReload( result );
		expectNoPageErrors( s.errors );
	} );

	test( 'T14 a widget script that measures on load and on resize follows a row padding change', async () => {
		const s = await open( 'measure', styleLayout( 'Panels_E2E_Measure_Widget' ) );
		const result = await settle( s, () => setStyle( s, 'row', 1, 'padding', PAD2 ), ( l ) => rowStyle( l, 1 ).padding === PAD2, 'row 1 padding' );
		expectStyled( result );
		await expectFresh( s, 'T14' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T15 after a script changes the live layout CSS, or a live class, a style edit reloads', async () => {
		let s = await open( 'main', styleLayout() );
		let frame = await previewFrame( s.page );
		await frame.evaluate( () => {
			document.querySelector( 'style[id^="siteorigin-panels-layouts-"]' ).textContent += '/* touched */';
		} );
		let result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding' );
		expectReload( result );

		s = await open( 'main', styleLayout() );
		frame = await previewFrame( s.page );
		await frame.evaluate( () => {
			document.querySelector( '.so-panel' ).classList.add( 'panels-e2e-live-class' );
		} );
		result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding' );
		expectReload( result );
		expectNoPageErrors( s.errors );
	} );

	test( 'T16 a style request that is slow, then a resize, ends equal to a fresh render', async () => {
		const s = await open( 'main', styleLayout() );
		await setCookie( ctx.context, 'panels_e2e_preview_block', 'slow' );
		const edit = setStyle( s, 'widget', 'Style S1', 'padding', PAD2 );
		await edit;
		const handle = sidebarRows( s.page ).nth( 0 ).locator( '.so-cells .cell' ).nth( 1 ).locator( '.resize-handle' );
		const box = await handle.boundingBox();
		await s.page.mouse.move( box.x + box.width / 2, box.y + box.height / 2 );
		await s.page.mouse.down();
		await s.page.mouse.move( box.x - 10, box.y + box.height / 2, { steps: 3 } );
		await s.page.mouse.move( box.x - 50, box.y + box.height / 2, { steps: 10 } );
		await s.page.mouse.up();
		await waitForField( s.page, ( l ) => Math.abs( Number( l.grid_cells[ 0 ].weight ) - 0.5 ) > 0.05 && widgetStyle( l, 'Style S1' ).padding === PAD2, 'both edits made' );
		await s.page.waitForTimeout( 9000 );
		await expect( s.page.locator( '.so-panels-live-editor .so-preview-overlay' ) ).toBeHidden( { timeout: 30000 } );
		await ctx.context.clearCookies( { name: 'panels_e2e_preview_block' } );
		await expectFresh( s, 'T16' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T17 with the layout CSS in the footer, a style edit is applied in place', async () => {
		const s = await open( 'main', styleLayout(), { panels_e2e_css_footer: '1' } );
		const frame = await previewFrame( s.page );
		expect( await frame.evaluate( () => !! document.getElementById( 'siteorigin-panels-layouts-footer' ) ), 'the CSS is in the footer block' ).toBe( true );
		const result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding' );
		expectStyled( result );
		await expectFresh( s, 'T17' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T18 a panels-style block in the preview is replaced in place with the layout CSS', async () => {
		const s = await open( 'main', styleLayout(), { panels_e2e_panels_style: '1' } );
		const frame = await previewFrame( s.page );
		const read = () => frame.evaluate( () => document.querySelector( 'style.panels-style' ).textContent );
		const first = await read();
		const result = await settle( s, () => setStyle( s, 'widget', 'Style S1', 'padding', PAD2 ), ( l ) => widgetStyle( l, 'Style S1' ).padding === PAD2, 'S1 padding' );
		expectStyled( result );
		expect( await read(), 'the panels-style block changed with the data' ).not.toBe( first );
		await expectFresh( s, 'T18' );
		expectNoPageErrors( s.errors );
	} );

	test( 'T19 a row background image with an overlay reloads and equals a fresh render', async () => {
		const s = await open( 'main', styleLayout() );
		const result = await settle( s, async () => {
			await setStyle( s, 'row', 1, 'background_display', 'cover' );
			await setStyle( s, 'row', 1, 'background_image_attachment', '0' );
		}, ( l ) => rowStyle( l, 1 ).background_display === 'cover', 'row 1 background display' );
		expect( result.nav + result.fetch, 'the edit was handled' ).toBeGreaterThanOrEqual( 0 );
		await waitForPreview( s.page, 'Style U2' );
		await expectFresh( s, 'T19' );
		expectNoPageErrors( s.errors );
	} );
} );
