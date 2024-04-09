class SiteOriginPanelsLayoutBlock extends wp.element.Component {
	constructor( props ) {
		super( props );

		const hasPanelsData = typeof props.panelsData === 'object' && Object.keys( props.panelsData ).length > 0;
		const isDefaultModeEdit = window.soPanelsBlockEditorAdmin.defaultMode === 'edit';
		const editMode = hasPanelsData === true ? isDefaultModeEdit : true;

		this.state = {
			editing: editMode,
			loadingPreview: ! editMode,
			previewHtml: '',
			previewInitialized: ! editMode,
			pendingPreviewRequest: false,
			panelsInitialized: false,
		};
		this.panelsContainer = wp.element.createRef();
		this.previewContainer = wp.element.createRef();
		this.fetchPreviewTimer;
	}

	componentDidMount() {
		this.isStillMounted = true;

		if ( this.state.editing ) {
			this.setupPanels();
		} else if ( ! this.state.editing && ! this.previewInitialized ) {
			clearTimeout( this.fetchPreviewTimer );
			var current = this;
			this.fetchPreviewTimer = setTimeout( function() {
				current.fetchPreview( current.props );
			}, 1000 );
		}
	}

	componentWillUnmount() {
		this.isStillMounted = false;
		if ( this.builderView ) {
			this.builderView.off( 'content_change' );
		}
	}

	componentDidUpdate( prevProps ) {
		if ( this.state.editing && ! this.state.panelsInitialized ) {
			this.setupPanels();
		} else if ( this.state.loadingPreview ) {
        	if ( ! this.state.pendingPreviewRequest ) {
				this.setState({
					pendingPreviewRequest: true,
				} );
				clearTimeout(this.fetchPreviewTimer);
				var current = this;
				this.fetchPreviewTimer = setTimeout( function() {
					current.fetchPreview( current.props );
				}, 1000 );
        	}
		} else if ( ! this.state.previewInitialized ) {
			jQuery( document ).trigger( 'panels_setup_preview' );
			this.setState( {
				previewInitialized: true,
			} );
		}
	}

	setupPanels() {
		// Should we set up panels?
		if ( ! this.state.editing || this.state.panelsInitialized ) {
			return;
		  }

		var $panelsContainer = jQuery( this.panelsContainer.current );

		var config = {
			editorType: 'standalone',
	        loadLiveEditor: false,
	        postId: window.soPanelsBlockEditorAdmin.postId,
	        editorPreview: window.soPanelsBlockEditorAdmin.liveEditor,
		};

		var builderModel = new panels.model.builder();

		this.builderView = new panels.view.builder( {
			model: builderModel,
			config: config
		} );

		// Make sure panelsData is defined and clone so that we don't alter the underlying attribute.
		var panelsData = JSON.parse( JSON.stringify( jQuery.extend( {}, this.props.panelsData ) ) );

		// Disable block selection while dragging rows or widgets.
		let rowOrWidgetMouseDown = () => {
			if ( typeof this.props.onRowOrWidgetMouseDown === 'function' ) {
				this.props.onRowOrWidgetMouseDown();
			}
			let rowOrWidgetMouseUp = () => {
				jQuery( document ).off( 'mouseup', rowOrWidgetMouseUp );
				if ( typeof this.props.onRowOrWidgetMouseUp === 'function' ) {
					this.props.onRowOrWidgetMouseUp();
				}
			};
			jQuery( document ).on( 'mouseup', rowOrWidgetMouseUp );
		};

		this.builderView.on( 'row_added', () => {
			this.builderView.$( '.so-row-move' ).off( 'mousedown', rowOrWidgetMouseDown );
			this.builderView.$( '.so-row-move' ).on( 'mousedown', rowOrWidgetMouseDown );
			this.builderView.$( '.so-widget' ).off( 'mousedown', rowOrWidgetMouseDown );
			this.builderView.$( '.so-widget' ).on( 'mousedown', rowOrWidgetMouseDown );
		} );

		this.builderView.on( 'widget_added', () => {
			this.builderView.$( '.so-widget' ).off( 'mousedown', rowOrWidgetMouseDown );
			this.builderView.$( '.so-widget' ).on( 'mousedown', rowOrWidgetMouseDown );
		} );

		this.builderView
		.render()
		.attach( {
			container: $panelsContainer
		} )
		.setData( panelsData );

		this.builderView.trigger( 'builder_resize' );

		/**
		 * Checks if two panels data objects are equal.
		 * @param {Object} newPanelsData - The new panels data object.
		 * @param {Object} oldPanelsData - The old panels data object.
		 * @returns {boolean} - Returns true if the two panels data objects are equal, otherwise false.
		 */
		var SiteOriginIsPanelsEqual = function( newPanelsData, oldPanelsData ) {
			if ( newPanelsData === oldPanelsData ) {
				return true;
			}

			if (
				! newPanelsData ||
				! oldPanelsData ||
				(
					typeof newPanelsData !== 'object' &&
					typeof oldPanelsData !== 'object'
				)
			) {
				return newPanelsData === oldPanelsData;
			}

			var keys = Object.keys( newPanelsData );
			if ( keys.length !== Object.keys( oldPanelsData ).length ) {
				return false;
			}

			return keys.every( k => SiteOriginIsPanelsEqual(
				newPanelsData[ k ], oldPanelsData[ k ]
			) );
		}

		this.builderView.on( 'content_change', () => {
			const newPanelsData = this.builderView.getData();
			this.panelsDataChanged = ! SiteOriginIsPanelsEqual( panelsData, newPanelsData );

			if ( this.panelsDataChanged ) {
				if (
					this.props.onContentChange &&
					typeof this.props.onContentChange === 'function'
				) {
					this.props.onContentChange( newPanelsData );
				}
				this.setState( { loadingPreview: true, previewHtml: '' } );
			}
		} );


		jQuery( document ).trigger( 'panels_setup', this.builderView );

		if ( typeof window.soPanelsBuilderView == 'undefined' ) {
			window.soPanelsBuilderView = [];
		}
		window.soPanelsBuilderView.push( this.builderView );

		this.setState({
			panelsInitialized: true
		} );
	}

	fetchPreview( props ) {
		if ( ! this.isStillMounted ) {
			return;
		}

		// If we don't have panelsData yet, fetch it from PB directly.
		var panelsData = props.panelsData === null ? this.builderView.getData() : props.panelsData;

		this.setState( {
			previewInitialized: false,
		} );

		const fetchRequest = this.currentFetchRequest = jQuery.post( {
			url: window.soPanelsBlockEditorAdmin.previewUrl,
			data: {
				action: 'so_panels_layout_block_preview',
				panelsData: JSON.stringify( panelsData ),
			}
		} )
		.then( ( preview ) => {
			if (
				this.isStillMounted &&
				fetchRequest === this.currentFetchRequest &&
				preview
			) {
				this.setState( {
					previewHtml: preview,
					loadingPreview: false,
            		previewInitialized: false,
            		pendingPreviewRequest: false,
				} );
			}
		} );
		return fetchRequest;
	}

	render() {
		const { panelsData } = this.props;

		let switchToEditing = () => {
			this.setState( { editing: true } );
		}

		let switchToPreview = () => {
			if ( panelsData ) {
				this.setState({
					editing: false,
				});
			}
		}

		return (
			<wp.element.Fragment>
				<wp.blockEditor.BlockControls>
				<wp.components.Toolbar label={ wp.i18n.__( 'Page Builder Mode.', 'siteorigin-panels' ) }>
					{this.state.editing ? (
					<wp.components.ToolbarButton
						icon="visibility"
						className="components-icon-button components-toolbar__control"
						label={ wp.i18n.__( 'Preview layout.', 'siteorigin-panels' ) }
						onClick={ switchToPreview }
					/>
					) : (
					<wp.components.ToolbarButton
						icon="edit"
						className="components-icon-button components-toolbar__control"
						label={ wp.i18n.__( 'Edit layout.', 'siteorigin-panels' ) }
						onClick={ switchToEditing }
					/>
					)}
				</wp.components.Toolbar>
				</wp.blockEditor.BlockControls>
				<div
				key="layout-block"
				className="siteorigin-panels-layout-block-container"
				ref={ this.panelsContainer }
				hidden={ ! this.state.editing }
				/>
				<div
					key="preview"
					className="so-panels-block-layout-preview-container"
					hidden={ this.state.editing }
				>
				{ this.state.loadingPreview ? (
					<div className="so-panels-spinner-container">
						<span><wp.components.Spinner/></span>
					</div>
				) : (
					<div className="so-panels-raw-html-container" ref={ this.previewContainer }>
						<wp.element.RawHTML>{ this.state.previewHtml }</wp.element.RawHTML>
					</div>
				) }
				</div>
			</wp.element.Fragment>
		);
	}

}

var hasLayoutCategory = wp.blocks.getCategories().some( function( category ) {
	return category.slug === 'layout';
} );

wp.blocks.registerBlockType( 'siteorigin-panels/layout-block', {
	title: wp.i18n.__( 'SiteOrigin Layout', 'siteorigin-panels' ),

	description: wp.i18n.__( "Build a layout using SiteOrigin's Page Builder.", 'siteorigin-panels' ),

	icon () {
		return <span className="siteorigin-panels-block-icon"/>;
	},

	category: hasLayoutCategory ? 'layout' : 'design',

	keywords: [ 'page builder', 'column,grid', 'panel' ],

	supports: {
		html: false,
	},

	attributes: {
		panelsData: {
			type: 'object',
		},
		contentPreview: {
			type: 'string',
		}
	},

	edit( { attributes, setAttributes, toggleSelection } ) {
		let onLayoutBlockContentChange = ( newPanelsData ) => {

			if (
				typeof newPanelsData.widgets === 'object' &&
				Object.keys( newPanelsData.widgets ).length > 0
			) {
				// Send panelsData to server for sanitization.
				var isNewWPBlockEditor = jQuery( '.widgets-php' ).length;
				if ( ! isNewWPBlockEditor ) {
					wp.data.dispatch( 'core/editor' ).lockPostSaving();
				}

				jQuery.post(
					panelsOptions.ajaxurl,
					{
						action: 'so_panels_builder_content_json',
						panels_data: JSON.stringify( newPanelsData ),
						post_id: ! isNewWPBlockEditor ? wp.data.select("core/editor").getCurrentPostId() : ''
					},
					function( content ) {
						let panelsAttributes = {};
						if ( content.sanitized_panels_data !== '' ) {
							panelsAttributes.panelsData = content.sanitized_panels_data;
						}
						if ( content.preview !== '' ) {
							panelsAttributes.contentPreview = content.preview;
						}

						setAttributes( panelsAttributes );

						if ( ! isNewWPBlockEditor ) {
							wp.data.dispatch( 'core/editor' ).unlockPostSaving();
						}
					}
				);
			} else {
				setAttributes( {
					panelsData: null,
					contentPreview: null,
				} );
			}
		};

		let disableSelection = ( ) => {
			toggleSelection( false );
		};

		let enableSelection = ( ) => {
			toggleSelection( true );
		};

		return (
			<SiteOriginPanelsLayoutBlock
				panelsData={attributes.panelsData}
				onContentChange={onLayoutBlockContentChange}
				onRowOrWidgetMouseDown={disableSelection}
				onRowOrWidgetMouseUp={enableSelection}
			/>
		);
	}
} );

( ( jQuery ) => {
	// Resolve Block Editor warning for SO Layout Block.
	let unsubscribe = null;
	unsubscribe = wp.data.subscribe( () => {
		let isEditorReady = false;

		if ( wp.data.select( 'core/block-editor' ) ) {
			isEditorReady = wp.data.select( 'core/block-editor' ).hasInserterItems();
		} else if( wp.data.select( 'core/editor' ) ) {
			isEditorReady = wp.data.select( 'core/editor' ).__unstableIsEditorReady();
		}

		if ( isEditorReady && unsubscribe ) {
			unsubscribe();
			setTimeout( function () {
				jQuery( '.wp-block[data-type="siteorigin-panels/layout-block"].has-warning .block-editor-warning__action .components-button' ).trigger( 'click' );
			}, 250 );
		}
	} );

	if ( window.soPanelsBlockEditorAdmin.showAddButton ) {
		jQuery( () => {
			setTimeout( () => {
				const editorDispatch = wp.data.dispatch( 'core/editor' );
				const editorSelect = wp.data.select( 'core/editor' );
				var tmpl = jQuery( '#siteorigin-panels-add-layout-block-button' ).html();
				if ( jQuery( '.block-editor-writing-flow > .block-editor-block-list__layout' ).length ) {
					// > WP 5.7
					var buttonSelector = '.block-editor-writing-flow > .block-editor-block-list__layout';
				} else {
					// < WP 5.7
					var buttonSelector = '.editor-writing-flow > div:first, .block-editor-writing-flow > div:not([tabindex])';
				}
				var $addButton = jQuery( tmpl ).appendTo( buttonSelector );
				$addButton.on( 'click', () => {
					var layoutBlock = wp.blocks.createBlock( 'siteorigin-panels/layout-block', {} );
					const isEmpty = editorSelect.isEditedPostEmpty();
					if ( isEmpty ) {
						const blocks = editorSelect.getBlocks();
						if ( blocks.length ) {
							editorDispatch.replaceBlock( blocks[0].clientId, layoutBlock );
						} else {
							editorDispatch.insertBlock( layoutBlock );
						}
					} else {
						editorDispatch.insertBlock( layoutBlock );
					}
				} );
				let hideButtonIfBlocks = () => {
					const isEmpty = wp.data.select( 'core/editor' ).isEditedPostEmpty();
					if ( isEmpty ) {
						$addButton.show();
					} else {
						$addButton.hide();
					}
				};
				wp.data.subscribe( hideButtonIfBlocks );
				hideButtonIfBlocks();
			}, 100 );
		} );
	}

} )( jQuery );

// Detect preview mode changes, and trigger resize.
jQuery( document ).on( 'click', '.block-editor-post-preview__button-resize', function( e ) {
	if ( ! jQuery( this ).hasClass('has-icon') ) {
		jQuery( window ).trigger( 'resize' );
	}
} );
