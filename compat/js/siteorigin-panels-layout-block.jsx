/**
 * Checks if two panels data objects are equal.
 * @param {Object} newPanelsData - The new panels data object.
 * @param {Object} oldPanelsData - The old panels data object.
 * @returns {boolean} - Returns true if the two panels data objects are equal, otherwise false.
 */
function SiteOriginIsPanelsEqual( newPanelsData, oldPanelsData ) {
	if ( newPanelsData === oldPanelsData ) {
		return true;
	}

	if ( ! newPanelsData || ! oldPanelsData ) {
		return newPanelsData === oldPanelsData;
	}

	// If neither newPanelsData nor oldPanelsData are objects, assume they're not the same.
	if (
		typeof( newPanelsData ) !== 'object' ||
		typeof( oldPanelsData ) !== 'object'
	) {
		return false;
	}

	var keys = Object.keys( newPanelsData );
	if ( keys.length !== Object.keys( oldPanelsData ).length ) {
		return false;
	}

	return keys.every( k => SiteOriginIsPanelsEqual(
		newPanelsData[ k ], oldPanelsData[ k ]
	) );
}

function SiteOriginPanelsLayoutBlock( props ) {
	const { editing, panelsData, onContentChange, onRowOrWidgetMouseDown, onRowOrWidgetMouseUp } = props;

	// State
	const [ loadingPreview, setLoadingPreview ] = wp.element.useState( true );
	const [ previewHtml, setPreviewHtml ] = wp.element.useState( '' );
	const [ previewInitialized, setPreviewInitialized ] = wp.element.useState( ! editing );
	const [ pendingPreviewRequest, setPendingPreviewRequest ] = wp.element.useState( false );
	const [ panelsInitialized, setPanelsInitialized ] = wp.element.useState( false );

	// DOM and instance refs
	const panelsContainer = wp.element.useRef( null );
	const previewContainer = wp.element.useRef( null );
	const builderViewRef = wp.element.useRef( null );
	const fetchPreviewTimer = wp.element.useRef( null );
	const currentFetchRequest = wp.element.useRef( null );
	const isMountedRef = wp.element.useRef( false );

	// Keep prop callbacks current so the mount-only setup effect always calls the latest version.
	const onContentChangeRef = wp.element.useRef( onContentChange );
	const onRowOrWidgetMouseDownRef = wp.element.useRef( onRowOrWidgetMouseDown );
	const onRowOrWidgetMouseUpRef = wp.element.useRef( onRowOrWidgetMouseUp );
	wp.element.useEffect( () => { onContentChangeRef.current = onContentChange; }, [ onContentChange ] );
	wp.element.useEffect( () => { onRowOrWidgetMouseDownRef.current = onRowOrWidgetMouseDown; }, [ onRowOrWidgetMouseDown ] );
	wp.element.useEffect( () => { onRowOrWidgetMouseUpRef.current = onRowOrWidgetMouseUp; }, [ onRowOrWidgetMouseUp ] );

	// Fetch a preview from the server and update state.
	const fetchPreview = wp.element.useCallback( () => {
		if ( ! isMountedRef.current ) {
			return;
		}

		setPreviewInitialized( false );

		// Capture the iframe document now so the deferred trigger uses the correct context.
		const iframeDoc = panelsContainer.current
			? panelsContainer.current.ownerDocument
			: document;

		const fetchRequest = jQuery.post( {
			url: window.soPanelsBlockEditorAdmin.previewUrl,
			data: {
				action: 'so_panels_layout_block_preview',
				panelsData: JSON.stringify( builderViewRef.current.getData() ),
			}
		} )
		.then( ( preview ) => {
			if ( ! isMountedRef.current ) {
				return;
			}

			// Wait until previewHTML has finished updating to cut
			// down on the chance of nothing being rendered.
			setTimeout( function() {
				jQuery( iframeDoc ).trigger( 'panels_setup_preview' );
			}, 1000 );

			if ( fetchRequest === currentFetchRequest.current && preview ) {
				setPreviewHtml( preview );
				setLoadingPreview( false );
				setPreviewInitialized( false );
				setPendingPreviewRequest( false );
			}
		} );

		currentFetchRequest.current = fetchRequest;
		return fetchRequest;
	}, [] );

	// Setup the panels builder on mount; tear it down on unmount.
	wp.element.useEffect( () => {
		isMountedRef.current = true;

		// Resolve iframe document and whether script/content run inside an iframe.
		const iframeDoc = panelsContainer.current.ownerDocument;
		const isScriptInIframe = window.self !== window.top;
		const isContentInIframe = iframeDoc !== window.document;
		const soDocument = iframeDoc;

		// Block native HTML5 dragstart so the Block Editor doesn't intercept panel drags.
		var onContainerDragStart = function( e ) {
			e.stopPropagation();
			e.preventDefault();
		};
		panelsContainer.current.addEventListener( 'dragstart', onContainerDragStart );

		var $panelsContainer = jQuery( panelsContainer.current );

		var config = {
			editorType: 'standalone',
			loadLiveEditor: false,
			postId: window.soPanelsBlockEditorAdmin.postId,
			editorPreview: window.soPanelsBlockEditorAdmin.liveEditor,
		};

		var builderModel = new panels.model.builder();

		builderViewRef.current = new panels.view.builder( {
			model: builderModel,
			config: config
		} );

		// Make sure panelsData is defined and clone so that we don't alter the underlying attribute.
		var initialPanelsData = JSON.parse( JSON.stringify( jQuery.extend( {}, panelsData ) ) );

		// Disable block selection while dragging rows or widgets.
		let rowOrWidgetMouseDown = ( e ) => {
			// toggleSelection(false) tells the block editor to not start its own drag-selection
			// handling. Do NOT stopPropagation here — jQuery UI sortable binds its mousedown
			// handler on the sortable container (an ancestor), so stopping propagation would
			// prevent jQuery UI from ever seeing the event and starting the drag.
			if ( typeof onRowOrWidgetMouseDownRef.current === 'function' ) {
				onRowOrWidgetMouseDownRef.current();
			}
			let rowOrWidgetMouseUp = () => {
				jQuery( soDocument ).off( 'mouseup', rowOrWidgetMouseUp );
				if ( typeof onRowOrWidgetMouseUpRef.current === 'function' ) {
					onRowOrWidgetMouseUpRef.current();
				}
			};
			jQuery( soDocument ).on( 'mouseup', rowOrWidgetMouseUp );
		};

		builderViewRef.current.on( 'row_added', () => {
			builderViewRef.current.$( '.so-row-move' ).off( 'mousedown', rowOrWidgetMouseDown );
			builderViewRef.current.$( '.so-row-move' ).on( 'mousedown', rowOrWidgetMouseDown );
			builderViewRef.current.$( '.so-widget' ).off( 'mousedown', rowOrWidgetMouseDown );
			builderViewRef.current.$( '.so-widget' ).on( 'mousedown', rowOrWidgetMouseDown );
		} );

		builderViewRef.current.on( 'widget_added', () => {
			builderViewRef.current.$( '.so-widget' ).off( 'mousedown', rowOrWidgetMouseDown );
			builderViewRef.current.$( '.so-widget' ).on( 'mousedown', rowOrWidgetMouseDown );
		} );

		builderViewRef.current
		.render()
		.attach( {
			container: $panelsContainer
		} )
		.setData( initialPanelsData );

		builderViewRef.current.trigger( 'builder_resize' );

		builderViewRef.current.on( 'content_change', () => {
			const newPanelsData = builderViewRef.current.getData();

			if ( ! SiteOriginIsPanelsEqual( initialPanelsData, newPanelsData ) ) {
				if ( typeof onContentChangeRef.current === 'function' ) {
					onContentChangeRef.current( newPanelsData );
				}
				setLoadingPreview( true );
				setPreviewHtml( '' );
			}
		} );

		// Use iframeDoc so panels scripts inside the iframe receive the setup event.
		jQuery( iframeDoc ).trigger( 'panels_setup', builderViewRef.current );

		if ( typeof window.soPanelsBuilderView === 'undefined' ) {
			window.soPanelsBuilderView = [];
		}
		window.soPanelsBuilderView.push( builderViewRef.current );

		// If in an iframe, patch jQuery UI instances so their document/window use iframeDoc.
		if ( isContentInIframe || isScriptInIframe ) {
			const iframeWindow = iframeDoc.defaultView;
			const patchJQueryUIDocuments = () => {
				if ( ! builderViewRef.current ) {
					return;
				}
				builderViewRef.current.$( '.so-rows-container, .widgets-container' ).each( function() {
					const inst = jQuery( this ).sortable( 'instance' );
					if ( inst && inst.document && inst.document[0] !== iframeDoc ) {
						inst.document = jQuery( iframeDoc );
						inst.window   = jQuery( iframeWindow );
					}
				} );
				builderViewRef.current.$( '.resize-handle' ).each( function() {
					const inst = jQuery( this ).draggable( 'instance' );
					if ( inst && inst.document && inst.document[0] !== iframeDoc ) {
						inst.document = jQuery( iframeDoc );
						inst.window   = jQuery( iframeWindow );
					}
				} );
			};
			// Patch initial instances after first render.
			setTimeout( patchJQueryUIDocuments, 0 );
			// Re-patch whenever a new row or widget is added (new instances are created).
			builderViewRef.current.on( 'row_added widget_added', patchJQueryUIDocuments );
		}

		setPanelsInitialized( true );

		return () => {
			isMountedRef.current = false;

			if ( panelsContainer.current ) {
				panelsContainer.current.removeEventListener( 'dragstart', onContainerDragStart );
			}

			if ( builderViewRef.current ) {
				// Remove builder from global builder list.
				if ( typeof window.soPanelsBuilderView !== 'undefined' ) {
					window.soPanelsBuilderView = window.soPanelsBuilderView.filter(
						view => view !== builderViewRef.current
					);
				}
				builderViewRef.current.remove();
				builderViewRef.current = null;
			}

			if (
				currentFetchRequest.current &&
				typeof currentFetchRequest.current.abort === 'function'
			) {
				currentFetchRequest.current.abort();
			}

			clearTimeout( fetchPreviewTimer.current );

			if ( panelsContainer.current ) {
				jQuery( panelsContainer.current ).empty();
			}

			if ( previewContainer.current ) {
				jQuery( previewContainer.current ).empty();
			}
		};
	}, [] );

	// Schedule a preview fetch or fire setup when preview loading state changes.
	wp.element.useEffect( () => {
		if ( ! panelsInitialized ) {
			return;
		}

		if ( loadingPreview ) {
			if ( ! pendingPreviewRequest ) {
				setPendingPreviewRequest( true );
				clearTimeout( fetchPreviewTimer.current );
				fetchPreviewTimer.current = setTimeout( () => fetchPreview(), 1000 );
			}
		} else if ( ! previewInitialized ) {
			const iframeDoc = panelsContainer.current
				? panelsContainer.current.ownerDocument
				: document;
			jQuery( iframeDoc ).trigger( 'panels_setup_preview' );
			setPreviewInitialized( true );
		}
	}, [ loadingPreview, panelsInitialized, pendingPreviewRequest, previewInitialized, fetchPreview ] );

	// Trigger a layout recalculation whenever we switch back into edit mode.
	wp.element.useEffect( () => {
		if ( editing && builderViewRef.current ) {
			builderViewRef.current.menu.setContext( {
				container: jQuery( panelsContainer.current )
			} );

			setTimeout( () => {
				if ( builderViewRef.current ) {
					builderViewRef.current.trigger( 'builder_resize' );
				}
			} );
		}
	}, [ editing ] );

	return (
		<wp.element.Fragment>
			<div
			 key="layout-block"
			 className="siteorigin-panels-layout-block-container"
			 ref={ panelsContainer }
			 hidden={ ! editing }
			/>
			<div
				key="preview"
				className="so-panels-block-layout-preview-container"
				hidden={ editing }
			>
			{ loadingPreview ? (
				<div className="so-panels-spinner-container">
					<span><wp.components.Spinner/></span>
				</div>
			) : (
				<div className="so-panels-raw-html-container" ref={ previewContainer }>
					<wp.element.RawHTML>{ previewHtml }</wp.element.RawHTML>
				</div>
			) }
			</div>
		</wp.element.Fragment>
	);
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

	apiVersion: 3,

	edit( { attributes, setAttributes, toggleSelection } ) {
		const blockProps = wp.blockEditor.useBlockProps();

		// Derive the initial editing state once.
		const hasPanelsData = attributes.panelsData &&
			typeof attributes.panelsData === 'object' &&
			Object.keys( attributes.panelsData ).length > 0;
		const initialEditing = hasPanelsData
			? window.soPanelsBlockEditorAdmin.defaultMode === 'edit'
			: true;

		const [ editing, setEditing ] = wp.element.useState( initialEditing );

		const switchToEditing = wp.element.useCallback( () => {
			setEditing( true );
		}, [] );

		const switchToPreview = wp.element.useCallback( () => {
			if ( attributes.panelsData ) {
				setEditing( false );
			}
		}, [ attributes.panelsData ] );

		const onLayoutBlockContentChange = wp.element.useCallback( ( newPanelsData ) => {

			if (
				newPanelsData.widgets !== null &&
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

						setAttributes( {
							contentPreview: panelsAttributes.contentPreview,
							panelsData: panelsAttributes.panelsData,
							previewInitialized: false,
						} );

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
		}, [ setAttributes ] );

		const disableSelection = wp.element.useCallback( () => {
			toggleSelection( false );
		}, [ toggleSelection ] );

		const enableSelection = wp.element.useCallback( () => {
			toggleSelection( true );
		}, [ toggleSelection ] );

		return (
			<wp.element.Fragment>
				<wp.blockEditor.BlockControls>
				<wp.components.Toolbar label={ wp.i18n.__( 'Page Builder Mode.', 'siteorigin-panels' ) }>
					{ editing ? (
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
					) }
				</wp.components.Toolbar>
				</wp.blockEditor.BlockControls>
				<div { ...blockProps }>
					<SiteOriginPanelsLayoutBlock
						editing={ editing }
						panelsData={ attributes.panelsData }
						onContentChange={ onLayoutBlockContentChange }
						onRowOrWidgetMouseDown={ disableSelection }
						onRowOrWidgetMouseUp={ enableSelection }
					/>
				</div>
			</wp.element.Fragment>
		);
	}
} );

jQuery( function() {
	const isEditorReady = function() {
		let editorState = false;
		if ( wp.data.select( 'core/block-editor' ) ) {
			editorState = wp.data.select( 'core/block-editor' ).hasInserterItems();
		} else if ( wp.data.select( 'core/editor' ) ) {
			editorState = wp.data.select( 'core/editor' ).__unstableIsEditorReady();
		}
		return editorState;
	}

	// Resolve Block Editor warning for SO Layout Block.
	var unsubscribe = null;
	unsubscribe = wp.data.subscribe( function() {
		if ( isEditorReady() && unsubscribe ) {
			unsubscribe();
			setTimeout( function() {
			jQuery( '.wp-block[data-type="siteorigin-panels/layout-block"].has-warning .block-editor-warning__action .components-button' ).trigger( 'click' );
			}, 250 );
		}
	} );

	// It's possible the above attempt may fail.
	// So to prevent a situation where the button will still appear,
	// do an additional check every 1.5s until it's unlikely there are
	// any buttons are present.
	var checkInterval = setInterval( function() {
		if ( isEditorReady() ) {
			return;
		}
		jQuery( '.wp-block[data-type="siteorigin-panels/layout-block"].has-warning .block-editor-warning__action .components-button' ).trigger( 'click' );
		clearInterval( checkInterval );
	}, 1500 );


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
} );

// Detect preview mode changes, and trigger resize.
jQuery( document ).on( 'click', '.block-editor-post-preview__button-resize', function( e ) {
	if ( ! jQuery( this ).hasClass('has-icon') ) {
		jQuery( window ).trigger( 'resize' );
	}
} );
