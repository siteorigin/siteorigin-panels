( function ( editor, blocks, i18n, element, components, compose ) {
	
	var el = element.createElement;
	var BlockControls = editor.BlockControls;
	var withState = compose.withState;
	var Toolbar = components.Toolbar;
	var IconButton = components.IconButton;
	var Spinner  = components.Spinner;
	var __ = i18n.__;
	
	blocks.registerBlockType( 'siteorigin-panels/layout-block', {
		title: __( 'SiteOrigin Layout', 'siteorigin-panels' ),
		
		description: __( "Build a layout using SiteOrigin's Page Builder.", 'siteorigin-panels' ),
		
		icon: function() {
			return el(
				'span',
				{
					className: 'siteorigin-panels-block-icon'
				}
			)
		},
		
		category: 'layout',
		
		supports: {
			html: false,
		},
		
		attributes: {
			panelsData: {
				type: 'object',
			}
		},
		
		edit: withState( {
			editing: false,
			panelsInitialized: false,
			loadingPreview: false,
			previewInitialized: false,
			previewHtml: ''
		} )( function ( props ) {
			
			function setupPreview() {
				if ( ! props.editing ) {
					$( document ).trigger( 'panels_setup_preview' );
					if ( window.sowb ) {
						$ ( window.sowb ).trigger( 'setup_widgets' );
					}
				}
			}
			
			function switchToEditing() {
				props.setState( { editing: true, panelsInitialized: false } );
			}
			
			function switchToPreview() {
				if ( props.attributes.panelsData ) {
					props.setState( { editing: false, previewInitialized: false } );
				}
			}
			
			function setupPanels( panelsContainer ) {
				if ( ! props.panelsInitialized ) {
					var $panelsContainer = jQuery( panelsContainer );
					
					var config = {
							editorType: 'standalone'
						};
					
					var builderModel = new panels.model.builder();
					
					var builderView = new panels.view.builder( {
						model: builderModel,
						config: config
					} );
					
					// Make sure panelsData is defined and clone so that we don't alter the underlying attribute.
					var panelsData = JSON.parse( JSON.stringify( $.extend( {}, props.attributes.panelsData ) ) );
					
					// Disable block selection while dragging rows or widgets.
					function disableSelection() {
						props.toggleSelection( false );
						$( document ).on( 'mouseup', function enableSelection() {
							props.toggleSelection( true );
							$( document ).off( 'mouseup', enableSelection );
						} );
					}
					
					builderView.on( 'row_added', function() {
						builderView.$( '.so-row-move' ).off( 'mousedown', disableSelection );
						builderView.$( '.so-row-move' ).on( 'mousedown', disableSelection );
						builderView.$( '.so-widget' ).off( 'mousedown', disableSelection );
						builderView.$( '.so-widget' ).on( 'mousedown', disableSelection );
					} );
					
					builderView.on( 'widget_added', function() {
						builderView.$( '.so-widget' ).off( 'mousedown', disableSelection );
						builderView.$( '.so-widget' ).on( 'mousedown', disableSelection );
					} );
					
					builderView
					.render()
					.attach( {
						container: $panelsContainer
					} )
					.setData( panelsData );
					
					builderView.trigger( 'builder_resize' );
					
					builderView.on( 'content_change', function () {
						props.setAttributes( { panelsData: builderView.getData() } );
						props.setState( { previewInitialized: false, previewHtml: '' } );
					} );
					
					$( document ).trigger( 'panels_setup', builderView );
					
					props.setState( { editing: true, panelsInitialized: true } );
				}
			}
			if ( props.editing || ! props.attributes.panelsData ) {
				return [
					el(
						BlockControls,
						{ key: 'controls' },
						el(
							Toolbar,
							null,
							el(
								IconButton,
								{
									className: 'components-icon-button components-toolbar__control',
									label: __( 'Preview layout.', 'siteorigin-panels' ),
									onClick: switchToPreview,
									icon: 'visibility'
								}
							)
						)
					),
					el( 'div', {
						key: 'pageBuilder',
						className: 'siteorigin-panels-layout-block-container',
						ref: setupPanels,
					} )
				];
			} else {
				
				var loadingPreview = !props.editing && !props.previewHtml && props.attributes.panelsData;
				if ( loadingPreview ) {
					$.post( {
						url: soPanelsBlockEditorAdmin.previewUrl,
						data: {
							action: 'so_panels_block_editor_preview',
							panelsData: JSON.stringify( props.attributes.panelsData ),
						}
					} )
					.then( function ( preview ) {
						props.setState( {
							previewHtml: preview,
							loadingPreview: false,
						} );
					} );
				}
				var preview = props.previewHtml ? props.previewHtml : '';
				return [
					el(
						BlockControls,
						{ key: 'controls' },
						el(
							Toolbar,
							null,
							el(
								IconButton,
								{
									className: 'components-icon-button components-toolbar__control',
									label: __( 'Edit layout.', 'siteorigin-panels' ),
									onClick: switchToEditing,
									icon: 'edit'
								}
							)
						)
					),
					el(
						'div',
						{
							key: 'preview',
							className: 'so-panels-block-layout-preview-container'
						},
						( loadingPreview ?
							el( 'div', {
									className: 'so-panels-spinner-container'
								},
								el(
									'span',
									null,
									el( Spinner )
								)
							) :
							el( 'div', {
								dangerouslySetInnerHTML: { __html: preview },
								ref: setupPreview,
							} )
						)
					)
				];
			}
		} ),
		
		save: function () {
			// Render in PHP
			return null;
		}
	} );
} )( window.wp.editor, window.wp.blocks, window.wp.i18n, window.wp.element, window.wp.components, window.wp.compose );
