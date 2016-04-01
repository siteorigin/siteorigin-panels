module.exports = Backbone.Model.extend( {
	/* A collection of widgets */
	widgets: {},

	/* The row this model belongs to */
	row: null,

	defaults: {
		weight: 0
	},

	indexes: null,

	/**
	 * Set up the cell model
	 */
	initialize: function () {
		this.widgets = new panels.collection.widgets();
		this.on( 'destroy', this.onDestroy, this );
	},

	/**
	 * Triggered when we destroy a cell
	 */
	onDestroy: function () {
		_.invoke( this.widgets.toArray(), 'destroy' );
		this.widgets.reset();
	},

	/**
	 * Create a clone of the cell, along with all its widgets
	 */
	clone: function ( row, cloneOptions ) {
		if ( _.isUndefined( row ) ) {
			row = this.row;
		}
		cloneOptions = _.extend( {cloneWidgets: true}, cloneOptions );

		var clone = new this.constructor( this.attributes );
		clone.set( 'collection', row.cells, {silent: true} );
		clone.row = row;

		if ( cloneOptions.cloneWidgets ) {
			// Now we're going add all the widgets that belong to this, to the clone
			this.widgets.each( function ( widget ) {
				clone.widgets.add( widget.clone( clone, cloneOptions ), {silent: true} );
			} );
		}

		return clone;
	}

} );
