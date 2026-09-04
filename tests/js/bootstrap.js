'use strict';

/**
 * Loads the builder's Backbone models into Node the way js/siteorigin-panels/main.js
 * loads them in the browser: the modules read `_`, `Backbone`, `panels` and
 * `panelsOptions` as globals, and `window.panels` doubles as the model and
 * collection registry that the modules look each other up through.
 *
 * Only the model layer is loaded. Views, dialogs and helpers that touch the
 * DOM stay out, so a stub stands in for the one helper the models call.
 */

const path = require( 'path' );

const _ = require( 'underscore' );
const Backbone = require( 'backbone' );

global._ = _;
global.Backbone = Backbone;

let uuid = 0;

const panels = {
	model: {},
	collection: {},
	helpers: {
		utils: {
			generateUUID: function () {
				uuid += 1;
				return 'uuid-' + uuid;
			}
		}
	}
};

global.window = global.window || {};
global.window.console = console;
global.window.panels = panels;
global.panels = panels;
global.panelsOptions = { widgets: {} };

const base = path.join( __dirname, '..', '..', 'js', 'siteorigin-panels' );

// Same order as main.js: models first, then the collections that reference them.
panels.model.widget = require( path.join( base, 'model', 'widget' ) );
panels.model.cell = require( path.join( base, 'model', 'cell' ) );
panels.model.row = require( path.join( base, 'model', 'row' ) );
panels.model.builder = require( path.join( base, 'model', 'builder' ) );

panels.collection.widgets = require( path.join( base, 'collection', 'widgets' ) );
panels.collection.cells = require( path.join( base, 'collection', 'cells' ) );
panels.collection.rows = require( path.join( base, 'collection', 'rows' ) );

module.exports = panels;
