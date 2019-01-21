const { isEqual, debounce } = lodash;
const { registerBlockType } = wp.blocks;
const { Component, Fragment, RawHTML, createRef } = wp.element;
const { BlockControls } = wp.editor;
const { Toolbar, IconButton, Spinner } = wp.components;
const { __ } = wp.i18n;


class SiteOriginPanelsLayoutBlock extends Component {
	constructor( props ) {
		super( props );
		const editMode = soPanelsBlockEditorAdmin.defaultMode === 'edit';
		this.state = {
			editing: editMode,
			loadingPreview: ! editMode,
			previewHtml: ''
		};
		this.panelsContainer = createRef();
		this.panelsInitialized = false;
		this.previewInitialized = false;
	}
	
	componentDidMount() {
		this.isStillMounted = true;
		
		if ( this.state.editing ) {
			this.setupPanels();
		} else if ( ! this.state.editing && ! this.previewInitialized ) {
			this.fetchPreview( this.props );
			this.fetchPreview = debounce( this.fetchPreview, 500 );
		}
	}
	
	componentWillUnmount() {
		this.isStillMounted = false;
		if ( this.builderView ) {
			this.builderView.off( 'content_change' );
		}
	}
	
	componentDidUpdate( prevProps ) {
		// let propsChanged = !isEqual( prevProps.panelsData, this.props.panelsData );
		if ( this.state.editing && ! this.panelsInitialized ) {
			this.setupPanels();
		} else if ( this.state.loadingPreview ) {
			this.fetchPreview( this.props );
		} else {
			$( document ).trigger( 'panels_setup_preview' );
			this.previewInitialized = true;
		}
	}
	
	setupPanels() {
		var $panelsContainer = jQuery( this.panelsContainer.current );
		
		var config = {
			editorType: 'standalone'
		};
		
		var builderModel = new panels.model.builder();
		
		this.builderView = new panels.view.builder( {
			model: builderModel,
			config: config
		} );
		
		// Make sure panelsData is defined and clone so that we don't alter the underlying attribute.
		var panelsData = JSON.parse( JSON.stringify( $.extend( {}, this.props.panelsData ) ) );
		
		// Disable block selection while dragging rows or widgets.
		let disableSelection = () => {
			this.props.toggleSelection( false );
			let enableSelection = () => {
				this.props.toggleSelection( true );
				$( document ).off( 'mouseup', enableSelection );
			};
			$( document ).on( 'mouseup', enableSelection );
		};
		
		this.builderView.on( 'row_added', () => {
			this.builderView.$( '.so-row-move' ).off( 'mousedown', disableSelection );
			this.builderView.$( '.so-row-move' ).on( 'mousedown', disableSelection );
			this.builderView.$( '.so-widget' ).off( 'mousedown', disableSelection );
			this.builderView.$( '.so-widget' ).on( 'mousedown', disableSelection );
		} );
		
		this.builderView.on( 'widget_added', () => {
			this.builderView.$( '.so-widget' ).off( 'mousedown', disableSelection );
			this.builderView.$( '.so-widget' ).on( 'mousedown', disableSelection );
		} );
		
		this.builderView
		.render()
		.attach( {
			container: $panelsContainer
		} )
		.setData( panelsData );
		
		this.builderView.trigger( 'builder_resize' );
		
		this.builderView.on( 'content_change', () => {
			const newPanelsData = this.builderView.getData();
			this.panelsDataChanged = !isEqual( panelsData, newPanelsData );
			if ( this.panelsDataChanged ) {
				this.props.onContentChange( newPanelsData );
				this.setState( { loadingPreview: true, previewHtml: '' } );
			}
		} );
		
		$( document ).trigger( 'panels_setup', this.builderView );
		
		this.panelsInitialized = true;
	}
	
	fetchPreview( props ) {
		if ( ! this.isStillMounted ) {
			return;
		}
		
		this.previewInitialized = false;
		
		// var loadingPreview = !props.editing && !props.previewHtml && props.attributes.panelsData;
		const fetchRequest = this.currentFetchRequest = $.post( {
			url: soPanelsBlockEditorAdmin.previewUrl,
			data: {
				action: 'so_panels_block_editor_preview',
				panelsData: JSON.stringify( props.panelsData ),
			}
		} )
		.then( ( preview ) => {
			if ( this.isStillMounted && fetchRequest === this.currentFetchRequest && preview ) {
				this.setState( {
					previewHtml: preview,
					loadingPreview: false,
				} );
			}
		} );
		return fetchRequest;
	}
	
	render() {
		const { panelsData } = this.props;
		
		let switchToEditing = () => {
			this.panelsInitialized = false;
			this.setState( { editing: true } );
		}
		
		let switchToPreview = () => {
			if ( panelsData ) {
				this.setState( { editing: false } );
			}
		}
		
		if ( this.state.editing || ! panelsData ) {
			return (
				<Fragment>
					<BlockControls>
						<Toolbar>
							<IconButton
								icon="visibility"
								className="components-icon-button components-toolbar__control"
								label={ __( 'Preview layout.', 'siteorigin-panels' ) }
								onClick={ switchToPreview }
							/>
						</Toolbar>
					</BlockControls>
					<div
						key="layout-block"
						className="siteorigin-panels-layout-block-container"
						ref={this.panelsContainer}
					/>
				</Fragment>
			);
		} else {
			const loadingPreview = this.state.loadingPreview;
			return (
				<Fragment>
					<BlockControls>
						<Toolbar>
							<IconButton
								icon="edit"
								className="components-icon-button components-toolbar__control"
								label={ __( 'Edit layout.', 'siteorigin-panels' ) }
								onClick={ switchToEditing }
							/>
						</Toolbar>
					</BlockControls>
					<div key="preview" className="so-panels-block-layout-preview-container">
						{ loadingPreview ? (
							<div className="so-panels-spinner-container">
								<span><Spinner/></span>
							</div>
						) : (
							<RawHTML>{this.state.previewHtml}</RawHTML>
						) }
					</div>
				</Fragment>
			);
		}
	}
}

registerBlockType( 'siteorigin-panels/layout-block', {
	title: __( 'SiteOrigin Layout', 'siteorigin-panels' ),
	
	description: __( "Build a layout using SiteOrigin's Page Builder.", 'siteorigin-panels' ),
	
	icon () {
		return <span className="siteorigin-panels-block-icon"/>;
	},
	
	category: 'layout',
	
	keywords: [ 'page builder', 'column,grid', 'panel' ],
	
	supports: {
		html: false,
	},
	
	attributes: {
		panelsData: {
			type: 'object',
		}
	},
	
	edit( { attributes, className, setAttributes, toggleSelection } ) {
		
		let onLayoutBlockContentChange = ( newContent ) => {
			setAttributes( { panelsData: newContent } );
		};
		
		return (
			<SiteOriginPanelsLayoutBlock
				panelsData={attributes.panelsData}
				onContentChange={onLayoutBlockContentChange}
				toggleSelection={toggleSelection}
			/>
		);
	},
	
	save() {
		// Render in PHP
		return null;
	}
} );
