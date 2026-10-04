var panels = window.panels, $ = jQuery;

/**
 * Whether two lists hold the same elements in the same order. Identity, not _.isEqual(), which compares
 * DOM elements by their own properties.
 */
var sameElements = function ( a, b ) {
	if ( a.length !== b.length ) {
		return false;
	}

	for ( var i = 0; i < a.length; i++ ) {
		if ( a[ i ] !== b[ i ] ) {
			return false;
		}
	}

	return true;
};

module.exports = Backbone.View.extend( {
	template: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-live-editor' ).html() ) ),

	previewScrollTop: 0,
	loadTimes: [],
	previewFrameId: 1,

	previewUrl: null,
	previewIframe: null,

	// How long a preview may take to load before the error panel shows (ms). panelsOptions.live_editor_preview_timeout overrides it.
	PREVIEW_TIMEOUT: 30000,

	// The URL and fields of the current preview POST, the load timer, and the AbortController of the diagnostic request.
	previewRequest: null,
	previewTimer: null,
	previewProbe: null,

	// Increased on every preview load. A ready or load event from an older preview is ignored.
	previewGeneration: 0,

	// The builder as the preview shows it (panels.helpers.liveEditorPatch.snapshot()), the map from model cid to
	// preview element (null = every change reloads), and the patched cell widths by cell cid.
	previewSnapshot: null,
	previewMap: null,
	previewWidths: null,

	events: {
		'click .live-editor-close': 'close',
		'click .live-editor-save': 'closeAndSave',
		'click .live-editor-collapse': 'collapse',
		'click .live-editor-mode': 'mobileToggle',
		'click .so-preview-error-retry': function () {
			this.refreshPreview( this.builder.model.getPanelsData() );
		},
		'keyup .live-editor-mode': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
	},

	initialize: function ( options ) {
		options = _.extend( {
			builder: false,
			previewUrl: false,
		}, options );

		if( _.isEmpty( options.previewUrl ) ) {
			options.previewUrl = panelsOptions.ajaxurl + "&action=so_panels_live_editor_preview";
		}

		this.builder = options.builder;
		this.previewUrl = options.previewUrl;

		this.listenTo( this.builder.model, 'refresh_panels_data', this.handleRefreshData );
		this.listenTo( this.builder.model, 'load_panels_data', this.handleLoadData );
	},

	/**
	 * Render the live editor
	 */
	render: function () {
		this.setElement( this.template() );
		this.$el.hide();

		if ( $( '#submitdiv #save-post' ).length > 0 ) {
			var $saveButton = this.$el.find( '.live-editor-save' );
			$saveButton.text( $saveButton.data( 'save' ) );
		}

		var isMouseDown = false;
		$( document )
			.on( 'mousedown', function() {
				isMouseDown = true;
			} )
			.on( 'mouseup', function() {
				isMouseDown = false;
			} );

		// Handle highlighting the relevant widget in the live editor preview
		var liveEditorView = this;

		this.$el.on( 'mouseenter focusin', '.so-widget', function () {
			var $$ = $( this ),
				previewWidget = $$.data( 'live-editor-preview-widget' );

			if ( ! isMouseDown && previewWidget !== undefined && previewWidget.length && ! liveEditorView.$( '.so-preview-overlay' ).is( ':visible' ) ) {
				liveEditorView.highlightElement( previewWidget );
				liveEditorView.scrollToElement( previewWidget );
			}
		} );

		this.$el.on( 'mouseleave focusout', '.so-widget', function () {
			this.resetHighlights();
		}.bind(this) );

		this.listenTo( this.builder, 'open_dialog', function () {
			this.resetHighlights();
		} );

		return this;
	},

	/**
	 * Attach the live editor to the document
	 */
	attach: function () {
		this.$el.appendTo( 'body' );
	},

	/**
	 * Display the live editor
	 */
	open: function () {
		if ( this.$el.html() === '' ) {
			this.render();
		}
		if ( this.$el.closest( 'body' ).length === 0 ) {
			this.attach();
		}

		// Disable page scrolling
		panels.helpers.pageScroll.lock();

		if ( this.$el.is( ':visible' ) ) {
			return this;
		}

		// Refresh the preview display
		this.$el.show();
		this.refreshPreview( this.builder.model.getPanelsData() );

		$( '.live-editor-close' ).trigger( 'focus' );

		// Move the builder view into the Live Editor
		this.originalContainer = this.builder.$el.parent();
		this.builder.$el.appendTo( this.$( '.so-live-editor-builder' ) );
		this.builder.menu.setContext( {
			container: this.$( '.so-live-editor-builder' )
		} );
		this.builder.$( '.so-tool-button.so-live-editor' ).hide();
		this.builder.trigger( 'builder_resize' );


		if( $('#original_post_status' ).val() === 'auto-draft' && ! this.autoSaved ) {
			// The live editor requires a saved draft post, so we'll create one for auto-draft posts
			var thisView = this;

			if ( wp.autosave ) {
				// Set a temporary post title so the autosave triggers properly
				if( $('#title[name="post_title"]' ).val() === '' ) {
					$('#title[name="post_title"]' ).val( panelsOptions.loc.draft ).trigger('keydown');
				}

				$( document ).one( 'heartbeat-tick.autosave', function(){
					thisView.autoSaved = true;
					thisView.refreshPreview( thisView.builder.model.getPanelsData() );
				} );
				wp.autosave.server.triggerSave();
			}
		}
	},

	/**
	 * Close the Live Editor
	 */
	close: function ( closeAfter = true ) {
		// No load timer or diagnostic request may outlive the Live Editor.
		this.clearPreviewFailure();

		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		if ( closeAfter && this.builder.config.liveEditorCloseAfter ) {
			// Live Editor is set to be closed upon saving the page.
			// This is done using a trigger rather than a redirect to confirm if
			// the user wants to save.
			$( '#wp-admin-bar-view a' )[0].click(); // JS click.
			return this;
		}

		this.$el.hide();
		panels.helpers.pageScroll.unlock();

		// Move the builder back to its original container
		this.builder.$el.appendTo( this.originalContainer );
		this.builder.menu.setContext( {
			container: this.originalContainer
		} );
		this.builder.$( '.so-tool-button.so-live-editor' ).show();
		this.builder.trigger( 'builder_resize' );
	},

	/**
	 * Close the Live Editor and save the post.
	 */
	closeAndSave: function(){
		this.close( false );

		if ( panels.helpers.utils.shouldUseBlockEditorSave( this ) ) {
			panels.helpers.utils.saveBlockEditor( this, function() {} );
			return;
		}

		// Finds the submit input for saving without publishing draft posts.
		var saveButton = $( '#submitdiv input[type="submit"][name="save"], .editor-post-publish-button, .edit-widgets-header__actions .is-primary' )[0];
		if ( saveButton ) {
			saveButton.click();
		}
	},

	/**
	 * Collapse the live editor
	 */
	collapse: function () {
		this.$el.toggleClass( 'so-collapsed' );
	},

	/**
	 * Create an overlay in the preview.
	 *
	 * @param over
	 * @return {*|Object} The item we're hovering over.
	 */
	highlightElement: function ( over ) {
		if( ! _.isUndefined( this.resetHighlightTimeout ) ) {
			clearTimeout( this.resetHighlightTimeout );
		}

		// Remove any old overlays

		var body = this.previewIframe.contents().find( 'body' );
		body.find( '.panel-grid .panel-grid-cell .so-panel' )
			.filter( function () {
				// Filter to only include non nested
				return $( this ).parents( '.so-panel' ).length === 0;
			} )
			.not( over )
			.addClass( 'so-panels-faded' );

		over.removeClass( 'so-panels-faded' ).addClass( 'so-panels-highlighted' );
	},

	/**
	 * Reset highlights in the live preview
	 */
	resetHighlights: function() {

		var body = this.previewIframe.contents().find( 'body' );
		this.resetHighlightTimeout = setTimeout( function(){
			body.find( '.panel-grid .panel-grid-cell .so-panel' )
				.removeClass( 'so-panels-faded so-panels-highlighted' );
		}, 100 );
	},

	/**
	 * Scroll over an element in the live preview
	 * @param over
	 */
	scrollToElement: function( over ) {
		var contentWindow = this.$( '.so-preview iframe' )[0].contentWindow;
		contentWindow.liveEditorScrollTo( over );
	},

	handleRefreshData: function ( newData ) {
		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		// A move or a resize changes the preview in place when nothing else changed.
		if ( this.patchPreview( newData ) ) {
			return this;
		}

		this.refreshPreview( newData );
	},

	handleLoadData: function () {
		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		this.refreshPreview( this.builder.model.getPanelsData() );
	},

	/**
	 * Refresh the Live Editor preview.
	 * @returns {exports}
	 */
	refreshPreview: function ( data ) {
		var loadTimePrediction = this.loadTimes.length ?
		_.reduce( this.loadTimes, function ( memo, num ) {
			return memo + num;
		}, 0 ) / this.loadTimes.length : 1000;

		this.previewGeneration++;
		this.previewSnapshot = panels.helpers.liveEditorPatch.snapshot( this.builder.model, data );
		this.previewMap = null;
		this.previewWidths = {};

		// A failed preview has no readable scroll position.
		var previewFailed = this.$( '.so-preview-error' ).hasClass( 'so-active' );
		this.clearPreviewFailure();

		// Store the last preview iframe position
		if( ! _.isNull( this.previewIframe ) && ! previewFailed ) {
			if ( ! this.$( '.so-preview-overlay' ).is( ':visible' ) ) {
				this.previewScrollTop = this.previewIframe.contents().scrollTop();
			}
		}

		// Add a loading bar
		this.$( '.so-preview-overlay' ).show();
		this.$( '.so-preview-overlay .so-loading-bar' )
			.clearQueue()
			.css( 'width', '0%' )
			.animate( {width: '100%'}, parseInt( loadTimePrediction ) + 100 );


		this.postToIframe(
			{
				live_editor_panels_data: JSON.stringify( data ),
				live_editor_post_ID: this.builder.config.postId
			},
			this.previewUrl,
			this.$('.so-preview')
		);

		this.previewIframe.data( 'load-start', new Date().getTime() );

		// Show the error panel if the preview does not load in time.
		var thisView = this,
			iframeEl = this.previewIframe[0],
			timeout = this.previewTimeout();
		this.previewTimer = setTimeout( function () {
			thisView.failPreview( iframeEl, 'timeout', timeout );
		}, timeout );
	},

	/**
	 * The preview load timeout in milliseconds.
	 *
	 * @return {number}
	 */
	previewTimeout: function () {
		var timeout = typeof panelsOptions !== 'undefined' ? parseInt( panelsOptions.live_editor_preview_timeout, 10 ) : NaN;

		return timeout > 0 ? timeout : this.PREVIEW_TIMEOUT;
	},

	/**
	 * Stop the load timer, cancel the diagnostic request and hide the error panel.
	 */
	clearPreviewFailure: function () {
		this.clearPreviewTimer();

		if ( this.previewProbe ) {
			this.previewProbe.abort();
			this.previewProbe = null;
		}

		this.$( '.so-preview-error' ).removeClass( 'so-active' );
	},

	clearPreviewTimer: function () {
		if ( this.previewTimer ) {
			clearTimeout( this.previewTimer );
			this.previewTimer = null;
		}
	},

	/**
	 * Whether an iframe element is the current preview, of the current generation.
	 *
	 * @param {HTMLIFrameElement} iframeEl
	 * @return {boolean}
	 */
	isCurrentPreview: function ( iframeEl ) {
		return ! _.isNull( this.previewIframe ) &&
			iframeEl === this.previewIframe[0] &&
			$( iframeEl ).data( 'generation' ) === this.previewGeneration;
	},

	/**
	 * The document of a preview iframe when it holds a Live Editor preview, else null.
	 *
	 * Null for the initial blank document, a response in another agent cluster, and a response that is not a
	 * preview (every preview has the body class from SiteOrigin_Panels::body_class()).
	 *
	 * @param {HTMLIFrameElement} iframeEl
	 * @return {Document|null}
	 */
	previewDocument: function ( iframeEl ) {
		var doc = null;

		try {
			doc = iframeEl.contentDocument;
		} catch ( e ) {
			doc = null;
		}

		if (
			! doc ||
			doc.URL === 'about:blank' ||
			! doc.body ||
			! doc.body.classList.contains( 'siteorigin-panels-live-editor' )
		) {
			return null;
		}

		return doc;
	},

	/**
	 * Show the error panel for a preview that failed to load (#1368).
	 *
	 * The preview is a form POST into an iframe, so its HTTP status cannot be read. After a failed load, one
	 * request with the same URL and fields reads the status. A preview that loads costs nothing extra.
	 *
	 * @param {HTMLIFrameElement} iframeEl The preview iframe that failed.
	 * @param {string} reason 'timeout' or 'load'.
	 * @param {number} timeout The timeout in milliseconds, for 'timeout'.
	 */
	failPreview: function ( iframeEl, reason, timeout ) {
		if ( ! this.isCurrentPreview( iframeEl ) || ! this.$el.is( ':visible' ) ) {
			return;
		}

		this.clearPreviewTimer();

		this.$( '.so-preview-overlay .so-loading-bar' ).stop( true );
		this.$( '.so-preview-overlay' ).hide();

		var thisView = this,
			$panel = this.$( '.so-preview-error' ),
			$reason = $panel.find( '.so-preview-error-reason' ),
			setReason = function ( key, value ) {
				var text = String( $panel.data( key ) || '' );
				$reason.text( value === undefined ? text : text.replace( /%(1\$)?s/, value ) );
			};

		$reason.text( '' );
		// The stylesheet shows the panel where the preview shows: not at 980px and below, unless collapsed.
		$panel.addClass( 'so-active' );

		if ( reason === 'timeout' ) {
			setReason( 'timeout', Math.round( timeout / 1000 ) );
			return;
		}

		var request = this.previewRequest;
		if ( ! request || typeof window.fetch !== 'function' ) {
			setReason( 'unknown' );
			return;
		}

		if ( this.previewProbe ) {
			this.previewProbe.abort();
		}
		var probe = this.previewProbe = typeof AbortController === 'function' ? new AbortController() : null;
		var isCurrent = function () {
			return thisView.previewProbe === probe && thisView.isCurrentPreview( iframeEl );
		};

		window.fetch( request.url, {
			method: 'POST',
			credentials: 'same-origin',
			body: new URLSearchParams( request.fields ),
			signal: probe ? probe.signal : undefined,
		} ).then( function ( response ) {
			// Only the status is needed.
			if ( response.body && typeof response.body.cancel === 'function' ) {
				response.body.cancel().catch( function () {} );
			}

			if ( ! isCurrent() ) {
				return;
			}

			if ( response.ok ) {
				setReason( 'unknown' );
			} else {
				setReason( 'status', response.status );
			}
		} ).catch( function ( error ) {
			if ( ( error && error.name === 'AbortError' ) || ! isCurrent() ) {
				return;
			}

			setReason( 'unknown' );
		} );
	},

	/**
	 * Use a temporary form to post data to an iframe.
	 *
	 * @param data The data to send
	 * @param url The preview URL
	 * @param target The target iframe
	 */
	postToIframe: function( data, url, target ){
		// An isolated editor needs a preview with the same isolation policy (#1400).
		url = panels.helpers.utils.isolatedPreviewUrl( url );

		// Kept for the diagnostic request after a failed load.
		this.previewRequest = { url: url, fields: data };

		// Store the old preview

		if( ! _.isNull( this.previewIframe )  ) {
			this.previewIframe.remove();
		}

		var iframeId = 'siteorigin-panels-live-preview-' + this.previewFrameId;

		// Remove the old preview frame
		// No src: the form POST below is the only navigation, so no other document can become ready first.
		this.previewIframe = $( '<iframe></iframe>' )
			.attr( {
				'id' : iframeId,
				'name' : iframeId,
			} )
			.data( 'generation', this.previewGeneration )
			.appendTo( target );

		this.setupPreviewFrame( this.previewIframe );

		// We can use a normal POST form submit
		var tempForm = $( '<form id="soPostToPreviewFrame" method="post"></form>' )
			.attr( {
				id: iframeId,
				target: this.previewIframe.attr('id'),
				action: url
			} )
			.appendTo( 'body' );

		$.each( data, function( name, value ){
			$('<input type="hidden" />')
				.attr( {
					name: name,
					value: value
				} )
				.appendTo( tempForm );
		} );

		tempForm
			.trigger( 'submit' )
			.remove();

		this.previewFrameId++;

		return this.previewIframe;
	},

	/**
	 * Do all the basic setup for the preview Iframe element
	 * @param iframe
	 */
	setupPreviewFrame: function( iframe ){
		var thisView = this;
		iframe
			.data( 'iframeready', false )
			.on( 'iframeready', function () {
				var $$ = $( this );

				if( $$.data( 'iframeready' ) ) {
					// Skip this if the iframeready function has already run
					return;
				}

				// Bind only the current preview, and only a document that is a Live Editor preview.
				if ( ! thisView.isCurrentPreview( this ) || ! thisView.previewDocument( this ) ) {
					return;
				}

				var $iframeContents = $$.contents();

				$$.data( 'iframeready', true );

				thisView.clearPreviewTimer();
				thisView.$( '.so-preview-error' ).removeClass( 'so-active' );

				if ( $$.data( 'load-start' ) !== undefined ) {
					thisView.loadTimes.unshift( new Date().getTime() - $$.data( 'load-start' ) );

					if ( ! _.isEmpty( thisView.loadTimes ) ) {
						thisView.loadTimes = thisView.loadTimes.slice( 0, 4 );
					}
				}

				if ( $( '.live-editor-mode.so-active' ).length ) {
					$( '.so-panels-live-editor .so-preview iframe' ).css( 'transition', 'none' );
					thisView.mobileToggle();
				}

				setTimeout( function(){
					// Scroll to the correct position
					$iframeContents.scrollTop( thisView.previewScrollTop );
					thisView.$( '.so-preview-overlay' ).hide();
					$( '.so-panels-live-editor .so-preview iframe' ).css( 'transition', 'all .2s ease' );
				}, 100 );

				// Map the preview before the binding below changes it.
				thisView.previewMap = thisView.buildPreviewMap( thisView.previewDocument( this ) );

				// Lets find all the first level grids. This is to account for the Page Builder layout widget.
				var layoutWrapper = $iframeContents.find( '#pl-' + thisView.builder.config.postId );
				layoutWrapper.find( '.panel-grid .panel-grid-cell .so-panel' )
					.filter( function () {
						// Filter to only include non nested
						return $( this ).closest( '.panel-layout' ).is( layoutWrapper );
					} )
					.each( function ( i, el ) {
						var $$ = $( el );
						thisView.bindPreviewWidget( $$, thisView.$( '.so-live-editor-builder .so-widget' ).eq( $$.data( 'index' ) ) );
					} );

				// Prevent default clicks inside the preview iframe
				$iframeContents.find( "a" ).css( {'pointer-events': 'none'} ).on( 'click', function( e ) {
					e.preventDefault();
				} );

			} )
			.on( 'load', function(){
				var $$ = $( this ),
					doc = null;

				// Null, or a throw, when the response is in another agent cluster (a blocked response under isolation).
				try {
					doc = this.contentDocument;
				} catch ( e ) {
					doc = null;
				}

				// The initial blank document: the preview is still loading.
				if ( doc && doc.URL === 'about:blank' ) {
					return;
				}

				// A load from an older preview.
				if ( ! thisView.isCurrentPreview( this ) ) {
					return;
				}

				thisView.clearPreviewTimer();

				if ( $$.data( 'iframeready' ) ) {
					return;
				}

				// Every preview has this body class (SiteOrigin_Panels::body_class()).
				if ( thisView.previewDocument( this ) ) {
					$$.trigger( 'iframeready' );
				} else {
					thisView.failPreview( this, 'load' );
				}
			} );
	},

	/**
	 * Map the rows, cells and widgets of the preview snapshot to their elements in the preview.
	 *
	 * Null (every change reloads) unless the preview is the post's own layout (#pl-{postId}; the admin-ajax
	 * preview and the Layout Block use other ids), every row, cell and widget is where the snapshot puts it,
	 * every non-empty cell's widgets are the only children of one container, and widget margins are not
	 * inline (inline-styles writes position-dependent margins into each widget).
	 *
	 * @param {Document|null} doc The preview document.
	 * @return {Object|null}
	 */
	buildPreviewMap: function ( doc ) {
		var snap = this.previewSnapshot,
			postId = this.builder.config.postId;

		if (
			! doc ||
			! snap ||
			! snap.data ||
			! _.isArray( snap.data.grid_cells ) ||
			( typeof panelsOptions !== 'undefined' && panelsOptions.live_editor_inline_styles )
		) {
			return null;
		}

		var wrapper = doc.getElementById( 'pl-' + postId );
		if ( ! wrapper ) {
			return null;
		}

		var map = {
				doc: doc,
				wrapper: wrapper,
				rows: {},
				cells: {},
				containers: {},
				widgets: {},
				renderedWeights: {},
				widthRules: {},
			},
			cellIndex = 0,
			widgetIndex = 0,
			valid = true;

		_.each( snap.rows, function ( row, ri ) {
			var rowEl = doc.getElementById( 'pg-' + postId + '-' + ri );
			if ( ! rowEl || rowEl.parentNode !== wrapper ) {
				valid = false;
				return;
			}
			map.rows[ row.cid ] = rowEl;

			_.each( row.cells, function ( cell, ci ) {
				var cellEl = doc.getElementById( 'pgc-' + postId + '-' + ri + '-' + ci );
				var cellData = snap.data.grid_cells[ cellIndex++ ];
				if ( ! cellEl || ! rowEl.contains( cellEl ) || ! cellData ) {
					valid = false;
					return;
				}
				map.cells[ cell.cid ] = cellEl;
				map.renderedWeights[ cell.cid ] = Number( cellData.weight );

				var widgetEls = _.map( cell.widgets, function ( widgetCid, wi ) {
					var el = doc.getElementById( 'panel-' + postId + '-' + ri + '-' + ci + '-' + wi );
					if ( ! el || ! cellEl.contains( el ) || String( el.getAttribute( 'data-index' ) ) !== String( widgetIndex ) ) {
						valid = false;
					}
					widgetIndex++;
					map.widgets[ widgetCid ] = el;

					return el;
				} );

				if ( valid && widgetEls.length ) {
					var container = widgetEls[0].parentNode;
					var children = Array.prototype.slice.call( container.children );
					if ( children.length !== widgetEls.length || _.some( children, function ( child, i ) {
						return child !== widgetEls[ i ];
					} ) ) {
						valid = false;
						return;
					}
					map.containers[ cell.cid ] = container;
				}
			} );
		} );

		var rowCount = _.filter( wrapper.children, function ( el ) {
			return el.classList.contains( 'panel-grid' );
		} ).length;

		return valid && rowCount === snap.rows.length ? map : null;
	},

	/**
	 * Apply a move or resize to the preview without a reload.
	 *
	 * @param {Object} data The new builder data.
	 * @return {boolean} False when the preview must reload.
	 */
	patchPreview: function ( data ) {
		var map = this.previewMap;

		if (
			! map ||
			_.isNull( this.previewIframe ) ||
			! this.previewIframe.data( 'iframeready' ) ||
			! this.isCurrentPreview( this.previewIframe[0] ) ||
			this.previewDocument( this.previewIframe[0] ) !== map.doc ||
			this.$( '.so-preview-overlay' ).is( ':visible' ) ||
			this.$( '.so-preview-error' ).hasClass( 'so-active' )
		) {
			return false;
		}

		var patch = panels.helpers.liveEditorPatch,
			next = patch.snapshot( this.builder.model, data ),
			ops = patch.plan( this.previewSnapshot, next );

		if ( ! ops ) {
			return false;
		}

		// Work out every new width before the DOM changes, so a width that cannot be read reloads cleanly.
		var widths = _.clone( this.previewWidths || {} );
		var widthsOk = _.every( ops.weights, function ( weight, cellCid ) {
			var rule = this.cellWidthRule( cellCid );
			var width = rule ? patch.cellWidth( rule.width, map.renderedWeights[ cellCid ], weight ) : null;
			if ( ! width ) {
				return false;
			}
			widths[ cellCid ] = { selector: rule.selector, media: rule.media, width: width };

			return true;
		}, this );
		if ( ! widthsOk ) {
			return false;
		}

		try {
			this.moveRows( ops.rowOrder );
			this.moveWidgets( ops.cells );
			this.writeWidths( widths );
		} catch ( e ) {
			return false;
		}

		this.previewWidths = widths;
		this.previewSnapshot = next;

		// Widgets that measure their layout listen for this.
		var previewWindow = this.previewIframe[0].contentWindow;
		previewWindow.dispatchEvent( new previewWindow.Event( 'resize' ) );

		return true;
	},

	/**
	 * Put the row elements into the places the rows held, in the new order. Other children of the layout
	 * wrapper stay where they are.
	 *
	 * @param {string[]} rowOrder Row cids.
	 */
	moveRows: function ( rowOrder ) {
		var map = this.previewMap,
			rowEls = _.values( map.rows ),
			current = _.filter( map.wrapper.children, function ( el ) {
				return _.contains( rowEls, el );
			} ),
			desired = _.map( rowOrder, function ( cid ) {
				return map.rows[ cid ];
			} );

		if ( sameElements( current, desired ) ) {
			return;
		}

		var markers = _.map( current, function ( el ) {
			var marker = map.doc.createComment( '' );
			map.wrapper.insertBefore( marker, el );

			return marker;
		} );

		_.each( markers, function ( marker, i ) {
			map.wrapper.replaceChild( desired[ i ], marker );
		} );
	},

	/**
	 * Put the widget elements into their cells in the new order, and set the first and last child classes.
	 *
	 * @param {Object} cells Widget cids by cell cid, for every non-empty cell.
	 */
	moveWidgets: function ( cells ) {
		var map = this.previewMap,
			elementsOf = function ( widgetCids ) {
				return _.map( widgetCids, function ( cid ) {
					return map.widgets[ cid ];
				} );
			};

		// Append in order to every cell whose widgets changed. A widget that left a cell is appended to its new one.
		_.each( cells, function ( widgetCids, cellCid ) {
			var container = map.containers[ cellCid ];
			var desired = elementsOf( widgetCids );
			if ( ! sameElements( container.children, desired ) ) {
				_.each( desired, function ( el ) {
					container.appendChild( el );
				} );
			}
		} );

		_.each( cells, function ( widgetCids, cellCid ) {
			var container = map.containers[ cellCid ];
			var desired = elementsOf( widgetCids );
			if ( ! sameElements( container.children, desired ) ) {
				throw new Error( 'The preview cell does not hold the expected widgets.' );
			}

			_.each( desired, function ( el, i ) {
				el.classList.toggle( 'panel-first-child', i === 0 );
				el.classList.toggle( 'panel-last-child', i === desired.length - 1 );
			} );
		} );
	},

	/**
	 * The server's width rule for a cell: the one rule whose selector list names the cell's id and sets a
	 * width, with its @media condition. Null when there is not exactly one, or it is inside another kind of
	 * group (@supports, @layer, @container).
	 *
	 * @param {string} cellCid
	 * @return {Object|null} { selector, media, width }
	 */
	cellWidthRule: function ( cellCid ) {
		var map = this.previewMap;
		if ( _.has( map.widthRules, cellCid ) ) {
			return map.widthRules[ cellCid ];
		}

		var selector = '#' + map.cells[ cellCid ].id,
			found = [];

		var walk = function ( rules, media, unsupported ) {
			_.each( rules, function ( rule ) {
				if ( rule.type === 1 ) {
					// A style rule. With CSS nesting it also has cssRules, so test the type first.
					var selectors = _.map( String( rule.selectorText || '' ).split( ',' ), function ( part ) {
						return part.trim();
					} );
					if ( _.contains( selectors, selector ) && rule.style && rule.style.width ) {
						found.push( { selector: selector, media: media, width: rule.style.width, unsupported: unsupported } );
					}
				} else if ( rule.type === 4 ) {
					// @media. A nested @media has two conditions to keep: not supported.
					walk( rule.cssRules, rule.conditionText || rule.media.mediaText, unsupported || !! media );
				} else if ( rule.cssRules ) {
					walk( rule.cssRules, media, true );
				}
			} );
		};

		_.each( map.doc.styleSheets, function ( sheet ) {
			if ( sheet.ownerNode && sheet.ownerNode.id === 'so-live-editor-patch' ) {
				return;
			}

			var rules;
			try {
				rules = sheet.cssRules;
			} catch ( e ) {
				// A cross-origin stylesheet.
				return;
			}
			walk( rules, '', false );
		} );

		var rule = found.length === 1 && ! found[0].unsupported ? _.omit( found[0], 'unsupported' ) : null;
		map.widthRules[ cellCid ] = rule;

		return rule;
	},

	/**
	 * Write the patched cell widths into one style element at the end of the preview body. A later rule with
	 * the same selector and condition wins over the server's rule; the mobile rule (id and class) still wins.
	 *
	 * @param {Object} widths { selector, media, width } by cell cid.
	 */
	writeWidths: function ( widths ) {
		var doc = this.previewMap.doc;
		var css = _.map( widths, function ( entry ) {
			var rule = entry.selector + ' { width: ' + entry.width + '; }';

			return entry.media ? '@media ' + entry.media + ' { ' + rule + ' }' : rule;
		} ).join( '\n' );

		var style = doc.getElementById( 'so-live-editor-patch' );
		if ( ! css ) {
			if ( style ) {
				style.parentNode.removeChild( style );
			}
			return;
		}

		if ( ! style ) {
			style = doc.createElement( 'style' );
			style.id = 'so-live-editor-patch';
			doc.body.appendChild( style );
		}
		style.textContent = css;
	},

	/**
	 * Bind a preview widget to its widget in the Live Editor sidebar: pointer cursor, hover highlight in both
	 * directions, and click to edit.
	 *
	 * @param {jQuery} $previewEl The widget wrapper (.so-panel) in the preview.
	 * @param {jQuery} $sidebarWidget The widget (.so-widget) in the Live Editor sidebar.
	 */
	bindPreviewWidget: function ( $previewEl, $sidebarWidget ) {
		var thisView = this;
		$sidebarWidget.data( 'live-editor-preview-widget', $previewEl );

		$previewEl
			.css( {
				'cursor': 'pointer'
			} )
			.on( 'mouseenter', function() {
				$sidebarWidget.parent().addClass( 'so-hovered' );
				thisView.highlightElement( $previewEl );
			} )
			.on( 'mouseleave', function() {
				$sidebarWidget.parent().removeClass( 'so-hovered' );
				thisView.resetHighlights();
			} )
			.on( 'click', function( e ) {
				e.preventDefault();
				// When we click a widget, send that click to the form
				$sidebarWidget.find( '.title h4' ).trigger( 'click' );
			} );
	},

	/**
	 * Return true if the live editor has a valid preview URL.
	 * @return {boolean}
	 */
	hasPreviewUrl: function () {
		return this.$( 'form.live-editor-form' ).attr( 'action' ) !== '';
	},

	/**
	 * Toggle the size of the preview iframe to simulate mobile devices.
	 * @param e
	 */
	mobileToggle: function( e ){
		var button = typeof e !== "undefined" ? $( e.currentTarget ) : $( '.live-editor-mode.so-active' );
		this.$('.live-editor-mode' ).not( button ).removeClass('so-active');
		button.addClass( 'so-active' );

		this.$el
			.removeClass( 'live-editor-desktop-mode live-editor-tablet-mode live-editor-mobile-mode' )
			.addClass( 'live-editor-' + button.data( 'mode' ) + '-mode' )
			.find( 'iframe' ).css( 'width', button.data( 'width' ) );
	}
} );
