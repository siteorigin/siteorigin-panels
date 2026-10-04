/**
 * The parity harness keeps one source tree per git ref in its cache. A ref that moves (a branch)
 * must get a fresh tree; an unchanged ref reuses the cached one.
 *
 * Runs in a scratch git repository in the system temp folder; the plugin repository is not touched.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );

const git = ( cwd, ...args ) => execFileSync( 'git', [ '-c', 'user.name=Parity', '-c', 'user.email=parity@example.com', '-c', 'commit.gpgsign=false', ...args ], { cwd, encoding: 'utf8' } ).trim();

test( 'a cached tree follows its ref', async () => {
	const { materialise } = await import( '../parity/parity.mjs' );
	const scratch = fs.mkdtempSync( path.join( os.tmpdir(), 'parity-materialise-' ) );
	const repo = path.join( scratch, 'repo' );
	const outroot = path.join( scratch, 'out' );

	try {
		fs.mkdirSync( path.join( repo, 'tests' ), { recursive: true } );
		git( repo, 'init', '-q' );
		fs.writeFileSync( path.join( repo, 'siteorigin-panels.php' ), '<?php // one' );
		fs.writeFileSync( path.join( repo, 'tests', 'OneTest.php' ), '<?php' );
		git( repo, 'add', '-A' );
		git( repo, 'commit', '-q', '-m', 'one' );
		git( repo, 'branch', 'scratch-ref' );
		const first = git( repo, 'rev-parse', 'scratch-ref' );

		const dir = materialise( 'scratch-ref', { root: repo, outroot } );
		assert.strictEqual( fs.readFileSync( path.join( dir, 'siteorigin-panels.php' ), 'utf8' ), '<?php // one' );
		assert.strictEqual( fs.readFileSync( path.join( dir, '.complete' ), 'utf8' ).trim(), first );
		assert.strictEqual( fs.existsSync( path.join( dir, 'tests' ) ), false, 'the tests folder is left out' );

		// Unchanged ref: the cached tree is reused (a file added to it survives).
		fs.writeFileSync( path.join( dir, 'reuse-marker' ), '' );
		materialise( 'scratch-ref', { root: repo, outroot } );
		assert.strictEqual( fs.existsSync( path.join( dir, 'reuse-marker' ) ), true, 'unchanged ref reuses the tree' );

		// Move the ref: the tree is built again from the new commit.
		fs.writeFileSync( path.join( repo, 'siteorigin-panels.php' ), '<?php // two' );
		git( repo, 'commit', '-q', '-am', 'two' );
		git( repo, 'branch', '-f', 'scratch-ref', 'HEAD' );
		const second = git( repo, 'rev-parse', 'scratch-ref' );
		assert.notStrictEqual( second, first );

		const again = materialise( 'scratch-ref', { root: repo, outroot } );
		assert.strictEqual( again, dir );
		assert.strictEqual( fs.readFileSync( path.join( dir, 'siteorigin-panels.php' ), 'utf8' ), '<?php // two' );
		assert.strictEqual( fs.readFileSync( path.join( dir, '.complete' ), 'utf8' ).trim(), second );
		assert.strictEqual( fs.existsSync( path.join( dir, 'reuse-marker' ) ), false, 'a moved ref gets a fresh tree' );
	} finally {
		fs.rmSync( scratch, { recursive: true, force: true } );
	}
} );
