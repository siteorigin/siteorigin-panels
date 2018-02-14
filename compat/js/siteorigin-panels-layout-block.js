( function ( blocks, i18n, element, components ) {
	
	var el = element.createElement;
	var BlockControls = blocks.BlockControls;
	var withState = components.withState;
	var IconButton = components.IconButton;
	var __ = i18n.__;
	
	blocks.registerBlockType( 'siteorigin-panels/layout-block', {
		title: __( 'SiteOrigin Layout', 'siteorigin-panels' ),
		
		description: __( 'Build a layout using SiteOrigin\'s Page Builder.', 'siteorigin-panels' ),
		
		icon: 'siteorigin-panels-icon',
		
		category: 'layout',
		
		attributes: {
			panelsData: {
				type: 'object',
			}
		},
		
		edit: withState( {
			editing: true,
			panelsInitialized: false,
		} )( function ( props ) {
			var editing = props.editing;
			
			function togglePreview() {
				console.log( 'Toggle Preview ' );
			}
			
			function setupPanels( panelsContainer ) {
				if ( ! props.panelsInitialized ) {
					var $panelsContainer = jQuery( panelsContainer );
					
					var config = {
							loadLiveEditor: false,
							editorType: 'standalone'
						};
					
					var builderModel = new panels.model.builder();
					
					var builderView = new panels.view.builder( {
						model: builderModel,
						config: config
					} );
					
					// Make sure panelsData is defined and clone so that we don't alter the underlying attribute.
					var panelsData = JSON.parse( JSON.stringify( $.extend( {}, props.attributes.panelsData ) ) );
	
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
			
			return [
				!! props.focus && el(
					BlockControls,
					{ key: 'controls' },
					el(
						IconButton,
						{
							className: 'components-icon-button components-toolbar__control',
							label: editing ? __( 'Edit layout.', 'siteorigin-panels' ) : __( 'Preview layout.', 'siteorigin-panels' ),
							onClick: togglePreview,
							icon: editing ? 'visibility' : 'edit'
						}
					)
				),
				el( 'div', {
					key: 'pageBuilder',
					className: 'siteorigin-panels-layout-block-container',
					ref: setupPanels,
				} )
			];
		} ),
		
		save: function () {
			// Render in PHP
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.i18n, window.wp.element, window.wp.components );
