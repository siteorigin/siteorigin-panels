/**
 * The layout abilities through the WordPress MCP adapter's default server
 * (HTTP transport): discovery, layout-get, and layout-update with the
 * pre-write hook passing and stopping the write on both storage paths.
 *
 * The adapter exposes an ability only when its meta marks it public to MCP,
 * so this file fails if the abilities lose their `mcp.public` flag.
 *
 * The adapter is not on wordpress.org. When the site does not have it, this
 * file downloads the pinned release, installs it through the plugin upload
 * screen, and removes it again afterwards. An install or activation failure
 * fails the file; it never skips.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const { expect, test } = require( '@playwright/test' );

const { doLogin, soGoTo } = require( 'siteorigin-tests-common/playwright/common' );

const {
	adminLogin,
	createPost,
	deletePost,
	execute,
	layoutBlock,
	openMcpSession,
	probeLayout,
	probeState,
	rawStorage,
	rest,
	setProbe,
	snapshot,
	toolFailure,
	toolPayload,
} = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

const ADAPTER_ZIP = 'https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip';
const ADAPTER_PLUGIN = 'mcp-adapter/mcp-adapter';
const PARAGRAPH = '<!-- wp:paragraph --><p>Keep this paragraph</p><!-- /wp:paragraph -->';

let admin;
let mcp;
const pages = [];
const adapterState = {
	installedByTest: false,
	priorStatus: null,
};

const probeText = ( label ) => `${ label }-${ Date.now() } <strong>Probe text</strong>`;

const findAdapter = async () => {
	const plugins = await rest( admin, 'GET', '/wp/v2/plugins' );
	expect( plugins.status ).toBe( 200 );

	return plugins.body.find( ( plugin ) => plugin.plugin === ADAPTER_PLUGIN ) || null;
};

/**
 * Download the pinned adapter release and install it through the real
 * plugin upload screen.
 */
const installAdapter = async ( browser ) => {
	const download = await fetch( ADAPTER_ZIP );
	expect( download.ok, `download ${ ADAPTER_ZIP }` ).toBe( true );

	const zipPath = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'panels-mcp-' ) ), 'mcp-adapter.zip' );
	fs.writeFileSync( zipPath, Buffer.from( await download.arrayBuffer() ) );

	const page = await browser.newPage();

	try {
		await doLogin( page );
		await soGoTo( page, 'wp-admin/plugin-install.php?tab=upload' );
		await page.setInputFiles( '#pluginzip', zipPath );
		await Promise.all( [
			page.waitForNavigation(),
			page.click( '#install-plugin-submit', { noWaitAfter: true } ),
		] );
		await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Plugin installed successfully' );
	} finally {
		await page.close();
		fs.rmSync( path.dirname( zipPath ), { recursive: true, force: true } );
	}
};

/**
 * Run a stopped layout-update over MCP and check that nothing changed and
 * nothing rendered.
 */
const expectStoppedWrite = async ( id, parameters ) => {
	const before = await snapshot( admin, id, 'page' );
	await setProbe( admin, 'wp_error' );

	try {
		const message = toolFailure( await execute( mcp, 'siteorigin-panels/layout-update', parameters ) );
		expect( message ).toContain( 'Blocked by the e2e listener.' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expect( state.probe_renders ).toBe( 0 );

		expect( await snapshot( admin, id, 'page' ) ).toStrictEqual( before );
	} finally {
		await setProbe( admin, 'pass' );
	}
};

test.beforeAll( async ( { browser } ) => {
	test.setTimeout( 180_000 );
	admin = await adminLogin();

	let adapter = await findAdapter();

	if ( ! adapter ) {
		await installAdapter( browser );
		adapterState.installedByTest = true;
		adapter = await findAdapter();
	}

	expect( adapter, 'the MCP adapter must be installed' ).not.toBeNull();
	adapterState.priorStatus = adapterState.installedByTest ? 'inactive' : adapter.status;

	if ( adapter.status !== 'active' ) {
		const activated = await rest( admin, 'PUT', `/wp/v2/plugins/${ ADAPTER_PLUGIN }`, {
			data: { status: 'active' },
		} );
		expect( activated.body.status, JSON.stringify( activated.body ) ).toBe( 'active' );
	}

	await setProbe( admin, 'pass' );
	mcp = await openMcpSession( admin );
} );

test.afterAll( async () => {
	if ( ! admin ) {
		return;
	}

	await mcp?.close().catch( () => {} );
	await setProbe( admin, 'pass' ).catch( () => {} );

	for ( const id of pages ) {
		await deletePost( admin, 'page', id ).catch( () => {} );
	}

	if ( adapterState.priorStatus && adapterState.priorStatus !== 'active' ) {
		await rest( admin, 'PUT', `/wp/v2/plugins/${ ADAPTER_PLUGIN }`, {
			data: { status: adapterState.priorStatus },
		} ).catch( () => {} );
	}

	if ( adapterState.installedByTest ) {
		await rest( admin, 'DELETE', `/wp/v2/plugins/${ ADAPTER_PLUGIN }` ).catch( () => {} );
	}

	await admin.context.dispose();
} );

test( 'M1: discovery lists both layout abilities', async () => {
	const discovered = toolPayload( await mcp.callTool( 'mcp-adapter-discover-abilities', {} ) );
	const names = discovered.abilities.map( ( ability ) => ability.name );

	expect( names ).toContain( 'siteorigin-panels/layout-get' );
	expect( names ).toContain( 'siteorigin-panels/layout-update' );
} );

test( 'M2: layout-get runs over MCP', async () => {
	const id = await createPost( admin, 'page', { title: 'Panels MCP M2' } );
	pages.push( id );

	const payload = toolPayload( await execute( mcp, 'siteorigin-panels/layout-get', { post_id: id } ) );
	expect( payload.success ).toBe( true );
	expect( payload.data.post_id ).toBe( id );
	expect( Array.isArray( payload.data.layouts ) ).toBe( true );
} );

test.describe( 'classic (meta) path', () => {
	let id;

	test( 'M3: a passing layout-update fires the hook once with the stored layout', async () => {
		id = await createPost( admin, 'page', { title: 'Panels MCP classic page' } );
		pages.push( id );
		await setProbe( admin, 'pass' );

		const payload = toolPayload( await execute( mcp, 'siteorigin-panels/layout-update', {
			post_id: id,
			panels_data: probeLayout( probeText( 'M3' ) ),
		} ) );
		expect( payload.success ).toBe( true );
		expect( payload.data.updated ).toBe( true );
		expect( payload.data.source ).toBe( 'meta' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expect( state.log[ 0 ].storage ).toBe( 'meta' );
		expect( state.log[ 0 ].panels_data ).toStrictEqual( ( await rawStorage( admin, id ) ).meta );
	} );

	test( 'M4: a WP_Error from the hook reaches the MCP client and nothing is stored', async () => {
		await expectStoppedWrite( id, {
			post_id: id,
			panels_data: probeLayout( probeText( 'M4' ) ),
		} );
	} );
} );

test.describe( 'Layout Block path', () => {
	let id;

	test( 'M5: a passing block layout-update fires the hook once with the stored block', async () => {
		await setProbe( admin, 'pass' );
		id = await createPost( admin, 'page', {
			title: 'Panels MCP block page',
			content: `${ PARAGRAPH }\n\n${ layoutBlock( probeLayout( probeText( 'M5-initial' ) ) ) }`,
		} );
		pages.push( id );

		const payload = toolPayload( await execute( mcp, 'siteorigin-panels/layout-update', {
			post_id: id,
			panels_data: probeLayout( probeText( 'M5' ) ),
			block_index: 0,
		} ) );
		expect( payload.success ).toBe( true );
		expect( payload.data.source ).toBe( 'block' );

		const state = await probeState( admin );
		expect( state.log ).toHaveLength( 1 );
		expect( state.log[ 0 ].storage ).toBe( 'block' );
		expect( state.log[ 0 ].block_index ).toBe( 0 );
		expect( state.log[ 0 ].panels_data ).toStrictEqual( ( await rawStorage( admin, id ) ).blocks[ 0 ] );
	} );

	test( 'M6: a WP_Error from the hook stops the block write over MCP', async () => {
		await expectStoppedWrite( id, {
			post_id: id,
			panels_data: probeLayout( probeText( 'M6' ) ),
			block_index: 0,
		} );
	} );
} );
