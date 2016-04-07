var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.widget,

	initialize: function () {

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
