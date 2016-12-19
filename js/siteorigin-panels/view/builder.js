var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {

	// Config options
	config: {},

	template: _.template( $( '#siteorigin-panels-builder' ).html().panelsProcessTemplate() ),
	dialogs: {},
	rowsSortable: null,
	dataField: false,
	currentData: '',

	attachedToEditor: false,
	liveEditor: undefined,
	menu: false,

	events: {
		'click .so-tool-button.so-widget-add': 'displayAddWidgetDialog',
		'click .so-tool-button.so-row-add': 'displayAddRowDialog',
		'click .so-tool-button.so-prebuilt-add': 'displayAddPrebuiltDialog',
		'click .so-tool-button.so-history': 'displayHistoryDialog',
		'click .so-tool-button.so-live-editor': 'displayLiveEditor'
	},

	/* A row collection */
	rows: null,

	/**
	 * Initialize the builder
	 */
	initialize: function ( options ) {
		var builder = this;

		this.config = _.extend( {
			loadLiveEditor: false,
			builderSupports : {}
		}, options.config);

		this.config.builderSupports = _.extend( {
			addRow: true,
			editRow: true,
			deleteRow: true,
			moveRow: true,
			addWidget: true,
			editWidget: true,
			deleteWidget: true,
			moveWidget: true,
			prebuilt: true,
			history: true,
			liveEditor: true,
			revertToEditor: true
		}, this.config.builderSupports );

		// Automatically load the live editor as soon as it's ready
		if( options.config.loadLiveEditor ) {
			this.on( 'builder_live_editor_added', function(){
				this.displayLiveEditor();
			} );
			}

		// Now lets create all the dialog boxes that the main builder interface uses
		this.dialogs = {
			widgets: new panels.dialog.widgets(),
			row: new panels.dialog.row(),
			prebuilt: new panels.dialog.prebuilt()
		};

		// Set the builder for each dialog and render it.
		_.each( this.dialogs, function ( p, i, d ) {
			d[i].setBuilder( builder );
		} );

		this.dialogs.row.setRowDialogType( 'create' );

		// This handles a new row being added to the collection - we'll display it in the interface
		this.model.rows.on( 'add', this.onAddRow, this );

		// Reflow the entire builder when ever the
		$( window ).resize( function ( e ) {
			if ( e.target === window ) {
				builder.trigger( 'builder_resize' );
			}
		} );

		// When the data changes in the model, store it in the field
		this.model.on( 'change:data load_panels_data', this.storeModelData, this );

		// Handle a content change
		this.on( 'content_change', this.handleContentChange, this );
		this.on( 'display_builder', this.handleDisplayBuilder, this );
		this.on( 'builder_rendered builder_resize', this.handleBuilderSizing, this );
		this.model.on( 'change:data load_panels_data', this.toggleWelcomeDisplay, this );

		// Create the context menu for this builder
		this.menu = new panels.utils.menu( {} );
		this.menu.on( 'activate_context', this.activateContextMenu, this );

		return this;
	},

	/**
	 * Render the builder interface.
	 *
	 * @return {panels.view.builder}
	 */
	render: function () {
		// this.$el.html( this.template() );
		this.setElement( this.template() );
		this.$el
			.attr( 'id', 'siteorigin-panels-builder-' + this.cid )
			.addClass( 'so-builder-container' );

		this.trigger( 'builder_rendered' );

		this.$( '.so-tip-wrapper a' ).click( function( e ){
			e.preventDefault();
			var $$ = $(this).blur();
			var newwindow = window.open(
				$$.attr('href'),
				'signup-window',
				'height=450,width=650,toolbar=false'
			);
			if ( window.focus ) {
				newwindow.focus();
			}
		} );

		return this;
	},

	/**
	 * Attach the builder to the given container
	 *
	 * @param container
	 * @returns {panels.view.builder}
	 */
	attach: function ( options ) {

		options = _.extend( {
			container: false,
			dialog: false
		}, options );

		if ( options.dialog ) {
			// We're going to add this to a dialog
			this.dialog = new panels.dialog.builder();
			this.dialog.builder = this;
		} else {
			// Attach this in the standard way
			this.$el.appendTo( options.container );
			this.metabox = options.container.closest( '.postbox' );
			this.initSortable();
			this.trigger( 'attached_to_container', options.container );
		}

		this.trigger( 'builder_attached' );

		// Add support for components we have

		if( this.supports( 'liveEditor' ) ) {
			this.addLiveEditor();
		}
		if( this.supports( 'history' ) ) {
			this.addHistoryBrowser();
		}

		// Hide toolbar buttons we don't support
		var toolbar = this.$('.so-builder-toolbar');
		if( ! this.supports( 'addWidget' ) ) {
			toolbar.find('.so-widget-add' ).hide();
		}
		if( ! this.supports( 'addRow' ) ) {
			toolbar.find('.so-row-add' ).hide();
		}
		if( ! this.supports( 'prebuilt' ) ) {
			toolbar.find('.so-prebuilt-add' ).hide();
		}

		return this;
	},

	/**
	 * This will move the Page Builder meta box into the editor if we're in the post/page edit interface.
	 *
	 * @returns {panels.view.builder}
	 */
	attachToEditor: function () {
		if ( this.config.editorType !== 'tinymce' ) {
			return this;
		}

		this.attachedToEditor = true;
		var metabox = this.metabox;
		var thisView = this;

		// Handle switching between the page builder and other tabs
		$( '#wp-content-wrap .wp-editor-tabs' )
			.find( '.wp-switch-editor' )
			.click( function ( e ) {
				e.preventDefault();
				$( '#wp-content-editor-container, #post-status-info' ).show();
				// metabox.hide();
				$( '#wp-content-wrap' ).removeClass( 'panels-active' );
				$( '#content-resize-handle' ).show();
				thisView.trigger( 'hide_builder' );
			} ).end()
			.append(
			$( '<a id="content-panels" class="hide-if-no-js wp-switch-editor switch-panels">' + metabox.find( '.hndle span' ).html() + '</a>' )
				.click( function ( e ) {
					// Switch to the Page Builder interface
					e.preventDefault();

					var $$ = jQuery( this );

					// Hide the standard content editor
					$( '#wp-content-wrap, #post-status-info' ).hide();

					// Show page builder and the inside div
					metabox.show().find( '> .inside' ).show();

					// Triggers full refresh
					$( window ).resize();
					$( document ).scroll();

					thisView.trigger( 'display_builder' );

				} )
		);

		// Switch back to the standard editor
		if( this.supports( 'revertToEditor' ) ) {
			metabox.find( '.so-switch-to-standard' ).click( function ( e ) {
				e.preventDefault();

				if ( ! confirm( panelsOptions.loc.confirm_stop_builder ) ) {
					return;
				}

				// User is switching to the standard visual editor
				thisView.addHistoryEntry( 'back_to_editor' );
				thisView.model.loadPanelsData( false );

				// Switch back to the standard editor
				$( '#wp-content-wrap, #post-status-info' ).show();
				metabox.hide();

				// Resize to trigger reflow of WordPress editor stuff
				$( window ).resize();
			} ).show();
		}

		// Move the panels box into a tab of the content editor
		metabox.insertAfter( '#wp-content-wrap' ).hide().addClass( 'attached-to-editor' );

		// Switch to the Page Builder interface as soon as we load the page if there are widgets
		var data = this.model.get( 'data' );
		if ( (
			     ! _.isEmpty( data.widgets )
		     ) || (
			     ! _.isEmpty( data.grids )
		     ) ) {
			$( '#content-panels.switch-panels' ).click();
		}

		// We will also make this sticky if its attached to an editor.
		var stickToolbar = function () {
			var toolbar = thisView.$( '.so-builder-toolbar' );

			if ( thisView.$el.hasClass( 'so-display-narrow' ) ) {
				// In this case, we don't want to stick the toolbar.
				toolbar.css( {
					top: 0,
					left: 0,
					width: '100%',
					position: 'absolute'
				} );
				thisView.$el.css( 'padding-top', toolbar.outerHeight() );
				return;
			}

			var newTop = $( window ).scrollTop() - thisView.$el.offset().top;

			if ( $( '#wpadminbar' ).css( 'position' ) === 'fixed' ) {
				newTop += $( '#wpadminbar' ).outerHeight();
			}

			var limits = {
				top: 0,
				bottom: thisView.$el.outerHeight() - toolbar.outerHeight() + 20
			};

			if ( newTop > limits.top && newTop < limits.bottom ) {
				if ( toolbar.css( 'position' ) !== 'fixed' ) {
					// The toolbar needs to stick to the top, over the interface
					toolbar.css( {
						top: $( '#wpadminbar' ).outerHeight(),
						left: thisView.$el.offset().left,
						width: thisView.$el.outerWidth(),
						position: 'fixed'
					} );
				}
			} else {
				// The toolbar needs to be at the top or bottom of the interface
				toolbar.css( {
					top: Math.min( Math.max( newTop, 0 ), thisView.$el.outerHeight() - toolbar.outerHeight() + 20 ),
					left: 0,
					width: '100%',
					position: 'absolute'
				} );
			}

			thisView.$el.css( 'padding-top', toolbar.outerHeight() );
		};

		this.on( 'builder_resize', stickToolbar, this );
		$( document ).scroll( stickToolbar );
		stickToolbar();

		this.trigger('builder_attached_to_editor');

		return this;
	},

	/**
	 * Initialize the row sortables
	 */
	initSortable: function () {
		if( ! this.supports( 'moveRow' ) ) {
			return this;
		}

		// Create the sortable for the rows
		var builderView = this;

		this.rowsSortable = this.$( '.so-rows-container' ).sortable( {
			appendTo: '#wpwrap',
			items: '.so-row-container',
			handle: '.so-row-move',
			axis: 'y',
			tolerance: 'pointer',
			scroll: false,
			stop: function ( e ) {
				builderView.addHistoryEntry( 'row_moved' );

				// Sort the rows collection after updating all the indexes.
				builderView.sortCollections();
			}
		} );

		return this;
	},

	/**
	 * Refresh the row sortable
	 */
	refreshSortable: function () {
		// Refresh the sortable to account for the new row
		if ( ! _.isNull( this.rowsSortable ) ) {
			this.rowsSortable.sortable( 'refresh' );
		}
	},

	/**
	 * Set the field that's used to store the data
	 * @param field
	 */
	setDataField: function ( field, options ) {
		options = _.extend( {
			load: true
		}, options );

		this.dataField = field;
		this.dataField.data( 'builder', this );

		if ( options.load && field.val() !== '' ) {
			var data = this.dataField.val();
			try {
				data = JSON.parse( data );
			}
			catch ( err ) {
				data = {};
			}

			this.model.loadPanelsData( data );
			this.currentData = data;
			this.toggleWelcomeDisplay();
			this.sortCollections();
		}

		return this;
	},

	/**
	 * Store the model data in the data html field set in this.setDataField.
	 */
	storeModelData: function () {
		var data = JSON.stringify( this.model.get( 'data' ) );

		if ( $( this.dataField ).val() !== data ) {
			// If the data is different, set it and trigger a content_change event
			$( this.dataField ).val( data );
			$( this.dataField ).trigger( 'change' );
			this.trigger( 'content_change' );
		}
	},

	/**
	 * HAndle the visual side of adding a new row to the builder.
	 *
	 * @param row
	 * @param collection
	 * @param options
	 */
	onAddRow: function ( row, collection, options ) {
		options = _.extend( {noAnimate: false}, options );
		// Create a view for the row
		var rowView = new panels.view.row( {model: row} );
		rowView.builder = this;
		rowView.render();

		// Attach the row elements to this builder
		if ( _.isUndefined( options.at ) || collection.length <= 1 ) {
			// Insert this at the end of the widgets container
			rowView.$el.appendTo( this.$( '.so-rows-container' ) );
		} else {
			// We need to insert this at a specific position
			rowView.$el.insertAfter(
				this.$( '.so-rows-container .so-row-container' ).eq( options.at - 1 )
			);
		}

		if ( options.noAnimate === false ) {
			rowView.visualCreate();
		}

		this.refreshSortable();
		rowView.resize();
	},

	/**
	 * Display the dialog to add a new widget.
	 *
	 * @returns {boolean}
	 */
	displayAddWidgetDialog: function () {
		this.dialogs.widgets.openDialog();
	},

	/**
	 * Display the dialog to add a new row.
	 *
	 * @returns {boolean}
	 */
	displayAddRowDialog: function () {
		this.dialogs.row.openDialog();
		this.dialogs.row.setRowModel(); // Set this to an empty row model
	},

	/**
	 * Display the dialog to add prebuilt layouts.
	 *
	 * @returns {boolean}
	 */
	displayAddPrebuiltDialog: function () {
		this.dialogs.prebuilt.openDialog();
	},

	/**
	 * Display the history dialog.
	 *
	 * @returns {boolean}
	 */
	displayHistoryDialog: function () {
		this.dialogs.history.openDialog();
	},

	/**
	 * Get the model for the currently selected cell
	 */
	getActiveCell: function ( options ) {
		options = _.extend( {
			createCell: true,
			defaultPosition: 'first'
		}, options );

		if ( this.$( '.so-cells .cell' ).length === 0 ) {

			if ( options.createCell ) {
				// Create a row with a single cell
				this.model.addRow( [1], {noAnimate: true} );
			} else {
				return null;
			}

		}

		var activeCell = this.$( '.so-cells .cell.cell-selected' );

		if ( activeCell.length === 0 ) {
			if ( options.defaultPosition === 'last' ) {
				activeCell = this.$( '.so-cells .cell' ).first();
			} else {
				activeCell = this.$( '.so-cells .cell' ).last();
			}
		}

		return activeCell.data( 'view' ).model;
	},

	/**
	 * Sort all widget and row collections based on their dom position
	 */
	sortCollections: function () {

		// Give the widgets their indexes
		this.$( '.so-widget' ).each( function ( i ) {
			var $$ = $( this );
			$$.data( 'view' ).model.indexes = {
				builder: i,
				cell: $$.index()
			};
		} );

		// Give the cells their indexes
		this.$( '.so-cells .cell' ).each( function ( i ) {
			var $$ = $( this );
			$$.data( 'view' ).model.indexes = {
				builder: i,
				row: $$.index()
			};
		} );

		// Give the rows their indexes
		this.$( '.so-row-container' ).each( function ( i ) {
			var $$ = $( this );
			$$.data( 'view' ).model.indexes = {
				builder: i,
			};
		} );


		// Sort the rows by their visual index
		this.model.rows.visualSort();

		// Sort the widget collections by their visual index
		this.model.rows.each( function ( row ) {
			row.cells.each( function ( cell ) {
				cell.widgets.visualSort();
			} );
		} );

		// Update the builder model to reflect the newly ordered data.
		this.model.refreshPanelsData();
	},

	/**
	 * Add a live editor to the builder
	 *
	 * @returns {panels.view.builder}
	 */
	addLiveEditor: function ( ) {
		if( _.isEmpty( this.config.liveEditorPreview ) ) {
			return this;
		}

		// Create the live editor and set the builder to this.
		this.liveEditor = new panels.view.liveEditor( {
			builder: this,
			previewUrl: this.config.liveEditorPreview
		} );

		// Display the live editor button in the toolbar
		if ( this.liveEditor.hasPreviewUrl() ) {
			this.$( '.so-builder-toolbar .so-live-editor' ).show();
		}

		this.trigger('builder_live_editor_added');

		return this;
	},

	/**
	 * Show the current live editor
	 */
	displayLiveEditor: function () {
		if ( _.isUndefined( this.liveEditor ) ) {
			return;
		}

		this.liveEditor.open();
	},

	/**
	 * Add the history browser.
	 *
	 * @return {panels.view.builder}
	 */
	addHistoryBrowser: function () {
		if( _.isEmpty( this.config.liveEditorPreview ) ) {
			return this;
		}

		this.dialogs.history = new panels.dialog.history();
		this.dialogs.history.builder = this;
		this.dialogs.history.entries.builder = this.model;

		// Set the revert entry
		this.dialogs.history.setRevertEntry( this.model );

		// Display the live editor button in the toolbar
		this.$( '.so-builder-toolbar .so-history' ).show();
	},

	/**
	 * Add an entry.
	 *
	 * @param text
	 * @param data
	 */
	addHistoryEntry: function ( text, data ) {
		if ( _.isUndefined( data ) ) {
			data = null;
		}

		if ( ! _.isUndefined( this.dialogs.history ) ) {
			this.dialogs.history.entries.addEntry( text, data );
		}
	},

	supports: function( thing ){

		if( thing === 'rowAction' ) {
			// Check if this supports any row action
			return this.supports( 'addRow' ) || this.supports( 'editRow' ) || this.supports( 'deleteRow' );
		} else if ( thing === 'widgetAction' ) {
			// Check if this supports any widget action
			return this.supports( 'addWidget' ) || this.supports( 'editWidget' ) || this.supports( 'deleteWidget' );
		}

		return _.isUndefined( this.config.builderSupports[ thing ] ) ? false : this.config.builderSupports[ thing ];
	},

	/**
	 * Handle a change of the content
	 */
	handleContentChange: function () {

		// Make sure we actually need to copy content.
		if ( panelsOptions.copy_content && this.attachedToEditor && this.$el.is( ':visible' ) ) {

			var panelsData = this.model.getPanelsData();
			if( ! _.isEmpty( panelsData.widgets ) ) {
				// We're going to create a copy of page builder content into the post content
				$.post(
					panelsOptions.ajaxurl,
					{
						action: 'so_panels_builder_content',
						panels_data: JSON.stringify( panelsData ),
						post_id: this.config.postId
					},
					function ( content ) {

						// Strip all the known layout divs
						var t = $( '<div />' ).html( content );
						t.find( 'div' ).each( function () {
							var c = $( this ).contents();
							$( this ).replaceWith( c );
						} );

						content = t.html()
							.replace( /[\r\n]+/g, "\n" )
							.replace( /\n\s+/g, "\n" )
							.trim();

						if( content !== '' ) {
							this.updateEditorContent( content );
						}
					}.bind( this )
				);
			}
		}
	},

	/**
	 * Update editor content with the given content.
	 *
	 * @param content
	 */
	updateEditorContent: function ( content ) {
		// Switch back to the standard editor
		if ( this.config.editorType !== 'tinyMCE' || typeof tinyMCE === 'undefined' || _.isNull( tinyMCE.get( "content" ) ) ) {
			var $editor = $( this.config.editorId );
			$editor.val( content ).trigger( 'change' ).trigger( 'keyup' );
		} else {
			var contentEd = tinyMCE.get( "content" );

			contentEd.setContent( content );

			contentEd.fire( 'change' );
			contentEd.fire( 'keyup' );
		}

		this.triggerYoastSeoChange();
	},

	/**
	 * Trigger a change on Yoast SEO
	 */
	triggerYoastSeoChange: function () {
		if ( $( '#yoast_wpseo_focuskw_text_input' ).length ) {
			var element = document.getElementById( 'yoast_wpseo_focuskw_text_input' ), event;

			if ( document.createEvent ) {
				event = document.createEvent( "HTMLEvents" );
				event.initEvent( "keyup", true, true );
			} else {
				event = document.createEventObject();
				event.eventType = "keyup";
			}

			event.eventName = "keyup";

			if ( document.createEvent ) {
				element.dispatchEvent( event );
			} else {
				element.fireEvent( "on" + event.eventType, event );
			}
		}
	},

	/**
	 * Handle displaying the builder
	 */
	handleDisplayBuilder: function () {
		var editorContent = '';
		var editor;

		if ( typeof tinyMCE !== 'undefined' ) {
			editor = tinyMCE.get( 'content' );
		}
		if ( editor && _.isFunction( editor.getContent ) ) {
			editorContent = editor.getContent();
		} else {
			editorContent = $( 'textarea#content' ).val();
		}

		if (
			(
				_.isEmpty( this.model.get( 'data' ) ) ||
				( _.isEmpty( this.model.get( 'data' ).widgets ) && _.isEmpty( this.model.get( 'data' ).grids ) )
			) &&
			editorContent !== ''
		) {
			// Confirm that the user wants to copy their content to Page Builder.
			if ( ! confirm( panelsOptions.loc.confirm_use_builder ) ) {
				return;
			}

			var widgetClass = '';
			// There is a small chance a theme will have removed this, so check
			if ( ! _.isUndefined( panelsOptions.widgets.SiteOrigin_Widget_Editor_Widget ) ) {
				widgetClass = 'SiteOrigin_Widget_Editor_Widget';
			}
			else if ( ! _.isUndefined( panelsOptions.widgets.WP_Widget_Text ) ) {
				widgetClass = 'WP_Widget_Text';
			}

			if ( widgetClass === '' ) {
				return;
			}

			// Create the existing page content in a single widget
			this.model.loadPanelsData( {
				grid_cells: [{grid: 0, weight: 1}],
				grids: [{cells: 1}],
				widgets: [
					{
						filter: "1",
						text: editorContent,
						title: "",
						type: "visual",
						panels_info: {
							class: widgetClass,
							raw: false,
							grid: 0,
							cell: 0
						}
					}
				]
			} );
			this.model.trigger( 'change' );
			this.model.trigger( 'change:data' );
		}
	},

	handleBuilderSizing: function () {
		var width = this.$el.width();

		if ( ! width ) {
			return this;
		}

		if ( width < 480 ) {
			this.$el.addClass( 'so-display-narrow' );
		} else {
			this.$el.removeClass( 'so-display-narrow' );
		}

	},

	/**
	 * Set the parent dialog for all the dialogs in this builder.
	 *
	 * @param text
	 * @param dialog
	 */
	setDialogParents: function ( text, dialog ) {
		_.each( this.dialogs, function ( p, i, d ) {
			d[i].setParent( text, dialog );
		} );

		// For any future dialogs
		this.on( 'add_dialog', function ( newDialog ) {
			newDialog.setParent( text, dialog );
		}, this );
	},

	/**
	 * This shows or hides the welcome display depending on whether there are any rows in the collection.
	 */
	toggleWelcomeDisplay: function () {
		if ( ! this.model.rows.isEmpty() ) {
			this.$( '.so-panels-welcome-message' ).hide();
		} else {
			this.$( '.so-panels-welcome-message' ).show();
		}
	},

	activateContextMenu: function ( e, menu ) {
		var builder = this;

		// Of all the visible builders, find the topmost
		var topmostBuilder = $( '.siteorigin-panels-builder:visible' )
			.sort( function ( a, b ) {
				return $( a ).zIndex() > $( b ).zIndex() ? 1 : - 1;
			} )
			.last();

		var topmostDialog = $( '.so-panels-dialog-wrapper:visible' )
			.sort( function ( a, b ) {
				return $( a ).zIndex() > $( b ).zIndex() ? 1 : - 1;
			} )
			.last();

		var closestDialog = builder.$el.closest('.so-panels-dialog-wrapper');

		// Only run this if its element is the topmost builder, in the topmost dialog
		if (
			builder.$el.is( topmostBuilder ) &&
			(
				topmostDialog.length === 0 ||
				topmostDialog.is( closestDialog )
			)
		) {
			// Get the element we're currently hovering over
			var over = $( [] )
				.add( builder.$( '.so-rows-container > .so-row-container' ) )
				.add( builder.$( '.so-cells > .cell' ) )
				.add( builder.$( '.cell-wrapper > .so-widget' ) )
				.filter( function ( i ) {
					return menu.isOverEl( $( this ), e );
				} );

			var activeView = over.last().data( 'view' );
			if ( activeView !== undefined && activeView.buildContextualMenu !== undefined ) {
				// We'll pass this to the current active view so it can popular the contextual menu
				activeView.buildContextualMenu( e, menu );
			}
		}
	},

	/**
	 * Lock window scrolling for the main overlay
	 */
	lockPageScroll: function () {
		if ( $( 'body' ).css( 'overflow' ) === 'hidden' ) {
			return;
		}

		// lock scroll position, but retain settings for later
		var scrollPosition = [
			self.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft,
			self.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop
		];

		$( 'body' )
			.data( {
				'scroll-position': scrollPosition
			} )
			.css( 'overflow', 'hidden' );

		if( ! _.isUndefined( scrollPosition ) ) {
			window.scrollTo( scrollPosition[0], scrollPosition[1] );
		}
	},

	/**
	 * Unlock window scrolling
	 */
	unlockPageScroll: function () {
		if ( $( 'body' ).css( 'overflow' ) !== 'hidden' ) {
			return;
		}

		// Check that there are no more dialogs or a live editor
		if ( ! $( '.so-panels-dialog-wrapper' ).is( ':visible' ) && ! $( '.so-panels-live-editor' ).is( ':visible' ) ) {
			$( 'body' ).css( 'overflow', 'visible' );
			var scrollPosition = $( 'body' ).data( 'scroll-position' );

			if( ! _.isUndefined( scrollPosition ) ) {
				window.scrollTo( scrollPosition[0], scrollPosition[1] );
			}
		}
	}

} );
