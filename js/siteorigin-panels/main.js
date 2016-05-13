/**
 * Everything we need for SiteOrigin Page Builder.
 *
 * @copyright Greg Priday 2013 - 2016 - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

/* global Backbone, _, jQuery, tinyMCE, panelsOptions, plupload, confirm, console, require */

/**
 * Convert template into something compatible with Underscore.js templates
 *
 * @param s
 * @return {*}
 */
String.prototype.panelsProcessTemplate = function () {
	var s = this;
	s = s.replace( /{{%/g, '<%' );
	s = s.replace( /%}}/g, '%>' );
	s = s.trim();
	return s;
};

var panels = {};

// Store everything globally
window.panels = panels;
window.siteoriginPanels = panels;

// The models
panels.model = {};
panels.model.widget = require( './model/widget' );
panels.model.cell = require( './model/cell' );
panels.model.row = require( './model/row' );
panels.model.builder = require( './model/builder' );
panels.model.historyEntry = require( './model/history-entry' );

// The collections
panels.collection = {};
panels.collection.widgets = require( './collection/widgets' );
panels.collection.cells = require( './collection/cells' );
panels.collection.rows = require( './collection/rows' );
panels.collection.historyEntries = require( './collection/history-entries' );

// The views
panels.view = {};
panels.view.widget = require( './view/widget' );
panels.view.cell = require( './view/cell' );
panels.view.row = require( './view/row' );
panels.view.builder = require( './view/builder' );
panels.view.dialog = require( './view/dialog' );
panels.view.styles = require( './view/styles' );
panels.view.liveEditor = require( './view/live-editor' );

// The dialogs
panels.dialog = {};
panels.dialog.builder = require( './dialog/builder' );
panels.dialog.widgets = require( './dialog/widgets' );
panels.dialog.widget = require( './dialog/widget' );
panels.dialog.prebuilt = require( './dialog/prebuilt' );
panels.dialog.row = require( './dialog/row' );
panels.dialog.history = require( './dialog/history' );

// The utils
panels.utils = {};
panels.utils.menu = require( './utils/menu' );

// jQuery Plugins
jQuery.fn.soPanelsSetupBuilderWidget = require( './jquery/setup-builder-widget' );


// Set up Page Builder if we're on the main interface
jQuery( function ( $ ) {

	var container,
		field,
		form,
		builderConfig;

	if ( $( '#siteorigin-panels-metabox' ).length && $( 'form#post' ).length ) {
		// This is usually the case when we're in the post edit interface
		container = $( '#siteorigin-panels-metabox' );
		field = $( '#siteorigin-panels-metabox .siteorigin-panels-data-field' );
		form = $( 'form#post' );

		builderConfig = {
			editorType: 'tinymce',
			postId: $( '#post_ID' ).val(),
			editorId: '#content',
			builderType: $( '#siteorigin-panels-metabox' ).data( 'builder-type' ),
			builderSupports: $( '#siteorigin-panels-metabox' ).data( 'builder-supports' ),
			loadLiveEditor: $( '#siteorigin-panels-metabox' ).data('live-editor') == 1,
			liveEditorPreview: container.data('preview-url')
		};
	}
	else if ( $( '.siteorigin-panels-builder-form' ).length ) {
		// We're dealing with another interface like the custom home page interface
		var $$ = $( '.siteorigin-panels-builder-form' );

		container = $$.find( '.siteorigin-panels-builder-container' );
		field = $$.find( 'input[name="panels_data"]' );
		form = $$;

		builderConfig = {
			editorType: 'standalone',
			postId: $$.data( 'post-id' ),
			editorId: '#post_content',
			builderType: $$.data( 'type' ),
			builderSupports: $$.data( 'builder-supports' ),
			loadLiveEditor: false,
			liveEditorPreview: $$.data( 'preview-url' )
		};
	}

	if ( ! _.isUndefined( container ) ) {
		// If we have a container, then set up the main builder
		var panels = window.siteoriginPanels;

		// Create the main builder model
		var builderModel = new panels.model.builder();

		// Now for the view to display the builder
		var builderView = new panels.view.builder( {
			model: builderModel,
			config: builderConfig
		} );

		// Set up the builder view
		builderView
			.render()
			.attach( {
				container: container
			} )
			.setDataField( field )
			.attachToEditor();

		// When the form is submitted, update the panels data
		form.submit( function () {
			// Refresh the data
			builderModel.refreshPanelsData();
		} );

		container.removeClass( 'so-panels-loading' );

		// Trigger a global jQuery event after we've setup the builder view. Everything is accessible form there
		$( document ).trigger( 'panels_setup', builderView, window.panels );
	}

	// Setup new widgets when they're added in the standard widget interface
	$( document ).on( 'widget-added', function ( e, widget ) {
		$( widget ).find( '.siteorigin-page-builder-widget' ).soPanelsSetupBuilderWidget();
	} );

	// Setup existing widgets on the page (for the widgets interface)
	if ( ! $( 'body' ).hasClass( 'wp-customizer' ) ) {
		$( function () {
			$( '.siteorigin-page-builder-widget' ).soPanelsSetupBuilderWidget();
		} );
	}
} );
