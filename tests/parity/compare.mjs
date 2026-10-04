/**
 * Strict comparison of two parity runs: byte-identical outside the named tokens.
 *
 * Usage: node tests/parity/compare.mjs <labelX> <labelY> [--tokens=port,version] [--only=<regex>]
 * Writes tests/cache/parity/compare/<X>__<Y>.md and .json. Exit code 1 when any field differs or a
 * case is missing.
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { OUTROOT, args } from './lib.mjs';

// The renderers a record was captured on (modern and legacy, or the theme's own with a theme that picks it).
const renderersOf = ( c ) => ( c.vis && Object.keys( c.vis ).length ? Object.keys( c.vis ) : [ 'modern', 'legacy' ] );
const first = ( c ) => ( c.vis ? c.vis[ renderersOf( c )[ 0 ] ] : null );
const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

// Named tokens. Each is a value that changes between two runs of the SAME code.
export function tokenList( extra, versions = [] ) {
	const tokens = [
		// uniqid(): Layout Block with no stored builder_id, printed as gb<post>-<13 hex> (gb-<13 hex> in a stored preview).
		[ 'uniqid:layout-block-render-id', /\bgb(\d*)-[0-9a-f]{13}\b/g, 'gb$1-UNIQID' ],
		// uniqid(): Layout Builder widget builder_id, made again on every widget update(); printed as w<13 hex>.
		[ 'uniqid:layout-builder-widget-id', /\bw[0-9a-f]{13}\b/g, 'wUNIQID' ],
		[ 'uniqid:layout-builder-widget-id', /(builder_id\\?";s:13:\\?")[0-9a-f]{13}/g, '$1UNIQID' ],
		[ 'uniqid:layout-builder-widget-id', /(\\?"builder_id\\?":\\?")[0-9a-f]{13}/g, '$1UNIQID' ],
		// Widgets Bundle form timestamp (13 digits) in stored values.
		[ 'wb:_sow_form_timestamp', /(_sow_form_timestamp\\?";(?:s:13:\\?"|d:))\d{13}/g, '$1TIMESTAMP' ],
		[ 'wb:_sow_form_timestamp', /(\\?"_sow_form_timestamp\\?":\\?"?)\d{13}/g, '$1TIMESTAMP' ],
		// A theme that prints the post's modified time (Vantage): a saved post is modified at the time of the run.
		[ 'wp-core:post-modified-time(theme)', /(<time class="updated" datetime=")[^"]*(">)[^<]*(<\/time>)/g, '$1MODIFIED$2MODIFIED$3' ],
	];
	if ( extra.includes( 'port' ) ) {
		// The two runs used different ports.
		tokens.push( [ 'harness:port', /127\.0\.0\.1(:|%3A)\d{2,5}/g, '127.0.0.1$1PORT' ] );
	}
	if ( extra.includes( 'premium-animations' ) ) {
		// SiteOrigin Premium Animations: a random id per animated element, made on every render.
		tokens.push( [ 'premium:animation-id', /\banimate-[0-9a-f]{22}\b/g, 'animate-ANIMID' ] );
	}
	if ( extra.includes( 'wb-google-map' ) ) {
		// Widgets Bundle Google Maps: a random map id, made on every render.
		tokens.push( [ 'wb:google-map-id', /("id":")[0-9a-f]{6}"/g, '$1MAPID"' ] );
	}
	if ( extra.includes( 'version' ) ) {
		// The plugin version string of each run (from info.json): asset URLs and the generator comment.
		for ( const v of [ ...new Set( versions.filter( Boolean ) ) ] ) {
			if ( /\d/.test( v ) ) {
				tokens.push( [ 'package:version-string', new RegExp( escapeRe( v ), 'g' ), 'PBVERSION' ] );
			} else {
				tokens.push( [ 'package:version-string', new RegExp( '(ver=)' + escapeRe( v ) + '\\b', 'g' ), '$1PBVERSION' ] );
			}
		}
	}
	return tokens;
}

export function normaliser( tokens, used ) {
	return ( s ) => {
		if ( typeof s !== 'string' ) {
			return s;
		}
		for ( const [ name, re, to ] of tokens ) {
			s = s.replace( re, ( ...m ) => {
				used[ name ] = ( used[ name ] || 0 ) + 1;
				return to.replace( /\$(\d)/g, ( _, d ) => m[ Number( d ) ] );
			} );
		}
		return s;
	};
}

export const ctx = ( a, b ) => {
	let i = 0;
	while ( i < a.length && i < b.length && a[ i ] === b[ i ] ) {
		i++;
	}
	return { at: i, x: a.slice( Math.max( 0, i - 80 ), i + 120 ), y: b.slice( Math.max( 0, i - 80 ), i + 120 ), lenX: a.length, lenY: b.length };
};

const readHtml = ( l, key, r ) => {
	const f = path.join( OUTROOT, l, 'html', `${ key }.${ r }.html` );
	return fs.existsSync( f ) ? fs.readFileSync( f, 'utf8' ) : null;
};

// Coverage: is the comparison a real one?
export function coverage( l, recs ) {
	const meta = ( c ) => ( ( c.stored && c.stored.meta ) || [] ).find( ( m ) => m.meta_key === 'panels_data' );
	const h = ( c ) => readHtml( l, c.key, renderersOf( c )[ 0 ] ) || '';
	const admin = recs.filter( ( c ) => ! c.key.includes( '.author.' ) );
	return {
		keys: recs.map( ( c ) => c.key ),
		cases: recs.length,
		harnessErrors: recs.filter( ( c ) => c.error ).length,
		visitor200: recs.filter( ( c ) => c.vis && renderersOf( c ).every( ( r ) => c.vis[ r ] && c.vis[ r ].status === 200 ) ).length,
		storedLayout: recs.filter( ( c ) => meta( c ) || ( c.stored && /wp:siteorigin-panels\/layout-block/.test( c.stored.content ) ) ).length,
		noLayoutStored: recs.filter( ( c ) => c.stored && ! c.stored.option && ! meta( c ) && ! /wp:siteorigin-panels\/layout-block/.test( c.stored.content ) ).map( ( c ) => c.key ),
		pagePrintsPanelsCss: recs.filter( ( c ) => first( c ) && first( c ).styleBlocks.length > 0 ).length,
		pageShowsWidgetText: recs.filter( ( c ) => /PARITY-S-/.test( h( c ) ) ).length,
		liveIframe: admin.filter( ( c ) => /<iframe[^>]*PARITYIFRAME/.test( h( c ) ) ).map( ( c ) => c.key ),
		liveScript: admin.filter( ( c ) => /<script>console\.log\("parity-script"\)<\/script>/.test( h( c ) ) ).map( ( c ) => c.key ),
		liveStyle: admin.filter( ( c ) => /<style>\.parity-style/.test( h( c ) ) ).map( ( c ) => c.key ),
		liveSvg: admin.filter( ( c ) => /<circle cx="25"/.test( h( c ) ) ).map( ( c ) => c.key ),
		liveShortcode: admin.filter( ( c ) => /<form class="parity-form">/.test( h( c ) ) ).map( ( c ) => c.key ),
		nonAsciiRowSelector: recs.filter( ( c ) => first( c ) && first( c ).styleBlocks.join( '' ).includes( '#ряд' ) ).map( ( c ) => c.key ),
		widgetCases: recs.filter( ( c ) => /^s?widget\./.test( c.key ) ).length,
		widgetAreaText: recs.filter( ( c ) => /^s?widget\./.test( c.key ) && /<div id="panels-parity-sidebar">[\s\S]*PARITY-S-/.test( h( c ) ) ).map( ( c ) => c.key ),
		homeCases: recs.filter( ( c ) => /^s?home\./.test( c.key ) ).length,
		homePageText: recs.filter( ( c ) => /^s?home\./.test( c.key ) && /PARITY-S-/.test( h( c ) ) ).map( ( c ) => c.key ),
	};
}

// The coverage floor of the pass rule. Returns the list of missed items (empty = met).
// Each count applies when the run holds a case that should produce it (a --filter can leave them out).
// With requireAll (a run without --filter) every case family must be present as well, so a renamed
// family cannot switch its count off.
const FLOOR = {
	liveIframe: /\.admin\.std\.iframe$/,
	liveScript: /\.admin\.std\.script$/,
	liveStyle: /\.admin\.std\.style$/,
	liveSvg: /\.admin\.std\.svg$/,
	liveShortcode: /\.admin\.std\.shortcode$/,
	nonAsciiRowSelector: /strnonascii/,
};

const FAMILIES = {
	...FLOOR,
	widgetAreaText: /^s?widget\./,
	homePageText: /^s?home\./,
};

export function coverageFloor( cov, { requireAll = false } = {} ) {
	const missed = [];
	if ( requireAll ) {
		for ( const [ k, re ] of Object.entries( FAMILIES ) ) {
			if ( ! cov.keys.some( ( key ) => re.test( key ) ) ) {
				missed.push( `${ k }: no case of this family in the run` );
			}
		}
	}
	if ( cov.cases === 0 ) {
		missed.push( 'no cases' );
	}
	if ( cov.visitor200 !== cov.cases ) {
		missed.push( `visitor200 ${ cov.visitor200 } of ${ cov.cases }` );
	}
	for ( const [ k, re ] of Object.entries( FLOOR ) ) {
		if ( cov.keys.some( ( key ) => re.test( key ) ) && ! cov[ k ].length ) {
			missed.push( `${ k } is 0` );
		}
	}
	// The widget area and home page counts apply when the run holds those cases (a --filter can leave them out).
	if ( cov.widgetCases && ! cov.widgetAreaText.length ) {
		missed.push( 'widgetAreaText is 0' );
	}
	if ( cov.homeCases && ! cov.homePageText.length ) {
		missed.push( 'homePageText is 0' );
	}
	return missed;
}

export function fieldsOf( l, c, used ) {
	const f = {
		save: JSON.stringify( c.save ?? null ),
		ability: JSON.stringify( c.ai ?? null ),
		id: String( c.id ),
		'stored.content': c.stored ? String( c.stored.content ?? '' ) : 'NONE',
		// WordPress core writes `enclosure` post meta from WP-Cron (do_enclose) for a media URL in the
		// content. Whether cron has run yet is timing, so the row is left out (named token).
		'stored.meta': c.stored ? JSON.stringify( ( c.stored.meta || [] ).filter( ( m ) => {
			if ( m.meta_key !== 'enclosure' ) {
				return true;
			}
			used[ 'wp-core:enclosure-meta(cron)' ] = ( used[ 'wp-core:enclosure-meta(cron)' ] || 0 ) + 1;
			return false;
		} ) ) : 'NONE',
		'stored.other': c.stored ? JSON.stringify( [ c.stored.status, c.stored.author, c.stored.revisions, c.stored.option ?? null ] ) : 'NONE',
	};
	for ( const r of renderersOf( c ) ) {
		f[ `html.${ r }` ] = readHtml( l, c.key, r ) ?? 'NONE';
		f[ `http.${ r }` ] = c.vis && c.vis[ r ] ? String( c.vis[ r ].status ) : 'NONE';
		f[ `pagecss.${ r }` ] = c.vis && c.vis[ r ] ? JSON.stringify( c.vis[ r ].styleBlocks ) : 'NONE';
		f[ `gencss.${ r }` ] = c.css && c.css[ r ] ? JSON.stringify( c.css[ r ] ) : 'NONE';
	}
	return f;
}

export function compareRuns( X, Y, { tokens: extra = [], only = '', requireAll = false } = {} ) {
	const load = ( l ) => JSON.parse( fs.readFileSync( path.join( OUTROOT, l, 'cases.json' ), 'utf8' ) );
	const infoOf = ( l ) => {
		try {
			return JSON.parse( fs.readFileSync( path.join( OUTROOT, l, 'info.json' ), 'utf8' ) );
		} catch ( e ) {
			return {};
		}
	};
	const versions = [ X, Y ].map( ( l ) => infoOf( l ).admin && infoOf( l ).admin.panels_version );
	const used = {};
	const norm = normaliser( tokenList( extra, versions ), used );
	const onlyRe = only ? new RegExp( only ) : null;
	const want = ( c ) => ( onlyRe ? onlyRe.test( c.key ) : true );
	const rx = load( X ).filter( want );
	const ry = load( Y ).filter( want );
	const my = new Map( ry.map( ( c ) => [ c.key, c ] ) );
	const kx = new Set( rx.map( ( c ) => c.key ) );
	const tally = {};
	const diffs = [];
	const missing = [];
	let compared = 0;
	for ( const cx of rx ) {
		const cy = my.get( cx.key );
		if ( ! cy ) {
			missing.push( cx.key + ' (only in ' + X + ')' );
			continue;
		}
		if ( cx.error || cy.error ) {
			diffs.push( { key: cx.key, field: 'harness-error', x: cx.error, y: cy.error } );
			continue;
		}
		compared++;
		const fx = fieldsOf( X, cx, used );
		const fy = fieldsOf( Y, cy, used );
		for ( const k of Object.keys( fx ) ) {
			tally[ k ] = tally[ k ] || { raw: 0, norm: 0, diff: 0 };
			if ( fx[ k ] === fy[ k ] ) {
				tally[ k ].raw++;
			} else if ( norm( fx[ k ] ) === norm( fy[ k ] ) ) {
				tally[ k ].norm++;
			} else {
				tally[ k ].diff++;
				diffs.push( { key: cx.key, field: k, ...ctx( norm( fx[ k ] ), norm( fy[ k ] ) ) } );
			}
		}
	}
	for ( const cy of ry ) {
		if ( ! kx.has( cy.key ) ) {
			missing.push( cy.key + ' (only in ' + Y + ')' );
		}
	}
	const cov = { [ X ]: coverage( X, rx ), [ Y ]: coverage( Y, ry ) };
	const out = { X, Y, compared, missing, tally, tokensUsed: used, diffs, coverage: cov, floorMissed: { [ X ]: coverageFloor( cov[ X ], { requireAll } ), [ Y ]: coverageFloor( cov[ Y ], { requireAll } ) } };
	out.pass = diffs.length === 0 && missing.length === 0;

	const dir = path.join( OUTROOT, 'compare' );
	const sfx = only ? '__only' : '';
	fs.mkdirSync( dir, { recursive: true } );
	fs.writeFileSync( path.join( dir, `${ X }__${ Y }${ sfx }.json` ), JSON.stringify( out, null, 1 ) );
	const md = [ `# ${ X } vs ${ Y }: ${ out.pass ? 'PASS' : 'FAIL' }`, '', `Cases compared: ${ compared }. Missing: ${ missing.length }. Fields that differ after the named tokens: ${ diffs.length }.`, '', '| Field | identical bytes | identical after named tokens | DIFFERENT |', '|---|---|---|---|' ];
	for ( const [ k, t ] of Object.entries( tally ) ) {
		md.push( `| ${ k } | ${ t.raw } | ${ t.norm } | ${ t.diff } |` );
	}
	md.push( '', 'Named tokens that were needed: ' + ( Object.entries( used ).map( ( [ k, n ] ) => `${ k } (${ n })` ).join( ', ' ) || 'none' ), '' );
	if ( missing.length ) {
		md.push( '## Missing cases', '', ...missing.map( ( m ) => `- ${ m }` ), '' );
	}
	if ( diffs.length ) {
		md.push( '## Differences', '' );
		for ( const d of diffs.slice( 0, 300 ) ) {
			md.push( `- **${ d.key }** \`${ d.field }\` at ${ d.at } (len ${ d.lenX } vs ${ d.lenY })`, '  - X: `' + JSON.stringify( d.x ) + '`', '  - Y: `' + JSON.stringify( d.y ) + '`' );
		}
	}
	const c = cov[ X ];
	md.push( '', `## Coverage (${ X })`, '', ...Object.entries( c ).filter( ( [ k ] ) => k !== 'keys' ).map( ( [ k, v ] ) => `- ${ k }: ${ Array.isArray( v ) ? v.length + ( k === 'noLayoutStored' ? ' (' + v.join( ', ' ) + ')' : '' ) : v }` ) );
	md.push( '', `Coverage floor: ${ out.floorMissed[ X ].length ? 'MISSED: ' + out.floorMissed[ X ].join( '; ' ) : 'met' }` );
	fs.writeFileSync( path.join( dir, `${ X }__${ Y }${ sfx }.md` ), md.join( '\n' ) + '\n' );

	console.log( `${ X } vs ${ Y }: ${ out.pass ? 'PASS' : 'FAIL' } — ${ compared } cases, ${ diffs.length } differing fields, ${ missing.length } missing; tokens: ${ Object.entries( used ).map( ( [ k, n ] ) => `${ k }=${ n }` ).join( ' ' ) || 'none' }` );
	if ( diffs.length ) {
		const by = {};
		for ( const d of diffs ) {
			by[ d.field ] = ( by[ d.field ] || 0 ) + 1;
		}
		console.log( '  differing fields:', JSON.stringify( by ) );
		const keys = [ ...new Set( diffs.map( ( d ) => d.key ) ) ];
		console.log( '  cases:', keys.length, keys.slice( 0, 12 ).join( ', ' ) );
	}
	return out;
}

if ( import.meta.url === pathToFileURL( process.argv[ 1 ] ).href ) {
	const [ X, Y ] = process.argv.slice( 2 ).filter( ( a ) => ! a.startsWith( '--' ) );
	const o = args();
	if ( ! X || ! Y ) {
		console.error( 'usage: compare.mjs <labelX> <labelY> [--tokens=port,version] [--only=<regex>]' );
		process.exit( 2 );
	}
	const out = compareRuns( X, Y, { tokens: String( o.tokens || '' ).split( ',' ).filter( Boolean ), only: o.only || '', requireAll: ! o.only } );
	process.exitCode = out.pass ? 0 : 1;
}
