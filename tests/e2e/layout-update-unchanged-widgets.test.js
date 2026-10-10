/**
 * The layout-update ability keeps the stored value of widgets the caller sent
 * back unchanged, and filters every new or changed widget, on both storage
 * paths. https://github.com/siteorigin/siteorigin-panels/issues/1409
 *
 * Layouts are seeded by the administrator through the editor save paths, so
 * an embed is stored the way a site owner stores it. Each update reads the
 * layout with layout-get, changes it and writes it back, as an AI caller does.
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createPost,
	createUserSession,
	deletePost,
	deleteUser,
	layoutBlock,
	probeState,
	rawStorage,
	rest,
	runAbility,
	setProbe,
	siteUrl,
} = require( './helpers' );

const EMBED = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe>';
const MALICIOUS = 'Changed <img src=x onerror="panelsE2e()"><script>panelsE2e()</script>';

let admin;
let author;
const created = [];

const customHtml = ( content, cell, id, widgetId ) => ( {
	title: '',
	content,
	panels_info: {
		class: 'WP_Widget_Custom_HTML',
		grid: 0,
		cell,
		id,
		widget_id: widgetId,
	},
} );

/**
 * One row with two cells: widget A (the embed) and widget B (plain text) in
 * the first cell.
 */
const seedLayout = () => ( {
	widgets: [
		customHtml( EMBED, 0, 0, 'aaaaaaaa-0000-4000-8000-000000000001' ),
		customHtml( 'Plain text', 0, 1, 'bbbbbbbb-0000-4000-8000-000000000002' ),
	],
	grids: [ { cells: 2 } ],
	grid_cells: [
		{ grid: 0, weight: 0.5 },
		{ grid: 0, weight: 0.5 },
	],
} );

const createTracked = async ( session, type, fields ) => {
	const id = await createPost( session, type, fields );
	created.push( { type, id } );

	return id;
};

/**
 * Store a classic layout through the classic editor save, as the
 * administrator.
 */
const seedClassic = async ( postId, type, layout, authorId = null ) => {
	// A post without a classic layout opens in the block editor, which has no
	// Page Builder form. A first layout write makes the edit screen the
	// classic Page Builder screen.
	const first = await runAbility( admin, 'siteorigin-panels/layout-update', { post_id: postId, panels_data: { widgets: [ customHtml( 'Placeholder', 0, 0, 'dddddddd-0000-4000-8000-000000000004' ) ], grids: [ { cells: 2 } ], grid_cells: [ { grid: 0, weight: 0.5 }, { grid: 0, weight: 0.5 } ] } } );
	expect( first.status, JSON.stringify( first.body ) ).toBe( 200 );

	const edit = await admin.context.get( siteUrl( `wp-admin/post.php?post=${ postId }&action=edit` ) );
	expect( edit.status() ).toBe( 200 );
	const html = await edit.text();

	const wpNonce = html.match( /id="_wpnonce" name="_wpnonce" value="([^"]+)"/ );
	const panelsNonce = html.match( /name="_sopanels_nonce" value="([^"]+)"/ );
	expect( wpNonce, 'the post form nonce' ).not.toBeNull();
	expect( panelsNonce, 'the Page Builder metabox nonce' ).not.toBeNull();

	const save = await admin.context.post( siteUrl( 'wp-admin/post.php' ), {
		form: {
			action: 'editpost',
			originalaction: 'editpost',
			post_ID: String( postId ),
			post_type: type,
			post_title: 'Panels e2e unchanged widgets',
			_wpnonce: wpNonce[ 1 ],
			_sopanels_nonce: panelsNonce[ 1 ],
			panels_data: JSON.stringify( layout ),
			// Without it the classic save makes the administrator the author.
			...( authorId ? { post_author_override: String( authorId ) } : {} ),
		},
		maxRedirects: 0,
	} );
	expect( save.status() ).toBe( 302 );

	const raw = await rawStorage( admin, postId );
	expect( raw.meta.widgets[ 0 ].content, 'the administrator save keeps the embed' ).toBe( EMBED );

	return raw.meta;
};

const storedLayout = ( raw, storage ) => ( storage === 'meta' ? raw.meta : raw.blocks[ 0 ] );

const readLayout = async ( session, postId ) => {
	const response = await runAbility( session, 'siteorigin-panels/layout-get', { post_id: postId }, 'GET' );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
	expect( response.body.layouts ).toHaveLength( 1 );

	return response.body.layouts[ 0 ];
};

/**
 * layout-get, change the copy, layout-update. Returns the raw storage after
 * the write and the single pre-write payload.
 */
const roundTrip = async ( session, postId, change ) => {
	const layout = await readLayout( session, postId );
	const panelsData = change( JSON.parse( JSON.stringify( layout.panels_data ) ) );
	const input = { post_id: postId, panels_data: panelsData };
	if ( layout.storage === 'block' ) {
		input.block_index = layout.block_index;
	}

	await setProbe( admin, 'pass' );
	const response = await runAbility( session, 'siteorigin-panels/layout-update', input );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
	expect( response.body.updated ).toBe( true );

	const state = await probeState( admin );
	expect( state.log ).toHaveLength( 1 );

	const raw = await rawStorage( admin, postId );
	expect( state.log[ 0 ].panels_data, 'the pre-write payload equals what is stored' ).toStrictEqual( storedLayout( raw, layout.storage ) );

	return { raw, stored: storedLayout( raw, layout.storage ) };
};

const changeB = ( panelsData ) => {
	panelsData.widgets[ 1 ].content = MALICIOUS;

	return panelsData;
};

const expectFloored = ( widget ) => {
	expect( widget.content ).toContain( 'Changed' );
	expect( widget.content ).not.toContain( 'onerror' );
	expect( widget.content ).not.toContain( '<script' );
};

test.beforeAll( async () => {
	admin = await adminLogin();
	author = await createUserSession( admin, 'author' );

	const me = await rest( author, 'GET', '/wp/v2/users/me', { params: { context: 'edit' } } );
	expect( me.status ).toBe( 200 );
	expect( me.body.capabilities.unfiltered_html ).not.toBe( true );

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

test.describe( 'administrator, classic (meta) layout', () => {
	let pageId;
	let seeded;

	test.beforeEach( async () => {
		pageId = await createTracked( admin, 'page', { title: 'Panels e2e unchanged widgets' } );
		seeded = await seedClassic( pageId, 'page', seedLayout() );
	} );

	test( 'M1: an unchanged embed is kept while the changed widget is filtered', async () => {
		const { stored } = await roundTrip( admin, pageId, changeB );

		expect( stored.widgets[ 0 ] ).toStrictEqual( seeded.widgets[ 0 ] );
		expectFloored( stored.widgets[ 1 ] );
	} );

	test( 'M2: a new widget with an embed is filtered', async () => {
		const { stored } = await roundTrip( admin, pageId, ( panelsData ) => {
			panelsData.widgets.push( customHtml( `New ${ EMBED }`, 1, 2, 'cccccccc-0000-4000-8000-000000000003' ) );

			return panelsData;
		} );

		expect( stored.widgets[ 0 ] ).toStrictEqual( seeded.widgets[ 0 ] );
		expect( stored.widgets[ 2 ].content ).toContain( 'New' );
		expect( stored.widgets[ 2 ].content ).not.toContain( '<iframe' );
	} );

	test( 'M3: a moved widget keeps its embed at the new cell', async () => {
		const { stored } = await roundTrip( admin, pageId, ( panelsData ) => {
			panelsData.widgets[ 0 ].panels_info.cell = 1;
			panelsData.widgets[ 0 ].panels_info.id = 1;
			panelsData.widgets[ 1 ].panels_info.id = 0;

			return panelsData;
		} );

		expect( stored.widgets[ 0 ].content ).toBe( EMBED );
		expect( stored.widgets[ 0 ].panels_info.cell ).toBe( 1 );
	} );
} );

test.describe( 'administrator, Layout Block', () => {
	let pageId;
	let seeded;

	test.beforeEach( async () => {
		pageId = await createTracked( admin, 'page', {
			title: 'Panels e2e unchanged widgets (block)',
			content: layoutBlock( seedLayout() ),
		} );

		const raw = await rawStorage( admin, pageId );
		seeded = raw.blocks[ 0 ];
		expect( seeded.widgets[ 0 ].content, 'the administrator save keeps the embed' ).toBe( EMBED );
	} );

	test( 'B1: an unchanged embed is kept while the changed widget is filtered', async () => {
		const { stored } = await roundTrip( admin, pageId, changeB );

		expect( stored.widgets[ 0 ] ).toStrictEqual( seeded.widgets[ 0 ] );
		expectFloored( stored.widgets[ 1 ] );
	} );

	test( 'B2: a new widget with an embed is filtered', async () => {
		const { stored } = await roundTrip( admin, pageId, ( panelsData ) => {
			panelsData.widgets.push( customHtml( `New ${ EMBED }`, 1, 2, 'cccccccc-0000-4000-8000-000000000003' ) );

			return panelsData;
		} );

		expect( stored.widgets[ 0 ] ).toStrictEqual( seeded.widgets[ 0 ] );
		expect( stored.widgets[ 2 ].content ).not.toContain( '<iframe' );
	} );

	test( 'B3: a moved widget keeps its embed at the new cell', async () => {
		const { stored } = await roundTrip( admin, pageId, ( panelsData ) => {
			panelsData.widgets[ 0 ].panels_info.cell = 1;
			panelsData.widgets[ 0 ].panels_info.id = 1;
			panelsData.widgets[ 1 ].panels_info.id = 0;

			return panelsData;
		} );

		expect( stored.widgets[ 0 ].content ).toBe( EMBED );
		expect( stored.widgets[ 0 ].panels_info.cell ).toBe( 1 );
	} );
} );

test.describe( 'author credential', () => {
	test( 'A1: classic layout — the administrator embed is kept, the change is filtered', async () => {
		const postId = await createTracked( author, 'post', { title: 'Panels e2e author draft' } );
		const seeded = await seedClassic( postId, 'post', seedLayout(), author.userId );

		const { stored } = await roundTrip( author, postId, changeB );

		expect( stored.widgets[ 0 ] ).toStrictEqual( seeded.widgets[ 0 ] );
		expectFloored( stored.widgets[ 1 ] );
	} );

	test( 'A2: Layout Block — the payload equals what is stored', async () => {
		const postId = await createTracked( author, 'post', { title: 'Panels e2e author draft (block)' } );
		const seeded = await rest( admin, 'POST', `/wp/v2/posts/${ postId }`, { data: { content: layoutBlock( seedLayout() ) } } );
		expect( seeded.status, JSON.stringify( seeded.body ) ).toBe( 200 );

		// roundTrip() asserts the payload equals storage. Whether the embed
		// survives is decided by WordPress's own kses for an author.
		const { stored } = await roundTrip( author, postId, changeB );
		expectFloored( stored.widgets[ 1 ] );
	} );
} );
