/**
 * Meta write authorization for other forms of the panels_data key.
 *
 * WordPress selects the meta rows to update or delete with the database's
 * own comparison of the key, and it removes slashes from the key first. So a
 * key such as Panels_Data can select the stored panels_data row. A write
 * route that checks the meta capability on the key it was given, then calls
 * update_post_meta() or delete_post_meta() with that key, must get the same
 * answer for such a key as for panels_data itself: the capability needs
 * unfiltered_html.
 *
 * The test-only route in tests/playground/mu-plugins/panels-e2e-meta-write.php
 * is such a write route. Each form of the key is checked in two steps:
 *
 * 1. The administrator account (has unfiltered_html) writes with that key.
 *    This shows what the database of the test site does with the key: either
 *    the write lands on the panels_data row, or it does not. Letter case and
 *    a removed slash must land on it. A trailing space and an accented letter
 *    depend on the collation of the meta key column, so the test takes the
 *    answer from this step.
 * 2. An author (no unfiltered_html) writes with that key to a post of its own
 *    that has a stored layout. The write is allowed only if step 1 showed the
 *    key does not land on the panels_data row. In every case the stored row
 *    stays byte-identical.
 *
 * Storage is read straight from the database, as stored.
 */
const { expect, test } = require( '@playwright/test' );

const {
	adminLogin,
	createPost,
	deletePost,
	deleteUser,
	login,
	probeLayout,
	rest,
} = require( './helpers' );

const META_KEY = 'panels_data';

const LAYOUT_A = probeLayout( 'Meta key authorization A' );
const LAYOUT_B = probeLayout( 'Meta key authorization B', 'Second widget' );

const KEY_FORMS = [
	{ label: 'another letter case', key: 'Panels_Data', alwaysSelectsTheRow: true },
	{ label: 'upper case', key: 'PANELS_DATA', alwaysSelectsTheRow: true },
	{ label: 'a slash that WordPress removes', key: 'panels\\_data', alwaysSelectsTheRow: true },
	{ label: 'a trailing space', key: 'panels_data ', alwaysSelectsTheRow: false },
	{ label: 'an accented letter', key: 'pánels_data', alwaysSelectsTheRow: false },
];

let admin;
let author;
const createdPosts = [];

const createTracked = async ( session, label ) => {
	const id = await createPost( session, 'post', { title: `Meta key authorization ${ label } ${ Date.now() }` } );
	createdPosts.push( id );

	return id;
};

/**
 * Write through the capability-then-write route as the session's user.
 *
 * @return {Promise<boolean>} Whether the capability check allowed the write.
 */
const write = async ( session, postId, operation, key, value ) => {
	const response = await rest( session, 'POST', '/panels-e2e/v1/meta-write', {
		data: { post_id: postId, operation, key, value },
	} );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

	return response.body.allowed;
};

/**
 * Every meta row of the post, as stored.
 *
 * @return {Promise<Array<{meta_id: number, meta_key: string, meta_value: string}>>}
 */
const metaRows = async ( postId ) => {
	const response = await rest( admin, 'GET', `/panels-e2e/v1/post-meta/${ postId }` );
	expect( response.status, JSON.stringify( response.body ) ).toBe( 200 );

	return response.body.meta;
};

/**
 * The stored panels_data row of the post: meta ID, key and value string.
 */
const layoutRow = async ( postId ) => {
	const rows = ( await metaRows( postId ) ).filter( ( row ) => row.meta_key === META_KEY );
	expect( rows.length ).toBeLessThanOrEqual( 1 );

	return rows[ 0 ];
};

/**
 * Store a layout on the post as the administrator account, with the key
 * itself, and return the stored row.
 */
const storeLayout = async ( postId, layout ) => {
	expect( await write( admin, postId, 'update', META_KEY, layout ) ).toBe( true );

	const row = await layoutRow( postId );
	expect( row ).toBeTruthy();

	return row;
};

test.beforeAll( async () => {
	admin = await adminLogin();

	// Network sites only accept lower-case letters and digits in a username.
	const suffix = `${ Date.now() }${ Math.floor( Math.random() * 1e6 ) }`;
	const username = `panelse2ekeyauthor${ suffix }`;
	const password = `panels-e2e-${ suffix }!A1`;
	const created = await rest( admin, 'POST', '/wp/v2/users', {
		data: {
			username,
			email: `${ username }@example.com`,
			password,
			roles: [ 'author' ],
		},
	} );
	expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );

	author = await login( username, password );
	author.userId = created.body.id;
} );

test.afterAll( async () => {
	if ( ! admin ) {
		return;
	}

	for ( const id of createdPosts ) {
		await deletePost( admin, 'post', id );
	}

	if ( author ) {
		await deleteUser( admin, author.userId );
		await author.context.dispose();
	}

	await admin.context.dispose();
} );

test.describe( 'panels_data meta write authorization for other forms of the key', () => {
	for ( const form of KEY_FORMS ) {
		test( `${ form.label }: an author cannot change the stored layout`, async () => {
			// 1. What the database does with this key.
			const adminPost = await createTracked( admin, 'administrator' );
			const adminStored = await storeLayout( adminPost, LAYOUT_A );

			expect( await write( admin, adminPost, 'update', form.key, LAYOUT_B ) ).toBe( true );

			const afterUpdate = await layoutRow( adminPost );
			expect( afterUpdate ).toBeTruthy();
			expect( afterUpdate.meta_id ).toBe( adminStored.meta_id );

			const selectsTheRow = afterUpdate.meta_value !== adminStored.meta_value;
			test.info().annotations.push( {
				type: 'database',
				description: `${ JSON.stringify( form.key ) } ${ selectsTheRow ? 'selects' : 'does not select' } the panels_data row`,
			} );

			if ( form.alwaysSelectsTheRow ) {
				expect( selectsTheRow, `${ JSON.stringify( form.key ) } must select the panels_data row` ).toBe( true );
			}

			expect( await write( admin, adminPost, 'delete', form.key ) ).toBe( true );
			if ( selectsTheRow ) {
				expect( await layoutRow( adminPost ) ).toBeUndefined();
			} else {
				expect( await layoutRow( adminPost ) ).toStrictEqual( adminStored );
			}

			// 2. The author, on a post of its own that has a stored layout.
			const authorPost = await createTracked( author, 'author' );
			const stored = await storeLayout( authorPost, LAYOUT_A );

			const updateAllowed = await write( author, authorPost, 'update', form.key, LAYOUT_B );
			expect( await layoutRow( authorPost ) ).toStrictEqual( stored );
			expect( updateAllowed ).toBe( ! selectsTheRow );

			const deleteAllowed = await write( author, authorPost, 'delete', form.key );
			expect( await layoutRow( authorPost ) ).toStrictEqual( stored );
			expect( deleteAllowed ).toBe( ! selectsTheRow );
		} );
	}

	test( 'the key itself: an author cannot change the stored layout', async () => {
		const authorPost = await createTracked( author, 'author' );
		const stored = await storeLayout( authorPost, LAYOUT_A );

		const updateAllowed = await write( author, authorPost, 'update', META_KEY, LAYOUT_B );
		expect( await layoutRow( authorPost ) ).toStrictEqual( stored );
		expect( updateAllowed ).toBe( false );

		const deleteAllowed = await write( author, authorPost, 'delete', META_KEY );
		expect( await layoutRow( authorPost ) ).toStrictEqual( stored );
		expect( deleteAllowed ).toBe( false );
	} );

	test( 'another key: an author adds, edits and deletes it on a post that has a stored layout', async () => {
		const authorPost = await createTracked( author, 'author' );
		const stored = await storeLayout( authorPost, LAYOUT_A );

		const fieldRows = async () => ( await metaRows( authorPost ) ).filter( ( row ) => row.meta_key === 'my_field' );

		expect( await write( author, authorPost, 'update', 'my_field', 'First value' ) ).toBe( true );
		expect( ( await fieldRows() ).map( ( row ) => row.meta_value ) ).toStrictEqual( [ 'First value' ] );

		expect( await write( author, authorPost, 'update', 'my_field', 'Second value' ) ).toBe( true );
		expect( ( await fieldRows() ).map( ( row ) => row.meta_value ) ).toStrictEqual( [ 'Second value' ] );

		expect( await write( author, authorPost, 'delete', 'my_field' ) ).toBe( true );
		expect( await fieldRows() ).toStrictEqual( [] );

		expect( await layoutRow( authorPost ) ).toStrictEqual( stored );
	} );
} );
