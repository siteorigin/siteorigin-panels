var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {
	wrapperTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-context-menu' ).html() ) ),
	sectionTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-context-menu-section' ).html() ) ),

	contexts: [],
	active: false,

	events: {
		'keyup .so-search-wrapper input': 'searchKeyUp'
	},

	/**
	 * Intialize the context menu
	 */
	initialize: function () {
		this.contextDocument = document;
		this.contextWindow = window;
		this.listenContextMenu();
		this.render();
		this.attach();
	},

	getEventNamespace: function () {
		return '.siteoriginPanelsMenu' + this.cid;
	},

	getContextDocument: function () {
		return this.contextDocument || document;
	},

	getContextWindow: function () {
		var contextDocument = this.getContextDocument();
		return this.contextWindow || contextDocument.defaultView || window;
	},

	getOutsideClickWindows: function () {
		var interactionWindows = [ this.getContextWindow() ];

		if ( window !== interactionWindows[ 0 ] ) {
			interactionWindows.push( window );
		}

		return interactionWindows;
	},

	setContext: function( options ) {
		options = options || {};

		var contextDocument = options.document;
		if (
			! contextDocument &&
			options.container &&
			options.container.length &&
			options.container[ 0 ]
		) {
			contextDocument = options.container[ 0 ].ownerDocument;
		}

		if ( ! contextDocument ) {
			contextDocument = this.getContextDocument();
		}

		var contextWindow = options.window || contextDocument.defaultView || window;

		if (
			this.contextDocument === contextDocument &&
			this.contextWindow === contextWindow
		) {
			return this;
		}

		this.closeMenu();
		this.unlistenContextMenu();

		this.contextDocument = contextDocument;
		this.contextWindow = contextWindow;

		this.attach();
		this.listenContextMenu();

		return this;
	},

	/**
	 * Listen for the right click context menu
	 */
	listenContextMenu: function () {
		var thisView = this,
			eventNamespace = 'contextmenu' + this.getEventNamespace();

		$( this.getContextDocument() ).on( eventNamespace, function ( e ) {
			if ( thisView.active && ! thisView.isOverEl( thisView.$el, e ) ) {
				thisView.closeMenu();
				thisView.active = false;
				e.preventDefault();
				return false;
			}

			if ( thisView.active ) {
				// Lets not double up on the context menu
				return true;
			}

			// Other components should listen to activate_context
			thisView.active = false;
			thisView.trigger( 'activate_context', e, thisView );

			if ( thisView.active ) {
				// We don't want the default event to happen.
				e.preventDefault();

				thisView.openMenu( {
					left: e.pageX,
					top: e.pageY
				} );
			}
		} );
	},

	unlistenContextMenu: function() {
		var eventNamespace = this.getEventNamespace(),
			contextWindow = this.getContextWindow();
		$( this.getContextDocument() ).off( 'contextmenu' + eventNamespace );
		$( contextWindow ).off( 'keyup' + eventNamespace );

		_.each( this.getOutsideClickWindows(), function( interactionWindow ) {
			$( interactionWindow ).off( 'click' + eventNamespace );
		} );
	},

	render: function () {
		this.setElement( this.wrapperTemplate() );
	},

	attach: function () {
		this.$el.appendTo( this.getContextDocument().body );
	},

	/**
	 * Display the actual context menu.
	 *
	 * @param position
	 */
	openMenu: function ( position ) {
		var $contextWindow = $( this.getContextWindow() ),
			eventNamespace = this.getEventNamespace(),
			thisView = this;

		this.trigger( 'open_menu' );

		// Start listening for situations when we should close the menu
		$( this.getContextWindow() ).on( 'keyup' + eventNamespace, {menu: thisView}, thisView.keyboardListen );

		_.each( this.getOutsideClickWindows(), function( interactionWindow ) {
			$( interactionWindow ).on( 'click' + eventNamespace, {menu: thisView}, thisView.clickOutsideListen );
		} );

		// Set the maximum height of the menu
		this.$el.css( 'max-height', $contextWindow.height() - 20 );

		// Correct the left position
		if ( position.left + this.$el.outerWidth() + 10 >= $contextWindow.width() ) {
			position.left = $contextWindow.width() - this.$el.outerWidth() - 10;
		}
		if ( position.left <= 0 ) {
			position.left = 10;
		}

		// Check top position
		if ( position.top + this.$el.outerHeight() - $contextWindow.scrollTop() + 10 >= $contextWindow.height() ) {
			position.top = $contextWindow.height() + $contextWindow.scrollTop() - this.$el.outerHeight() - 10;
		}
		if ( position.left <= 0 ) {
			position.left = 10;
		}

		// position the contextual menu
		this.$el.css( {
			left: position.left + 1,
			top: position.top + 1
		} ).show();
		this.$( '.so-search-wrapper input' ).trigger( 'focus' );
	},

	closeMenu: function () {
		var eventNamespace = this.getEventNamespace(),
			contextWindow = this.getContextWindow();

		this.trigger( 'close_menu' );

		// Stop listening for situations when we should close the menu
		$( contextWindow ).off( 'keyup' + eventNamespace, this.keyboardListen );

		_.each( this.getOutsideClickWindows(), function( interactionWindow ) {
			$( interactionWindow ).off( 'click' + eventNamespace, this.clickOutsideListen );
		}, this );

		this.active = false;
		this.$el.empty().hide();
	},

	/**
	 * Keyboard events handler
	 */
	keyboardListen: function ( e ) {
		var menu = e.data.menu;

		switch ( e.which ) {
			case 27:
				menu.closeMenu();
				break;
		}
	},

	/**
	 * Listen for a click outside the menu to close it.
	 * @param e
	 */
	clickOutsideListen: function ( e ) {
		var menu = e.data.menu;
		if ( e.which !== 3 && menu.$el.is( ':visible' ) && ! menu.isOverEl( menu.$el, e ) ) {
			menu.closeMenu();
		}
	},

	/**
	 * Add a new section to the contextual menu.
	 *
	 * @param settings
	 * @param items
	 * @param callback
	 */
	addSection: function ( id, settings, items, callback ) {
		var thisView = this;
		settings = _.extend( {
			display: 5,
			defaultDisplay: false,
			search: true,

			// All the labels
			sectionTitle: '',
			searchPlaceholder: '',

			// This is the key to be used in items for the title. Makes it easier to list objects
			titleKey: 'title'
		}, settings );

		// Create the new section
		var section = $( this.sectionTemplate( {
			settings: settings,
			items: items
		} ) ).attr( 'id', 'panels-menu-section-' + id );
		this.$el.append( section );

		section.find( '.so-item:not(.so-confirm)' ).on( 'click', function() {
			var $$ = $( this );
			callback( $$.data( 'key' ) );
			thisView.closeMenu();
		} );

		section.find( '.so-item.so-confirm' ).on( 'click', function() {
			var $$ = $( this );

			if ( $$.hasClass( 'so-confirming' ) ) {
				callback( $$.data( 'key' ) );
				thisView.closeMenu();
				return;
			}

			$$
				.data( 'original-text', $$.html() )
				.addClass( 'so-confirming' )
				.html( '<span class="dashicons dashicons-yes"></span> ' + panelsOptions.loc.dropdown_confirm );

			setTimeout( function () {
				$$.removeClass( 'so-confirming' );
				$$.html( $$.data( 'original-text' ) );
			}, 2500 );
		} );

		section.data( 'settings', settings ).find( '.so-search-wrapper input' ).trigger( 'keyup' );

		this.active = true;
	},

	/**
	 * Check if a section exists in the current menu.
	 *
	 * @param id
	 * @returns {boolean}
	 */
	hasSection: function( id ){
		return this.$el.find( '#panels-menu-section-' + id  ).length > 0;
	},

	/**
	 * Handle searching inside a section.
	 *
	 * @param e
	 * @returns {boolean}
	 */
	searchKeyUp: function ( e ) {
		var
			$$ = $( e.currentTarget ),
			section = $$.closest( '.so-section' ),
			settings = section.data( 'settings' );

		if ( e.which === 38 || e.which === 40 ) {
			// First, lets check if this is an up, down or enter press
			var
				items = section.find( 'ul li:visible' ),
				activeItem = items.filter( '.so-active' ).eq( 0 );

			if ( activeItem.length ) {
				items.removeClass( 'so-active' );

				var activeIndex = items.index( activeItem );

				if ( e.which === 38 ) {
					if ( activeIndex - 1 < 0 ) {
						activeItem = items.last();
					} else {
						activeItem = items.eq( activeIndex - 1 );
					}
				}
				else if ( e.which === 40 ) {
					if ( activeIndex + 1 >= items.length ) {
						activeItem = items.first();
					} else {
						activeItem = items.eq( activeIndex + 1 );
					}
				}
			}
			else if ( e.which === 38 ) {
				activeItem = items.last();
			}
			else if ( e.which === 40 ) {
				activeItem = items.first();
			}

			activeItem.addClass( 'so-active' );
			return false;
		}
		if ( e.which === 13 ) {
			if ( section.find( 'ul li:visible' ).length === 1 ) {
				// We'll treat a single visible item as active when enter is clicked
				section.find( 'ul li:visible' ).trigger( 'click' );
				return false;
			}
			section.find( 'ul li.so-active:visible' ).trigger( 'click' );
			return false;
		}

		if ( $$.val() === '' ) {
			// We'll display the defaultDisplay items
			if ( settings.defaultDisplay ) {
				section.find( '.so-item' ).hide();
				for ( var i = 0; i < settings.defaultDisplay.length; i ++ ) {
					section.find( '.so-item[data-key="' + settings.defaultDisplay[i] + '"]' ).show();
				}
			} else {
				// We'll just display all the items
				section.find( '.so-item' ).show();
			}
		} else {
			section.find( '.so-item' ).hide().each( function () {
				var item = $( this );
				if ( item.html().toLowerCase().indexOf( $$.val().toLowerCase() ) !== - 1 ) {
					item.show();
				}
			} );
		}

		// Now, we'll only show the first settings.display visible items
		section.find( '.so-item:visible:gt(' + (
			settings.display - 1
			) + ')' ).hide();


		if ( section.find( '.so-item:visible' ).length === 0 && $$.val() !== '' ) {
			section.find( '.so-no-results' ).show();
		} else {
			section.find( '.so-no-results' ).hide();
		}
	},

	/**
	 * Check if the given mouse event is over the element
	 * @param el
	 * @param event
	 */
	isOverEl: function ( el, event ) {
		var elPos = [
			[el.offset().left, el.offset().top],
			[el.offset().left + el.outerWidth(), el.offset().top + el.outerHeight()]
		];

		// Return if this event is over the given element
		return (
			event.pageX >= elPos[0][0] && event.pageX <= elPos[1][0] &&
			event.pageY >= elPos[0][1] && event.pageY <= elPos[1][1]
		);
	}

} );
