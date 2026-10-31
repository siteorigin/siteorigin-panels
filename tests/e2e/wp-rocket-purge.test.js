/**
 * WP Rocket's widget purge: requests that prepare a layout don't run it, and saves still do.
 * tests/playground/mu-plugins/panels-e2e-wp-rocket.php supplies a stand-in for WP Rocket's callback.
 * Each preparing request runs twice: in baseline mode the stand-in is attached again after the
 * compatibility code, so the count must be above zero, which shows the request reaches the filter.
 * In the on mode the count must be zero. The mode is a cookie or a header, so only this spec's requests
 * get the stand-in. builder-widget-area and builder-home-page check their own saves.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	createPost,
	deletePost,
	expectOpenDialogs,
	newLoggedInPage,
	openClassicBuilder,
	openDialog,
	rawStorage,
	rest,
	seedLayout,
	setWidgetText,
	siteUrl,
	uiOption,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const MODE_COOKIE = 'panels_e2e_wp_rocket';
const MODE_HEADER = 'X-Panels-E2E-WP-Rocket';
const PURGES = 'panels_e2e_wp_rocket_purges';
const TEXT = 'Rocket text';
const LISTED = [
	'so_panels_builder_content',
	'so_panels_builder_content_json',
	'so_panels_get_layout',
	'so_panels_import_layout',
	'so_panels_layout_block_preview',
	'so_panels_live_editor_preview',
];

const layout = ( text = TEXT ) => ( {
	widgets: [ {
		text,
		panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0, widget_id: 'c0e2e000-0000-4000-8000-000000000001' },
	} ],
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

const ajax = ( query ) => siteUrl( `wp-admin/admin-ajax.php?${ new URLSearchParams( query ) }` );

// The action of an admin-ajax request, from its URL or its body.
const requestAction = ( request ) => {
	const url = new URL( request.url() );
	if ( ! url.pathname.endsWith( 'admin-ajax.php' ) ) {
		return null;
	}
	const body = request.postData() || '';
	const field = body.match( /name="action"\r?\n\r?\n([\w-]+)/ );

	return url.searchParams.get( 'action' ) || new URLSearchParams( body ).get( 'action' ) || ( field && field[ 1 ] );
};

const isListed = ( request ) => LISTED.includes( requestAction( request ) );
const isFrontPreview = ( request ) => request.method() === 'POST' &&
	! request.url().includes( 'admin-ajax.php' ) &&
	request.url().includes( 'siteorigin_panels_live_editor' );

// Tracks the requests that could land in a count, so a late one can't reach the next measurement.
const trackRequests = ( page ) => {
	const pending = new Set();
	const done = ( request ) => pending.delete( request );
	page.on( 'request', ( request ) => {
		if ( isListed( request ) || isFrontPreview( request ) ) {
			pending.add( request );
		}
	} );
	page.on( 'requestfinished', done );
	page.on( 'requestfailed', done );

	return async () => {
		await expect.poll( () => pending.size, { timeout: 30000 } ).toBe( 0 );
	};
};

test.describe( 'WP Rocket widget purge', () => {
	let admin;
	let ctx;
	let settled;
	let nonces;
	let mode = 'on';
	const created = [];

	const setMode = async ( value ) => {
		mode = value;
		await ctx.context.addCookies( [ { name: MODE_COOKIE, value, url: siteUrl( '' ) } ] );
	};
	const resetCounts = () => uiOption( admin, PURGES, { exists: false } );
	const counts = async () => ( await uiOption( admin, PURGES ) ).value || {};

	/**
	 * Run one trigger in both modes, and check the count for its action in each.
	 *
	 * @param {string}   key     The count's key: the action, or page for a front-end request.
	 * @param {Function} trigger Sends the request, waits for its response and returns the response.
	 */
	const bothModes = async ( key, trigger ) => {
		const results = {};
		for ( const run of [ 'baseline', 'on' ] ) {
			await settled();
			await setMode( run );
			await resetCounts();
			results[ run ] = await trigger( run );
			await settled();
			const count = ( await counts() )[ key ] || 0;
			// eslint-disable-next-line no-console
			console.log( `${ key } ${ run }: count ${ count }` );
			if ( run === 'baseline' ) {
				expect( count, `${ key } reaches the filter` ).toBeGreaterThan( 0 );
			} else {
				expect( count, `${ key } doesn't purge` ).toBe( 0 );
			}
		}
		await setMode( 'on' );

		return results;
	};

	/**
	 * Wait for a browser request while a step runs, and check its status and size.
	 */
	const during = async ( page, match, step ) => {
		const [ response ] = await Promise.all( [
			page.waitForResponse( ( r ) => match( r.request() ), { timeout: 30000 } ),
			step(),
		] );
		const body = await response.text();
		// eslint-disable-next-line no-console
		console.log( `${ response.url().slice( -70 ) }: ${ response.status() }, ${ body.length } bytes` );
		expect( response.status() ).toBe( 200 );
		// A widget save answers with an empty body.
		if ( isListed( response.request() ) || isFrontPreview( response.request() ) ) {
			expect( body.length ).toBeGreaterThan( 0 );
		}

		return response;
	};

	const forAction = ( action ) => ( request ) => requestAction( request ) === action;

	test.beforeAll( async ( { browser } ) => {
		admin = await adminLogin();
		ctx = await newLoggedInPage( browser, process.env.WP_USERNAME, process.env.WP_PASSWORD );
		settled = trackRequests( ctx.page );
		await setMode( 'on' );
		nonces = ( await rest( admin, 'GET', '/panels-e2e/v1/wp-rocket/nonces' ) ).body;
	} );

	test.afterAll( async () => {
		await resetCounts();
		for ( const [ type, id ] of created ) {
			await deletePost( admin, type, id );
		}
		if ( ctx ) {
			await ctx.context.close();
		}
		await admin.context.dispose();
	} );

	const newPost = async ( title = 'WP Rocket purge' ) => {
		const id = await createPost( admin, 'post', { title, status: 'publish', content: '' } );
		created.push( [ 'post', id ] );
		await seedLayout( admin, id, layout() );

		return id;
	};

	test( 'J0 the compatibility code loads only with the callback', async () => {
		const loaded = async ( headers ) => {
			const response = await admin.context.get( siteUrl( `wp-json/panels-e2e/v1/ui/option?name=${ PURGES }` ), { headers: { 'X-WP-Nonce': admin.nonce, ...headers } } );
			expect( response.status() ).toBe( 200 );

			return response.headers()[ 'x-panels-e2e-wp-rocket-compat' ];
		};

		expect( await loaded( { [ MODE_HEADER ]: 'on' } ) ).toBe( '1' );
		expect( await loaded( {} ) ).toBe( '0' );
	} );

	test.describe( 'R1 each request', () => {
		let postId;
		const form = () => ( { post_id: postId, panels_data: JSON.stringify( layout() ) } );

		test.beforeAll( async () => {
			postId = await newPost();
		} );

		const send = async ( options, query ) => {
			const response = await admin.context.post( ajax( query ), { ...options, headers: { [ MODE_HEADER ]: mode } } );
			const body = await response.text();
			// eslint-disable-next-line no-console
			console.log( `${ query.action }: ${ response.status() }, ${ body.length } bytes` );
			expect( response.status() ).toBe( 200 );

			return body;
		};

		test( 'so_panels_builder_content', async () => {
			const action = 'so_panels_builder_content';
			await bothModes( action, async () => {
				const body = await send( { form: form() }, { action, _panelsnonce: nonces.panels_action } );
				expect( body ).toContain( TEXT );
			} );
		} );

		test( 'so_panels_builder_content_json', async () => {
			const action = 'so_panels_builder_content_json';
			const results = await bothModes( action, async () => {
				const json = JSON.parse( await send( { form: form() }, { action, _panelsnonce: nonces.panels_action } ) );
				expect( json.sanitized_panels_data.widgets[ 0 ].text ).toBe( TEXT );
				expect( json.sanitized_panels_data.widgets[ 0 ].panels_e2e_witness ).toBe( 'kept' );
				expect( json.preview ).toContain( TEXT );

				return json.sanitized_panels_data;
			} );
			expect( results.on ).toEqual( results.baseline );
		} );

		test( 'so_panels_get_layout', async () => {
			const action = 'so_panels_get_layout';
			await bothModes( action, async () => {
				const json = JSON.parse( await send(
					{ form: { type: 'prebuilt', lid: 'panels-e2e-rocket' } },
					{ action, _panelsnonce: nonces.panels_action }
				) );
				expect( json.success ).toBe( true );
				expect( json.data.widgets[ 0 ].panels_e2e_witness ).toBe( 'kept' );
			} );
		} );

		test( 'so_panels_import_layout', async () => {
			const action = 'so_panels_import_layout';
			await bothModes( action, async () => {
				const json = JSON.parse( await send(
					{
						multipart: {
							panels_import_data: {
								name: 'layout.json',
								mimeType: 'application/json',
								buffer: Buffer.from( JSON.stringify( layout() ) ),
							},
						},
					},
					{ action, _panelsnonce: nonces.panels_action }
				) );
				expect( json.widgets[ 0 ].text ).toBe( TEXT );
				expect( json.widgets[ 0 ].panels_e2e_witness ).toBe( 'kept' );
			} );
		} );

		test( 'so_panels_live_editor_preview', async () => {
			const action = 'so_panels_live_editor_preview';
			await bothModes( action, async () => {
				const body = await send(
					{ form: { live_editor_panels_data: JSON.stringify( layout() ), live_editor_post_ID: postId } },
					{ action, siteorigin_panels_live_editor: 'true', _panelsnonce: nonces.live_editor_preview }
				);
				expect( body ).toContain( TEXT );
			} );
		} );

		test( 'so_panels_layout_block_preview', async () => {
			const action = 'so_panels_layout_block_preview';
			await bothModes( action, async () => {
				const body = await send(
					{ form: { panelsData: JSON.stringify( layout() ) } },
					{ action, _panelsnonce: nonces.layout_block_preview }
				);
				expect( body ).toContain( TEXT );
			} );
		} );
	} );

	test( 'J1 opening a page in the classic builder', async () => {
		const { page } = ctx;
		const postId = await newPost();
		const action = 'so_panels_builder_content_json';

		await bothModes( action, () => during( page, forAction( action ), () => openClassicBuilder( page, { postId } ) ) );
	} );

	test( 'J2 a layout change, then a save, in the classic builder', async () => {
		const { page } = ctx;
		const postId = await newPost();
		const action = 'so_panels_builder_content_json';
		const root = await openClassicBuilder( page, { postId } );
		await settled();

		await bothModes( action, ( mode ) => during( page, forAction( action ), () => setWidgetText( root, root.locator( '.so-widget' ).first(), `Changed ${ mode }`, page ) ) );

		await settled();
		await resetCounts();
		await Promise.all( [
			page.waitForURL( /message=\d+/, { timeout: 60000 } ),
			page.locator( '#publish' ).click(),
		] );
		expect( ( await counts() ).editpost, 'a classic save purges' ).toBeGreaterThan( 0 );

		// J5: the front end shows what was saved.
		await page.goto( siteUrl( `?p=${ postId }` ) );
		await expect( page.locator( '.panels-e2e-text', { hasText: 'Changed on' } ) ).toHaveCount( 1 );
	} );

	test( 'J3 editing and saving a Layout Block', async () => {
		const { page } = ctx;
		const json = 'so_panels_builder_content_json';
		const preview = 'so_panels_layout_block_preview';

		await page.goto( siteUrl( 'wp-admin/post-new.php?block-editor' ) );
		await page.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/editor' ) && wp.blocks && wp.blocks.getBlockType( 'siteorigin-panels/layout-block' ) );
		await page.evaluate( () => {
			const prefs = wp.data.dispatch( 'core/preferences' );
			if ( prefs ) {
				prefs.set( 'core/edit-post', 'welcomeGuide', false );
				prefs.set( 'core', 'enableChoosePatternModal', false );
			}
			wp.data.dispatch( 'core/editor' ).editPost( { title: 'WP Rocket Layout Block' } );
			wp.data.dispatch( 'core/block-editor' ).insertBlock( wp.blocks.createBlock( 'siteorigin-panels/layout-block' ) );
		} );
		const postId = await page.evaluate( () => wp.data.select( 'core/editor' ).getCurrentPostId() );
		created.push( [ 'post', postId ] );

		const canvas = ( await page.locator( 'iframe[name="editor-canvas"]' ).count() )
			? page.frameLocator( 'iframe[name="editor-canvas"]' )
			: page;
		const root = canvas.locator( '.siteorigin-panels-layout-block-container' );
		await expect( root.locator( '.so-builder-toolbar' ) ).toBeVisible( { timeout: 30000 } );

		let widget;
		await bothModes( json, async ( mode ) => {
			if ( mode === 'baseline' ) {
				const row = await addRow( root, 1, page );
				return during( page, forAction( json ), async () => {
					widget = await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget', page );
				} );
			}

			return during( page, forAction( json ), () => setWidgetText( root, widget, 'Block text saved', page ) );
		} );

		await settled();
		await page.waitForFunction( () => {
			const block = wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === 'siteorigin-panels/layout-block' );
			const data = block && block.attributes.panelsData;
			return !! data && data.widgets.some( ( w ) => w.text === 'Block text saved' ) && ! wp.data.select( 'core/editor' ).isPostSavingLocked();
		}, null, { timeout: 20000 } );

		// The block fetches a preview after a change, once it's no longer selected.
		await bothModes( preview, async ( mode ) => during( page, forAction( preview ), async () => {
			await page.evaluate( () => wp.data.dispatch( 'core/block-editor' ).selectBlock( wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].clientId ) );
			await setWidgetText( root, root.locator( '.so-widget' ).first(), `Block text ${ mode }`, page );
			await page.waitForFunction( ( text ) => {
				const block = wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === 'siteorigin-panels/layout-block' );
				return block.attributes.panelsData.widgets.some( ( w ) => w.text === text ) && ! wp.data.select( 'core/editor' ).isPostSavingLocked();
			}, `Block text ${ mode }`, { timeout: 20000 } );
			await page.evaluate( () => wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock() );
		} ) );

		await settled();
		await resetCounts();
		await page.evaluate( () => {
			wp.data.dispatch( 'core/editor' ).editPost( { status: 'publish' } );
			return wp.data.dispatch( 'core/editor' ).savePost();
		} );
		await page.waitForFunction( () => {
			const editor = wp.data.select( 'core/editor' );
			return ! editor.isSavingPost() && editor.didPostSaveRequestSucceed();
		}, null, { timeout: 30000 } );
		expect( ( await counts() ).rest, 'a Layout Block save purges' ).toBeGreaterThan( 0 );

		const raw = await rawStorage( admin, postId );
		expect( raw.blocks[ 0 ].widgets.map( ( w ) => w.text ) ).toEqual( [ 'Block text on' ] );

		// J5: the front end shows what was saved.
		await page.goto( siteUrl( `?p=${ postId }` ) );
		await expect( page.locator( '.panels-e2e-text', { hasText: 'Block text on' } ) ).toHaveCount( 1 );
	} );

	test( 'J4 the Live Editor on the front end', async () => {
		const { page } = ctx;
		const postId = await newPost();

		await bothModes( 'page', async () => {
			const root = await openClassicBuilder( page, { postId } );
			await settled();
			await resetCounts();

			return during( page, isFrontPreview, () => root.locator( '.so-builder-toolbar .so-live-editor' ).click() );
		} );
	} );

	test( 'J4 a history preview on the front end', async () => {
		const { page } = ctx;
		const postId = await newPost();

		await bothModes( 'page', async () => {
			const root = await openClassicBuilder( page, { postId } );
			await setWidgetText( root, root.locator( '.so-widget' ).first(), 'History change', page );
			await settled();
			await root.locator( '.so-builder-toolbar .so-history' ).click();
			await settled();
			await resetCounts();

			return during( page, isFrontPreview, () => page.locator( '.so-panels-dialog-history .history-entry', { hasText: 'Original' } ).click() );
		} );
	} );

	test( 'J4 the Live Editor on a non-public post type', async () => {
		const { page } = ctx;
		const action = 'so_panels_live_editor_preview';
		await page.goto( siteUrl( 'wp-admin/post-new.php?post_type=panels_e2e_private&siteorigin-page-builder' ) );
		const root = page.locator( '#siteorigin-panels-metabox' );
		await expect( root.locator( '.so-builder-toolbar' ).first() ).toBeVisible( { timeout: 30000 } );
		const row = await addRow( root, 1 );
		const widget = await addWidget( root, row.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget' );
		await setWidgetText( root, widget, TEXT );
		await settled();

		await bothModes( action, async () => {
			const opened = await during( page, forAction( action ), () => root.locator( '.so-builder-toolbar .so-live-editor' ).click() );
			await page.locator( '.so-panels-live-editor .live-editor-close' ).click();

			return opened;
		} );
	} );

	test( 'J4 importing a layout and loading a prebuilt layout', async () => {
		const { page } = ctx;
		const postId = await newPost();
		const importAction = 'so_panels_import_layout';
		const getAction = 'so_panels_get_layout';
		const root = await openClassicBuilder( page, { postId } );
		await settled();

		const openLayouts = async ( tab ) => {
			await root.locator( '.so-builder-toolbar .so-prebuilt-add' ).click();
			const dialog = openDialog( page );
			await dialog.locator( `.so-sidebar-tabs a[href="${ tab }"]` ).click();

			return dialog;
		};

		await bothModes( importAction, async () => {
			const dialog = await openLayouts( '#import' );
			const uploaded = await during( page, forAction( importAction ), () => dialog.locator( 'input[type="file"]' ).setInputFiles( {
				name: 'layout.json',
				mimeType: 'application/json',
				buffer: Buffer.from( JSON.stringify( layout() ) ),
			} ) );
			await openDialog( page ).locator( '.so-title-bar .so-close' ).click();
			await expectOpenDialogs( page, 0 );

			return uploaded;
		} );

		await bothModes( getAction, async () => {
			const dialog = await openLayouts( '#prebuilt' );
			await dialog.locator( '.so-directory-item', { hasText: 'Panels E2E Rocket' } ).locator( '.so-title' ).click();
			await dialog.locator( '.so-import-layout' ).click();
			const loaded = await during( page, forAction( getAction ), () => dialog.locator( '.so-toolbar-button[data-value="after"]' ).click() );
			await expectOpenDialogs( page, 0 );

			return loaded;
		} );
	} );

	test( 'J6 a REST widget save with the Live Editor query arg keeps the purge', async () => {
		await resetCounts();
		const response = await admin.context.fetch( siteUrl( 'wp-json/wp/v2/widgets' ), {
			method: 'POST',
			params: { siteorigin_panels_live_editor: '1' },
			headers: { 'X-WP-Nonce': admin.nonce, 'Content-Type': 'application/json', [ MODE_HEADER ]: 'on' },
			data: {
				id_base: 'panels_e2e_text',
				sidebar: 'wp_inactive_widgets',
				form_data: 'widget-panels_e2e_text[3][text]=Rest+text',
			},
		} );
		const body = await response.json();
		expect( response.status(), JSON.stringify( body ) ).toBe( 201 );
		expect( ( await counts() ).rest, 'a REST widget save purges' ).toBeGreaterThan( 0 );
	} );

	test( 'J6 a Customizer widget save keeps the purge', async () => {
		const { page } = ctx;
		await page.goto( siteUrl( 'wp-admin/customize.php' ) );
		await page.waitForFunction( () => window.wp && wp.customize && wp.customize.Widgets && wp.customize.settings && wp.customize.settings.nonce );
		await resetCounts();
		const result = await page.evaluate( async () => {
			const params = new URLSearchParams( {
				action: 'update-widget',
				wp_customize: 'on',
				nonce: wp.customize.settings.nonce[ 'update-widget' ],
				customize_changeset_uuid: wp.customize.settings.changeset.uuid,
				customize_theme: wp.customize.settings.theme.stylesheet,
				customized: '{}',
				'widget-id': 'panels_e2e_text-9',
				'id_base': 'panels_e2e_text',
				'widget-width': '250',
				'widget-height': '200',
				widget_number: '9',
				add_new: '',
				'widget-panels_e2e_text[9][text]': 'Customizer text',
			} );
			const response = await fetch( window.ajaxurl || wp.customize.settings.url.ajax, { method: 'POST', credentials: 'same-origin', body: params } );

			return { status: response.status, body: await response.text() };
		} );
		expect( result.status ).toBe( 200 );
		expect( JSON.parse( result.body ).success, result.body ).toBe( true );
		expect( ( await counts() )[ 'update-widget' ], 'a Customizer widget save purges' ).toBeGreaterThan( 0 );
	} );
} );
