/**
 * Meta write authorization for the panels_data key over the XML-RPC
 * custom_fields route (wp.newPost and wp.editPost).
 *
 * That route adds, edits and deletes post meta through the WordPress meta
 * capabilities. For panels_data those capabilities need unfiltered_html:
 *
 * Single site
 * - administrator, editor: have the capability. The value is stored.
 * - author, contributor: do not. The value is not stored; the post is.
 *
 * Network (run with PANELS_E2E_BLUEPRINT=tests/playground/blueprint-multisite.json)
 * - super admin: has the capability. The value is stored.
 * - administrator, editor, author, contributor of the site: do not.
 * - super admin on a request where DISALLOW_UNFILTERED_HTML is set: does not.
 *
 * A write that is not authorized skips the meta only. The request returns no
 * fault, the rest of the post is saved, and a stored value stays as it was.
 *
 * XML-RPC takes the username and password in the call, so those requests
 * carry no cookie. Storage is read straight from the database through the
 * test-only route in tests/playground/mu-plugins/panels-e2e-meta-write.php.
 * That file also sets DISALLOW_UNFILTERED_HTML for a request that carries the
 * header below, so the constant never reaches another test.
 */
const { expect, request, test } = require( '@playwright/test' );

const {
	adminLogin,
	deletePost,
	deleteUser,
	probeLayout,
	rest,
	siteUrl,
} = require( './helpers' );

const META_KEY = 'panels_data';
const CONTENT = 'Meta write authorization content.';
const ROLES = [ 'administrator', 'editor', 'author', 'contributor' ];
const SINGLE_SITE_ROLES_WITH_CAPABILITY = [ 'administrator', 'editor' ];
const DISALLOW_UNFILTERED_HTML_HEADER = { 'X-Panels-E2E-Disallow-Unfiltered-HTML': '1' };

const LAYOUT_A = probeLayout( 'Meta write authorization A' );
const LAYOUT_B = probeLayout( 'Meta write authorization B', 'Second widget' );

let admin;
let site;
let xmlrpcContext;
const users = {};
const createdPosts = [];
let titleCount = 0;

const uniqueTitle = ( label ) => `Meta write ${ label } ${ Date.now() }-${ ++titleCount }`;

const xmlEscape = ( text ) => text
	.replace( /&/g, '&amp;' )
	.replace( /</g, '&lt;' )
	.replace( />/g, '&gt;' );

/**
 * An XML-RPC value: integer, boolean, string, array or struct.
 */
const xmlValue = ( value ) => {
	if ( typeof value === 'number' && Number.isInteger( value ) ) {
		return `<value><int>${ value }</int></value>`;
	}

	if ( typeof value === 'boolean' ) {
		return `<value><boolean>${ value ? 1 : 0 }</boolean></value>`;
	}

	if ( typeof value === 'string' ) {
		return `<value><string>${ xmlEscape( value ) }</string></value>`;
	}

	if ( Array.isArray( value ) ) {
		return `<value><array><data>${ value.map( xmlValue ).join( '' ) }</data></array></value>`;
	}

	if ( value !== null && typeof value === 'object' ) {
		const members = Object.entries( value ).map(
			( [ name, member ] ) => `<member><name>${ xmlEscape( name ) }</name>${ xmlValue( member ) }</member>`
		);

		return `<value><struct>${ members.join( '' ) }</struct></value>`;
	}

	throw new Error( `Unsupported XML-RPC value: ${ value }` );
};

/**
 * Call an XML-RPC method. Resolves to the HTTP status, whether the response
 * is a fault, and the response text.
 */
const xmlrpc = async ( method, params, headers = {} ) => {
	const body = [
		'<?xml version="1.0"?>',
		'<methodCall>',
		`<methodName>${ method }</methodName>`,
		`<params>${ params.map( ( param ) => `<param>${ xmlValue( param ) }</param>` ).join( '' ) }</params>`,
		'</methodCall>',
	].join( '' );

	const response = await xmlrpcContext.post( siteUrl( 'xmlrpc.php' ), {
		headers: { ...headers, 'Content-Type': 'text/xml' },
		data: body,
	} );
	const text = await response.text();

	return {
		status: response.status(),
		fault: text.includes( '<fault>' ),
		text,
	};
};

const expectNoFault = ( response, label ) => {
	expect( response.status, `${ label }: ${ response.text }` ).toBe( 200 );
	expect( response.fault, `${ label }: ${ response.text }` ).toBe( false );
	expect( response.text, label ).toContain( '<methodResponse>' );
};

/**
 * wp.newPost as the user. Resolves to the new post ID. A user's `headers`
 * are sent with each of its XML-RPC requests.
 */
const newPost = async ( user, fields ) => {
	const response = await xmlrpc( 'wp.newPost', [ 1, user.username, user.password, {
		post_type: 'post',
		post_status: 'draft',
		...fields,
	} ], user.headers );
	expectNoFault( response, `wp.newPost as ${ user.username }` );

	const match = response.text.match( /<string>(\d+)<\/string>/ );
	expect( match, response.text ).not.toBeNull();

	const id = parseInt( match[ 1 ], 10 );
	createdPosts.push( id );

	return id;
};

/**
 * wp.editPost as the user.
 */
const editPost = async ( user, id, fields ) => {
	const response = await xmlrpc( 'wp.editPost', [ 1, user.username, user.password, id, fields ], user.headers );
	expectNoFault( response, `wp.editPost as ${ user.username }` );
	expect( response.text ).toContain( '<boolean>1</boolean>' );
};

/**
 * The post and its panels_data rows, read straight from the database.
 *
 * @return {Promise<{exists: boolean, title: string, content: string, status: string, author: number, meta: Array<{meta_id: number, value: any}>}>}
 */
const stored = async ( id ) => {
	const response = await rest( admin, 'GET', `/panels-e2e/v1/meta-rows/${ id }` );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

	return response.body;
};

/**
 * Create a user with the given role. Network sites only accept lower-case
 * letters and digits in a username.
 */
const createUser = async ( role ) => {
	const suffix = `${ Date.now() }${ Math.floor( Math.random() * 1e6 ) }`;
	const username = `panelse2e${ role }${ suffix }`;
	const password = `panels-e2e-${ suffix }!A1`;
	const created = await rest( admin, 'POST', '/wp/v2/users', {
		data: {
			username,
			email: `${ username }@example.com`,
			password,
			roles: [ role ],
		},
	} );
	expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );

	return { id: created.body.id, username, password };
};

/**
 * A user with the capability adds, edits and deletes the meta.
 */
const expectAuthorizedWrites = async ( user ) => {
	const title = uniqueTitle( 'authorized' );
	const id = await newPost( user, {
		post_title: title,
		post_content: CONTENT,
		custom_fields: [ { key: META_KEY, value: LAYOUT_A } ],
	} );

	let state = await stored( id );
	expect( state.title ).toBe( title );
	expect( state.content ).toBe( CONTENT );
	expect( state.author ).toBe( user.id );
	expect( state.meta.map( ( row ) => row.value ) ).toStrictEqual( [ LAYOUT_A ] );

	const metaId = state.meta[ 0 ].meta_id;

	await editPost( user, id, {
		custom_fields: [ { id: metaId, key: META_KEY, value: LAYOUT_B } ],
	} );
	state = await stored( id );
	expect( state.meta ).toStrictEqual( [ { meta_id: metaId, value: LAYOUT_B } ] );

	await editPost( user, id, {
		custom_fields: [ { id: metaId } ],
	} );
	state = await stored( id );
	expect( state.meta ).toStrictEqual( [] );
	expect( state.title ).toBe( title );
};

/**
 * A user without the capability cannot add, edit or delete the meta. Each
 * request still saves the rest of the post.
 *
 * `seed` stores LAYOUT_A on the post as a user with the capability, through
 * the same route.
 */
const expectUnauthorizedWrites = async ( user, seed ) => {
	// Add.
	const title = uniqueTitle( 'not authorized' );
	const id = await newPost( user, {
		post_title: title,
		post_content: CONTENT,
		custom_fields: [ { key: META_KEY, value: LAYOUT_B } ],
	} );

	let state = await stored( id );
	expect( state.exists ).toBe( true );
	expect( state.title ).toBe( title );
	expect( state.content ).toBe( CONTENT );
	expect( state.status ).toBe( 'draft' );
	expect( state.author ).toBe( user.id );
	expect( state.meta ).toStrictEqual( [] );

	// Add to an existing post.
	const addTitle = uniqueTitle( 'not authorized add' );
	await editPost( user, id, {
		post_title: addTitle,
		custom_fields: [ { key: META_KEY, value: LAYOUT_B } ],
	} );
	state = await stored( id );
	expect( state.title ).toBe( addTitle );
	expect( state.meta ).toStrictEqual( [] );

	await seed( id );
	state = await stored( id );
	expect( state.meta.map( ( row ) => row.value ) ).toStrictEqual( [ LAYOUT_A ] );
	expect( state.author ).toBe( user.id );

	const seeded = state.meta;
	const metaId = seeded[ 0 ].meta_id;

	// Edit.
	const editTitle = uniqueTitle( 'not authorized edit' );
	await editPost( user, id, {
		post_title: editTitle,
		custom_fields: [ { id: metaId, key: META_KEY, value: LAYOUT_B } ],
	} );
	state = await stored( id );
	expect( state.title ).toBe( editTitle );
	expect( state.content ).toBe( CONTENT );
	expect( state.meta ).toStrictEqual( seeded );

	// Delete.
	const deleteTitle = uniqueTitle( 'not authorized delete' );
	await editPost( user, id, {
		post_title: deleteTitle,
		custom_fields: [ { id: metaId } ],
	} );
	state = await stored( id );
	expect( state.title ).toBe( deleteTitle );
	expect( state.meta ).toStrictEqual( seeded );
};

const seedAsAdmin = ( id ) => editPost( users.admin, id, {
	custom_fields: [ { key: META_KEY, value: LAYOUT_A } ],
} );

test.beforeAll( async () => {
	admin = await adminLogin();
	xmlrpcContext = await request.newContext( { ignoreHTTPSErrors: true } );

	const state = await rest( admin, 'GET', '/panels-e2e/v1/site' );
	expect( state.status, JSON.stringify( state.body ) ).toBe( 200 );
	site = state.body;

	const me = await rest( admin, 'GET', '/wp/v2/users/me', { params: { _fields: 'id' } } );
	expect( me.status ).toBe( 200 );
	users.admin = {
		id: me.body.id,
		username: process.env.WP_USERNAME,
		password: process.env.WP_PASSWORD,
	};

	for ( const role of ROLES ) {
		users[ role ] = await createUser( role );
	}
} );

test.afterAll( async () => {
	if ( ! admin ) {
		return;
	}

	for ( const id of createdPosts ) {
		await deletePost( admin, 'post', id );
	}

	for ( const role of ROLES ) {
		if ( users[ role ] ) {
			await deleteUser( admin, users[ role ].id );
		}
	}

	if ( xmlrpcContext ) {
		await xmlrpcContext.dispose();
	}
	await admin.context.dispose();
} );

test.describe( 'panels_data meta write authorization over the custom_fields route', () => {
	test( 'the site administrator account adds, edits and deletes the meta', async () => {
		// The built-in administrator: a super admin on a network.
		await expectAuthorizedWrites( users.admin );
	} );

	test.describe( 'single site', () => {
		for ( const role of ROLES ) {
			const hasCapability = SINGLE_SITE_ROLES_WITH_CAPABILITY.includes( role );

			test( `${ role }: the meta is ${ hasCapability ? 'stored' : 'not stored, the post is saved' }`, async () => {
				test.skip( site.multisite, 'Single site only.' );

				if ( hasCapability ) {
					await expectAuthorizedWrites( users[ role ] );
				} else {
					await expectUnauthorizedWrites( users[ role ], seedAsAdmin );
				}
			} );
		}
	} );

	test.describe( 'network', () => {
		for ( const role of ROLES ) {
			test( `${ role } of the site: the meta is not stored, the post is saved`, async () => {
				test.skip( ! site.multisite, 'Network only.' );

				await expectUnauthorizedWrites( users[ role ], seedAsAdmin );
			} );
		}

		test( 'super admin with DISALLOW_UNFILTERED_HTML: the meta is not stored, the post is saved', async () => {
			test.skip( ! site.multisite, 'Network only.' );

			// The seed is the same account without the header, so it has the capability.
			await expectUnauthorizedWrites( { ...users.admin, headers: DISALLOW_UNFILTERED_HTML_HEADER }, seedAsAdmin );
		} );
	} );
} );
