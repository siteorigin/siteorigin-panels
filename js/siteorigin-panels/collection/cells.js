var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.cell,

	initialize: function () {
	},

	/**
	 * Get the total weight for the cells in this collection.
	 * @returns {number}
	 */
	totalWeight: function () {
		var totalWeight = 0;
		this.each( function ( cell ) {
			totalWeight += cell.get( 'weight' );
		} );

		return totalWeight;
	},

	visualSortComparator: function ( item ) {
		if ( ! _.isNull( item.indexes ) ) {
			return item.indexes.builder;
		} else {
			return null;
		}
	},

	visualSort: function(){
		var oldComparator = this.comparator;
		this.comparator = this.visualSortComparator;
		this.sort();
		this.comparator = oldComparator;
	}
} );
