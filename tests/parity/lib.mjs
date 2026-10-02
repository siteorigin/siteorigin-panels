/**
 * Parity harness shared library: Playground boot, HTTP helpers, layout builders and save paths.
 *
 * Every run boots one WordPress Playground with one Page Builder tree, the parity mu-plugin and the
 * Widgets Bundle, runs a case list and stops. Two runs of two trees on the same port share every URL
 * and asset string, so only plugin behaviour can differ between them.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

export const HERE = path.dirname( fileURLToPath( import.meta.url ) );
export const ROOT = path.resolve( HERE, '..', '..' );
export const CLI = path.join( ROOT, 'node_modules', '.bin', 'wp-playground-cli' );
export const OUTROOT = process.env.PARITY_OUT ? path.resolve( process.env.PARITY_OUT ) : path.join( ROOT, 'tests', 'cache', 'parity' );
export const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
export const sha = ( s ) => crypto.createHash( 'sha256' ).update( s ).digest( 'hex' );

export function args( argv = process.argv.slice( 2 ) ) {
	const o = {};
	for ( const a of argv ) {
		const m = /^--([^=]+)(?:=(.*))?$/.exec( a );
		if ( m ) {
			o[ m[ 1 ] ] = m[ 2 ] === undefined ? true : m[ 2 ];
		}
	}
	return o;
}

// Widgets Bundle source for the blueprint: latest from wordpress.org, a pinned version, a local path, or none.
export function wbSource( wb ) {
	if ( ! wb || wb === 'none' ) {
		return null;
	}
	if ( wb === 'latest' ) {
		return { install: { resource: 'wordpress.org/plugins', slug: 'so-widgets-bundle' } };
	}
	if ( /^\d+(\.\d+)+$/.test( wb ) ) {
		return { install: { resource: 'url', url: `https://downloads.wordpress.org/plugin/so-widgets-bundle.${ wb }.zip` } };
	}
	return { mount: path.resolve( wb ) };
}

export class Site {
	constructor( o ) {
		this.o = o;
		this.o.wb = this.o.wb || 'latest';
		this.port = Number( o.port || 1139 );
		this.BASE = `http://127.0.0.1:${ this.port }`;
		this.OUT = path.join( OUTROOT, o.label );
		fs.mkdirSync( this.OUT, { recursive: true } );
		this.reqCount = 0;
		this.cookies = {};
		this.uuidN = 0;
	}

	abs( p ) {
		if ( ! p ) {
			return '';
		}
		return path.isAbsolute( p ) ? p : path.resolve( p );
	}

	hasWb() {
		return !! this.o.wb && this.o.wb !== 'none';
	}

	hasPremium() {
		return !! this.o.premium && this.o.premium !== 'none';
	}

	blueprint( mounts ) {
		const o = this.o;
		const wp = String( o.wp || 'latest' );
		const php = String( o.php || '8.3' );
		const steps = [ { step: 'defineWpConfigConsts', consts: { PANELS_PARITY_HARNESS: true } } ];
		const wb = wbSource( o.wb );
		if ( wb && wb.mount ) {
			mounts.push( `--mount=${ wb.mount }:/wordpress/wp-content/plugins/so-widgets-bundle` );
			steps.push( { step: 'activatePlugin', pluginPath: '/wordpress/wp-content/plugins/so-widgets-bundle/so-widgets-bundle.php' } );
		} else if ( wb ) {
			steps.push( { step: 'installPlugin', pluginData: wb.install, options: { activate: true } } );
		}
		if ( o.zip ) {
			// Install a release zip through WordPress, as a site owner does.
			mounts.push( `--mount=${ path.dirname( this.abs( o.zip ) ) }:/tmp/parity-zips` );
			steps.push( { step: 'installPlugin', pluginData: { resource: 'vfs', path: '/tmp/parity-zips/' + path.basename( o.zip ) }, options: { activate: true } } );
		} else {
			mounts.push( `--mount=${ this.abs( o.pb ) }:/wordpress/wp-content/plugins/siteorigin-panels` );
			steps.push( { step: 'activatePlugin', pluginPath: '/wordpress/wp-content/plugins/siteorigin-panels/siteorigin-panels.php' } );
		}
		if ( this.hasPremium() ) {
			mounts.push( `--mount=${ this.abs( o.premium ) }:/wordpress/wp-content/plugins/siteorigin-premium` );
			steps.push( { step: 'activatePlugin', pluginPath: '/wordpress/wp-content/plugins/siteorigin-premium/siteorigin-premium.php' } );
		}
		if ( o.theme ) {
			steps.push( { step: 'installTheme', themeData: { resource: 'wordpress.org/themes', slug: o.theme }, options: { activate: true } } );
		}
		// Playground's enableMultisite step refuses a custom port, so convert with WP-CLI, after activation.
		if ( o.multisite ) {
			steps.push( { step: 'wp-cli', command: 'wp core multisite-convert' } );
			steps.push( { step: 'defineWpConfigConsts', consts: { MULTISITE: true, SUBDOMAIN_INSTALL: false, DOMAIN_CURRENT_SITE: `127.0.0.1:${ this.port }`, PATH_CURRENT_SITE: '/', SITE_ID_CURRENT_SITE: 1, BLOG_ID_CURRENT_SITE: 1 } } );
			if ( ! o.siteurl ) {
				o.siteurl = `http://127.0.0.1:${ this.port }`;
			}
		}
		mounts.push( `--mount=${ path.join( HERE, 'mu-plugins' ) }:/wordpress/wp-content/mu-plugins` );
		return {
			$schema: 'https://playground.wordpress.net/blueprint-schema.json',
			...( o.multisite ? { extraLibraries: [ 'wp-cli' ] } : {} ),
			preferredVersions: { php, wp },
			features: { networking: !! o.theme },
			steps,
		};
	}

	async boot() {
		const o = this.o;
		const mounts = [];
		const bp = this.blueprint( mounts );
		const bpFile = path.join( this.OUT, 'blueprint.json' );
		fs.writeFileSync( bpFile, JSON.stringify( bp, null, 1 ) );
		const cli = [ 'server', `--wp=${ bp.preferredVersions.wp }`, `--php=${ bp.preferredVersions.php }`, `--port=${ this.port }`, `--blueprint=${ bpFile }`, ...mounts ];
		if ( o.siteurl ) {
			cli.push( `--site-url=${ o.siteurl }` );
		}
		fs.writeFileSync( path.join( this.OUT, 'command.txt' ), CLI + ' ' + cli.join( ' ' ) + '\n' );
		this.child = spawn( CLI, cli, { stdio: [ 'ignore', 'pipe', 'pipe' ], detached: true } );
		const log = fs.createWriteStream( path.join( this.OUT, 'server.log' ) );
		this.child.stdout.pipe( log );
		this.child.stderr.pipe( log );
		for ( let i = 0; i < 600; i++ ) {
			await sleep( 1000 );
			const r = await this.req( 'GET', '/?parity=info', { user: 'admin' } );
			const j = r.status === 200 ? parse( r ) : null;
			// The server answers before the blueprint has finished. Wait until every plugin of this run is active.
			if ( j && j.panels_version && ( ! this.hasWb() || j.sow_version ) && ( ! this.hasPremium() || j.premium_version ) && ( ! o.multisite || j.multisite ) ) {
				// And until the blueprint's last step is done.
				await sleep( o.multisite ? 4000 : 1500 );
				return;
			}
			if ( this.child.exitCode !== null ) {
				throw new Error( 'server exited ' + this.child.exitCode );
			}
		}
		throw new Error( 'server did not start' );
	}

	stop() {
		try {
			process.kill( -this.child.pid, 'SIGTERM' );
		} catch ( e ) {}
	}

	async req( method, url, { user, json, form, cookie, follow, renderer, headers } = {} ) {
		const h = {};
		if ( user ) {
			h[ 'X-Parity-User' ] = user;
		}
		if ( renderer ) {
			h[ 'X-Parity-Renderer' ] = renderer;
		}
		if ( cookie ) {
			h.Cookie = cookie;
		}
		if ( headers ) {
			Object.assign( h, headers );
		}
		let body;
		if ( json !== undefined ) {
			h[ 'Content-Type' ] = 'application/json';
			body = JSON.stringify( json );
		}
		if ( form ) {
			h[ 'Content-Type' ] = 'application/x-www-form-urlencoded';
			body = new URLSearchParams( form ).toString();
		}
		this.reqCount++;
		try {
			const res = await fetch( this.BASE + url, { method, headers: h, body, redirect: follow ? 'follow' : 'manual', signal: AbortSignal.timeout( 180000 ) } );
			const text = await res.text();
			return { status: res.status, text, location: res.headers.get( 'location' ) || undefined, setCookie: res.headers.getSetCookie ? res.headers.getSetCookie() : [] };
		} catch ( e ) {
			return { status: 0, text: 'FETCH ERROR ' + e.name + ' ' + e.message, setCookie: [] };
		}
	}

	rest( method, route, user, json ) {
		return this.req( method, '/?rest_route=' + route, { user, json } );
	}

	async info( user = 'admin' ) {
		return parse( await this.req( 'GET', '/?parity=info', { user } ) );
	}

	async createPost( user, status, content, title ) {
		const r = await this.rest( 'POST', '/wp/v2/posts', user, { title, content, status, date: '2026-01-15T10:00:00' } );
		const j = parse( r );
		return { status: r.status, id: j && j.id, code: j && j.code, message: j && j.message };
	}

	async seed( body ) {
		return parse( await this.req( 'POST', '/?parity=seed', { user: 'admin', json: body } ) );
	}

	async dump( id ) {
		return parse( await this.req( 'GET', `/?parity=dump&id=${ id }`, { user: 'admin' } ) );
	}

	async visitor( id, renderer ) {
		const r = await this.req( 'GET', `/?p=${ id }`, { follow: true, renderer } );
		return { status: r.status, html: r.text };
	}

	async css( id, renderer ) {
		return parse( await this.req( 'GET', `/?parity=css&id=${ id }`, { user: 'admin', renderer } ) );
	}

	async ability( name, user, input, readonly ) {
		let r;
		if ( readonly ) {
			const q = Object.entries( input ).map( ( [ k, v ] ) => `&input[${ k }]=${ encodeURIComponent( v ) }` ).join( '' );
			r = await this.req( 'GET', `/?rest_route=/wp-abilities/v1/abilities/${ name }/run${ q }`, { user } );
			if ( r.status === 405 ) {
				r = await this.rest( 'POST', `/wp-abilities/v1/abilities/${ name }/run`, user, { input } );
			}
		} else {
			r = await this.rest( 'POST', `/wp-abilities/v1/abilities/${ name }/run`, user, { input } );
		}
		return { http: r.status, body: parse( r ) ?? r.text.slice( 0, 600 ) };
	}

	async login( who ) {
		if ( this.cookies[ who ] ) {
			return this.cookies[ who ];
		}
		const map = { admin: [ 'admin', 'password' ], author: [ 'pauthor', 'parity-author-pass' ] };
		const creds = map[ who ];
		if ( ! creds ) {
			throw new Error( `unknown user ${ who }` );
		}
		const r = await this.req( 'POST', '/wp-login.php', { cookie: 'wordpress_test_cookie=WP%20Cookie%20check', form: { log: creds[ 0 ], pwd: creds[ 1 ], 'wp-submit': 'Log In', redirect_to: this.BASE + '/wp-admin/', testcookie: '1' } } );
		const jar = r.setCookie.map( ( c ) => c.split( ';' )[ 0 ] ).filter( ( c ) => ! /=\s*$/.test( c ) );
		if ( ! jar.some( ( c ) => c.startsWith( 'wordpress_logged_in' ) ) ) {
			throw new Error( `login failed for ${ who }: ${ r.status } ${ r.text.slice( 0, 300 ) }` );
		}
		this.cookies[ who ] = jar.join( '; ' );
		// Warm-up: the first wp-admin request after activation is redirected to the Page Builder welcome page.
		for ( let i = 0; i < 3; i++ ) {
			const w = await this.req( 'GET', '/wp-admin/index.php', { cookie: this.cookies[ who ] } );
			if ( w.status === 200 ) {
				break;
			}
		}
		return this.cookies[ who ];
	}

	// Classic Page Builder save: wp-admin/post.php editpost with panels_data and _sopanels_nonce, cookie
	// session. pdJson is the exact string the builder field would carry.
	async pbSave( who, pdJson, title, existingId, content = '' ) {
		let id = existingId;
		if ( ! id ) {
			const c = await this.createPost( who, 'publish', '', title );
			if ( ! c.id ) {
				return { error: 'create failed', create: c };
			}
			id = c.id;
		}
		const cookie = await this.login( who );
		const n = parse( await this.req( 'GET', `/?parity=nonces&id=${ id }`, { cookie } ) );
		const r = await this.req( 'POST', '/wp-admin/post.php', { cookie, form: {
			action: 'editpost', post_ID: String( id ), _wpnonce: n.update, post_type: 'post', originalaction: 'editpost',
			original_post_status: 'publish', post_status: 'publish', post_title: title, content,
			panels_data: pdJson, _sopanels_nonce: n.panels,
		} } );
		return { id, nonceUser: n.user, saveStatus: r.status, saveLocation: r.location ? r.location.replace( this.BASE, '' ) : r.location, saveOk: r.status === 302 && String( r.location ).includes( `post.php?post=${ id }` ) };
	}

	uuid() {
		return `c0ffee00-0000-4000-8000-${ String( ++this.uuidN ).padStart( 12, '0' ) }`;
	}
}

export const parse = ( r ) => {
	try {
		return JSON.parse( r.text );
	} catch ( e ) {
		return null;
	}
};

// ---- Block serialisation (the escaping the block editor applies to comment attributes) ----
export const ser = ( attrs ) => JSON.stringify( attrs ).replace( /--/g, '\\u002d\\u002d' ).replace( /</g, '\\u003c' ).replace( />/g, '\\u003e' ).replace( /&/g, '\\u0026' ).replace( /\\"/g, '\\u0022' );
export const blk = ( name, attrs ) => `<!-- wp:${ name } ${ ser( attrs ) } /-->`;
export const lb = ( pd ) => blk( 'siteorigin-panels/layout-block', { panelsData: pd } );

// ---- Embed payloads ----
export const EMB = {
	iframe: '<iframe width="560" height="315" src="https://www.youtube.com/embed/PARITYIFRAME" title="YouTube video player" frameborder="0" allowfullscreen></iframe>',
	shortcode: '[parity_form id="1"]\n\n[audio src="https://example.com/parity-a.mp3"]',
	svg: '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><circle cx="25" cy="25" r="21" fill="red"/></svg>',
	script: '<script>console.log("parity-script")</script>',
	style: '<style>.parity-style{color:red}</style>',
	mix: '<strong>Bold</strong> and <a href="https://example.com/?a=1&amp;b=2">a link</a> &amp; café 日本語',
};
export const body = ( k, tag ) => `<p>Before PARITY-S-${ k }-${ tag }.</p>\n\n${ EMB[ k ] }\n\n<p>After PARITY-E-${ k }-${ tag }.</p>`;

const EDC = 'SiteOrigin_Widget_Editor_Widget';
const ROW_STYLE = { id: 'parity-row-one', class: 'parity-row-class', row_css: 'outline: 1px solid red;', mobile_css: 'outline: 1px solid blue;', padding: '10px 20px 10px 20px', mobile_padding: '5px 5px 5px 5px', background: '#f0f0f0', background_display: 'tile', bottom_margin: '40px', gutter: '20px', row_stretch: 'full', collapse_order: 'right-top', cell_alignment: 'center' };
const CELL_STYLE = { class: 'parity-cell-class', padding: '4px 4px 4px 4px', mobile_padding: '2px 2px 2px 2px', vertical_alignment: 'center', background: '#e0e0ff', background_display: 'tile', font_color: '#111111' };
const WIDGET_STYLE = { id: 'parity-widget-one', class: 'parity-widget-class', widget_css: 'outline: 1px dotted green;', mobile_css: 'outline: 1px dotted orange;', padding: '6px 6px 6px 6px', mobile_padding: '3px 3px 3px 3px', margin: '0px 0px 12px 0px', background: '#ffffee', background_display: 'tile', font_color: '#222222', link_color: '#0000aa' };

// Standard layout: 2 rows, 3 cells, 3 widgets (SiteOrigin Editor, Custom HTML, core Text).
// raw=true is the builder's form shape (classic save). raw=false is the stored shape.
export function std( site, k, raw ) {
	const info = ( cls, grid, cell, id, style ) => ( { class: cls, ...( raw ? { raw: true } : {} ), grid, cell, id, widget_id: site.uuid(), style } );
	// Premium cells only: style fields of the two addons that call the CSS builder (Toggle Visibility, Link Overlay).
	const prem = site.hasPremium();
	const pRow0 = prem ? { link_overlay_url: 'https://example.com/parity-row', disable_tablet: true } : {};
	const pRow1 = prem ? { disable_mobile: true } : {};
	const pW1 = prem ? { disable_tablet: true, disable_desktop: true } : {};
	const pW2 = prem ? { link_overlay_url: 'https://example.com/parity-widget', disable_mobile: true } : {};
	return {
		widgets: [
			{ title: '', text: body( k, 'E' ), text_selected_editor: 'tinymce', autop: true, panels_info: info( EDC, 0, 0, 0, { ...WIDGET_STYLE } ) },
			{ title: '', content: body( k, 'H' ), panels_info: info( 'WP_Widget_Custom_HTML', 0, 1, 1, { background_display: 'tile', ...pW1 } ) },
			{ title: 'Text title', text: body( k, 'T' ), filter: true, visual: true, panels_info: info( 'WP_Widget_Text', 1, 0, 2, { background_display: 'tile', ...pW2 } ) },
		],
		grids: [ { cells: 2, style: { ...ROW_STYLE, ...pRow0 }, ratio: 1, ratio_direction: 'right' }, { cells: 1, style: { background_display: 'tile', ...pRow1 } } ],
		grid_cells: [ { grid: 0, index: 0, weight: 0.5, style: { ...CELL_STYLE } }, { grid: 0, index: 1, weight: 0.5, style: {} }, { grid: 1, index: 0, weight: 1, style: {} } ],
	};
}

const obj = ( arr, start = 0 ) => Object.fromEntries( arr.map( ( v, i ) => [ String( i + start ), v ] ) );
// Older and unusual layout shapes. Each takes a std() layout and returns the changed layout.
export const SHAPES = {
	std: ( pd ) => pd,
	// Page Builder 1.x: `info` in place of `panels_info`.
	info: ( pd ) => {
		pd.widgets = pd.widgets.map( ( w ) => {
			const { panels_info: panelsInfo, ...rest } = w;
			return { ...rest, info: panelsInfo };
		} );
		return pd;
	},
	// Lists stored as numeric-keyed objects, keys 0..n-1.
	numkey: ( pd ) => ( { widgets: obj( pd.widgets ), grids: obj( pd.grids ), grid_cells: obj( pd.grid_cells ) } ),
	// Numeric-keyed objects with keys 1..n.
	numkeygap: ( pd ) => ( { widgets: obj( pd.widgets, 1 ), grids: obj( pd.grids, 1 ), grid_cells: obj( pd.grid_cells, 1 ) } ),
	// References and numbers as numeric strings.
	numstr: ( pd ) => {
		pd.widgets.forEach( ( w ) => {
			w.panels_info.grid = String( w.panels_info.grid );
			w.panels_info.cell = String( w.panels_info.cell );
			w.panels_info.id = String( w.panels_info.id );
		} );
		pd.grids.forEach( ( g ) => {
			g.cells = String( g.cells );
		} );
		pd.grid_cells.forEach( ( c ) => {
			c.grid = String( c.grid );
			c.index = String( c.index );
			c.weight = String( c.weight );
		} );
		return pd;
	},
	// A string row ID, ASCII.
	strascii: ( pd ) => {
		pd.grid_cells.push( { grid: 'parity_my-row', index: 0, weight: 1, style: {} } );
		return pd;
	},
	// A string row ID with non-ASCII characters.
	strnonascii: ( pd ) => {
		pd.grid_cells.push( { grid: 'ряд-é-日本', index: 0, weight: 1, style: {} } );
		return pd;
	},
	// Every row reference is a non-ASCII string row ID.
	strnonasciiall: ( pd ) => {
		pd.grid_cells.forEach( ( c ) => {
			c.grid = [ 'рядодин', '行二' ][ c.grid ];
		} );
		return pd;
	},
};

// A layout that holds one Layout Builder widget (inner layout) and one Editor widget.
export function outer( site, inner, raw, innerAsString ) {
	const info = ( cls, id ) => ( { class: cls, ...( raw ? { raw: true } : {} ), grid: 0, cell: 0, id, widget_id: site.uuid(), style: { background_display: 'tile' } } );
	return {
		widgets: [
			{ panels_data: innerAsString ? JSON.stringify( inner ) : inner, builder_id: 'a1b2c3d4e5f60', panels_info: info( 'SiteOrigin_Panels_Widgets_Layout', 0 ) },
			{ title: '', text: '<p>Outer editor widget.</p>', text_selected_editor: 'tinymce', autop: true, panels_info: info( EDC, 1 ) },
		],
		grids: [ { cells: 1, style: { bottom_margin: '25px' } } ],
		grid_cells: [ { grid: 0, index: 0, weight: 1, style: {} } ],
	};
}

// The <style> blocks Page Builder prints, in page order.
export function styleBlocks( html ) {
	const out = [];
	const re = /<style[^>]*id=["']siteorigin-panels-layouts-[^"']*["'][^>]*>([\s\S]*?)<\/style>/g;
	let m;
	while ( ( m = re.exec( html ) ) ) {
		out.push( m[ 1 ] );
	}
	return out;
}
