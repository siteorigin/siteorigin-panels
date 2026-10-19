/**
 * Helpers that decide when the Live Editor may replace one edited widget in the preview instead of
 * reloading it.
 *
 * The Live Editor fetches the same preview request twice (the data the preview shows, and the new data),
 * parses both responses inertly with DOMParser, and swaps one widget only when the two documents are the
 * same apart from that widget. These helpers hold the checks; the view runs the flow.
 */

// The positional id of a widget wrapper: panel-{postId}-{row}-{cell}-{widget}.
var TARGET_ID = /^panel-\d+-\d+-\d+-\d+$/;

// Replaces the target in a shell, so two shells compare everything but the target.
var PLACEHOLDER = 'so-live-editor-swap-target';

/*
 * Markup a swap cannot reproduce, so the preview reloads instead:
 * - scripts: a parsed script does not run when inserted, but runs on a reload;
 * - media and embeds (iframe, video, audio, object, embed, and wp-embed / wp-block-embed classes): their
 *   players and sizing are set up by scripts on document load (MediaElement, wp-embed.js);
 * - noscript and template: DOMParser parses with scripting off, so noscript content becomes live markup and
 *   a declarative shadow root is not attached.
 * A later opt-in (for example a Widgets Bundle widget with its own setup) can extend these lists.
 */
var documentSetupMarkup = {
	tags: [ 'script', 'iframe', 'video', 'audio', 'object', 'embed', 'noscript', 'template' ],
	classPrefixes: [ 'wp-embed', 'wp-block-embed' ],
};

// A line, or a paragraph, that holds only a URL: WordPress replaces it with an embed (WP_Embed::autoembed()).
var BARE_URL_LINE = /^\s*https?:\/\/[^\s<>"]+\s*$/im;
var BARE_URL_PARAGRAPH = /<p(?:\s[^>]*)?>\s*https?:\/\/[^\s<>"]+\s*<\/p>/i;

/**
 * Every string in a value, at any depth.
 */
var strings = function ( value, out ) {
	out = out || [];
	if ( typeof value === 'string' ) {
		out.push( value );
	} else if ( value && typeof value === 'object' ) {
		Object.keys( value ).forEach( function ( key ) {
			strings( value[ key ], out );
		} );
	}

	return out;
};

/**
 * The elements with an id, found by attribute so a duplicate id is seen. Empty for an id that is not a
 * widget wrapper id.
 */
var byId = function ( root, targetId ) {
	if ( ! TARGET_ID.test( String( targetId ) ) ) {
		return [];
	}

	return Array.prototype.slice.call( root.querySelectorAll( '[id="' + targetId + '"]' ) );
};

module.exports = {

	documentSetupMarkup: documentSetupMarkup,

	/**
	 * True when a widget's values may render as something that needs document-load setup: a shortcode
	 * (any "["; [video], [audio] and plugin shortcodes set themselves up on document ready) or a URL alone
	 * on a line or in a paragraph (an auto-embed). Checked before any request.
	 *
	 * @param {Object} widgetData One entry of panels_data.widgets.
	 * @return {boolean}
	 */
	hasDeferredContent: function ( widgetData ) {
		if ( ! widgetData || typeof widgetData !== 'object' ) {
			return true;
		}

		var values = {};
		Object.keys( widgetData ).forEach( function ( key ) {
			if ( key !== 'panels_info' ) {
				values[ key ] = widgetData[ key ];
			}
		} );

		return strings( values ).some( function ( text ) {
			return text.indexOf( '[' ) !== -1 || BARE_URL_LINE.test( text ) || BARE_URL_PARAGRAPH.test( text );
		} );
	},

	/**
	 * True when an element, or anything in it, is markup a swap cannot reproduce (documentSetupMarkup).
	 *
	 * @param {Element} el
	 * @return {boolean}
	 */
	needsDocumentSetup: function ( el ) {
		var nodes = [ el ].concat( Array.prototype.slice.call( el.querySelectorAll( '*' ) ) );

		return nodes.some( function ( node ) {
			var tag = String( node.tagName || '' ).toLowerCase();
			if ( documentSetupMarkup.tags.indexOf( tag ) !== -1 ) {
				return true;
			}

			return Array.prototype.some.call( node.classList || [], function ( className ) {
				return documentSetupMarkup.classPrefixes.some( function ( prefix ) {
					return className.indexOf( prefix ) === 0;
				} );
			} );
		} );
	},

	/**
	 * True only for the front-end permalink preview: an absolute http(s) URL with the same origin as the
	 * preview document, whose path is not admin-ajax.php. The admin-ajax preview renders without the
	 * edit_post check, so it is never swapped.
	 *
	 * @param {string} previewUrl The URL the preview was posted to.
	 * @param {string} previewOrigin The origin of the preview document.
	 * @return {boolean}
	 */
	isSwapRoute: function ( previewUrl, previewOrigin ) {
		if ( typeof previewUrl !== 'string' || ! /^https?:\/\//i.test( previewUrl ) || typeof previewOrigin !== 'string' ) {
			return false;
		}

		var url;
		try {
			url = new URL( previewUrl );
		} catch ( e ) {
			return false;
		}

		return url.origin === previewOrigin &&
			! /\/admin-ajax\.php$/i.test( url.pathname ) &&
			url.pathname.indexOf( '/wp-admin/' ) === -1;
	},

	/**
	 * True when the widget's class is in the allow list (panelsOptions.live_editor_swap_widgets).
	 *
	 * @param {Object} widgetData One entry of panels_data.widgets.
	 * @param {string[]} allowList
	 * @return {boolean}
	 */
	isSwapWidget: function ( widgetData, allowList ) {
		var widgetClass = widgetData && widgetData.panels_info && widgetData.panels_info.class;

		return typeof widgetClass === 'string' &&
			Array.isArray( allowList ) &&
			allowList.indexOf( widgetClass ) !== -1;
	},

	/**
	 * True when a response has a document policy that a swap cannot carry into the preview: a
	 * Content-Security-Policy or Content-Security-Policy-Report-Only header, or a CSP meta tag.
	 *
	 * @param {Headers} headers The response headers.
	 * @param {Document|null} doc The parsed response, when there is one.
	 * @return {boolean}
	 */
	hasDocumentPolicy: function ( headers, doc ) {
		if (
			! headers ||
			typeof headers.has !== 'function' ||
			headers.has( 'content-security-policy' ) ||
			headers.has( 'content-security-policy-report-only' )
		) {
			return true;
		}

		if ( ! doc ) {
			return false;
		}

		return Array.prototype.some.call( doc.querySelectorAll( 'meta[http-equiv]' ), function ( meta ) {
			return String( meta.getAttribute( 'http-equiv' ) ).trim().toLowerCase().indexOf( 'content-security-policy' ) === 0;
		} );
	},

	/**
	 * The whole document serialised, with the target element replaced by a fixed placeholder comment.
	 * Null when the target is missing or not unique.
	 *
	 * @param {Document} doc A parsed response.
	 * @param {string} targetId
	 * @return {string|null}
	 */
	shell: function ( doc, targetId ) {
		if ( ! doc || ! doc.documentElement || byId( doc, targetId ).length !== 1 ) {
			return null;
		}

		var copy = doc.documentElement.cloneNode( true );
		var target = byId( copy, targetId );
		if ( target.length !== 1 ) {
			return null;
		}
		target[0].parentNode.replaceChild( doc.createComment( PLACEHOLDER ), target[0] );

		var doctype = doc.doctype ? '<!DOCTYPE ' + doc.doctype.name + '>' : '';

		return doctype + copy.outerHTML;
	},

	/**
	 * The parsed target element. Null when it is missing, not unique, or is or holds markup a swap cannot
	 * reproduce (needsDocumentSetup(): scripts, media, embeds, noscript, template).
	 *
	 * @param {Document} doc A parsed response.
	 * @param {string} targetId
	 * @return {Element|null}
	 */
	target: function ( doc, targetId ) {
		var found = doc ? byId( doc, targetId ) : [];
		if ( found.length !== 1 ) {
			return null;
		}

		return this.needsDocumentSetup( found[0] ) ? null : found[0];
	},
};
