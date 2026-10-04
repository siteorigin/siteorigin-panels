/**
 * Start WordPress Playground with this plugin and the e2e mu-plugins, then run
 * the Playwright end-to-end tests against it.
 *
 * If tests/so-tests.env exists, Playground is not started and the tests run
 * against the site that file names (siteorigin-tests-common convention). That
 * site must have Page Builder active and the files in tests/playground/mu-plugins
 * installed by hand as must-use plugins.
 *
 * The port defaults to 1129 (the shared SiteOrigin default); set
 * PANELS_E2E_PORT to use another one.
 *
 * The blueprint defaults to tests/playground/blueprint.json, a single site.
 * Set PANELS_E2E_BLUEPRINT to a path, relative to the plugin root, to use
 * another one: tests/playground/blueprint-multisite.json starts a network.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { spawn } = require( 'child_process' );

const root = path.resolve( __dirname, '..', '..' );
const port = parseInt( process.env.PANELS_E2E_PORT || '1129', 10 );
const blueprintPath = path.resolve( root, process.env.PANELS_E2E_BLUEPRINT || path.join( 'tests', 'playground', 'blueprint.json' ) );

const startPlayground = async () => {
	const { runCLI } = require( '@wp-playground/cli' );

	process.env.WP_BASE_URL = `http://127.0.0.1:${ port }`;

	return runCLI( {
		command: 'server',
		port,
		blueprint: JSON.parse( fs.readFileSync( blueprintPath, 'utf8' ) ),
		'mount-before-install': [
			{
				hostPath: root,
				vfsPath: '/wordpress/wp-content/plugins/siteorigin-panels',
			},
			{
				hostPath: path.join( root, 'tests', 'playground', 'mu-plugins' ),
				vfsPath: '/wordpress/wp-content/mu-plugins',
			},
		],
	} );
};

const runPlaywright = () => new Promise( ( resolve ) => {
	const child = spawn( 'npx', [ 'playwright', 'test', ...process.argv.slice( 2 ) ], {
		cwd: root,
		env: process.env,
		stdio: 'inherit',
		shell: process.platform === 'win32',
	} );

	child.on( 'close', ( code ) => resolve( code === null ? 1 : code ) );
} );

const run = async () => {
	let server = null;

	if ( ! fs.existsSync( path.join( root, 'tests', 'so-tests.env' ) ) ) {
		server = await startPlayground();
	}

	let code = 1;
	try {
		code = await runPlaywright();
	} finally {
		if ( server && typeof server[ Symbol.asyncDispose ] === 'function' ) {
			await server[ Symbol.asyncDispose ]().catch( () => {} );
		}
	}

	process.exit( code );
};

run().catch( ( error ) => {
	console.error( 'Error running the e2e tests:', error );
	process.exit( 1 );
} );
