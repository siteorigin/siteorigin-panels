/**
 * Everything we need for SiteOrigin Page Builder.
 *
 * @copyright Greg Priday 2013 - 2016 - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

/* global Backbone, _, jQuery, tinyMCE, panelsOptions, plupload, confirm, console, require */

var panels = {};

// Store everything globally
window.panels = panels;
window.siteoriginPanels = panels;

// Helpers
panels.helpers = {};
panels.helpers.clipboard = require( './helpers/clipboard' );
panels.helpers.utils = require( './helpers/utils' );
panels.helpers.editor = require( './helpers/editor' );
panels.helpers.serialize = require( './helpers/serialize' );
panels.helpers.pageScroll = require( './helpers/page-scroll' );
panels.helpers.accessibility = require( './helpers/accessibility' );

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
	
	var $panelsMetabox = $( '#siteorigin-panels-metabox' );
	form = $( 'form#post' );
	if ( $panelsMetabox.length && form.length ) {
		// This is usually the case when we're in the post edit interface
		container = $panelsMetabox;
		field = $panelsMetabox.find( '.siteorigin-panels-data-field' );

		builderConfig = {
			editorType: 'tinyMCE',
			postId: $( '#post_ID' ).val(),
			editorId: '#content',
			builderType: $panelsMetabox.data( 'builder-type' ),
			builderSupports: $panelsMetabox.data( 'builder-supports' ),
			loadOnAttach: panelsOptions.loadOnAttach && $( '#auto_draft' ).val() == 1,
			loadLiveEditor: $panelsMetabox.data( 'live-editor' ) == 1,
			liveEditorCloseAfter: $panelsMetabox.data( 'live-editor-close' ) == 1,
			editorPreview: container.data( 'preview-url' )
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
			liveEditorCloseAfter: false,
			editorPreview: $$.data( 'preview-url' )
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

		// Trigger an event before the panels setup to allow adding listeners for various builder events which are
		// triggered during initial setup.
		$(document).trigger('before_panels_setup', builderView);

		// Set up the builder view
		builderView
			.render()
			.attach( {
				container: container
			} )
			.setDataField( field )
			.attachToEditor();

		// When the form is submitted, update the panels data
		form.on( 'submit', function() {
			// Refresh the data
			builderModel.refreshPanelsData();
		} );

		container.removeClass( 'so-panels-loading' );

		// Trigger a global jQuery event after we've setup the builder view. Everything is accessible form there
		$( document ).trigger( 'panels_setup', builderView, window.panels );

		// Make this globally available for things like Yoast compatibility.
		window.soPanelsBuilderView = builderView;
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

	// A global escape handler
	$(window).on('keyup', function(e){
		// [Esc] to close
		if ( e.which === 27 ) {
			// Trigger a click on the last visible Page Builder window
			$( '.so-panels-dialog-wrapper, .so-panels-live-editor' ).filter(':visible')
				.last().find('.so-title-bar .so-close, .live-editor-close').trigger( 'click' );
		}
	});
} );

// WP 5.7+: Prevent undesired "restore content" notice.
if ( typeof window.wp.autosave !== 'undefined' && jQuery( '#siteorigin-panels-metabox' ).length ) {
	jQuery( function( e ) {
		var blog_id = typeof window.autosaveL10n !== 'undefined' && window.autosaveL10n.blog_id;
		
		// Ensure sessionStorage is working, and we were able to find a blog id.
		if ( typeof window.sessionStorage != 'object' && ! blog_id ) {
			return;
		}

		stored_obj = window.sessionStorage.getItem( 'wp-autosave-' + blog_id );
		if ( stored_obj ) {
			stored_obj = JSON.parse( stored_obj );
			var storedPostData = stored_obj[ 'post_' + jQuery( '#post_ID' ).val() ]

			if ( typeof storedPostData == 'object' ) {
				// Override existing store with stored session data. The content is exactly the same.
				jQuery( '#content' ).val( storedPostData.content );
			}
		}
	} );
}
