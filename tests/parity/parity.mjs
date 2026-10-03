/**
 * Parity check: stored data, pages and generated CSS of the working tree are the same as on the release.
 *
 * Runs base-1, cand-1, base-2, cand-2 one after another on ONE port, checks each run is complete (up to
 * --tries tries), then compares base-1/base-2 and cand-1/cand-2 (noise check) and base/cand twice.
 *
 * Usage: node tests/parity/parity.mjs [--base=<tag|git ref|absolute path>] [--candidate=<git ref>]
 *        [--smoke] [--filter=<regex>] [--port=1139] [--wp=latest] [--php=8.3]
 *        [--wb=latest|<version>|<path>|none] [--theme=<slug>] [--multisite] [--premium=<abs path>]
 *        [--tokens=port,version] [--tries=3] [--prefix=<label prefix>]
 * Exit 0 = PASS, 1 = a difference, an incomplete run, or a coverage floor missed.
 */
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import { spawn, execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { ROOT, HERE, OUTROOT, args, sleep } from './lib.mjs';
import { integrity } from './integrity.mjs';
import { compareRuns } from './compare.mjs';

const git = ( ...a ) => execFileSync( 'git', a, { cwd: ROOT, encoding: 'utf8' } ).trim();

// The highest tag that is a plain X.Y.Z version, by version order (the repo also has non-version tags).
export function latestReleaseTag() {
	const tags = git( 'tag', '--list' ).split( '\n' ).filter( ( t ) => /^\d+\.\d+\.\d+$/.test( t ) );
	if ( ! tags.length ) {
		throw new Error( 'no X.Y.Z tag found; fetch tags (git fetch --tags) or pass --base' );
	}
	const num = ( t ) => t.split( '.' ).map( Number );
	tags.sort( ( a, b ) => {
		const x = num( a );
		const y = num( b );
		return x[ 0 ] - y[ 0 ] || x[ 1 ] - y[ 1 ] || x[ 2 ] - y[ 2 ];
	} );
	return tags[ tags.length - 1 ];
}

// A git ref as a plain source tree in tests/cache/parity/trees/<ref>. Reused when complete.
// The ref's own tests/ folder is left out: the plugin does not need it, and PHPUnit scans tests/
// recursively, so test classes in a tree under tests/cache would be loaded twice by `composer test`.
export function materialise( ref ) {
	if ( path.isAbsolute( ref ) ) {
		if ( ! fs.existsSync( path.join( ref, 'siteorigin-panels.php' ) ) ) {
			throw new Error( `${ ref } is not a Page Builder tree` );
		}
		return ref;
	}
	git( 'rev-parse', '--verify', '--quiet', ref + '^{commit}' );
	const dir = path.join( OUTROOT, 'trees', ref.replace( /[^A-Za-z0-9._-]/g, '_' ) );
	if ( fs.existsSync( path.join( dir, '.complete' ) ) && ! fs.existsSync( path.join( dir, 'tests' ) ) ) {
		return dir;
	}
	fs.rmSync( dir, { recursive: true, force: true } );
	fs.mkdirSync( dir, { recursive: true } );
	execFileSync( 'sh', [ '-c', `git archive --format=tar "$1" -- . ":(exclude)tests" | tar -x -C "$2"`, 'sh', ref, dir ], { cwd: ROOT } );
	fs.writeFileSync( path.join( dir, '.complete' ), git( 'rev-parse', ref + '^{commit}' ) + '\n' );
	return dir;
}

export const portFree = ( port ) => new Promise( ( resolve ) => {
	const s = net.createServer();
	s.once( 'error', () => resolve( false ) );
	s.once( 'listening', () => s.close( () => resolve( true ) ) );
	s.listen( port, '127.0.0.1' );
} );

const RUN_OPTS = [ 'smoke', 'filter', 'port', 'wp', 'php', 'wb', 'theme', 'multisite', 'premium', 'zip' ];

// One run with an integrity check and up to `tries` tries. A failed try is moved to discarded/.
export async function runWithTries( label, tree, o, tries ) {
	const logs = path.join( OUTROOT, 'logs' );
	fs.mkdirSync( logs, { recursive: true } );
	const port = Number( o.port || 1139 );
	const pass = RUN_OPTS.filter( ( k ) => o[ k ] !== undefined && o[ k ] !== false ).map( ( k ) => ( o[ k ] === true ? `--${ k }` : `--${ k }=${ o[ k ] }` ) );
	for ( let t = 1; t <= tries; t++ ) {
		for ( let i = 0; i < 120 && ! ( await portFree( port ) ); i++ ) {
			await sleep( 5000 );
		}
		const out = path.join( OUTROOT, label );
		if ( fs.existsSync( out ) ) {
			const dst = path.join( OUTROOT, 'discarded', `${ label }.${ Date.now() }` );
			fs.mkdirSync( path.dirname( dst ), { recursive: true } );
			fs.renameSync( out, dst );
		}
		const t0 = Date.now();
		const logFile = path.join( logs, `${ label }.log` );
		const code = await new Promise( ( resolve ) => {
			const log = fs.openSync( logFile, 'w' );
			const child = spawn( process.execPath, [ path.join( HERE, 'run.mjs' ), `--label=${ label }`, `--pb=${ tree }`, `--port=${ port }`, ...pass ], { stdio: [ 'ignore', log, log ] } );
			child.on( 'close', ( c ) => {
				fs.closeSync( log );
				resolve( c );
			} );
		} );
		const problems = integrity( label );
		const secs = Math.round( ( Date.now() - t0 ) / 1000 );
		const last = fs.readFileSync( logFile, 'utf8' ).trim().split( '\n' ).pop();
		if ( code === 0 && ! problems.length ) {
			console.log( `${ label }: complete in ${ secs }s (${ last })` );
			return true;
		}
		const failed = fs.readFileSync( logFile, 'utf8' ).split( '\n' ).find( ( l ) => /^FAILED|Error: /.test( l ) );
		console.log( `${ label }: try ${ t } incomplete after ${ secs }s: exit ${ code }; ${ failed || problems[ 0 ] || last }` );
		runWithTries.lastReason = ( failed || problems[ 0 ] || last || '' ).trim();
	}
	return false;
}

export async function parity( o ) {
	const prefix = o.prefix ? `${ o.prefix }-` : '';
	const tries = Number( o.tries || 3 );
	const tokens = String( o.tokens || '' ).split( ',' ).filter( Boolean );
	const baseRef = o.base || latestReleaseTag();
	const baseTree = materialise( baseRef );
	const candTree = o.candidate ? materialise( o.candidate ) : ROOT;
	console.log( `base: ${ baseRef } (${ baseTree })\ncandidate: ${ o.candidate || 'working tree' } (${ candTree })` );
	const L = { b1: `${ prefix }base-1`, c1: `${ prefix }cand-1`, b2: `${ prefix }base-2`, c2: `${ prefix }cand-2` };
	const order = [ [ L.b1, baseTree ], [ L.c1, candTree ], [ L.b2, baseTree ], [ L.c2, candTree ] ];
	const complete = {};
	const reasons = {};
	for ( const [ label, tree ] of order ) {
		complete[ label ] = await runWithTries( label, tree, o, tries );
		if ( ! complete[ label ] ) {
			// An incomplete run is never compared, so the remaining runs would be wasted.
			reasons[ label ] = runWithTries.lastReason;
			break;
		}
	}
	const rows = [];
	let ok = Object.values( complete ).every( Boolean );
	if ( ! ok ) {
		rows.push( `INCOMPLETE: ${ Object.entries( complete ).filter( ( [ , v ] ) => ! v ).map( ( [ k ] ) => `${ k } (${ reasons[ k ] })` ).join( ', ' ) }` );
	} else {
		for ( const [ x, y, what ] of [ [ L.b1, L.b2, 'base noise' ], [ L.c1, L.c2, 'candidate noise' ], [ L.b1, L.c1, 'base vs candidate (1)' ], [ L.b2, L.c2, 'base vs candidate (2)' ] ] ) {
			const r = compareRuns( x, y, { tokens } );
			rows.push( `${ r.pass ? 'PASS' : 'FAIL' }  ${ what }: ${ x } vs ${ y } — ${ r.compared } cases, ${ r.diffs.length } differing fields, ${ r.missing.length } missing` );
			ok = ok && r.pass;
			if ( x === L.b1 && y === L.b2 ) {
				const missed = r.floorMissed[ x ];
				rows.push( `${ missed.length ? 'FAIL' : 'PASS' }  coverage of ${ x }: ${ missed.length ? missed.join( '; ' ) : 'floor met' }` );
				ok = ok && ! missed.length;
			}
		}
	}
	console.log( '\n' + rows.join( '\n' ) + `\n\nVERDICT: ${ ok ? 'PASS' : 'FAIL' }  (reports: ${ path.join( OUTROOT, 'compare' ) })` );
	return { ok, incomplete: Object.entries( complete ).filter( ( [ , v ] ) => ! v ).map( ( [ k ] ) => k ), reason: Object.values( reasons )[ 0 ] };
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	const o = args();
	parity( o ).then( ( res ) => {
		process.exitCode = res.ok ? 0 : 1;
	}, ( e ) => {
		console.error( e );
		process.exitCode = 1;
	} );
}
