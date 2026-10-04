/**
 * Helpers that decide when the Live Editor may replace one edited widget in the preview instead of
 * reloading it (Phase 3 of the Live Editor work).
 *
 * The Live Editor fetches the same preview request twice (the data the preview shows, and the new data),
 * parses both responses inertly with DOMParser, and swaps one widget only when the two documents are the
 * same apart from that widget. These helpers hold the checks; the view runs the flow.
 */

// The positional id of a widget wrapper: panel-{postId}-{row}-{cell}-{widget}.
var TARGET_ID = /^panel-\d+-\d+-\d+-\d+$/;

// Replaces the target in a shell, so two shells compare everything but the target.
var PLACEHOLDER = 'so-live-editor-swap-target';

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
	 * The parsed target element. Null when it is missing, not unique, or is or holds a script: a parsed
	 * script would not run when inserted, but would run on a reload.
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

		var el = found[0];
		if ( el.tagName.toLowerCase() === 'script' || el.querySelector( 'script' ) ) {
			return null;
		}

		return el;
	},
};
