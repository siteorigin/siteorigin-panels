var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	template: _.template( $( '#siteorigin-panels-live-editor' ).html().panelsProcessTemplate() ),

	postId: false,
	previewScrollTop: 0,
	loadTimes: [],

	events: {
		'click .live-editor-close': 'close',
		'click .live-editor-collapse': 'collapse',
		'click .live-editor-mode': 'mobileToggle'
	},

	initialize: function ( options ) {
		this.builder = options.builder;
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

		this.$( '.so-preview iframe' )
			.on( 'iframeready', function () {
				var $$ = $( this ),
					$iframeContents = $$.contents();

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
						return $( this ).parents( '.widget_siteorigin-panels-builder' ).length === 0;
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

			} );

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
			var $$ = $( this ), previewWidget = $( this ).data( 'live-editor-preview-widget' );

			if ( ! isMouseDown && previewWidget !== undefined && previewWidget.length && ! thisView.$( '.so-preview-overlay' ).is( ':visible' ) ) {
				thisView.highlightElement( previewWidget );
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

	setPostId: function ( postId ) {
		this.postId = postId;
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
		var body = this.$( 'iframe#siteorigin-panels-live-editor-iframe' ).contents().find( 'body' ).css( 'position', 'relative' );
		body.find( '.panel-grid .panel-grid-cell .so-panel' )
			.filter( function () {
				// Filter to only include non nested
				return $( this ).parents( '.widget_siteorigin-panels-builder' ).length === 0;
			} )
			.not( over )
			.addClass( 'so-panels-faded' );

		over.removeClass( 'so-panels-faded' ).addClass( 'so-panels-highlighted' );
	},

	resetHighlights: function() {

		var body = this.$( 'iframe#siteorigin-panels-live-editor-iframe' ).contents().find( 'body' );
		this.resetHighlightTimeout = setTimeout( function(){
			body.find( '.panel-grid .panel-grid-cell .so-panel' )
				.removeClass( 'so-panels-faded so-panels-highlighted' );
		}, 100 );
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
		var iframe = this.$( '.so-preview iframe' ),
			form = this.$( '.so-preview form' );

		if ( ! this.$( '.so-preview-overlay' ).is( ':visible' ) ) {
			this.previewScrollTop = iframe.contents().scrollTop();
		}

		var loadTimePrediction = this.loadTimes.length ?
		_.reduce( this.loadTimes, function ( memo, num ) {
			return memo + num
		}, 0 ) / this.loadTimes.length : 1000;

		this.$( '.so-preview-overlay' ).show();

		// Add a loading bar
		this.$( '.so-preview-overlay .so-loading-bar' )
			.clearQueue()
			.css( 'width', '0%' )
			.animate( {width: '100%'}, parseInt( loadTimePrediction ) + 100 );

		// Set the preview data and submit the form
		form.find( 'input[name="live_editor_panels_data"]' ).val( JSON.stringify( data ) );
		form.submit()

		iframe.data( 'load-start', new Date().getTime() );
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
