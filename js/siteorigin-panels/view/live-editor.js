var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( $( '#siteorigin-panels-live-editor' ).html().panelsProcessTemplate() ),

	previewScrollTop: 0,
	loadTimes: [],
	previewFrameId: 1,
	previewUrl: null,
	previewIframe: null,

	events: {
		'click .live-editor-close': 'close',
		'click .live-editor-collapse': 'collapse',
		'click .live-editor-mode': 'mobileToggle'
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

		this.builder.model.on( 'refresh_panels_data', this.handleRefreshData, this );
		this.builder.model.on( 'load_panels_data', this.handleLoadData, this );
	},

	/**
	 * Render the live editor
	 */
	render: function () {
		this.setElement( this.template() );
		this.$el.hide();
		var thisView = this;

		var isMouseDown = false;

		$( document )
			.mousedown( function () {
				isMouseDown = true;
			} )
			.mouseup( function () {
				isMouseDown = false;
			} );

		// Handle highlighting the relevant widget in the live editor preview
		thisView.$el.on( 'mouseenter', '.so-widget-wrapper', function () {
			var $$ = $( this ),
				previewWidget = $( this ).data( 'live-editor-preview-widget' );

			if ( ! isMouseDown && previewWidget !== undefined && previewWidget.length && ! thisView.$( '.so-preview-overlay' ).is( ':visible' ) ) {
				thisView.highlightElement( previewWidget );
				thisView.scrollToElement( previewWidget );
			}
		} );

		thisView.$el.on( 'mouseleave', '.so-widget-wrapper', function () {
			thisView.resetHighlights();
		} );

		thisView.builder.on( 'open_dialog', function () {
			thisView.resetHighlights();
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
		this.builder.lockPageScroll();

		if ( this.$el.is( ':visible' ) ) {
			return this;
		}

		// Refresh the preview display
		this.$el.show();
		this.refreshPreview( this.builder.model.getPanelsData() );

		this.originalContainer = this.builder.$el.parent();
		this.builder.$el.appendTo( this.$( '.so-live-editor-builder' ) );
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
	 * Close the live editor
	 */
	close: function () {
		if ( ! this.$el.is( ':visible' ) ) {
			return this;
		}

		this.$el.hide();
		this.builder.unlockPageScroll();

		// Move the builder back to its original container
		this.builder.$el.appendTo( this.originalContainer );
		this.builder.$( '.so-tool-button.so-live-editor' ).show();
		this.builder.trigger( 'builder_resize' );
	},

	/**
	 * Collapse the live editor
	 */
	collapse: function () {
		this.$el.toggleClass( 'so-collapsed' );

		var text = this.$( '.live-editor-collapse span' );
		text.html( text.data( this.$el.hasClass( 'so-collapsed' ) ? 'expand' : 'collapse' ) );
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

	handleRefreshData: function ( newData, args ) {
		if ( ! this.$el.is( ':visible' ) ) {
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

		// Store the last preview iframe position
		if( ! _.isNull( this.previewIframe )  ) {
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
				live_editor_panels_data: JSON.stringify( data )
			},
			this.previewUrl,
			this.$('.so-preview')
		);

		this.previewIframe.data( 'load-start', new Date().getTime() );
	},

	/**
	 * Use a temporary form to post data to an iframe.
	 *
	 * @param data The data to send
	 * @param url The preview URL
	 * @param target The target iframe
	 */
	postToIframe: function( data, url, target ){
		// Store the old preview

		if( ! _.isNull( this.previewIframe )  ) {
			this.previewIframe.remove();
		}

		var iframeId = 'siteorigin-panels-live-preview-' + this.previewFrameId;

		// Remove the old preview frame
		this.previewIframe = $('<iframe src="javascript:false;" />')
			.attr( {
				'id' : iframeId,
				'name' : iframeId,
			} )
			.appendTo( target )

		this.setupPreviewFrame( this.previewIframe );

		// We can use a normal POST form submit
		var tempForm = $('<form id="soPostToPreviewFrame" method="post" />')
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
			.submit()
			.remove();

		this.previewFrameId++;

		return this.previewIframe;
	},

	setupPreviewFrame: function( iframe ){
		var thisView = this;
		iframe
			.data( 'iframeready', false )
			.on( 'iframeready', function () {
				var $$ = $( this ),
					$iframeContents = $$.contents();

				if( $$.data( 'iframeready' ) ) {
					// Skip this if the iframeready function has already run
					return;
				}

				$$.data( 'iframeready', true );

				if ( $$.data( 'load-start' ) !== undefined ) {
					thisView.loadTimes.unshift( new Date().getTime() - $$.data( 'load-start' ) );

					if ( ! _.isEmpty( thisView.loadTimes ) ) {
						thisView.loadTimes = thisView.loadTimes.slice( 0, 4 );
					}
				}

				setTimeout( function(){
					// Scroll to the correct position
					$iframeContents.scrollTop( thisView.previewScrollTop );
					thisView.$( '.so-preview-overlay' ).hide();
				}, 100 );

				// Lets find all the first level grids. This is to account for the Page Builder layout widget.
				$iframeContents.find( '.panel-grid .panel-grid-cell .so-panel' )
					.filter( function () {
						// Filter to only include non nested
						return $( this ).parents( '.so-panel' ).length === 0;
					} )
					.each( function ( i, el ) {
						var $$ = $( el );
						var widgetEdit = thisView.$( '.so-live-editor-builder .so-widget-wrapper' ).eq( $$.data( 'index' ) );

						widgetEdit.data( 'live-editor-preview-widget', $$ );

						$$
							.css( {
								'cursor': 'pointer'
							} )
							.mouseenter( function () {
								widgetEdit.parent().addClass( 'so-hovered' );
								thisView.highlightElement( $$ );
							} )
							.mouseleave( function () {
								widgetEdit.parent().removeClass( 'so-hovered' );
								thisView.resetHighlights();
							} )
							.click( function ( e ) {
								e.preventDefault();
								// When we click a widget, send that click to the form
								widgetEdit.find( '.title h4' ).click();
							} );
					} );

				// Prevent default clicks
				$iframeContents.find( "a" ).css( {'pointer-events': 'none'} ).click( function ( e ) {
					e.preventDefault();
				} );

			} )
			.on( 'load', function(){
				var $$ = $( this );
				if( ! $$.data( 'iframeready' )  ) {
					$$.trigger('iframeready');
				}
			} );
	},

	/**
	 * Return true if the live editor has a valid preview URL.
	 * @return {boolean}
	 */
	hasPreviewUrl: function () {
		return this.$( 'form.live-editor-form' ).attr( 'action' ) !== '';
	},

	mobileToggle: function( e ){
		var button = $( e.currentTarget );
		this.$('.live-editor-mode' ).not( button ).removeClass('so-active');
		button.addClass( 'so-active' );

		this.$el
			.removeClass( 'live-editor-desktop-mode live-editor-tablet-mode live-editor-mobile-mode' )
			.addClass( 'live-editor-' + button.data( 'mode' ) + '-mode' );

	}
} );
