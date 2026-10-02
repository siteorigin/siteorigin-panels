/**
 * Generator test: many generated layouts go through the plugin's own save handlers and renderers on
 * the release and on the working tree. A layout the release stores must be stored byte-equal and
 * render equal on the working tree; two runs per version show which bytes change on their own.
 *
 * Usage: node tests/parity/generate.mjs [--seed=20261002] [--count=1000] [--chunk=50]
 *        [--base=<tag|git ref|absolute path>] [--candidate=<git ref>] [--port=1139]
 *        [--wp=latest] [--php=8.3] [--wb=latest|<version>|<path>|none] [--tokens=port,version]
 *        [--tries=3] [--prefix=gen]
 * Exit 0 = PASS, 1 = a difference, an incomplete run, a failed self-check or an acceptance floor missed.
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { pathToFileURL } from 'node:url';
import { Site, args, parse, OUTROOT, ROOT, sleep } from './lib.mjs';
import { latestReleaseTag, materialise, portFree } from './parity.mjs';
import { tokenList, normaliser, ctx } from './compare.mjs';

const ROLES = [ 'admin', 'author' ];
const PATHS = [ 'classic', 'block' ];
const RENDERERS = [ 'modern', 'legacy' ];
const REF_RULE = /^[\p{L}\p{N}_-]+$/u;
const ACCEPT_FLOOR = 0.6;

// ---------- self-check ----------

// Every string used as a row, cell or widget reference, in a layout and its nested layouts.
export function referenceStrings( layout, out = [] ) {
	if ( ! layout || typeof layout !== 'object' ) {
		return out;
	}
	const each = ( v, fn ) => {
		if ( v && typeof v === 'object' ) {
			Object.values( v ).forEach( fn );
		}
	};
	each( layout.grid_cells, ( c ) => {
		if ( c && typeof c === 'object' && typeof c.grid === 'string' ) {
			out.push( c.grid );
		}
	} );
	each( layout.widgets, ( w ) => {
		if ( ! w || typeof w !== 'object' ) {
			return;
		}
		for ( const holder of [ 'panels_info', 'info' ] ) {
			const info = w[ holder ];
			if ( info && typeof info === 'object' ) {
				for ( const f of [ 'grid', 'cell', 'id', 'widget_index' ] ) {
					if ( typeof info[ f ] === 'string' ) {
						out.push( info[ f ] );
					}
				}
			}
		}
		if ( w.panels_data && typeof w.panels_data === 'object' ) {
			referenceStrings( w.panels_data, out );
		}
	} );
	return out;
}

async function fetchCases( site, seed, count, chunk ) {
	const cases = [];
	for ( let from = 0; from < count; from += chunk ) {
		const r = await site.req( 'GET', `/?parity=generate&seed=${ seed }&count=${ count }&from=${ from }&to=${ Math.min( count, from + chunk ) }` );
		const list = parse( r );
		if ( ! Array.isArray( list ) ) {
			throw new Error( `generate ${ from }: HTTP ${ r.status } ${ r.text.slice( 0, 200 ) }` );
		}
		cases.push( ...list );
	}
	return cases;
}

async function selfCheck( site, seed, count, chunk ) {
	const a = await fetchCases( site, seed, count, chunk );
	const b = await fetchCases( site, seed, count, chunk );
	const problems = [];
	if ( JSON.stringify( a ) !== JSON.stringify( b ) ) {
		problems.push( 'two generations with one seed differ' );
	}
	if ( a.length !== count ) {
		problems.push( `${ a.length } cases, expected ${ count }` );
	}
	for ( const c of a ) {
		let layout;
		try {
			layout = JSON.parse( c.json );
		} catch ( e ) {
			problems.push( `case ${ c.i }: json does not parse` );
			continue;
		}
		const bad = referenceStrings( layout ).filter( ( s ) => ! REF_RULE.test( s ) );
		if ( bad.length ) {
			problems.push( `case ${ c.i } (${ c.kind }): reference ${ JSON.stringify( bad[ 0 ].slice( 0, 40 ) ) } is outside the rule` );
		}
	}
	return { cases: a, problems };
}

// ---------- one run ----------

async function oneRun( label, tree, o, seed, count, chunk ) {
	const site = new Site( { ...o, label, pb: tree } );
	const t0 = Date.now();
	try {
		await site.boot();
		await site.req( 'GET', '/?parity=setup', { user: 'admin' } );
		const info = { admin: await site.info( 'admin' ), options: o, seed, count };
		fs.writeFileSync( path.join( site.OUT, 'info.json' ), JSON.stringify( info, null, 1 ) );
		const cases = await fetchCases( site, seed, count, chunk );
		const recs = cases.map( ( c ) => ( { i: c.i, kind: c.kind, note: c.note, save: {}, render: {} } ) );
		const scratch = {};

		for ( const role of ROLES ) {
			for ( let from = 0; from < count; from += chunk ) {
				const r = await site.req( 'POST', '/wp-admin/admin-ajax.php?action=panels_parity_batch_save', { user: role, json: { seed, count, from, to: Math.min( count, from + chunk ) } } );
				const j = parse( r );
				if ( ! j || ! Array.isArray( j.cases ) ) {
					throw new Error( `save ${ role } ${ from }: HTTP ${ r.status } ${ r.text.slice( 0, 300 ) }` );
				}
				scratch[ role ] = j.scratch;
				for ( const c of j.cases ) {
					recs[ c.i ].save[ role ] = { classic: c.classic, block: c.block };
				}
			}
			console.log( `${ label }: saved ${ count } cases as ${ role }, ${ Math.round( ( Date.now() - t0 ) / 1000 ) }s` );
		}

		for ( const renderer of RENDERERS ) {
			for ( let from = 0; from < count; from += chunk ) {
				const items = [];
				for ( const rec of recs.slice( from, from + chunk ) ) {
					items.push( { key: `${ rec.i }|raw`, kind: 'raw', post_id: scratch.admin.classic, bytes_b64: Buffer.from( cases[ rec.i ].json, 'utf8' ).toString( 'base64' ) } );
					for ( const role of ROLES ) {
						for ( const p of PATHS ) {
							const s = rec.save[ role ] && rec.save[ role ][ p ];
							if ( s && s.accepted ) {
								items.push( { key: `${ rec.i }|${ role }.${ p }`, kind: p, post_id: scratch[ role ][ p ], bytes_b64: s.bytes } );
							}
						}
					}
				}
				const r = await site.req( 'POST', '/?parity=batch_render', { renderer, json: { items } } );
				const j = parse( r );
				if ( ! j || ! Array.isArray( j.items ) ) {
					throw new Error( `render ${ renderer } ${ from }: HTTP ${ r.status } ${ r.text.slice( 0, 300 ) }` );
				}
				for ( const it of j.items ) {
					const [ i, what ] = it.key.split( '|' );
					const slot = ( recs[ i ].render[ what ] = recs[ i ].render[ what ] || {} );
					slot[ renderer ] = { html: it.html, css: it.css, exception: it.exception };
				}
			}
			console.log( `${ label }: rendered on ${ renderer }, ${ Math.round( ( Date.now() - t0 ) / 1000 ) }s` );
		}

		const lines = recs.map( ( r ) => JSON.stringify( r ) ).join( '\n' ) + '\n';
		fs.writeFileSync( path.join( site.OUT, 'gen.jsonl.gz' ), zlib.gzipSync( lines ) );
		const secs = Math.round( ( Date.now() - t0 ) / 1000 );
		fs.writeFileSync( path.join( site.OUT, 'complete.json' ), JSON.stringify( { cases: recs.length, seconds: secs } ) );
		console.log( `${ label }: complete, ${ recs.length } cases in ${ secs }s` );
		return true;
	} finally {
		site.stop();
	}
}

async function runWithTries( label, tree, o, seed, count, chunk, tries ) {
	const port = Number( o.port || 1139 );
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
		try {
			return await oneRun( label, tree, o, seed, count, chunk );
		} catch ( e ) {
			console.log( `${ label }: try ${ t } incomplete: ${ String( ( e && e.message ) || e ).slice( 0, 300 ) }` );
		}
	}
	return false;
}

// ---------- compare ----------

const loadRun = ( label ) => zlib.gunzipSync( fs.readFileSync( path.join( OUTROOT, label, 'gen.jsonl.gz' ) ) ).toString( 'utf8' ).trim().split( '\n' ).map( ( l ) => JSON.parse( l ) );
const bytesOf = ( b64 ) => ( b64 === null || b64 === undefined ? null : Buffer.from( b64, 'base64' ).toString( 'latin1' ) );

export function compareGen( X, Y, extra = [] ) {
	const infoOf = ( l ) => JSON.parse( fs.readFileSync( path.join( OUTROOT, l, 'info.json' ), 'utf8' ) );
	const used = {};
	const norm = normaliser( tokenList( extra, [ X, Y ].map( ( l ) => infoOf( l ).admin.panels_version ) ), used );
	const rx = loadRun( X );
	const ry = loadRun( Y );
	const diffs = [];
	const add = ( i, field, a, b ) => {
		const x = typeof a === 'string' ? norm( a ) : JSON.stringify( a ?? null );
		const y = typeof b === 'string' ? norm( b ) : JSON.stringify( b ?? null );
		if ( x !== y ) {
			diffs.push( { i, field, ...( typeof x === 'string' && typeof y === 'string' ? ctx( x, y ) : {} ) } );
		}
	};
	if ( rx.length !== ry.length ) {
		diffs.push( { i: -1, field: 'case count', x: rx.length, y: ry.length } );
	}
	for ( let n = 0; n < Math.min( rx.length, ry.length ); n++ ) {
		const a = rx[ n ];
		const b = ry[ n ];
		const i = a.i;
		for ( const role of ROLES ) {
			for ( const p of PATHS ) {
				const sa = ( a.save[ role ] || {} )[ p ] || {};
				const sb = ( b.save[ role ] || {} )[ p ] || {};
				add( i, `${ role }.${ p }.accepted`, !! sa.accepted, !! sb.accepted );
				add( i, `${ role }.${ p }.exception`, sa.exception ?? null, sb.exception ?? null );
				if ( sa.accepted && sb.accepted ) {
					add( i, `${ role }.${ p }.stored`, bytesOf( sa.bytes ), bytesOf( sb.bytes ) );
				}
			}
		}
		for ( const what of new Set( [ ...Object.keys( a.render ), ...Object.keys( b.render ) ] ) ) {
			for ( const r of RENDERERS ) {
				const ra = ( a.render[ what ] || {} )[ r ] || {};
				const rb = ( b.render[ what ] || {} )[ r ] || {};
				add( i, `render.${ what }.${ r }.html`, ra.html ?? null, rb.html ?? null );
				add( i, `render.${ what }.${ r }.css`, ra.css ?? null, rb.css ?? null );
				add( i, `render.${ what }.${ r }.exception`, ra.exception ?? null, rb.exception ?? null );
			}
		}
	}
	const out = { X, Y, cases: rx.length, diffs, tokensUsed: used, pass: diffs.length === 0 };
	const dir = path.join( OUTROOT, 'compare' );
	fs.mkdirSync( dir, { recursive: true } );
	fs.writeFileSync( path.join( dir, `${ X }__${ Y }.json` ), JSON.stringify( out, null, 1 ) );
	const md = [ `# ${ X } vs ${ Y }: ${ out.pass ? 'PASS' : 'FAIL' }`, '', `Cases: ${ rx.length }. Fields that differ after the named tokens: ${ diffs.length }.`, '', 'Named tokens that were needed: ' + ( Object.entries( used ).map( ( [ k, v ] ) => `${ k } (${ v })` ).join( ', ' ) || 'none' ), '' ];
	for ( const d of diffs.slice( 0, 300 ) ) {
		md.push( `- case ${ d.i } \`${ d.field }\`` + ( d.x !== undefined ? `\n  - X: \`${ JSON.stringify( d.x ) }\`\n  - Y: \`${ JSON.stringify( d.y ) }\`` : '' ) );
	}
	fs.writeFileSync( path.join( dir, `${ X }__${ Y }.md` ), md.join( '\n' ) + '\n' );
	console.log( `${ X } vs ${ Y }: ${ out.pass ? 'PASS' : 'FAIL' } — ${ rx.length } cases, ${ diffs.length } differing fields; tokens: ${ Object.entries( used ).map( ( [ k, v ] ) => `${ k }=${ v }` ).join( ' ' ) || 'none' }` );
	if ( diffs.length ) {
		const by = {};
		for ( const d of diffs ) {
			const f = d.field.replace( /^render\.\d*\|?/, 'render.' );
			by[ f ] = ( by[ f ] || 0 ) + 1;
		}
		console.log( '  differing fields:', JSON.stringify( by ) );
		console.log( '  cases:', [ ...new Set( diffs.map( ( d ) => d.i ) ) ].slice( 0, 20 ).join( ', ' ) );
	}
	return out;
}

// Acceptance per path on the base, and the refused cases per kind.
export function acceptance( label ) {
	const recs = loadRun( label );
	const res = {};
	for ( const p of PATHS ) {
		let yes = 0;
		let all = 0;
		const refused = {};
		for ( const rec of recs ) {
			for ( const role of ROLES ) {
				all++;
				if ( rec.save[ role ] && rec.save[ role ][ p ] && rec.save[ role ][ p ].accepted ) {
					yes++;
				} else {
					refused[ rec.kind ] = ( refused[ rec.kind ] || 0 ) + 1;
				}
			}
		}
		res[ p ] = { ratio: all ? yes / all : 0, accepted: yes, of: all, refused };
	}
	return res;
}

export async function generate( o ) {
	const seed = Number( o.seed || 20261002 );
	const count = Number( o.count || 1000 );
	const chunk = Number( o.chunk || 50 );
	const tries = Number( o.tries || 3 );
	const tokens = String( o.tokens || '' ).split( ',' ).filter( Boolean );
	const prefix = o.prefix || 'gen';
	const baseRef = o.base || latestReleaseTag();
	const baseTree = materialise( baseRef );
	const candTree = o.candidate ? materialise( o.candidate ) : ROOT;
	const runOpts = { port: o.port, wp: o.wp, php: o.php, wb: o.wb };
	console.log( `base: ${ baseRef } (${ baseTree })\ncandidate: ${ o.candidate || 'working tree' } (${ candTree })\nseed ${ seed }, ${ count } cases` );
	const t0 = Date.now();
	const rows = [];

	// Self-check on a base site, before any run.
	let ok = true;
	{
		const port = Number( o.port || 1139 );
		for ( let i = 0; i < 120 && ! ( await portFree( port ) ); i++ ) {
			await sleep( 5000 );
		}
		const site = new Site( { ...runOpts, label: `${ prefix }-selfcheck`, pb: baseTree } );
		try {
			await site.boot();
			const sc = await selfCheck( site, seed, count, chunk );
			rows.push( `${ sc.problems.length ? 'FAIL' : 'PASS' }  self-check: ${ sc.problems.length ? sc.problems.slice( 0, 5 ).join( '; ' ) : `${ count } cases, same twice, every reference string inside the rule` }` );
			ok = ! sc.problems.length;
		} catch ( e ) {
			rows.push( `FAIL  self-check: ${ e.message }` );
			ok = false;
		} finally {
			site.stop();
		}
	}

	const L = { b1: `${ prefix }-base-1`, c1: `${ prefix }-cand-1`, b2: `${ prefix }-base-2`, c2: `${ prefix }-cand-2` };
	const complete = {};
	if ( ok ) {
		for ( const [ label, tree ] of [ [ L.b1, baseTree ], [ L.c1, candTree ], [ L.b2, baseTree ], [ L.c2, candTree ] ] ) {
			complete[ label ] = await runWithTries( label, tree, runOpts, seed, count, chunk, tries );
		}
		if ( ! Object.values( complete ).every( Boolean ) ) {
			ok = false;
			rows.push( `FAIL  incomplete: ${ Object.entries( complete ).filter( ( [ , v ] ) => ! v ).map( ( [ k ] ) => k ).join( ', ' ) }` );
		} else {
			for ( const [ x, y, what ] of [ [ L.b1, L.b2, 'base noise' ], [ L.c1, L.c2, 'candidate noise' ], [ L.b1, L.c1, 'base vs candidate (1)' ], [ L.b2, L.c2, 'base vs candidate (2)' ] ] ) {
				const r = compareGen( x, y, tokens );
				rows.push( `${ r.pass ? 'PASS' : 'FAIL' }  ${ what }: ${ x } vs ${ y } — ${ r.cases } cases, ${ r.diffs.length } differing fields` );
				ok = ok && r.pass;
			}
			const acc = acceptance( L.b1 );
			for ( const p of PATHS ) {
				const a = acc[ p ];
				const pass = a.ratio >= ACCEPT_FLOOR;
				ok = ok && pass;
				rows.push( `${ pass ? 'PASS' : 'FAIL' }  ${ p } saves accepted by the base: ${ a.accepted } of ${ a.of } (${ Math.round( a.ratio * 100 ) }%, floor ${ ACCEPT_FLOOR * 100 }%); refused per kind: ${ JSON.stringify( a.refused ) }` );
			}
		}
	}
	console.log( '\n' + rows.join( '\n' ) + `\n\nVERDICT: ${ ok ? 'PASS' : 'FAIL' }  (${ Math.round( ( Date.now() - t0 ) / 1000 ) }s; reports: ${ path.join( OUTROOT, 'compare' ) })` );
	return ok;
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	generate( args() ).then( ( ok ) => {
		process.exitCode = ok ? 0 : 1;
	}, ( e ) => {
		console.error( e );
		process.exitCode = 1;
	} );
}
