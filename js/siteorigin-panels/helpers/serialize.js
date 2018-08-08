/*
This is a modified version of https://github.com/underdogio/backbone-serialize/
*/

/* global Backbone, module, panels */

module.exports = {
	serialize: function( thing ){
		var val;

		if( thing instanceof Backbone.Model ) {
			var retObj = {};
			for ( var key in thing.attributes ) {
				if (thing.attributes.hasOwnProperty( key ) ) {
					// Skip these to avoid recursion
					if( key === 'builder' || key === 'collection' ) { continue; }

					// If the value is a Model or a Collection, then serialize them as well
					val = thing.attributes[key];
					if ( val instanceof Backbone.Model || val instanceof Backbone.Collection ) {
						retObj[key] = this.serialize( val );
					} else {
						// Otherwise, save the original value
						retObj[key] = val;
					}
				}
			}
			return retObj;
		}
		else if( thing instanceof Backbone.Collection ) {
			// Walk over all of our models
			var retArr = [];

			for ( var i = 0; i < thing.models.length; i++ ) {
				// If the model is serializable, then serialize it
				val = thing.models[i];

				if ( val instanceof Backbone.Model || val instanceof Backbone.Collection ) {
					retArr.push( this.serialize( val ) );
				} else {
					// Otherwise (it is an object), return it in its current form
					retArr.push( val );
				}
			}

			// Return the serialized models
			return retArr;
		}
	},

	unserialize: function( thing, thingType, parent ) {
		var retObj;

		switch( thingType ) {
			case 'row-model' :
				retObj = new panels.model.row();
				retObj.builder = parent;
				var atts = { style: thing.style };
				if ( thing.hasOwnProperty( 'label' ) ) {
					atts.label = thing.label;
				}
				if ( thing.hasOwnProperty( 'color_label' ) ) {
					atts.color_label = thing.color_label;
				}
				retObj.set( atts );
				retObj.setCells( this.unserialize( thing.cells, 'cell-collection', retObj ) );
				break;

			case 'cell-model' :
				retObj = new panels.model.cell();
				retObj.row = parent;
				retObj.set( 'weight', thing.weight );
				retObj.set( 'style', thing.style );
				retObj.set( 'widgets', this.unserialize( thing.widgets, 'widget-collection', retObj ) );
				break;

			case 'widget-model' :
				retObj = new panels.model.widget();
				retObj.cell = parent;
				for ( var key in thing ) {
					if ( thing.hasOwnProperty( key ) ) {
						retObj.set( key, thing[key] );
					}
				}
				retObj.set( 'widget_id', panels.helpers.utils.generateUUID() );
				break;

			case 'cell-collection':
				retObj = new panels.collection.cells();
				for( var i = 0; i < thing.length; i++ ) {
					retObj.push( this.unserialize( thing[i], 'cell-model', parent ) );
				}
				break;

			case 'widget-collection':
				retObj = new panels.collection.widgets();
				for( var i = 0; i < thing.length; i++ ) {
					retObj.push( this.unserialize( thing[i], 'widget-model', parent ) );
				}
				break;

			default:
				console.log( 'Unknown Thing - ' + thingType );
				break;
		}

		return retObj;
	}
};
