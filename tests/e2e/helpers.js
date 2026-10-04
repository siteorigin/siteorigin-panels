/**
 * Shared helpers for the Page Builder e2e tests.
 *
 * Every request uses a real cookie session and a REST nonce. Application
 * passwords are unavailable on a non-HTTPS site that is not a local
 * environment, such as Playground. The login, MCP session and tool result
 * helpers follow the Widgets Bundle abilities e2e tests.
 *
 * Probe control and raw storage reads go through the test-only routes in
 * tests/playground/mu-plugins/panels-e2e.php.
 */
const { expect, request } = require( '@playwright/test' );

const MCP_ENDPOINT = 'wp-json/mcp/mcp-adapter-default-server';

// Join a path to WP_BASE_URL. Normalizing the base to end in a slash keeps
// this correct for a root install and a subdirectory install.
const siteUrl = ( relativePath ) => {
	const base = process.env.WP_BASE_URL.endsWith( '/' )
		? process.env.WP_BASE_URL
		: `${ process.env.WP_BASE_URL }/`;

	return new URL( relativePath, base ).toString();
};

/**
 * Log a user in with a cookie session of its own and fetch a REST nonce.
 *
 * @return {Promise<{context: import('@playwright/test').APIRequestContext, nonce: string, username: string}>}
 */
const login = async ( username, password ) => {
	const context = await request.newContext( {
		baseURL: siteUrl( '' ),
		ignoreHTTPSErrors: true,
	} );

	// The login form needs the test cookie set by a first visit.
	await context.get( 'wp-login.php' );
	const response = await context.post( 'wp-login.php', {
		form: {
			log: username,
			pwd: password,
			testcookie: '1',
			'wp-submit': 'Log In',
		},
		maxRedirects: 0,
	} );
	expect( response.status(), `login ${ username }` ).toBe( 302 );

	const nonceResponse = await context.get( 'wp-admin/admin-ajax.php?action=rest-nonce' );
	expect( nonceResponse.ok(), `nonce ${ username }` ).toBe( true );

	return {
		context,
		nonce: ( await nonceResponse.text() ).trim(),
		username,
	};
};

const adminLogin = () => login( process.env.WP_USERNAME, process.env.WP_PASSWORD );

/**
 * A REST request as the session's user.
 *
 * @return {Promise<{status: number, body: any}>}
 */
const rest = async ( session, method, route, { data, params } = {} ) => {
	const options = {
		method,
		headers: {
			'X-WP-Nonce': session.nonce,
			Accept: 'application/json',
		},
		params,
	};

	if ( data !== undefined ) {
		options.headers[ 'Content-Type' ] = 'application/json';
		options.data = data;
	}

	const response = await session.context.fetch( siteUrl( `wp-json${ route }` ), options );
	const text = await response.text();
	let body = text;

	try {
		body = JSON.parse( text );
	} catch ( error ) {}

	return { status: response.status(), body };
};

/**
 * Create a user with the given role and log it in. The caller deletes the
 * user with deleteUser().
 */
const createUserSession = async ( adminSession, role ) => {
	const suffix = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;
	const username = `panels-e2e-${ role }-${ suffix }`;
	const password = `panels-e2e-${ suffix }!A1`;
	const created = await rest( adminSession, 'POST', '/wp/v2/users', {
		data: {
			username,
			email: `${ username }@example.com`,
			password,
			roles: [ role ],
		},
	} );
	expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );

	const session = await login( username, password );
	session.userId = created.body.id;

	return session;
};

const deleteUser = ( adminSession, userId ) => rest( adminSession, 'DELETE', `/wp/v2/users/${ userId }`, {
	params: { force: 'true', reassign: '1' },
} );

/**
 * Run an ability over the core Abilities REST API. POST sends { input } as
 * JSON; GET sends input[...] query parameters.
 */
const runAbility = ( session, name, input, method = 'POST' ) => {
	const route = `/wp-abilities/v1/abilities/${ name }/run`;

	if ( method === 'GET' ) {
		const params = {};
		for ( const [ key, value ] of Object.entries( input ) ) {
			params[ `input[${ key }]` ] = String( value );
		}

		return rest( session, 'GET', route, { params } );
	}

	return rest( session, method, route, { data: { input } } );
};

/**
 * Set the listener mode ('pass', 'wp_error' or 'false'). This also clears
 * the listener log and the probe render counter.
 */
const setProbe = async ( adminSession, mode ) => {
	const response = await rest( adminSession, 'POST', '/panels-e2e/v1/state', { data: { mode } } );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );
};

/**
 * @return {Promise<{mode: string, log: Array, probe_renders: number}>}
 */
const probeState = async ( adminSession ) => {
	const response = await rest( adminSession, 'GET', '/panels-e2e/v1/state' );
	expect( response.status ).toBe( 200 );

	return response.body;
};

/**
 * Raw storage for a post, read straight from the database.
 *
 * @return {Promise<{meta_rows: number, meta_exists: boolean, meta: any, post_content: string, blocks: Array}>}
 */
const rawStorage = async ( adminSession, id ) => {
	const response = await rest( adminSession, 'GET', `/panels-e2e/v1/raw/${ id }` );
	expect( response.status ).toBe( 200 );
	expect( response.body.meta_rows ).toBeLessThanOrEqual( 1 );

	return response.body;
};

/**
 * Everything a stopped write must leave unchanged. The _fields lists keep
 * WordPress from rendering the content, so a snapshot never renders the
 * probe widget.
 */
const snapshot = async ( adminSession, id, type ) => {
	const post = await rest( adminSession, 'GET', `/wp/v2/${ type }s/${ id }`, {
		params: { context: 'edit', _fields: 'content.raw,modified_gmt' },
	} );
	expect( post.status ).toBe( 200 );

	const revisions = await rest( adminSession, 'GET', `/wp/v2/${ type }s/${ id }/revisions`, {
		params: { per_page: '100', _fields: 'id' },
	} );
	expect( revisions.status ).toBe( 200 );

	return {
		raw: await rawStorage( adminSession, id ),
		content: post.body.content.raw,
		modified_gmt: post.body.modified_gmt,
		revisions: revisions.body.length,
	};
};

/**
 * A layout of one grid and one cell holding one probe widget per text.
 */
const probeLayout = ( ...texts ) => ( {
	widgets: texts.map( ( text, index ) => ( {
		text,
		panels_info: {
			class: 'Panels_E2E_Probe_Widget',
			grid: 0,
			cell: 0,
			id: index,
			widget_index: index,
		},
	} ) ),
	grids: [ { cells: 1 } ],
	grid_cells: [ { grid: 0, weight: 1 } ],
} );

/**
 * A self-closing Layout Block comment for post content.
 */
const layoutBlock = ( panelsData ) => `<!-- wp:siteorigin-panels/layout-block ${ JSON.stringify( { panelsData } ) } /-->`;

const createPost = async ( session, type, fields ) => {
	const response = await rest( session, 'POST', `/wp/v2/${ type }s`, {
		data: {
			status: 'draft',
			...fields,
		},
	} );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 201 );

	return response.body.id;
};

const deletePost = ( session, type, id ) => rest( session, 'DELETE', `/wp/v2/${ type }s/${ id }`, {
	params: { force: 'true' },
} );

/**
 * Open an MCP session on the default server as the given user.
 */
const openMcpSession = async ( session ) => {
	let sessionId = null;
	let nextId = 1;

	const send = async ( method, message ) => {
		const headers = {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
			'X-WP-Nonce': session.nonce,
		};

		if ( sessionId ) {
			headers[ 'Mcp-Session-Id' ] = sessionId;
		}

		return session.context.fetch( siteUrl( MCP_ENDPOINT ), {
			method,
			headers,
			data: message,
		} );
	};

	const initialize = await send( 'POST', {
		jsonrpc: '2.0',
		id: nextId++,
		method: 'initialize',
		params: {
			protocolVersion: '2025-11-25',
			capabilities: {},
			clientInfo: {
				name: 'panels-e2e',
				version: '1.0.0',
			},
		},
	} );
	expect( initialize.status() ).toBe( 200 );
	sessionId = initialize.headers()[ 'mcp-session-id' ];
	expect( sessionId ).toBeTruthy();

	const initialized = await send( 'POST', {
		jsonrpc: '2.0',
		method: 'notifications/initialized',
	} );
	expect( initialized.status() ).toBeLessThan( 300 );

	return {
		/**
		 * Call a tool. Resolves to the raw JSON-RPC response body.
		 */
		callTool: async ( name, args ) => {
			const response = await send( 'POST', {
				jsonrpc: '2.0',
				id: nextId++,
				method: 'tools/call',
				params: {
					name,
					arguments: args,
				},
			} );

			return response.json();
		},
		close: async () => {
			await send( 'DELETE' );
		},
	};
};

/**
 * The tool payload from a tools/call result: the structured content when
 * present, else the JSON text content. For the execute tool this is the
 * adapter's wrapper { success, data?, error? }, not the ability output.
 */
const toolPayload = ( rpc ) => {
	expect( rpc.error, JSON.stringify( rpc.error ) ).toBeUndefined();
	expect( rpc.result.isError, JSON.stringify( rpc.result ) ).toBeFalsy();

	if ( rpc.result.structuredContent ) {
		return rpc.result.structuredContent;
	}

	return JSON.parse( rpc.result.content[ 0 ].text );
};

const execute = ( mcp, abilityName, parameters ) => mcp.callTool( 'mcp-adapter-execute-ability', {
	ability_name: abilityName,
	parameters,
} );

/**
 * The error message of a failed tools/call. The v0.6.1 adapter turns the
 * execute tool's { success: false, error } wrapper into a tool error
 * (isError with the message as text); the wrapper form is accepted too. A
 * failure never carries ability data.
 */
const toolFailure = ( rpc ) => {
	expect( rpc.error, JSON.stringify( rpc.error ) ).toBeUndefined();

	if ( rpc.result.isError === true ) {
		expect( rpc.result.structuredContent ?? null ).toBeNull();

		return rpc.result.content[ 0 ].text;
	}

	const payload = toolPayload( rpc );
	expect( payload.success ).toBe( false );
	expect( payload.data ).toBeUndefined();

	return payload.error;
};

module.exports = {
	adminLogin,
	createPost,
	createUserSession,
	deletePost,
	deleteUser,
	execute,
	layoutBlock,
	login,
	openMcpSession,
	probeLayout,
	probeState,
	rawStorage,
	rest,
	runAbility,
	setProbe,
	siteUrl,
	snapshot,
	toolFailure,
	toolPayload,
};
