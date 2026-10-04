/**
 * The layout abilities as the core Abilities REST API reports them: MCP
 * exposure on both, the readonly annotation on layout-get, and the HTTP
 * method core requires for each.
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createPost,
	deletePost,
	rest,
	runAbility,
} = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

let admin;
const pages = [];

test.beforeAll( async () => {
	admin = await adminLogin();
} );

test.afterAll( async () => {
	for ( const id of pages ) {
		await deletePost( admin, 'page', id ).catch( () => {} );
	}

	await admin?.context.dispose();
} );

test( 'R1: both abilities are public to MCP; only layout-get is readonly', async () => {
	const get = await rest( admin, 'GET', '/wp-abilities/v1/abilities/siteorigin-panels/layout-get' );
	expect( get.status, JSON.stringify( get.body ) ).toBe( 200 );
	expect( get.body.meta.annotations.readonly ).toBe( true );
	expect( get.body.meta.readonly ).toBeUndefined();
	expect( get.body.meta.mcp.public ).toBe( true );

	const update = await rest( admin, 'GET', '/wp-abilities/v1/abilities/siteorigin-panels/layout-update' );
	expect( update.status, JSON.stringify( update.body ) ).toBe( 200 );
	expect( update.body.meta.mcp.public ).toBe( true );
	expect( update.body.meta.annotations?.readonly ).not.toBe( true );
} );

test( 'R2: layout-get runs over GET and refuses POST', async () => {
	const id = await createPost( admin, 'page', { title: 'Panels e2e R2' } );
	pages.push( id );

	const viaGet = await runAbility( admin, 'siteorigin-panels/layout-get', { post_id: id }, 'GET' );
	expect( viaGet.status, JSON.stringify( viaGet.body ) ).toBe( 200 );
	expect( viaGet.body.post_id ).toBe( id );

	const viaPost = await runAbility( admin, 'siteorigin-panels/layout-get', { post_id: id }, 'POST' );
	expect( viaPost.status ).toBe( 405 );
	expect( viaPost.body.code ).toBe( 'rest_ability_invalid_method' );
} );
