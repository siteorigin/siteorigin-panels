/**
 * The siteorigin_panels_layout_block_markup_removed action over the real REST
 * routes and the layout-update ability (#1454).
 *
 * The test-only mu-plugin records every report and, when asked, throws after
 * recording. The probe widget stores its text unfiltered, so a removal comes
 * from Page Builder or WordPress, never the widget.
 *
 * Two real users: the administrator (has unfiltered_html) and an author (no
 * unfiltered_html, so Page Builder's kses floor and WordPress kses run).
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createUserSession,
	deletePost,
	deleteUser,
	layoutBlock,
	probeState,
	rawStorage,
	rest,
	runAbility,
	setProbe,
} = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

const IFRAME = '<p>Embed</p><iframe src="https://example.com/embed"></iframe>';

let admin;
let author;
const created = [];

/**
 * A one-widget layout whose widget has a widget_id.
 */
const embedLayout = ( text, widgetId = 'e2e-embed' ) => ( {
	widgets: [
		{
			text,
			panels_info: {
				class: 'Panels_E2E_Probe_Widget',
				grid: 0,
				cell: 0,
				id: 0,
				widget_id: widgetId,
			},
		},
	],
	grids: [ { cells: 1 } ],
	grid_cells: [ { grid: 0, weight: 1 } ],
} );

const savePost = async ( session, type, id, layout ) => {
	const route = id ? `/wp/v2/${ type }s/${ id }` : `/wp/v2/${ type }s`;
	const response = await rest( session, 'POST', route, {
		data: { status: 'draft', title: 'Panels e2e markup removed', content: layoutBlock( layout ) },
	} );
	if ( ! id && response.status === 201 ) {
		created.push( { type, id: response.body.id } );
	}

	return response;
};

const setThrowingListener = async () => {
	const response = await rest( admin, 'POST', '/panels-e2e/v1/state', { data: { mode: 'pass', markup_removed_throws: 1 } } );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
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

test( 'M1: an author REST save that loses an iframe reports once, with the post ID on update', async () => {
	await setProbe( admin, 'pass' );

	const create = await savePost( author, 'post', 0, embedLayout( IFRAME ) );
	expect( create.status, JSON.stringify( create.body ) ).toBe( 201 );
	const id = create.body.id;

	let state = await probeState( admin );
	expect( state.markup_removed ).toStrictEqual( [
		{
			post_id: 0,
			context: 'rest_pre_insert',
			widgets: [
				{
					block: 0,
					block_index: null,
					widget: 0,
					widget_id: 'e2e-embed',
					class: 'Panels_E2E_Probe_Widget',
					source: 'page_builder',
				},
			],
		},
	] );

	await setProbe( admin, 'pass' );
	const update = await savePost( author, 'post', id, embedLayout( IFRAME ) );
	expect( update.status, JSON.stringify( update.body ) ).toBe( 200 );

	state = await probeState( admin );
	expect( state.markup_removed ).toHaveLength( 1 );
	expect( state.markup_removed[ 0 ].post_id ).toBe( id );
	expect( state.markup_removed[ 0 ].context ).toBe( 'rest_pre_insert' );

	const raw = await rawStorage( admin, id );
	expect( raw.post_content ).not.toContain( '<iframe' );
	expect( JSON.stringify( raw.blocks[ 0 ] ) ).not.toContain( 'iframe' );
} );

test( 'M2: an administrator REST save keeps the iframe and reports nothing', async () => {
	await setProbe( admin, 'pass' );

	const response = await savePost( admin, 'page', 0, embedLayout( IFRAME ) );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 201 );

	expect( ( await probeState( admin ) ).markup_removed ).toStrictEqual( [] );
	expect( JSON.stringify( ( await rawStorage( admin, response.body.id ) ).blocks[ 0 ] ) ).toContain( 'iframe' );
} );

test( 'M3: a layout-update ability write reports with its block_index', async () => {
	const page = await savePost( admin, 'page', 0, embedLayout( 'Before' ) );
	expect( page.status ).toBe( 201 );
	await setProbe( admin, 'pass' );

	const response = await runAbility( admin, 'siteorigin-panels/layout-update', {
		post_id: page.body.id,
		panels_data: embedLayout( IFRAME, 'e2e-ability' ),
		block_index: 0,
	} );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

	const state = await probeState( admin );
	expect( state.markup_removed ).toHaveLength( 1 );
	expect( state.markup_removed[ 0 ] ).toMatchObject( {
		post_id: page.body.id,
		context: 'ability',
		widgets: [ { block: null, block_index: 0, widget_id: 'e2e-ability', source: 'page_builder' } ],
	} );
} );

test( 'M4: an ability write stopped by the pre-write hook still reports, and the error reaches the caller', async () => {
	const page = await savePost( admin, 'page', 0, embedLayout( 'Before' ) );
	expect( page.status ).toBe( 201 );
	await setProbe( admin, 'wp_error' );

	try {
		const response = await runAbility( admin, 'siteorigin-panels/layout-update', {
			post_id: page.body.id,
			panels_data: embedLayout( IFRAME, 'e2e-stopped' ),
			block_index: 0,
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 409 );
		expect( response.body.code ).toBe( 'panels_e2e_blocked' );

		const state = await probeState( admin );
		expect( state.markup_removed ).toHaveLength( 1 );
		expect( state.markup_removed[ 0 ].widgets[ 0 ].widget_id ).toBe( 'e2e-stopped' );
	} finally {
		await setProbe( admin, 'pass' );
	}
} );

test( 'M5: a listener that throws does not break the save', async () => {
	await setThrowingListener();

	try {
		const response = await savePost( author, 'post', 0, embedLayout( IFRAME, 'e2e-throw' ) );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 201 );

		const state = await probeState( admin );
		expect( state.markup_removed ).toHaveLength( 1 );

		const raw = await rawStorage( admin, response.body.id );
		expect( raw.blocks ).toHaveLength( 1 );
		expect( JSON.stringify( raw.blocks[ 0 ] ) ).toContain( 'e2e-throw' );
		expect( JSON.stringify( raw.blocks[ 0 ] ) ).not.toContain( 'iframe' );
	} finally {
		await setProbe( admin, 'pass' );
	}
} );
