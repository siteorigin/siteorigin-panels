/**
 * Parity runner. Boots one Playground, saves or seeds every case, captures stored data, pages and
 * generated CSS, writes tests/cache/parity/<label>/ and stops the server.
 *
 * Usage: node tests/parity/run.mjs --label=<l> --pb=<tree> [--port=1139] [--zip=<zip>]
 *        [--wb=latest|<version>|<path>|none] [--wp=latest] [--php=8.3] [--theme=<slug>]
 *        [--premium=<path>] [--multisite] [--smoke] [--filter=<regex>]
 */
import fs from 'node:fs';
import path from 'node:path';
import { Site, args, parse, sha, lb, std, outer, SHAPES, styleBlocks } from './lib.mjs';

const o = args();
if ( ! o.label || ( ! o.pb && ! o.zip ) ) {
	console.error( 'usage: run.mjs --label=<l> --pb=<tree> [options]' );
	process.exit( 2 );
}
const filter = o.filter ? new RegExp( o.filter ) : null;
const site = new Site( o );
const HTML = path.join( site.OUT, 'html' );
fs.mkdirSync( HTML, { recursive: true } );
const RENDERERS = [ 'modern', 'legacy' ];

// ---------- Human saves and stored data ----------
// classic: builder field through wp-admin/post.php. block: Layout Block through REST.
// nclassic / nclassicarr / nblock: a Layout Builder widget that holds the layout (inner layout as a JSON
// string or an array). blockraw: a Layout Block with the builder's form shape. s*: direct database writes.
function casesA() {
	const list = [];
	const roles = [ 'admin', 'author' ];
	const add = ( st, who, shape, k ) => list.push( { key: `${ st }.${ who }.${ shape }.${ k }`, st, who, shape, k } );
	for ( const k of [ 'iframe', 'shortcode', 'svg', 'script', 'style' ] ) {
		for ( const st of [ 'classic', 'block', 'nclassic', 'nblock' ] ) {
			for ( const who of roles ) {
				add( st, who, 'std', k );
			}
		}
	}
	for ( const k of [ 'iframe', 'script' ] ) {
		for ( const who of roles ) {
			add( 'blockraw', who, 'std', k );
		}
	}
	for ( const shape of Object.keys( SHAPES ).filter( ( s ) => s !== 'std' ) ) {
		for ( const st of [ 'classic', 'block', 'nclassic', 'nclassicarr', 'nblock' ] ) {
			for ( const who of roles ) {
				add( st, who, shape, 'mix' );
			}
		}
	}
	for ( const shape of Object.keys( SHAPES ) ) {
		for ( const st of [ 'sclassic', 'sblock', 'snested', 'snestedblock' ] ) {
			add( st, 'seed', shape, 'mix' );
		}
	}
	if ( o.smoke ) {
		// Smoke subset: every storage path and role once with an embed, every shape once per storage family.
		// Plus one administrator classic save per other embed, so every coverage count has a case.
		return list.filter( ( c ) => ( c.shape === 'std' && [ 'iframe', 'script' ].includes( c.k ) ) || ( c.shape === 'std' && c.st === 'classic' && c.who === 'admin' ) || ( c.shape !== 'std' && [ 'classic', 'block', 'nclassic', 'sclassic', 'sblock', 'snested' ].includes( c.st ) && c.who !== 'author' ) || ( c.shape === 'std' && c.who === 'seed' ) );
	}
	return list;
}

// ---------- Ability round trip (layout-get, change one widget, layout-update) ----------
function casesAbility() {
	const list = [];
	for ( const who of [ 'author', 'admin' ] ) {
		list.push( { key: `ab.meta.${ who }.legit`, kind: 'abLegit', who, block: false } );
		list.push( { key: `ab.block.${ who }.legit`, kind: 'abLegit', who, block: true } );
		list.push( { key: `ab.meta.${ who }.clear`, kind: 'abClear', who } );
	}
	return list;
}

async function capture( rec, id, withCss = true, dir = HTML ) {
	const d = await site.dump( id );
	rec.stored = d && ! d.missing ? { content: d.row.post_content, status: d.row.post_status, author: d.row.post_author, meta: d.meta, revisions: d.revisions } : { missing: true };
	rec.vis = {};
	rec.css = {};
	for ( const r of RENDERERS ) {
		const v = await site.visitor( id, r );
		fs.writeFileSync( path.join( dir, `${ rec.key }.${ r }.html` ), v.html );
		rec.vis[ r ] = { status: v.status, len: v.html.length, sha: sha( v.html ), styleBlocks: styleBlocks( v.html ) };
		if ( withCss ) {
			rec.css[ r ] = await site.css( id, r );
		}
	}
}

async function runA( c ) {
	const rec = { key: c.key, set: 'A' };
	const raw = [ 'classic', 'nclassic', 'nclassicarr', 'blockraw' ].includes( c.st );
	const pd = SHAPES[ c.shape ]( std( site, c.k, raw ) );
	const title = `Parity ${ c.key }`;
	let s;
	switch ( c.st ) {
		case 'classic':
			s = await site.pbSave( c.who, JSON.stringify( pd ), title );
			break;
		case 'nclassic':
			s = await site.pbSave( c.who, JSON.stringify( outer( site, pd, true, true ) ), title );
			break;
		case 'nclassicarr':
			s = await site.pbSave( c.who, JSON.stringify( outer( site, pd, true, false ) ), title );
			break;
		case 'block':
		case 'blockraw':
			s = await site.createPost( c.who, 'publish', lb( pd ), title );
			break;
		case 'nblock':
			s = await site.createPost( c.who, 'publish', lb( outer( site, pd, false, false ) ), title );
			break;
		case 'sclassic':
			s = await site.seed( { title, panels: pd } );
			break;
		case 'sblock':
			s = await site.seed( { title, content: lb( pd ) } );
			break;
		case 'snested':
			s = await site.seed( { title, panels: outer( site, pd, false, false ) } );
			break;
		case 'snestedblock':
			s = await site.seed( { title, content: lb( outer( site, pd, false, false ) ) } );
			break;
	}
	rec.save = s;
	rec.id = s && s.id;
	if ( rec.id ) {
		await capture( rec, rec.id );
	}
	return rec;
}

const abRes = ( a ) => ( { http: a.http, updated: a.body && a.body.updated, source: a.body && a.body.source, code: a.body && a.body.code, message: a.body && a.body.message, raw: a.body && a.body.updated === undefined ? a.body : undefined } );

async function runAbility( c ) {
	const rec = { key: c.key, set: 'AB', kind: c.kind };
	const title = `Parity ${ c.key }`;
	const UP = 'siteorigin-panels/layout-update';
	if ( c.kind === 'abLegit' ) {
		let id;
		if ( c.block ) {
			id = ( await site.createPost( c.who, 'publish', lb( std( site, 'mix', false ) ), title ) ).id;
		} else {
			id = ( await site.pbSave( c.who, JSON.stringify( std( site, 'mix', true ) ), title ) ).id;
		}
		rec.id = id;
		const g = await site.ability( 'siteorigin-panels/layout-get', c.who, { post_id: id }, true );
		const entry = g.body && Array.isArray( g.body.layouts ) ? g.body.layouts[ 0 ] : null;
		rec.get = { http: g.http, source: g.body && g.body.source, has: !! ( entry && entry.panels_data ) };
		if ( entry && entry.panels_data ) {
			const pd = JSON.parse( JSON.stringify( entry.panels_data ) );
			pd.widgets[ 2 ].text = '<p>Changed by the ability round trip.</p>';
			const input = { post_id: id, panels_data: pd };
			if ( c.block ) {
				input.block_index = 0;
			}
			rec.ai = abRes( await site.ability( UP, c.who, input ) );
		}
	} else if ( c.kind === 'abClear' ) {
		const s = await site.seed( { title, panels: std( site, 'mix', false ), author: c.who } );
		rec.id = s.id;
		rec.ai = abRes( await site.ability( UP, c.who, { post_id: s.id, panels_data: {} } ) );
	}
	if ( rec.id ) {
		await capture( rec, rec.id );
	}
	return rec;
}

const t0 = Date.now();
const recs = [];
try {
	await site.boot();
	if ( site.hasPremium() ) {
		const addons = {};
		for ( const a of String( o.addons || 'plugin/toggle-visibility,plugin/link-overlay,plugin/animations' ).split( ',' ) ) {
			addons[ a ] = true;
		}
		await site.req( 'POST', '/?parity=option', { user: 'admin', json: { name: 'siteorigin_premium_active', value: addons } } );
	}
	const setup = parse( await site.req( 'GET', '/?parity=setup', { user: 'admin' } ) );
	const info = { admin: await site.info( 'admin' ), author: await site.info( 'author' ), options: o, setup };
	fs.writeFileSync( path.join( site.OUT, 'info.json' ), JSON.stringify( info, null, 1 ) );
	console.log( `${ o.label }: wp ${ info.admin.wp } php ${ info.admin.php } panels ${ info.admin.panels_version } wb ${ info.admin.sow_version } premium ${ info.admin.premium_version } multisite ${ info.admin.multisite } abilities ${ info.admin.has_abilities_api }` );
	const todo = casesA().map( ( c ) => [ runA, c ] );
	if ( info.admin.has_abilities_api ) {
		todo.push( ...casesAbility().map( ( c ) => [ runAbility, c ] ) );
	} else {
		console.log( `${ o.label }: ability round-trip cases skipped (no Abilities API on this WordPress)` );
	}
	for ( const [ fn, c ] of todo ) {
		if ( filter && ! filter.test( c.key ) ) {
			continue;
		}
		let rec;
		try {
			rec = await fn( c );
		} catch ( e ) {
			rec = { key: c.key, error: String( ( e && e.stack ) || e ) };
		}
		recs.push( rec );
		if ( recs.length % 20 === 0 ) {
			console.log( `${ o.label }: ${ recs.length } cases, ${ site.reqCount } requests, ${ Math.round( ( Date.now() - t0 ) / 1000 ) }s` );
		}
	}
	fs.writeFileSync( path.join( site.OUT, 'cases.json' ), JSON.stringify( recs, null, 1 ) );
	console.log( `${ o.label } done: ${ recs.length } cases, ${ recs.filter( ( r ) => r.error ).length } harness errors, ${ site.reqCount } requests, ${ Math.round( ( Date.now() - t0 ) / 1000 ) }s` );
} catch ( e ) {
	console.error( 'FAILED', e );
	fs.writeFileSync( path.join( site.OUT, 'cases.json' ), JSON.stringify( recs, null, 1 ) );
	process.exitCode = 1;
} finally {
	site.stop();
}
