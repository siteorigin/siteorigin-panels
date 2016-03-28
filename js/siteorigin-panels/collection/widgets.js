var panels = window.panels;

module.exports = Backbone.Collection.extend( {
    model : panels.model.widget,

    initialize: function(){

    },

	comparator: function( item ){
		if( ! _.isNull( item.indexes ) ) {
			return item.indexes.builder;
		}
		else {
			return null;
		}
	}

} );
