/**
 * The layout structure rule of the layout-update ability over the real REST
 * route, on both storage paths.
 *
 * The ability stores only a layout the builder can load. A layout whose cell
 * row reference is not a number is refused, and the stored layout, the post
 * and its revisions stay as they were. A layout whose references resolve is
 * stored, and a layout that layout-get returns is accepted again.
 *
 * Raw storage is read straight from the database through the test-only
 * mu-plugin, so "unchanged" is checked against real storage. The mu-plugin's
 * pre-write listener log shows that a refused write stops before the hook.
 *
 * Two real users: the administrator on pages, and an author on its own draft
 * posts.
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createPost,
	createUserSession,
	deletePost,
	deleteUser,
	layoutBlock,
	probeLayout,
	probeState,
	rawStorage,
	runAbility,
	setProbe,
	snapshot,
} = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

const PARAGRAPH = '<!-- wp:paragraph --><p>Keep this paragraph</p><!-- /wp:paragraph -->';

// Cell row references that are not numbers.
const NOT_A_NUMBER = [ 'x', 'row-1', '0 {} *' ];

const UNRESOLVED_CODE = 'siteorigin_panels_layout_update_unresolved_reference';

let admin;
let author;
const created = [];

const layoutUpdate = ( session, input ) => runAbility( session, 'siteorigin-panels/layout-update', input );
const layoutGet = ( session, postId ) => runAbility( session, 'siteorigin-panels/layout-get', { post_id: postId }, 'GET' );

const createTracked = async ( session, type, fields ) => {
	const id = await createPost( session, type, fields );
	created.push( { type, id } );

	return id;
};

/**
 * A probe layout whose only cell points at the given row reference.
 */
const layoutWithCellRow = ( reference, text ) => {
	const layout = probeLayout( text );
	layout.grid_cells[ 0 ].grid = reference;

	return layout;
};

/**
 * A layout with widgets and no rows, cells or widget placements.
 */
const widgetsOnlyLayout = ( text ) => ( {
	widgets: [ { text, panels_info: { class: 'Panels_E2E_Probe_Widget' } } ],
} );

/**
 * The message of a refused write names what a layout needs.
 */
const expectClearMessage = ( message ) => {
	expect( typeof message ).toBe( 'string' );

	for ( const term of [ 'grids', 'grid_cells', 'panels_info.grid', 'panels_info.cell', 'layout-get' ] ) {
		expect( message ).toContain( term );
	}
};

/**
 * Run a classic write that must be refused. Nothing may change, the pre-write
 * hook must not fire and nothing may render.
 */
const expectRefusedMetaWrite = async ( { session, id, type, panelsData } ) => {
	const before = await snapshot( admin, id, type );
	await setProbe( admin, 'pass' );

	const response = await layoutUpdate( session, { post_id: id, panels_data: panelsData } );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
	expect( response.body.updated ).toBe( false );
	expect( response.body.source ).toBe( 'unsupported' );
	expectClearMessage( response.body.message );

	const state = await probeState( admin );
	expect( state.log ).toHaveLength( 0 );
	expect( state.probe_renders ).toBe( 0 );

	expect( await snapshot( admin, id, type ) ).toStrictEqual( before );

	return before;
};

/**
 * Run a Layout Block write that must be refused. Nothing may change, the
 * pre-write hook must not fire and nothing may render.
 */
const expectRefusedBlockWrite = async ( { session, id, type, panelsData, blockIndex = 0 } ) => {
	const before = await snapshot( admin, id, type );
	await setProbe( admin, 'pass' );

	const response = await layoutUpdate( session, { post_id: id, panels_data: panelsData, block_index: blockIndex } );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 500 );
	expect( response.body.code ).toBe( UNRESOLVED_CODE );
	expectClearMessage( response.body.message );

	const state = await probeState( admin );
	expect( state.log ).toHaveLength( 0 );
	expect( state.probe_renders ).toBe( 0 );

	expect( await snapshot( admin, id, type ) ).toStrictEqual( before );

	return before;
};

test.beforeAll( async () => {
	admin = await adminLogin();
	author = await createUserSession( admin, 'author' );

	await setProbe( admin, 'pass' );
} );

test.afterAll( async () => {
	if ( ! admin ) {
		return;
	}

	await setProbe( admin, 'pass' ).catch( () => {} );

	for ( const { type, id } of created ) {
		await deletePost( admin, type, id ).catch( () => {} );
	}

	if ( author ) {
		await deleteUser( admin, author.userId ).catch( () => {} );
		await author.context.dispose();
	}

	await admin.context.dispose();
} );

test.describe( 'classic (meta) path', () => {
	let pageId;

	test.beforeAll( async () => {
		pageId = await createTracked( admin, 'page', { title: 'Panels e2e structure classic page' } );
	} );

	test( 'S1: a layout whose references resolve is stored', async () => {
		const response = await layoutUpdate( admin, { post_id: pageId, panels_data: probeLayout( 'S1 stored text' ) } );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.updated ).toBe( true );
		expect( response.body.source ).toBe( 'meta' );

		const raw = await rawStorage( admin, pageId );
		expect( raw.meta.widgets[ 0 ].text ).toBe( 'S1 stored text' );
		expect( raw.meta.grid_cells[ 0 ].grid ).toBe( 0 );
	} );

	for ( const reference of NOT_A_NUMBER ) {
		test( `S2: a cell row reference of ${ JSON.stringify( reference ) } is refused and the stored layout is unchanged`, async () => {
			const before = await expectRefusedMetaWrite( {
				session: admin,
				id: pageId,
				type: 'page',
				panelsData: layoutWithCellRow( reference, 'S2 refused text' ),
			} );

			expect( before.raw.meta.widgets[ 0 ].text ).toBe( 'S1 stored text' );
		} );
	}

	test( 'S3: a refused write to a page without a layout stores no layout', async () => {
		const emptyPage = await createTracked( admin, 'page', { title: 'Panels e2e structure empty page' } );

		const before = await expectRefusedMetaWrite( {
			session: admin,
			id: emptyPage,
			type: 'page',
			panelsData: layoutWithCellRow( 'x', 'S3 refused text' ),
		} );

		expect( before.raw.meta_exists ).toBe( false );
		expect( ( await rawStorage( admin, emptyPage ) ).meta_exists ).toBe( false );
	} );

	test( 'S4: a layout with widgets and no rows or cells is refused', async () => {
		await expectRefusedMetaWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			panelsData: widgetsOnlyLayout( 'S4 refused text' ),
		} );
	} );

	test( 'S5: an author is refused the same way on its own post', async () => {
		const postId = await createTracked( author, 'post', { title: 'Panels e2e structure author post' } );

		const stored = await layoutUpdate( author, { post_id: postId, panels_data: probeLayout( 'S5 stored text' ) } );
		expect( stored.status, JSON.stringify( stored.body ) ).toBe( 200 );
		expect( stored.body.updated ).toBe( true );

		const before = await expectRefusedMetaWrite( {
			session: author,
			id: postId,
			type: 'post',
			panelsData: layoutWithCellRow( 'x', 'S5 refused text' ),
		} );

		expect( before.raw.meta.widgets[ 0 ].text ).toBe( 'S5 stored text' );
	} );

	test( 'S6: a layout that layout-get returns is accepted again and stored unchanged', async () => {
		const before = await rawStorage( admin, pageId );

		const read = await layoutGet( admin, pageId );
		expect( read.status, JSON.stringify( read.body ) ).toBe( 200 );
		expect( read.body.layouts[ 0 ].storage ).toBe( 'meta' );

		const response = await layoutUpdate( admin, { post_id: pageId, panels_data: read.body.layouts[ 0 ].panels_data } );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.updated ).toBe( true );
		expect( response.body.source ).toBe( 'meta' );

		// layout-get returns the same layout after the write as before it.
		const again = await layoutGet( admin, pageId );
		expect( again.body.layouts[ 0 ].panels_data ).toStrictEqual( read.body.layouts[ 0 ].panels_data );

		// The stored layout does not change: every widget came back unchanged,
		// so each keeps its stored value, without the `cell_index` the read
		// filter adds to each widget placement.
		const after = await rawStorage( admin, pageId );
		expect( after.meta ).toStrictEqual( before.meta );
		expect( after.meta.grids ).toStrictEqual( before.meta.grids );
		expect( after.meta.grid_cells ).toStrictEqual( before.meta.grid_cells );
		expect( after.meta.widgets[ 0 ].text ).toBe( before.meta.widgets[ 0 ].text );
	} );

	test( 'S7: an empty layout still clears the classic layout', async () => {
		const response = await layoutUpdate( admin, { post_id: pageId, panels_data: {} } );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.updated ).toBe( true );
		expect( response.body.source ).toBe( 'meta' );

		expect( ( await rawStorage( admin, pageId ) ).meta_exists ).toBe( false );
	} );
} );

test.describe( 'Layout Block path', () => {
	let pageId;

	test.beforeAll( async () => {
		pageId = await createTracked( admin, 'page', {
			title: 'Panels e2e structure block page',
			content: [ PARAGRAPH, layoutBlock( probeLayout( 'T0 seeded text' ) ) ].join( '\n\n' ),
		} );
	} );

	test( 'T1: a layout whose references resolve is stored', async () => {
		const response = await layoutUpdate( admin, {
			post_id: pageId,
			panels_data: probeLayout( 'T1 stored text' ),
			block_index: 0,
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.updated ).toBe( true );
		expect( response.body.source ).toBe( 'block' );

		const raw = await rawStorage( admin, pageId );
		expect( raw.blocks[ 0 ].widgets[ 0 ].text ).toBe( 'T1 stored text' );
		expect( raw.blocks[ 0 ].grid_cells[ 0 ].grid ).toBe( 0 );
		expect( raw.post_content ).toContain( 'Keep this paragraph' );
	} );

	for ( const reference of NOT_A_NUMBER ) {
		test( `T2: a cell row reference of ${ JSON.stringify( reference ) } is refused and the stored block is unchanged`, async () => {
			const before = await expectRefusedBlockWrite( {
				session: admin,
				id: pageId,
				type: 'page',
				panelsData: layoutWithCellRow( reference, 'T2 refused text' ),
			} );

			expect( before.raw.blocks[ 0 ].widgets[ 0 ].text ).toBe( 'T1 stored text' );
			expect( before.raw.meta_exists ).toBe( false );
		} );
	}

	test( 'T3: a layout with widgets and no rows or cells is refused', async () => {
		await expectRefusedBlockWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			panelsData: widgetsOnlyLayout( 'T3 refused text' ),
		} );
	} );

	test( 'T4: an author is refused the same way on its own post', async () => {
		const postId = await createTracked( author, 'post', {
			title: 'Panels e2e structure author block post',
			content: [ PARAGRAPH, layoutBlock( probeLayout( 'T4 seeded text' ) ) ].join( '\n\n' ),
		} );

		const stored = await layoutUpdate( author, {
			post_id: postId,
			panels_data: probeLayout( 'T4 stored text' ),
			block_index: 0,
		} );
		expect( stored.status, JSON.stringify( stored.body ) ).toBe( 200 );
		expect( stored.body.updated ).toBe( true );

		const before = await expectRefusedBlockWrite( {
			session: author,
			id: postId,
			type: 'post',
			panelsData: layoutWithCellRow( 'x', 'T4 refused text' ),
		} );

		expect( before.raw.blocks[ 0 ].widgets[ 0 ].text ).toBe( 'T4 stored text' );
	} );

	test( 'T5: a layout that layout-get returns is accepted again and stored unchanged', async () => {
		const before = await rawStorage( admin, pageId );

		const read = await layoutGet( admin, pageId );
		expect( read.status, JSON.stringify( read.body ) ).toBe( 200 );
		expect( read.body.layouts[ 0 ].storage ).toBe( 'block' );

		const response = await layoutUpdate( admin, {
			post_id: pageId,
			panels_data: read.body.layouts[ 0 ].panels_data,
			block_index: read.body.layouts[ 0 ].block_index,
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.updated ).toBe( true );
		expect( response.body.source ).toBe( 'block' );

		// layout-get returns the same layout after the write as before it.
		const again = await layoutGet( admin, pageId );
		expect( again.body.layouts[ 0 ].panels_data ).toStrictEqual( read.body.layouts[ 0 ].panels_data );

		// The stored block layout does not change: every widget came back
		// unchanged, so each keeps its stored value.
		const after = await rawStorage( admin, pageId );
		expect( after.blocks[ 0 ] ).toStrictEqual( before.blocks[ 0 ] );
		expect( after.blocks[ 0 ].grids ).toStrictEqual( before.blocks[ 0 ].grids );
		expect( after.blocks[ 0 ].grid_cells ).toStrictEqual( before.blocks[ 0 ].grid_cells );
		expect( after.blocks[ 0 ].widgets[ 0 ].text ).toBe( before.blocks[ 0 ].widgets[ 0 ].text );
	} );
} );
