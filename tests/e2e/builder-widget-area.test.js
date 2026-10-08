/**
 * Layout Builder widget in a widget area, on the classic widgets screen: build a layout, save the
 * widget, and check the stored widget option, the front-end widget area and a reload. Then check the
 * pre-JavaScript stretch fallback for layouts in widget areas, including block widgets. Runs as an
 * administrator. Every option this file changes is restored after the run.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	closeDialog,
	createPost,
	deletePost,
	expectNoPageErrors,
	expectOpenDialogs,
	layoutBlock,
	newLoggedInPage,
	openDialog,
	rest,
	seedLayout,
	setWidgetText,
	siteUrl,
	uiOption,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const TEXT = 'Widget area text';
const SIDEBAR = 'panels-e2e-sidebar';
const OPTIONS = [ 'panels_e2e_classic_widgets', 'sidebars_widgets', 'widget_siteorigin-panels-builder', 'widget_block' ];
const FALLBACK = 'siteorigin-panels-before-js';
const FALLBACK_CLASS = new RegExp( `(^|\\s)${ FALLBACK }(\\s|$)` );
const STRIP = 'document.body.className = document.body.className.replace("siteorigin-panels-before-js","");';

const innerLayout = ( instance ) => ( typeof instance.panels_data === 'string' ? JSON.parse( instance.panels_data ) : instance.panels_data );

const bodyClasses = ( html ) => ( ( html.match( /<body[^>]*\bclass="([^"]*)"/ ) || [] )[ 1 ] || '' ).split( /\s+/ ).filter( Boolean );
const count = ( list, item ) => list.filter( ( entry ) => entry === item ).length;

// The front-end HTML a logged-out visitor is served.
const servedHtml = async ( browser, url ) => {
	const anon = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	try {
		const response = await anon.request.get( url );
		expect( response.status() ).toBe( 200 );

		return await response.text();
	} finally {
		await anon.close();
	}
};

const cell = { grid: 0, index: 0, weight: 1, style: {} };
const textWidget = ( text ) => ( { text, panels_info: { class: 'Panels_E2E_Text_Widget', grid: 0, cell: 0, id: 0, style: {} } } );

const STRETCHED_TEXT = 'Widget area stretched content';

// One Full Width Stretched row.
const stretchedLayout = () => ( {
	widgets: [ textWidget( STRETCHED_TEXT ) ],
	grids: [ { cells: 1, style: { row_stretch: 'full-width-stretch' } } ],
	grid_cells: [ cell ],
} );

// Write options for one check, then put the exact previous values back.
const withOptions = async ( admin, values, run ) => {
	const before = {};
	for ( const name of Object.keys( values ) ) {
		before[ name ] = await uiOption( admin, name );
	}
	try {
		for ( const [ name, value ] of Object.entries( values ) ) {
			await uiOption( admin, name, { value } );
		}
		await run();
	} finally {
		for ( const name of Object.keys( values ) ) {
			const old = before[ name ];
			await uiOption( admin, name, old.exists ? { value: old.value } : { exists: false } );
			expect( await uiOption( admin, name ), `${ name } is restored` ).toEqual( old );
		}
	}
};

// Widget areas with every Layout Builder widget and block widget taken out of the active ones.
const withoutWidgetLayouts = ( sidebars ) => Object.fromEntries( Object.entries( sidebars ).map( ( [ id, widgets ] ) => [
	id,
	id === 'wp_inactive_widgets' || ! Array.isArray( widgets ) ? widgets : widgets.filter( ( widget ) => ! /^(siteorigin-panels-builder|block)-\d+$/.test( widget ) ),
] ) );

const rowRects = ( rows ) => rows.evaluateAll( ( elements ) => elements.map( ( element ) => {
	const bounds = element.getBoundingClientRect();
	return { left: bounds.left, right: bounds.right, width: document.documentElement.clientWidth };
} ) );

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

	test( 'no fallback class without a layout in a widget area', async ( { browser } ) => {
		const sidebars = ( await uiOption( admin, 'sidebars_widgets' ) ).value || {};
		await withOptions( admin, { sidebars_widgets: withoutWidgetLayouts( sidebars ) }, async () => {
			const classes = bodyClasses( await servedHtml( browser, siteUrl( '?p=1' ) ) );
			expect( classes ).not.toContain( FALLBACK );
			expect( classes ).not.toContain( 'siteorigin-panels' );
		} );
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

	test( 'front-end widget area', async ( { browser } ) => {
		const { page } = ctx;
		await page.goto( siteUrl( '?p=1' ) );
		await expect( page.locator( `#${ SIDEBAR } .panels-e2e-text`, { hasText: TEXT } ) ).toHaveCount( 1 );
		expectNoPageErrors( ctx.errors );

		// The layout has no stretched rows, so the footer removes the fallback class.
		const html = await servedHtml( browser, siteUrl( '?p=1' ) );
		const classes = bodyClasses( html );
		expect( count( classes, FALLBACK ) ).toBe( 1 );
		expect( classes ).not.toContain( 'siteorigin-panels' );
		expect( html.split( STRIP ).length - 1 ).toBe( 1 );
		await expect( page.locator( 'body' ) ).not.toHaveClass( FALLBACK_CLASS );
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

	test( 'stretched rows in a Layout Builder widget cover the viewport before JavaScript', async ( { browser } ) => {
		const option = ( await uiOption( admin, 'widget_siteorigin-panels-builder' ) ).value;
		const number = Object.keys( option ).find( ( key ) => /^\d+$/.test( key ) );
		const value = { ...option, [ number ]: { ...option[ number ], panels_data: stretchedLayout() } };

		await withOptions( admin, { 'widget_siteorigin-panels-builder': value }, async () => {
			const context = await browser.newContext( {
				viewport: { width: 1280, height: 800 },
				storageState: { cookies: [], origins: [] },
			} );
			let release = () => {};
			let entered;
			const held = new Promise( ( resolve ) => { release = resolve; } );
			const gateEntered = new Promise( ( resolve ) => { entered = resolve; } );

			try {
				const target = siteUrl( '?p=1&panels_e2e_legacy_container=1' );
				const gate = siteUrl( 'widget-cls-ready-gate.js' );
				await context.route( gate, async ( route ) => {
					entered();
					await held;
					await route.fulfill( { contentType: 'application/javascript', body: '' } );
				} );
				await context.route( target, async ( route ) => {
					const response = await route.fetch();
					let html = ( await response.text() ).replace(
						'</head>',
						'<style>.panel-layout:not(.panel-layout .panel-layout){width:min(600px,100%)!important;max-width:none!important;margin-left:auto!important;margin-right:auto!important}</style></head>'
					);
					html = html.replace( '</body>', `<script src="${ gate }"></script></body>` );
					await route.fulfill( { response, body: html } );
				} );
				const page = await context.newPage();
				await page.goto( target, { waitUntil: 'commit' } );
				await gateEntered;

				// styling.js removes the fallback class first in its DOM-ready callback, so the class
				// still being there shows the rows below are measured before that callback ran.
				const rows = page.locator( `#${ SIDEBAR } .siteorigin-panels-stretch.panel-row-style` );
				await expect( rows ).toHaveCount( 1 );
				const state = await page.evaluate( () => ( {
					ready: document.readyState,
					styling: !! document.querySelector( 'script[src*="js/styling"]' ),
					classes: Array.from( document.body.classList ),
				} ) );
				expect( state.ready ).toBe( 'loading' );
				expect( state.styling ).toBe( true );
				expect( state.classes ).toContain( FALLBACK );
				expect( state.classes ).not.toContain( 'siteorigin-panels' );
				await expect.poll( () => rows.first().evaluate( ( element ) => getComputedStyle( element ).marginLeft ) ).toBe( '-1000px' );
				for ( const rect of await rowRects( rows ) ) {
					expect( rect.left ).toBeLessThanOrEqual( 1 );
					expect( rect.right ).toBeGreaterThanOrEqual( rect.width - 1 );
				}

				release();
				await page.waitForLoadState( 'domcontentloaded' );
				await expect( page.getByText( STRETCHED_TEXT ) ).toBeVisible();
				await expect( page.locator( 'body' ) ).not.toHaveClass( FALLBACK_CLASS );
				await expect.poll( async () => ( await rowRects( rows ) ).every( ( rect ) =>
					Math.abs( rect.left ) <= 1 && Math.abs( rect.right - rect.width ) <= 1
				) ).toBe( true );
			} finally {
				release();
				await context.close();
			}
		} );
	} );

	test( 'block widgets with a nested layout get the fallback class', async ( { browser } ) => {
		const layout = stretchedLayout();
		const encoded = await rest( admin, 'POST', '/wp/v2/widget-types/siteorigin-panels-builder/encode', {
			data: { form_data: `widget-siteorigin-panels-builder[1][panels_data]=${ encodeURIComponent( JSON.stringify( layout ) ) }` },
		} );
		expect( encoded.status, JSON.stringify( encoded.body ) ).toBe( 200 );
		const { encoded: data, hash } = encoded.body.instance;
		const legacyWidget = `<!-- wp:group --><div class="wp-block-group"><!-- wp:legacy-widget ${ JSON.stringify( { idBase: 'siteorigin-panels-builder', instance: { encoded: data, hash } } ) } /--></div><!-- /wp:group -->`;
		const layoutInBlock = `<!-- wp:group --><div class="wp-block-group">${ layoutBlock( layout ) }</div><!-- /wp:group -->`;
		const option = ( await uiOption( admin, 'widget_siteorigin-panels-builder' ) ).value;
		const widgetId = `siteorigin-panels-builder-${ Object.keys( option ).find( ( key ) => /^\d+$/.test( key ) ) }`;
		const legacyWidgetById = `<!-- wp:group --><div class="wp-block-group"><!-- wp:legacy-widget ${ JSON.stringify( { id: widgetId } ) } /--></div><!-- /wp:group -->`;
		const cases = [
			{ name: 'Legacy Widget in a Group', content: legacyWidget, expected: true, rows: 1 },
			{ name: 'Layout Block in a Group', content: layoutInBlock, expected: true, rows: 1 },
			{ name: 'Legacy Widget by id in a Group', content: legacyWidgetById, expected: true },
			{ name: 'paragraph', content: '<!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph -->', expected: false },
			{ name: 'malformed Legacy Widget', content: '<!-- wp:legacy-widget {"idBase":', expected: false },
			{ name: 'inactive Legacy Widget', content: legacyWidget, expected: false, area: 'wp_inactive_widgets' },
			{ name: 'orphaned Legacy Widget', content: legacyWidget, expected: false, area: 'orphaned_widgets_1' },
		];

		const stored = await uiOption( admin, 'widget_block' );
		const blocks = stored.exists && stored.value ? stored.value : { _multiwidget: 1 };
		const number = Math.max( 1, ...Object.keys( blocks ).filter( ( key ) => /^\d+$/.test( key ) ).map( Number ) ) + 1;
		const base = withoutWidgetLayouts( ( await uiOption( admin, 'sidebars_widgets' ) ).value || {} );

		for ( const check of cases ) {
			const area = check.area || SIDEBAR;
			const sidebars = { ...base, [ area ]: [ ...( base[ area ] || [] ), `block-${ number }` ] };
			await withOptions( admin, {
				widget_block: { ...blocks, [ number ]: { content: check.content } },
				sidebars_widgets: sidebars,
			}, async () => {
				const html = await servedHtml( browser, siteUrl( '?p=1' ) );
				const classes = bodyClasses( html );
				expect( classes.includes( FALLBACK ), check.name ).toBe( check.expected );
				expect( classes, check.name ).not.toContain( 'siteorigin-panels' );
				if ( check.rows ) {
					expect( ( html.match( /data-stretch-type="/g ) || [] ).length, check.name ).toBe( check.rows );
				}
			} );
		}
	} );

	test( 'Page Builder and Layout Block pages keep both classes', async ( { browser } ) => {
		const pageLayout = { widgets: [ textWidget( 'Page layout' ) ], grids: [ { cells: 1, style: {} } ], grid_cells: [ cell ] };
		for ( const source of [ 'classic', 'block' ] ) {
			let id;
			try {
				id = await createPost( admin, 'page', {
					title: `Widget area ${ source } page`,
					status: 'publish',
					...( source === 'block' ? { content: layoutBlock( pageLayout ) } : {} ),
				} );
				if ( source === 'classic' ) {
					await seedLayout( admin, id, pageLayout );
				}
				const classes = bodyClasses( await servedHtml( browser, siteUrl( `?page_id=${ id }` ) ) );
				expect( count( classes, 'siteorigin-panels' ), source ).toBe( 1 );
				expect( count( classes, FALLBACK ), source ).toBe( 1 );
			} finally {
				if ( id ) {
					await deletePost( admin, 'page', id );
				}
			}
		}
	} );
} );
