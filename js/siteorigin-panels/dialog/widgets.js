var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

	builder: null,
	widgetTemplate: _.template( panels.helpers.utils.processTemplate( $( '#siteorigin-panels-dialog-widgets-widget' ).html() ) ),
	filter: {},

	dialogClass: 'so-panels-dialog-add-widget',
	dialogIcon: 'add-widget',

	events: {
		'click .so-close': 'closeDialog',
		'click .widget-type': 'widgetClickHandler',
		'keyup .so-sidebar-search': 'searchHandler',
		'keyup .widget-type-wrapper': 'searchHandler',
		'keyup .widget-type-wrapper': function( e ) {
			panels.helpers.accessibility.triggerClickOnEnter( e );
		},
	},

	/**
	 * Initialize the widget adding dialog
	 */
	initializeDialog: function () {

		this.on( 'open_dialog', function () {
			this.filter.search = '';
			this.filterWidgets( this.filter );
		}, this );


		this.on( 'open_dialog_complete', function () {
			// Clear the search and re-filter the widgets when we open the dialog
			this.$( '.so-sidebar-search' ).val( '' ).trigger( 'focus' );
			this.balanceWidgetHeights();
		} );

		// We'll implement a custom tab click handler
		this.on( 'tab_click', this.tabClickHandler, this );
	},

	render: function () {
		// Render the dialog and attach it to the builder interface
		this.renderDialog( this.parseDialogContent( $( '#siteorigin-panels-dialog-widgets' ).html(), {} ) );

		// Add all the widgets
		_.each( panelsOptions.widgets, function ( widget ) {
			var $w = $( this.widgetTemplate( {
				title: widget.title,
				description: widget.description
			} ) );

			if ( _.isUndefined( widget.icon ) ) {
				widget.icon = 'dashicons dashicons-admin-generic';
			}

			$( '<span class="widget-icon"></span>' ).addClass( widget.icon ).prependTo( $w.find( '.widget-type-wrapper' ) );

			$w.data( 'class', widget.class ).appendTo( this.$( '.widget-type-list' ) );
		}, this );

		// Add the sidebar tabs
		var tabs = this.$( '.so-sidebar-tabs' );
		_.each( panelsOptions.widget_dialog_tabs, function ( tab, key ) {
			$( this.dialogTabTemplate( {'title': tab.title, 'tab': key} ) ).data( {
				'message': tab.message,
				'filter': tab.filter
			} ).appendTo( tabs );
		}, this );

		// We'll be using tabs, so initialize them
		this.initTabs();

		var thisDialog = this;
		$( window ).on( 'resize', function() {
			thisDialog.balanceWidgetHeights();
		} );
	},

	/**
	 * Handle a tab being clicked
	 */
	tabClickHandler: function ( $t ) {
		// Get the filter from the tab, and filter the widgets
		this.filter = $t.parent().data( 'filter' );
		this.filter.search = this.$( '.so-sidebar-search' ).val();

		var message = $t.parent().data( 'message' );
		if ( _.isEmpty( message ) ) {
			message = '';
		}

		this.$( '.so-toolbar .so-status' ).html( message );

		this.filterWidgets( this.filter );

		return false;
	},

	/**
	 * Handle changes to the search value
	 */
	searchHandler: function ( e ) {
		if( e.which === 13 ) {
			var visibleWidgets = this.$( '.widget-type-list .widget-type:visible' );
			if( visibleWidgets.length === 1 ) {
				visibleWidgets.trigger( 'click' );
			}
		}
		else {
			this.filter.search = $( e.target ).val().trim();
			this.filterWidgets( this.filter );
		}
	},

	/**
	 * Filter the widgets that we're displaying
	 * @param filter
	 */
	filterWidgets: function ( filter ) {
		if ( _.isUndefined( filter ) ) {
			filter = {};
		}

		if ( _.isUndefined( filter.groups ) ) {
			filter.groups = '';
		}

		this.$( '.widget-type-list .widget-type' ).each( function () {
			var $$ = $( this ), showWidget;
			var widgetClass = $$.data( 'class' );

			var widgetData = (
				! _.isUndefined( panelsOptions.widgets[widgetClass] )
			) ? panelsOptions.widgets[widgetClass] : null;

			if ( _.isEmpty( filter.groups ) ) {
				// This filter doesn't specify groups, so show all
				showWidget = true;
			} else if ( widgetData !== null && ! _.isEmpty( _.intersection( filter.groups, panelsOptions.widgets[widgetClass].groups ) ) ) {
				// This widget is in the filter group
				showWidget = true;
			} else {
				// This widget is not in the filter group
				showWidget = false;
			}

			// This can probably be done with a more intelligent operator
			if ( showWidget ) {

				if ( ! _.isUndefined( filter.search ) && filter.search !== '' ) {
					// Check if the widget title contains the search term
					if ( widgetData.title.toLowerCase().indexOf( filter.search.toLowerCase() ) === - 1 ) {
						showWidget = false;
					}
				}

			}

			if ( showWidget ) {
				$$.show();
			} else {
				$$.hide();
			}
		} );

		// Balance the tags after filtering
		this.balanceWidgetHeights();
	},

	/**
	 * Add the widget to the current builder
	 *
	 * @param e
	 */
	widgetClickHandler: function ( e ) {
		// Add the history entry
		this.builder.trigger('before_user_adds_widget');
		this.builder.addHistoryEntry( 'widget_added' );

		var $w = $( e.currentTarget );

		var widget = new panels.model.widget( {
			class: $w.data( 'class' )
		} );

		// Add the widget to the cell model
		widget.cell = this.builder.getActiveCell();
		widget.cell.get('widgets').add( widget );

		this.closeDialog();
		this.builder.model.refreshPanelsData();

		this.builder.trigger('after_user_adds_widget', widget);
	},

	/**
	 * Balance widgets in a given row so they have enqual height.
	 * @param e
	 */
	balanceWidgetHeights: function ( e ) {
		var widgetRows = [[]];
		var previousWidget = null;

		// Work out how many widgets there are per row
		var perRow = Math.round( this.$( '.widget-type' ).parent().width() / this.$( '.widget-type' ).width() );

		// Add clears to create balanced rows
		this.$( '.widget-type' )
			.css( 'clear', 'none' )
			.filter( ':visible' )
			.each( function ( i, el ) {
				if ( i % perRow === 0 && i !== 0 ) {
					$( el ).css( 'clear', 'both' );
				}
			} );

		// Group the widgets into rows
		this.$( '.widget-type-wrapper' )
			.css( 'height', 'auto' )
			.filter( ':visible' )
			.each( function ( i, el ) {
				var $el = $( el );
				if ( previousWidget !== null && previousWidget.position().top !== $el.position().top ) {
					widgetRows[widgetRows.length] = [];
				}
				previousWidget = $el;
				widgetRows[widgetRows.length - 1].push( $el );
			} );

		// Balance the height of the widgets within the row.
		_.each( widgetRows, function ( row, i ) {
			var maxHeight = _.max( row.map( function ( el ) {
				return el.height();
			} ) );
			// Set the height of each widget in the row
			_.each( row, function ( el ) {
				el.height( maxHeight );
			} );

		} );
	}
} );
