var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.row,

	/**
	 * Destroy all the rows in this collection
	 */
	empty: function () {
		var model;
		do {
			model = this.collection.first();
			if ( ! model ) {
				break;
			}

			model.destroy();
		} while ( true );
	},

	comparator: function ( item ) {
		if ( ! _.isNull( item.indexes ) ) {
			return item.indexes.builder;
		} else {
			return null;
		}
	}
} );
