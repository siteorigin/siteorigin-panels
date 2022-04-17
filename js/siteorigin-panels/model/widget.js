/**
 * Model for an instance of a widget
 */
module.exports = Backbone.Model.extend( {

	cell: null,

	defaults: {
		// The PHP Class of the widget
		class: null,

		// Is this class missing? Missing widgets are a special case.
		missing: false,

		// The values of the widget
		values: {},

		// Have the current values been passed through the widgets update function
		raw: false,

		// Visual style fields
		style: {},

		read_only: false,
		widget_id: '',
	},

	indexes: null,

	initialize: function () {
		var widgetClass = this.get( 'class' );
		if ( _.isUndefined( panelsOptions.widgets[widgetClass] ) || ! panelsOptions.widgets[widgetClass].installed ) {
			this.set( 'missing', true );
		}
	},

	/**
	 * @param field
	 * @returns {*}
	 */
	getWidgetField: function ( field ) {
		if ( _.isUndefined( panelsOptions.widgets[this.get( 'class' )] ) ) {
			if ( field === 'title' || field === 'description' ) {
				return panelsOptions.loc.missing_widget[field];
			} else {
				return '';
			}
		} else if ( this.has( 'label' ) && ! _.isEmpty( this.get( 'label' ) ) ) {
			// Use the label instead of the actual widget title
			return this.get( 'label' );
		} else {
			return panelsOptions.widgets[ this.get( 'class' ) ][ field ];
		}
	},

	/**
	 * Move this widget model to a new cell. Called by the views.
	 *
	 * @param panels.model.cell newCell
	 * @param object options The options passed to the
	 *
	 * @return boolean Indicating if the widget was moved into a different cell
	 */
	moveToCell: function ( newCell, options, at ) {
		options = _.extend( {
			silent: true,
		}, options );

		this.cell = newCell;
		this.collection.remove( this, options );
		newCell.get('widgets').add( this, _.extend( {
			at: at
		}, options ) );

		// This should be used by views to reposition everything.
		this.trigger( 'move_to_cell', newCell, at );

		return this;
	},

	/**
	 * This is basically a wrapper for set that checks if we need to trigger a change
	 */
	setValues: function ( values ) {
		var hasChanged = false;
		if ( JSON.stringify( values ) !== JSON.stringify( this.get( 'values' ) ) ) {
			hasChanged = true;
		}

		this.set( 'values', values, {silent: true} );

		if ( hasChanged ) {
			// We'll trigger our own change events.
			// NB: Must include the model being changed (i.e. `this`) as a workaround for a bug in Backbone 1.2.3
			this.trigger( 'change', this );
			this.trigger( 'change:values' );
		}
	},

	/**
	 * Create a clone of this widget attached to the given cell.
	 *
	 * @param {panels.model.cell} cell The cell model we're attaching this widget clone to.
	 * @returns {panels.model.widget}
	 */
	clone: function ( cell, options ) {
		if ( _.isUndefined( cell ) ) {
			cell = this.cell;
		}

		var clone = new this.constructor( this.attributes );

		// Create a deep clone of the original values
		var cloneValues = JSON.parse( JSON.stringify( this.get( 'values' ) ) );

		// We want to exclude any fields that start with _ from the clone. Assuming these are internal.
		var cleanClone = function ( vals ) {
			_.each( vals, function ( el, i ) {
				if ( _.isString( i ) && i[0] === '_' ) {
					delete vals[i];
				}
				else if ( _.isObject( vals[i] ) ) {
					cleanClone( vals[i] );
				}
			} );

			return vals;
		};
		cloneValues = cleanClone( cloneValues );

		if ( this.get( 'class' ) === "SiteOrigin_Panels_Widgets_Layout" ) {
			// Special case of this being a layout widget, it needs a new ID
			cloneValues.builder_id = Math.random().toString( 36 ).substr( 2 );
		}

		clone.set( 'widget_id', '' );
		clone.set( 'values', cloneValues, {silent: true} );
		clone.set( 'collection', cell.get('widgets'), {silent: true} );
		clone.cell = cell;

		// This is used to force a form reload later on
		clone.isDuplicate = true;

		return clone;
	},

	/**
	 * Ensure the title is valid.
	 *
	 * @param title The text we're testing.
	 * @returns boolean
	 */
	isValidTitle: function( title ) {
		return ! _.isUndefined( title ) &&
			_.isString( title ) &&
			title !== '' &&
			title !== 'on' &&
			title !== 'true' &&
			title !== 'false' &&
			title[0] !== '_' &&
			! _.isFinite( title );
	},

	/**
	 * Remove HTML from the title, and limit its length.
	 *
	 * @param title The title we're cleaning.
	 * @returns string The "cleaned" title.
	 */
	cleanTitle: function( title ) {
		// Prevent situation where invalid titles are processed for cleaning.
		if ( typeof title !== 'string' ) {
			return false;
		}
		title = title.replace( /<\/?[^>]+(>|$)/g, "" );
		var parts = title.split( " " );
		parts = parts.slice( 0, 20 );
		return parts.join( ' ' );
	},

	/**
	 * Iterate an array and find a valid field we can use for a title. Supports multidimensional arrays.
	 *
	 * @param values An array containing field values.
	 * @returns object thisView The current widget instance.
	 * @returns object fields The fields we're specifically check for.
	 * @param object check_sub_fields Whether we should check sub fields.
	 *
	 * @returns string The title we found. If we weren't able to find one, it returns false.
	 */
	getTitleFromValues: function( values, thisView, fields = false, check_sub_fields = true ) {
		var widgetTitle = false;
		for ( const k in values ) {
			if ( typeof values[ k ] == 'object' ) {
				if ( check_sub_fields ) {
					// Field is an object, check child for valid titles.
					widgetTitle = thisView.getTitleFromValues( values[ k ], thisView, fields );
					if ( widgetTitle ) {
						break;
					}
				}
			// Check for predefined title fields.
			} else if ( typeof fields == 'object' ) {
				for ( var i = 0; i < fields.length; i++ ) {
					if ( k == fields[i] ) {
						widgetTitle = thisView.cleanTitle( values[ k ] )
						if ( widgetTitle ) {
							break;
						}
					}
				}
				if ( widgetTitle ) {
					break;
				}
			// Ensure field isn't a required WB field, and if its not, confirm it's valid.
			} else if (
				typeof fields != 'object' &&
				k.charAt( 0 ) !== '_' &&
				k !== 'so_sidebar_emulator_id' &&
				k !== 'option_name' &&
				thisView.isValidTitle( values[ k ] )
			) {
				widgetTitle = thisView.cleanTitle( values[ k ] )
				if ( widgetTitle ) {
					break;
				}
			}
		};

		return widgetTitle;
	},

	/**
	 * Gets the value that makes most sense as the title.
	 */
	getTitle: function () {
		var widgetData = panelsOptions.widgets[this.get( 'class' )];
		var titleFields = [];
		var titleFieldOnly = false;

		if ( _.isUndefined( widgetData ) ) {
			return this.get( 'class' ).replace( /_/g, ' ' );
		} else if ( ! _.isUndefined( widgetData.panels_title ) ) {
			// This means that the widget has told us which field it wants us to use as a title
			if ( widgetData.panels_title === false ) {
				return panelsOptions.widgets[this.get( 'class' )].description;
			} else {
				titleFields.push( widgetData.panels_title );
				titleFieldOnly = true;
			}
		} else {
			titleFields = ['title', 'text'];
		}
		var values = this.get( 'values' );
		var thisView = this;
		var widgetTitle = false;

		// Check titleFields for valid titles.
		widgetTitle = this.getTitleFromValues(
			values,
			thisView,
			titleFields,
			typeof widgetData.panels_title_check_sub_fields != 'undefined' ? widgetData.panels_title_check_sub_fields : false
		);

		if ( ! widgetTitle && ! titleFieldOnly ) {
			// No titles were found. Let's check the rest of the fields for a valid title.
			widgetTitle = this.getTitleFromValues( values, thisView );
		}

		return widgetTitle ? widgetTitle : this.getWidgetField( 'description' );
	}

} );
