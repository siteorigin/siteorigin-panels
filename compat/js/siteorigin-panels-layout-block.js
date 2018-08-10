( function ( editor, blocks, i18n, element, components, compose ) {
	
	var el = element.createElement;
	var BlockControls = editor.BlockControls;
	var withState = compose.withState;
	var Toolbar = components.Toolbar;
	var IconButton = components.IconButton;
	var Spinner  = components.Spinner;
	var __ = i18n.__;
	
	blocks.registerBlockType( 'siteorigin-panels/layout-block', {
		title: __( 'SiteOrigin Layout (in beta)' ),
		
		description: __( "Build a layout using SiteOrigin's Page Builder." ),
		
		icon: function() {
			return el(
				'span',
				{
					className: 'siteorigin-panels-gutenberg-icon'
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
			editing: true,
			panelsInitialized: false,
			loadingPreview: false,
			previewHtml: ''
		} )( function ( props ) {
			var editing = props.editing;
			var loadingPreview = props.loadingPreview;
			function fetchPreview() {
				if ( props.attributes.panelsData ) {
					$.post( soPanelsGutenbergAdmin.previewUrl, {
						action: 'so_panels_gutenberg_preview',
						panelsData:  JSON.stringify( props.attributes.panelsData ),
					} ).then( function( result ) {
						if ( result.html ) {
							props.setState( { previewHtml: result.html, loadingPreview: false } );
						}
					});
					props.setState( { editing: false, loadingPreview: true } );
				}
			}
			
			function setupPreview() {
				if ( ! editing ) {
					$( document ).trigger( 'panels_setup_preview' );
					if ( window.sowb ) {
						$ ( window.sowb ).trigger( 'setup_widgets' );
					}
				}
			}
			
			function showEdit() {
				props.setState( { editing: true, panelsInitialized: false } );
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
					
					// Disable Gutenberg block selection while dragging rows or widgets.
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
					} );
					
					$( document ).trigger( 'panels_setup', builderView );
					
					props.setState( { panelsInitialized: true } );
				}
			}
			if ( editing ) {
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
									label: __( 'Preview layout.' ),
									onClick: fetchPreview,
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
				var preview = props.previewHtml;
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
									label: __( 'Edit layout.' ),
									onClick: showEdit,
									icon: 'edit'
								}
							)
						)
					),
					el(
						'div',
						{
							key: 'preview',
							className: 'so-panels-gutenberg-layout-preview-container'
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
