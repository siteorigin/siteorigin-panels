/**
 * Classic editor builder: add rows and widgets, move widgets between rows and inside a cell, sort rows,
 * save, and check that the builder field, the stored layout and the front-end page agree on where
 * every widget sits. Runs as an administrator and as an author.
 */
const { expect, test } = require( '@playwright/test' );

const {
	addRow,
	addWidget,
	adminLogin,
	createBrowserUser,
	deletePost,
	deleteUser,
	dragTo,
	expectNoPageErrors,
	fieldLayout,
	newLoggedInPage,
	openClassicBuilder,
	placement,
	rawStorage,
	setWidgetText,
	siteUrl,
	waitForField,
} = require( './builder-helpers' );

test.describe.configure( { mode: 'serial' } );

// Widgets are found by their text, which is unique in this layout. A widget added in this session has
// no widget_id in the builder model yet: the builder makes a new one each time it writes the field,
// until the post is saved and opened again. So widget_id is checked for presence before the first
// save, and for equality from the first reload on.
const TEXTS = [ 'Text A', 'Text B', 'Text C' ];
const withoutIds = ( list ) => list.map( ( { widget_id: widgetId, ...rest } ) => rest );
const byText = ( list ) => Object.fromEntries( list.map( ( p ) => [ p.text, p ] ) );
const expectAllWidgets = ( list ) => {
	expect( list.map( ( p ) => p.text ).sort() ).toEqual( TEXTS );
	for ( const p of list ) {
		expect( p.widget_id, `${ p.text } has a widget_id` ).toBeTruthy();
	}
};

for ( const role of [ 'administrator', 'author' ] ) {
	test.describe( `classic builder as ${ role }`, () => {
		let admin;
		let user = null;
		let ctx;
		let root;
		let postId;
		let storedPlacement;

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

		test( 'T1 add rows and widgets', async () => {
			const { page } = ctx;
			root = await openClassicBuilder( page, { postType: 'post' } );
			postId = Number( await page.locator( '#post_ID' ).inputValue() );
			await page.locator( '#title' ).fill( `Builder classic ${ role }` );

			const row1 = await addRow( root, 2 );
			const row2 = await addRow( root, 1 );
			const texts = [ [ row1, 0, 'Text A' ], [ row1, 1, 'Text B' ], [ row2, 0, 'Text C' ] ];

			for ( const [ row, cell, text ] of texts ) {
				const widget = await addWidget( root, row.locator( '.so-cells .cell' ).nth( cell ), 'Panels_E2E_Text_Widget' );
				await setWidgetText( root, widget, text );
			}

			await waitForField( page, ( l ) => ( l.widgets || [] ).filter( ( w ) => w.text ).length === 3, 'three widgets with text in the field' );
			const layout = await fieldLayout( page );
			expect( layout.grids ).toHaveLength( 2 );
			expect( layout.grid_cells ).toHaveLength( 3 );
			expect( layout.widgets ).toHaveLength( 3 );

			const list = placement( layout );
			expectAllWidgets( list );
			expect( byText( list )[ 'Text A' ] ).toMatchObject( { grid: 0, cell: 0 } );
			expect( byText( list )[ 'Text B' ] ).toMatchObject( { grid: 0, cell: 1 } );
			expect( byText( list )[ 'Text C' ] ).toMatchObject( { grid: 1, cell: 0 } );
			expectNoPageErrors( ctx.errors );
		} );

		test( 'T2 drag a widget to another row', async () => {
			const { page } = ctx;
			const widgetA = root.locator( '.so-widget' ).filter( { hasText: 'Text A' } );
			const widgetC = root.locator( '.so-widget' ).filter( { hasText: 'Text C' } );

			// Drop on the upper part of C, so A lands above C.
			await dragTo( page, widgetA.locator( '.title h4' ), widgetC, { offsetY: 4 } );

			await waitForField( page, ( l ) => {
				const p = byText( placement( l ) )[ 'Text A' ];
				return !! p && p.grid === 1 && p.cell === 0;
			}, 'widget A is in row 2' );
			const list = placement( await fieldLayout( page ) );
			expectAllWidgets( list );
			expect( list.filter( ( p ) => p.grid === 1 ).map( ( p ) => p.text ) ).toEqual( [ 'Text A', 'Text C' ] );
			expectNoPageErrors( ctx.errors );
		} );

		test( 'T3 reorder widgets in one cell', async () => {
			const { page } = ctx;
			const widgetA = root.locator( '.so-widget' ).filter( { hasText: 'Text A' } );
			const widgetC = root.locator( '.so-widget' ).filter( { hasText: 'Text C' } );

			await dragTo( page, widgetC.locator( '.title h4' ), widgetA, { offsetY: 4 } );

			await waitForField( page, ( l ) => placement( l ).filter( ( p ) => p.grid === 1 ).map( ( p ) => p.text ).join() === 'Text C,Text A', 'order in row 2 is C, A' );
			expectAllWidgets( placement( await fieldLayout( page ) ) );
			expectNoPageErrors( ctx.errors );
		} );

		test( 'T4 sort rows', async () => {
			const { page } = ctx;
			const rows = root.locator( '.so-row-container' );
			const before = byText( placement( await fieldLayout( page ) ) );

			await dragTo( page, rows.nth( 1 ).locator( '.so-row-move' ), rows.nth( 0 ), { offsetY: 4 } );

			await waitForField( page, ( l ) => {
				const p = byText( placement( l ) )[ 'Text C' ];
				return !! p && p.grid === 0;
			}, 'row 2 is now first' );
			const layout = await fieldLayout( page );
			expect( Number( layout.grids[ 0 ].cells ) ).toBe( 1 );
			expect( Number( layout.grids[ 1 ].cells ) ).toBe( 2 );
			const list = placement( layout );
			expectAllWidgets( list );
			const after = byText( list );
			for ( const text of TEXTS ) {
				expect( after[ text ].grid, `${ text } moved with its row` ).toBe( 1 - before[ text ].grid );
				expect( after[ text ].cell, `${ text } keeps its cell` ).toBe( before[ text ].cell );
			}
			expectNoPageErrors( ctx.errors );
		} );

		test( 'T5 save and stored layout', async () => {
			const { page } = ctx;
			const fieldPlacement = placement( await fieldLayout( page ) );
			await Promise.all( [
				page.waitForURL( /message=\d+/, { timeout: 60000 } ),
				page.locator( '#publish' ).click( { noWaitAfter: true } ),
			] );

			const raw = await rawStorage( admin, postId );
			expect( raw.meta_rows ).toBe( 1 );
			storedPlacement = placement( raw.meta );
			expectAllWidgets( storedPlacement );
			// The field is written again on submit, so widget_id of new widgets differs (see above).
			expect( withoutIds( storedPlacement ) ).toEqual( withoutIds( fieldPlacement ) );
			expectNoPageErrors( ctx.errors );
		} );

		test( 'T6 front-end page', async () => {
			const { page } = ctx;
			await page.goto( siteUrl( `?p=${ postId }` ) );

			for ( const p of storedPlacement ) {
				const cell = page.locator( `#pg-${ postId }-${ p.grid } #pgc-${ postId }-${ p.grid }-${ p.cell }` );
				await expect( cell.locator( '.panels-e2e-text', { hasText: p.text } ) ).toHaveCount( 1 );
			}
			expectNoPageErrors( ctx.errors );
		} );

		test( 'T7 reload and save again', async () => {
			const { page } = ctx;
			root = await openClassicBuilder( page, { postId } );
			await expect( root.locator( '.so-row-container' ) ).toHaveCount( 2 );
			await expect( root.locator( '.so-cells .cell' ) ).toHaveCount( 3 );
			await expect( root.locator( '.so-widget' ) ).toHaveCount( 3 );
			expect( placement( await fieldLayout( page ) ) ).toEqual( storedPlacement );

			await Promise.all( [
				page.waitForURL( /message=\d+/, { timeout: 60000 } ),
				page.locator( '#publish' ).click( { noWaitAfter: true } ),
			] );
			const raw = await rawStorage( admin, postId );
			expect( raw.meta_rows ).toBe( 1 );
			expect( placement( raw.meta ) ).toEqual( storedPlacement );
			expectNoPageErrors( ctx.errors );
		} );
	} );
}
