/**
 * Environment matrix: the parity smoke comparison in other environments. Base and candidate share
 * each cell, so a difference is still a plugin difference. Local only; not in CI.
 *
 * Usage: node tests/parity/matrix.mjs [--cell=<name>] [--premium=<absolute path>] [--port=1139]
 *        [--base=<tag|git ref|absolute path>]
 * Cells: multisite-subsite, legacy-theme, siteorigin-theme, no-wb; with --premium also premium and
 * premium-no-wb. A cell that cannot boot is NOT RUN (with the reason) and makes the exit code 1.
 */
import { pathToFileURL } from 'node:url';
import { args } from './lib.mjs';
import { parity } from './parity.mjs';

export function cells( o ) {
	const list = [
		[ 'multisite-subsite', { multisite: 'subsite' } ],
		[ 'legacy-theme', { theme: 'legacy-fixture' } ],
		[ 'siteorigin-theme', { theme: 'vantage' } ],
		[ 'no-wb', { wb: 'none' } ],
	];
	if ( o.premium ) {
		list.push( [ 'premium', { premium: o.premium } ] );
		list.push( [ 'premium-no-wb', { premium: o.premium, wb: 'none' } ] );
	}
	return o.cell ? list.filter( ( [ name ] ) => name === o.cell ) : list;
}

export async function matrix( o ) {
	if ( o.premium && process.env.CI ) {
		throw new Error( '--premium is for local runs only; it is refused when CI is set' );
	}
	const todo = cells( o );
	if ( ! todo.length ) {
		throw new Error( `unknown cell ${ o.cell }` );
	}
	const lines = [];
	let ok = true;
	for ( const [ name, opts ] of todo ) {
		console.log( `\n===== cell ${ name } =====` );
		let res;
		try {
			res = await parity( { port: o.port, base: o.base, smoke: true, prefix: `cell-${ name }`, ...opts } );
		} catch ( e ) {
			res = { ok: false, incomplete: [ 'all' ], reason: e.message };
		}
		if ( res.incomplete && res.incomplete.length ) {
			lines.push( `NOT RUN  ${ name }: ${ res.reason || `incomplete runs: ${ res.incomplete.join( ', ' ) } (see tests/cache/parity/logs/)` }` );
		} else {
			lines.push( `${ res.ok ? 'PASS' : 'FAIL' }     ${ name }` );
		}
		ok = ok && res.ok;
	}
	console.log( '\n' + lines.join( '\n' ) + `\n\nMATRIX: ${ ok ? 'PASS' : 'FAIL' }` );
	return ok;
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	matrix( args() ).then( ( ok ) => {
		process.exitCode = ok ? 0 : 1;
	}, ( e ) => {
		console.error( e.message );
		process.exitCode = 1;
	} );
}
