( function ( blocks, i18n, element, components ) {
	
	var el = element.createElement;
	var BlockControls = blocks.BlockControls;
	var withAPIData = components.withAPIData;
	var withState = components.withState;
	var Toolbar = components.Toolbar;
	var IconButton = components.IconButton;
	var Spinner  = components.Spinner;
	var __ = i18n.__;
	
	blocks.registerBlockType( 'siteorigin-panels/layout-block', {
		title: __( 'SiteOrigin Layout', 'siteorigin-panels' ),
		
		description: __( 'Build a layout using SiteOrigin\'s Page Builder.', 'siteorigin-panels' ),
		
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
		} )( withAPIData( function( props ) {
				var toGet = {};
				
				if ( props.attributes.panelsData ) {
					if ( ! props.editing ) {
						var data = props.attributes.panelsData || {};
						toGet.preview = '/so-panels/v1/layouts/previews';
						toGet.preview += '?panelsData=' + encodeURIComponent( JSON.stringify( data ) );
					}
				} else if ( ! props.editing ) {
					props.setState( { editing: true } );
				}
				
				return toGet;
			} )( function ( props ) {
			var editing = props.editing;
			var loadingPreview = !!props.preview && !props.preview.data;
			
			function togglePreview() {
				props.setState( { editing: ! editing, panelsInitialized: editing } );
			}
			
			function setupPreview() {
				if ( ! editing ) {
					$( document ).trigger( 'panels_setup_preview' );
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
					
					builderView.on( 'content_change', function () {
						props.setAttributes( { panelsData: builderView.getData() } );
					} );
					
					$( document ).trigger( 'panels_setup', builderView );
					
					props.setState( { panelsInitialized: true } );
				}
			}
			if ( editing ) {
				return [
					!!props.focus && el(
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
									onClick: togglePreview,
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
				var preview = props.preview ? props.preview.data : '';
				return [
					!! props.focus && el(
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
									onClick: togglePreview,
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
								el( Spinner ) :
								el( 'div', {
									dangerouslySetInnerHTML: { __html: preview },
									ref: setupPreview,
								} )
						)
					)
				];
			}
		} ) ),
		
		save: function () {
			// Render in PHP
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.i18n, window.wp.element, window.wp.components );
