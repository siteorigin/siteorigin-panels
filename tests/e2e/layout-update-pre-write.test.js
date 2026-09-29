/**
 * The siteorigin_panels_layout_update_pre_write hook over the real
 * layout-update REST route, on both storage paths.
 *
 * The test-only mu-plugin records every hook call and can pass, return a
 * WP_Error, or return false. Raw storage is read straight from the database,
 * so "the payload equals what is stored" is checked against real storage.
 *
 * Two real users: the administrator (has unfiltered_html) on pages, and an
 * author (no unfiltered_html, so WordPress kses runs on its saves) on its own
 * draft posts.
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
	rest,
	runAbility,
	setProbe,
	siteUrl,
	snapshot,
} = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

const PARAGRAPH = '<!-- wp:paragraph --><p>Keep this paragraph</p><!-- /wp:paragraph -->';
const ENTITY_TEXTS = [
	'Tom & Jerry',
	'Tom &amp; Jerry',
	'&amp;#91;x&amp;#93;',
	'&#91;x&#93;',
	'<strong>A &amp; B</strong>',
];

let admin;
let author;
const created = [];

const probeText = ( label ) => `${ label }-${ Date.now() } <script>panelsE2e()</script><strong>Probe text</strong>`;

const layoutUpdate = ( session, input ) => runAbility( session, 'siteorigin-panels/layout-update', input );

const createTracked = async ( session, type, fields ) => {
	const id = await createPost( session, type, fields );
	created.push( { type, id } );

	return id;
};

const blockPage = ( ...layouts ) => ( {
	title: 'Panels e2e block page',
	content: [ PARAGRAPH, ...layouts.map( ( layout ) => layoutBlock( layout ) ) ].join( '\n\n' ),
} );

/**
 * The single log entry's payload deep-equals the stored layout.
 */
const expectPayloadEqualsStored = ( entry, raw ) => {
	const stored = entry.storage === 'meta' ? raw.meta : raw.blocks[ entry.block_index ];
	expect( stored ).toBeTruthy();
	expect( entry.panels_data ).toStrictEqual( stored );
};

/**
 * Run a write with the listener in a stopping mode, and check that nothing
 * changed and nothing rendered.
 */
const expectStoppedWrite = async ( { session, id, type, mode, input, status, code } ) => {
	const before = await snapshot( admin, id, type );
	await setProbe( admin, mode );

	try {
		const response = await layoutUpdate( session, input );
		expect( response.status, JSON.stringify( response.body ) ).toBe( status );
		expect( response.body.code ).toBe( code );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expect( state.probe_renders ).toBe( 0 );

		const after = await snapshot( admin, id, type );
		expect( after ).toStrictEqual( before );

		return { before, state };
	} finally {
		await setProbe( admin, 'pass' );
	}
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

test.describe( 'classic (meta) path', () => {
	let pageId;

	test.beforeAll( async () => {
		pageId = await createTracked( admin, 'page', { title: 'Panels e2e classic page' } );
	} );

	test( 'C1: a passing write fires once with the stored layout', async () => {
		await setProbe( admin, 'pass' );

		const text = probeText( 'C1' );
		const response = await layoutUpdate( admin, { post_id: pageId, panels_data: probeLayout( text ) } );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.updated ).toBe( true );
		expect( response.body.source ).toBe( 'meta' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );

		const [ entry ] = state.log;
		expect( entry.storage ).toBe( 'meta' );
		expect( entry.block_index ).toBeNull();
		expect( entry.post_id ).toBe( pageId );
		expect( entry.panels_data.widgets[ 0 ].text ).toContain( '<strong>Probe text' );
		expect( entry.panels_data.widgets[ 0 ].text ).not.toContain( '<script' );

		const raw = await rawStorage( admin, pageId );
		expectPayloadEqualsStored( entry, raw );

		// Positive control: the copy-content mirror ran. It stores a
		// third-party widget as a [siteorigin_widget] shortcode that carries
		// the instance, so it holds the new marker without calling widget().
		// The stopped writes below then prove no mirror by an unchanged
		// content.raw.
		expect( raw.post_content ).toContain( 'Panels_E2E_Probe_Widget' );
		expect( raw.post_content ).toContain( text.split( ' ' )[ 0 ] );
	} );

	test( 'C2: a WP_Error stops the write before anything is stored or rendered', async () => {
		await expectStoppedWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			mode: 'wp_error',
			input: { post_id: pageId, panels_data: probeLayout( probeText( 'C2' ) ) },
			status: 409,
			code: 'panels_e2e_blocked',
		} );
	} );

	test( 'C3: false stops the write with the aborted code', async () => {
		await expectStoppedWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			mode: 'false',
			input: { post_id: pageId, panels_data: probeLayout( probeText( 'C3' ) ) },
			status: 500,
			code: 'siteorigin_panels_layout_update_aborted',
		} );
	} );

	test( 'C4: stopping an empty-layout write keeps the stored layout', async () => {
		const { before, state } = await expectStoppedWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			mode: 'wp_error',
			input: { post_id: pageId, panels_data: {} },
			status: 409,
			code: 'panels_e2e_blocked',
		} );

		expect( state.log[ 0 ].panels_data.widgets ?? [] ).toHaveLength( 0 );
		expect( before.raw.meta_exists ).toBe( true );
		expect( before.raw.meta.widgets.length ).toBeGreaterThan( 0 );
	} );

	test( 'C5: a human classic editor save does not fire the hook', async () => {
		await setProbe( admin, 'pass' );

		const edit = await admin.context.get( siteUrl( `wp-admin/post.php?post=${ pageId }&action=edit` ) );
		expect( edit.status() ).toBe( 200 );
		const html = await edit.text();

		const wpNonce = html.match( /id="_wpnonce" name="_wpnonce" value="([^"]+)"/ );
		const panelsNonce = html.match( /name="_sopanels_nonce" value="([^"]+)"/ );
		expect( wpNonce, 'the post form nonce' ).not.toBeNull();
		expect( panelsNonce, 'the Page Builder metabox nonce' ).not.toBeNull();

		const text = `C5-${ Date.now() } human save`;
		const save = await admin.context.post( siteUrl( 'wp-admin/post.php' ), {
			form: {
				action: 'editpost',
				originalaction: 'editpost',
				post_ID: String( pageId ),
				post_type: 'page',
				post_title: 'Panels e2e classic page',
				_wpnonce: wpNonce[ 1 ],
				_sopanels_nonce: panelsNonce[ 1 ],
				panels_data: JSON.stringify( probeLayout( text ) ),
			},
			maxRedirects: 0,
		} );
		expect( save.status() ).toBe( 302 );

		const raw = await rawStorage( admin, pageId );
		expect( raw.meta.widgets[ 0 ].text ).toBe( text );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 0 );
	} );
} );

test.describe( 'Layout Block path', () => {
	let pageId;

	test( 'B0: a human REST block save does not fire the hook', async () => {
		await setProbe( admin, 'pass' );

		pageId = await createTracked( admin, 'page', blockPage( probeLayout( probeText( 'B0' ) ) ) );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 0 );
	} );

	test( 'B1: a passing write fires once with the stored block layout', async () => {
		await setProbe( admin, 'pass' );

		const response = await layoutUpdate( admin, {
			post_id: pageId,
			panels_data: probeLayout( probeText( 'B1' ) ),
			block_index: 0,
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.source ).toBe( 'block' );
		expect( response.body.block_index ).toBe( 0 );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );

		const [ entry ] = state.log;
		expect( entry.storage ).toBe( 'block' );
		expect( entry.block_index ).toBe( 0 );
		expect( entry.post_id ).toBe( pageId );
		expect( entry.panels_data.widgets[ 0 ].text ).toContain( '<strong>Probe text' );
		expect( entry.panels_data.widgets[ 0 ].text ).not.toContain( '<script' );

		expectPayloadEqualsStored( entry, await rawStorage( admin, pageId ) );

		// Positive control: the contentPreview render ran the probe.
		expect( state.probe_renders ).toBeGreaterThanOrEqual( 1 );

		const after = await snapshot( admin, pageId, 'page' );
		expect( after.content ).toContain( 'Keep this paragraph' );
	} );

	test( 'B2: a WP_Error stops the block write before the render and the post update', async () => {
		await expectStoppedWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			mode: 'wp_error',
			input: { post_id: pageId, panels_data: probeLayout( probeText( 'B2' ) ), block_index: 0 },
			status: 409,
			code: 'panels_e2e_blocked',
		} );
	} );

	test( 'B3: false stops the block write with the aborted code', async () => {
		await expectStoppedWrite( {
			session: admin,
			id: pageId,
			type: 'page',
			mode: 'false',
			input: { post_id: pageId, panels_data: probeLayout( probeText( 'B3' ) ), block_index: 0 },
			status: 500,
			code: 'siteorigin_panels_layout_update_aborted',
		} );
	} );

	test( 'B4: the hook receives the requested block index', async () => {
		const twoBlocks = await createTracked( admin, 'page', blockPage(
			probeLayout( probeText( 'B4-first' ) ),
			probeLayout( probeText( 'B4-second' ) )
		) );
		const before = await rawStorage( admin, twoBlocks );
		await setProbe( admin, 'pass' );

		const response = await layoutUpdate( admin, {
			post_id: twoBlocks,
			panels_data: probeLayout( probeText( 'B4-update' ) ),
			block_index: 1,
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expect( state.log[ 0 ].block_index ).toBe( 1 );

		const raw = await rawStorage( admin, twoBlocks );
		expectPayloadEqualsStored( state.log[ 0 ], raw );
		expect( raw.blocks[ 0 ] ).toStrictEqual( before.blocks[ 0 ] );
	} );

	test( 'E1: administrator block write with entity text: the payload equals storage', async () => {
		await setProbe( admin, 'pass' );

		const response = await layoutUpdate( admin, {
			post_id: pageId,
			panels_data: probeLayout( ...ENTITY_TEXTS ),
			block_index: 0,
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expectPayloadEqualsStored( state.log[ 0 ], await rawStorage( admin, pageId ) );
	} );
} );

test.describe( 'author (no unfiltered_html)', () => {
	let blockPost;

	test( 'E2: author block write with entity text: the payload equals storage', async () => {
		await setProbe( admin, 'pass' );

		blockPost = await createTracked( author, 'post', {
			title: 'Panels e2e author block post',
			content: layoutBlock( probeLayout( probeText( 'E2-initial' ) ) ),
		} );
		expect( ( await probeState( admin ) ).log ).toHaveLength( 0 );

		const response = await layoutUpdate( author, {
			post_id: blockPost,
			panels_data: probeLayout( ...ENTITY_TEXTS ),
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.source ).toBe( 'block' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );

		const raw = await rawStorage( admin, blockPost );
		expectPayloadEqualsStored( state.log[ 0 ], raw );

		// WordPress kses stores a bare & as &amp; and Page Builder then
		// restores it in tag-free text. Both sides hold the restored text, so
		// the stored form was settled before the hook.
		expect( state.log[ 0 ].panels_data.widgets[ 0 ].text ).toBe( 'Tom & Jerry' );
		expect( raw.blocks[ 0 ].widgets[ 0 ].text ).toBe( 'Tom & Jerry' );
	} );

	test( 'E3: author meta write with entity text: the payload equals storage', async () => {
		const metaPost = await createTracked( author, 'post', { title: 'Panels e2e author classic post' } );
		await setProbe( admin, 'pass' );

		const response = await layoutUpdate( author, {
			post_id: metaPost,
			panels_data: probeLayout( ...ENTITY_TEXTS ),
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.source ).toBe( 'meta' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expectPayloadEqualsStored( state.log[ 0 ], await rawStorage( admin, metaPost ) );
	} );

	test( 'E4: a WP_Error stops the author block write', async () => {
		await expectStoppedWrite( {
			session: author,
			id: blockPost,
			type: 'post',
			mode: 'wp_error',
			input: { post_id: blockPost, panels_data: probeLayout( probeText( 'E4' ) ) },
			status: 409,
			code: 'panels_e2e_blocked',
		} );
	} );

	test( 'E5: author block write with double-escaped entity text is stored', async () => {
		await setProbe( admin, 'pass' );

		// Settles on the fourth save-filter pass for a user without
		// unfiltered_html: one amp; level per pass, then kses pads the entity.
		const response = await layoutUpdate( author, {
			post_id: blockPost,
			panels_data: probeLayout( '&amp;amp;#91;x&amp;amp;#93;' ),
		} );
		expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
		expect( response.body.source ).toBe( 'block' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );

		const raw = await rawStorage( admin, blockPost );
		expectPayloadEqualsStored( state.log[ 0 ], raw );
		expect( raw.blocks[ 0 ].widgets[ 0 ].text ).toBe( '&#091;x&#093;' );
	} );
} );
