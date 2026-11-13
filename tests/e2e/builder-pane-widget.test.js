/**
 * Pane widgets: third-party tab and accordion widgets that hold a layout per pane and JSON-encode
 * an empty pane as `""` (tests/playground/mu-plugins/panels-e2e-pane-widget.php stands in for them).
 * A new or empty pane opens as an empty builder, and its content saves. Everything that holds or
 * might hold content still locks and keeps what is stored: a pane or page whose layout fails
 * part-way through loading, and `""` reaching the builder any other way than pane setup (#1464).
 * Pane setup runs for a nested builder however its widget arrives: added, pasted, restored by History
 * or inserted by a prebuilt layout.
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
	newLoggedInPage,
	openClassicBuilder,
	openWidgetDialog,
	rawStorage,
	seedLayout,
	setWidgetText,
	siteUrl,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

const BOOM = 'Panels_E2E_Boom_Widget';

const textWidget = ( id, grid, cell, text, klass = 'Panels_E2E_Text_Widget' ) => ( {
	text,
	panels_info: { class: klass, raw: false, grid, cell, id, widget_id: `pane-e2e-${ id }-${ text.replace( /\W+/g, '-' ) }`, style: {} },
} );

const oneCell = ( widgets ) => ( {
	widgets,
	grids: [ { cells: 1, style: {} } ],
	grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
} );

// Five rows, six cells and seven widgets, the third of which throws while the builder loads it.
const failingLayout = () => {
	const cells = [ 1, 1, 1, 2, 1 ];
	const places = [ [ 0, 0 ], [ 1, 0 ], [ 1, 0 ], [ 2, 0 ], [ 3, 0 ], [ 3, 1 ], [ 4, 0 ] ];
	const layout = {
		grids: cells.map( ( count ) => ( { cells: count, style: {} } ) ),
		grid_cells: [],
		widgets: places.map( ( [ grid, cell ], i ) => textWidget( i, grid, cell, `Keep ${ i }`, i === 2 ? BOOM : 'Panels_E2E_Text_Widget' ) ),
	};
	cells.forEach( ( count, grid ) => {
		for ( let index = 0; index < count; index++ ) {
			layout.grid_cells.push( { grid, index, weight: 1 / count, style: {} } );
		}
	} );

	return layout;
};

const paneWidgetPage = ( items, mode = 'default-empty' ) => oneCell( [ {
	mode,
	items,
	panels_info: { class: 'Panels_E2E_Pane_Widget', raw: false, grid: 0, cell: 0, id: 0, widget_id: 'pane-e2e-widget', style: {} },
} ] );

const layoutWidgetPage = ( inner, extra = [] ) => oneCell( [
	...extra,
	{
		panels_data: inner,
		builder_id: 'panee2e',
		panels_info: { class: 'SiteOrigin_Panels_Widgets_Layout', raw: false, grid: 0, cell: 0, id: extra.length, widget_id: 'pane-e2e-layout', style: {} },
	},
] );

const storedItems = ( raw ) => Object.values( raw.meta.widgets[ 0 ].items );

// Saving a page through the editor adds sidebar emulator keys to each widget of a Layout Builder
// widget's inner layout, with or without a load failure. Everything else must match.
const withoutEmulatorKeys = ( layout ) => ( {
	...layout,
	widgets: layout.widgets.map( ( widget ) => {
		const { option_name: optionName, so_sidebar_emulator_id: emulatorId, ...rest } = widget; // eslint-disable-line no-unused-vars

		return rest;
	} ),
} );

/**
 * The state of every pane builder in a scope: its field, whether it locked, and its widget count.
 */
const paneStates = ( scope, selector = '.panels-e2e-pane .siteorigin-page-builder-widget' ) => scope.locator( selector ).evaluateAll( ( panes ) => panes.map( ( pane ) => {
	const input = pane.querySelector( 'input.panels-data' );
	const builder = window.jQuery( input ).data( 'builder' );
	const rows = builder ? builder.model.get( 'rows' ) : null;

	return {
		value: input.value,
		disabled: input.disabled,
		failed: builder ? builder.model.loadFailed : null,
		notice: Boolean( pane.querySelector( '.so-panels-load-failed-notice' ) ),
		rows: rows ? rows.length : null,
		widgets: rows ? rows.reduce( ( n, row ) => n + row.get( 'cells' ).reduce( ( k, cell ) => k + cell.get( 'widgets' ).length, 0 ), 0 ) : null,
	};
} ) );

const EMPTY_UNLOCKED = { value: '', disabled: false, failed: false, notice: false, rows: 0, widgets: 0 };

const save = async ( page ) => {
	await Promise.all( [
		page.waitForURL( /message=\d+/, { timeout: 60000 } ),
		page.locator( '#publish' ).click( { noWaitAfter: true } ),
	] );
};

const openFirstWidget = async ( root, page, ready ) => {
	const dialog = await openWidgetDialog( root.locator( '.so-widget' ).first(), page );
	await expect( dialog.locator( ready ).first() ).toBeVisible( { timeout: 15000 } );

	return dialog;
};

test.describe( 'pane widgets that hold a layout per pane', () => {
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

	const newPage = async ( title, layout, sanitize = true ) => {
		const id = await createPost( admin, 'page', { title } );
		posts.push( [ 'page', id ] );
		await seedLayout( admin, id, layout, { sanitize } );

		return id;
	};

	test( 'a new pane opens empty beside a pane with content, and both survive two saves', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: add a pane', paneWidgetPage( [ { panels_data: oneCell( [ textWidget( 0, 0, 0, 'Keep pane 0' ) ] ) } ] ) );
		const seeded = storedItems( await rawStorage( admin, id ) )[ 0 ].panels_data;

		let root = await openClassicBuilder( page, { postId: id } );
		let dialog = await openFirstWidget( root, page, '.panels-e2e-add-pane' );
		await dialog.locator( '.panels-e2e-add-pane' ).click();
		await expect( dialog.locator( '.panels-e2e-pane' ) ).toHaveCount( 2 );
		await expect.poll( () => paneStates( dialog ) ).toEqual( [
			{ value: expect.stringContaining( 'Keep pane 0' ), disabled: false, failed: false, notice: false, rows: 1, widgets: 1 },
			EMPTY_UNLOCKED,
		] );
		await expect( dialog.locator( '.panels-e2e-pane' ).nth( 1 ).locator( '.so-builder-toolbar' ) ).toBeVisible();
		await closeDialog( page );
		await expectOpenDialogs( page, 0 );
		await save( page );

		let items = storedItems( await rawStorage( admin, id ) );
		expect( items ).toHaveLength( 2 );
		expect( items[ 0 ].panels_data ).toEqual( seeded );
		expect( items[ 1 ].panels_data ).toBe( '' );

		// The saved empty pane renders `""` again and still opens empty. Give it content.
		root = await openClassicBuilder( page, { postId: id } );
		dialog = await openFirstWidget( root, page, '.panels-e2e-add-pane' );
		await expect.poll( async () => ( await paneStates( dialog ) )[ 1 ] ).toEqual( EMPTY_UNLOCKED );
		const inner = dialog.locator( '.panels-e2e-pane' ).nth( 1 ).locator( '.siteorigin-page-builder-widget' );
		const row = await addRow( inner, 1, page );
		const widget = await addWidget( inner, row.locator( '.so-cells .cell' ).first(), 'Panels_E2E_Text_Widget', page );
		await setWidgetText( inner, widget, 'New pane text', page );
		await closeDialog( page );
		await expectOpenDialogs( page, 0 );
		await save( page );

		items = storedItems( await rawStorage( admin, id ) );
		expect( items[ 0 ].panels_data ).toEqual( seeded );
		expect( items[ 1 ].panels_data.widgets.map( ( w ) => w.text ) ).toEqual( [ 'New pane text' ] );

		root = await openClassicBuilder( page, { postId: id } );
		dialog = await openFirstWidget( root, page, '.panels-e2e-add-pane' );
		await expect.poll( async () => ( await paneStates( dialog ) ).map( ( s ) => [ s.failed, s.widgets ] ) ).toEqual( [ [ false, 1 ], [ false, 1 ] ] );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );

	for ( const mode of [ 'default-empty', 'keep-old' ] ) {
		test( `a pane whose layout fails part-way through loading locks and keeps all seven widgets (${ mode })`, async () => {
			const { page } = ctx;
			const id = await newPage( `Pane widget: failing pane, ${ mode }`, paneWidgetPage( [ { panels_data: failingLayout() } ], mode ) );
			const seeded = storedItems( await rawStorage( admin, id ) )[ 0 ].panels_data;
			expect( seeded.widgets ).toHaveLength( 7 );

			const root = await openClassicBuilder( page, { postId: id } );
			const dialog = await openFirstWidget( root, page, '.panels-e2e-add-pane' );
			await expect.poll( () => paneStates( dialog ) ).toEqual( [
				// Every row and the first two widgets loaded before the third threw.
				{ value: expect.stringContaining( 'Keep 6' ), disabled: true, failed: true, notice: true, rows: 5, widgets: 2 },
			] );
			await closeDialog( page );
			await save( page );

			expect( storedItems( await rawStorage( admin, id ) )[ 0 ].panels_data ).toEqual( seeded );
			expectNoPageErrors( ctx.errors );
		} );
	}

	test( 'a page whose layout fails part-way through loading locks, and Update leaves its stored bytes alone', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: failing page', failingLayout(), false );
		const before = ( await rawStorage( admin, id ) ).meta_bytes;
		expect( before ).toContain( 'Keep 6' );

		// A locked builder hides its toolbar, so open the page without waiting for one.
		await page.goto( siteUrl( `wp-admin/post.php?post=${ id }&action=edit` ) );
		await expect( page.locator( '#siteorigin-panels-metabox .so-panels-load-failed-notice' ) ).toBeVisible( { timeout: 30000 } );
		const state = await page.evaluate( () => {
			const builder = window.jQuery( '#siteorigin-panels-metabox .siteorigin-panels-data-field' ).data( 'builder' );

			return { failed: builder.model.loadFailed, rows: builder.model.get( 'rows' ).length };
		} );
		expect( state ).toEqual( { failed: true, rows: 5 } );
		await save( page );

		expect( ( await rawStorage( admin, id ) ).meta_bytes ).toBe( before );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'a Layout Builder widget whose inner layout fails part-way through loading keeps it on save', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: failing nested layout', layoutWidgetPage( failingLayout() ) );
		const seeded = ( await rawStorage( admin, id ) ).meta.widgets[ 0 ];
		expect( seeded.panels_data.widgets ).toHaveLength( 7 );

		const root = await openClassicBuilder( page, { postId: id } );
		const dialog = await openFirstWidget( root, page, '.siteorigin-page-builder-widget' );
		await expect.poll( () => paneStates( dialog, '.siteorigin-page-builder-widget' ) ).toEqual( [
			{ value: expect.stringContaining( 'Keep 6' ), disabled: true, failed: true, notice: true, rows: 5, widgets: 2 },
		] );
		await closeDialog( page );
		await save( page );

		const stored = ( await rawStorage( admin, id ) ).meta.widgets[ 0 ];
		expect( stored.panels_data.widgets ).toHaveLength( 7 );
		expect( withoutEmulatorKeys( stored.panels_data ) ).toEqual( seeded.panels_data );
		// The Layout Builder widget gives itself a new builder ID on every save, and the editor save
		// adds the emulator keys and a cell index to it, as it does without a load failure.
		const outer = ( widget ) => {
			const { option_name: optionName, so_sidebar_emulator_id: emulatorId, ...rest } = widget; // eslint-disable-line no-unused-vars
			const { cell_index: cellIndex, ...info } = rest.panels_info; // eslint-disable-line no-unused-vars

			return { ...rest, panels_info: info, builder_id: 'x', panels_data: null };
		};
		expect( outer( stored ) ).toEqual( outer( seeded ) );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'a Layout Builder widget restored by History with an inner layout of `""` opens empty', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: history', layoutWidgetPage( '""', [ textWidget( 0, 0, 0, 'Delete me' ) ] ), false );

		const root = await openClassicBuilder( page, { postId: id } );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
		const first = root.locator( '.so-widget' ).first();
		await first.hover();
		await first.locator( '.actions .widget-delete' ).click( { force: true } );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 1 );

		await root.locator( '.so-builder-toolbar .so-history' ).click();
		const history = page.locator( '.so-panels-dialog-history' );
		await history.locator( '.history-entry', { hasText: 'Original' } ).click();
		await history.locator( '.so-restore' ).click();
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );

		const dialog = await openWidgetDialog( root.locator( '.so-widget' ).nth( 1 ), page );
		await expect.poll( () => paneStates( dialog, '.siteorigin-page-builder-widget' ) ).toEqual( [ EMPTY_UNLOCKED ] );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'a Layout Builder widget inserted by a prebuilt layout with an inner layout of `""` opens empty', async () => {
		const { page } = ctx;
		const root = await openClassicBuilder( page, { postType: 'page' } );
		posts.push( [ 'page', Number( await page.locator( '#post_ID' ).inputValue() ) ] );

		await root.locator( '.so-builder-toolbar .so-prebuilt-add' ).first().click();
		// The fixture's layout is listed under Theme Defined Layouts on the default tab.
		await page.locator( '.so-directory-item', { hasText: 'Panels E2E nested empty' } ).filter( { visible: true } ).first().click();
		await page.getByRole( 'button', { name: 'Insert', exact: true } ).filter( { visible: true } ).first().click();
		await page.getByText( 'Insert after', { exact: true } ).filter( { visible: true } ).first().click();
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 1, { timeout: 15000 } );
		await expectOpenDialogs( page, 0 );

		const dialog = await openWidgetDialog( root.locator( '.so-widget' ).first(), page );
		await expect.poll( () => paneStates( dialog, '.siteorigin-page-builder-widget' ) ).toEqual( [ EMPTY_UNLOCKED ] );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );

	const copyAndPaste = async ( root, page ) => {
		const widget = root.locator( '.so-widget' ).first();
		await widget.click( { button: 'right' } );
		await page.getByText( 'Copy Widget', { exact: true } ).filter( { visible: true } ).first().click();
		await widget.click( { button: 'right' } );
		await page.getByText( 'Paste Widget', { exact: true } ).filter( { visible: true } ).first().click();
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 2 );
	};

	test( 'a Layout Builder widget pasted with an inner layout of `""` opens empty', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: paste empty', layoutWidgetPage( '""' ), false );
		const root = await openClassicBuilder( page, { postId: id } );
		await copyAndPaste( root, page );

		const dialog = await openWidgetDialog( root.locator( '.so-widget' ).nth( 1 ), page );
		await expect.poll( () => paneStates( dialog, '.siteorigin-page-builder-widget' ) ).toEqual( [ EMPTY_UNLOCKED ] );
		await closeDialog( page );
		expectNoPageErrors( ctx.errors );
	} );

	test( 'a Layout Builder widget pasted with an inner layout that fails part-way through loading locks and keeps it', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: paste failing', layoutWidgetPage( failingLayout() ) );
		const seeded = ( await rawStorage( admin, id ) ).meta.widgets[ 0 ].panels_data;
		const root = await openClassicBuilder( page, { postId: id } );
		await copyAndPaste( root, page );

		const dialog = await openWidgetDialog( root.locator( '.so-widget' ).nth( 1 ), page );
		await expect.poll( () => paneStates( dialog, '.siteorigin-page-builder-widget' ) ).toEqual( [
			{ value: expect.stringContaining( 'Keep 6' ), disabled: true, failed: true, notice: true, rows: 5, widgets: 2 },
		] );
		await closeDialog( page );
		await save( page );

		const widgets = ( await rawStorage( admin, id ) ).meta.widgets;
		expect( widgets ).toHaveLength( 2 );
		for ( const widget of widgets ) {
			expect( withoutEmulatorKeys( widget.panels_data ) ).toEqual( seeded );
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'an empty string, `""`, null and "null" handed straight to the builder still lock', async () => {
		const { page } = ctx;
		for ( const value of [ '', '""', null, 'null' ] ) {
			const root = await openClassicBuilder( page, { postType: 'page' } );
			// The call the Prebuilt and History dialogs make with the layout they hold.
			const state = await page.evaluate( ( v ) => {
				const builder = window.jQuery( '#siteorigin-panels-metabox .siteorigin-panels-data-field' ).data( 'builder' );
				builder.model.loadPanelsData( v );

				return { failed: builder.model.loadFailed, data: builder.model.getPanelsData() };
			}, value );
			expect( state, JSON.stringify( value ) ).toEqual( { failed: true, data: null } );
			await expect( root.locator( '.so-panels-load-failed-notice' ) ).toBeVisible();
		}
		expectNoPageErrors( ctx.errors );
	} );

	test( 'importing a file holding `""` is refused and leaves the layout alone', async () => {
		const { page } = ctx;
		const id = await newPage( 'Pane widget: import', oneCell( [ textWidget( 0, 0, 0, 'Keep imported page' ) ] ) );
		const root = await openClassicBuilder( page, { postId: id } );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 1 );

		// The URL and field name the Import/Export tab's uploader uses.
		const importFile = ( contents ) => page.evaluate( async ( body ) => {
			const form = new FormData();
			form.append( 'action', 'so_panels_import_layout' );
			form.append( 'panels_import_data', new Blob( [ body ], { type: 'application/json' } ), 'layout.json' );
			const result = await fetch( window.panelsOptions.plupload.url, { method: 'POST', body: form, credentials: 'same-origin' } );

			return { status: result.status, body: await result.text() };
		}, contents );

		// Control: the same request with a layout returns it, so the request itself is accepted.
		const control = await importFile( JSON.stringify( oneCell( [ textWidget( 1, 0, 0, 'Imported' ) ] ) ) );
		expect( control.status ).toBe( 200 );
		expect( JSON.parse( control.body ).widgets.map( ( w ) => w.text ) ).toEqual( [ 'Imported' ] );

		// `""` is not a layout: the importer stops without returning one.
		const response = await importFile( '""' );
		expect( response.status ).toBe( 200 );
		expect( response.body.trim() ).toBe( '' );
		await expect( root.locator( '.so-widget' ) ).toHaveCount( 1 );
		expect( ( await rawStorage( admin, id ) ).meta.widgets.map( ( w ) => w.text ) ).toEqual( [ 'Keep imported page' ] );
		expectNoPageErrors( ctx.errors );
	} );
} );
