/**
 * Is a run complete? A run whose server stopped part-way must not be compared.
 *
 * Usage: node tests/parity/integrity.mjs <label>. Exit 0 when complete.
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { OUTROOT } from './lib.mjs';

export function integrity( label ) {
	const problems = [];
	const f = path.join( OUTROOT, label, 'cases.json' );
	if ( ! fs.existsSync( f ) ) {
		return [ `${ label }: no cases.json` ];
	}
	const r = JSON.parse( fs.readFileSync( f, 'utf8' ) );
	if ( ! r.length ) {
		problems.push( `${ label }: no cases` );
	}
	for ( const c of r ) {
		if ( c.error ) {
			problems.push( `${ label } ${ c.key }: harness error` );
		} else if ( ! c.id ) {
			problems.push( `${ label } ${ c.key }: no post id` );
		} else if ( ! c.stored || c.stored.missing ) {
			problems.push( `${ label } ${ c.key }: no stored data` );
		} else if ( ! c.vis || ! Object.keys( c.vis ).length || Object.values( c.vis ).some( ( v ) => ! v || v.status === 0 ) ) {
			problems.push( `${ label } ${ c.key }: no visitor response` );
		} else if ( c.ai && c.ai.http === 0 ) {
			problems.push( `${ label } ${ c.key }: no ability response` );
		}
	}
	return problems;
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	const label = process.argv[ 2 ];
	const problems = integrity( label );
	console.log( `${ label }: ${ problems.length ? 'INCOMPLETE (' + problems.length + ' problems; first: ' + problems[ 0 ] + ')' : 'complete' }` );
	process.exitCode = problems.length ? 1 : 0;
}
