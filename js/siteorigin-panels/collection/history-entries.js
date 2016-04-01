var panels = window.panels;

module.exports = Backbone.Collection.extend( {
	model: panels.model.historyEntry,

	/**
	 * The builder model
	 */
	builder: null,

	/**
	 * The maximum number of items in the history
	 */
	maxSize: 12,

	initialize: function () {
		this.on( 'add', this.onAddEntry, this );
	},

	/**
	 * Add an entry to the collection.
	 *
	 * @param text The text that defines the action taken to get to this
	 * @param data
	 */
	addEntry: function ( text, data ) {

		if ( _.isEmpty( data ) ) {
			data = this.builder.getPanelsData();
		}

		var entry = new panels.model.historyEntry( {
			text: text,
			data: JSON.stringify( data ),
			time: parseInt( new Date().getTime() / 1000 ),
			collection: this
		} );

		this.add( entry );
	},

	/**
	 * Resize the collection so it's not bigger than this.maxSize
	 */
	onAddEntry: function ( entry ) {

		if ( this.models.length > 1 ) {
			var lastEntry = this.at( this.models.length - 2 );

			if (
				(
					entry.get( 'text' ) === lastEntry.get( 'text' ) && entry.get( 'time' ) - lastEntry.get( 'time' ) < 15
				) ||
				(
					entry.get( 'data' ) === lastEntry.get( 'data' )
				)
			) {
				// If both entries have the same text and are within 20 seconds of each other, or have the same data, then remove most recent
				this.remove( entry );
				lastEntry.set( 'count', lastEntry.get( 'count' ) + 1 );
			}
		}

		// Make sure that there are not to many entries in this collection
		while ( this.models.length > this.maxSize ) {
			this.shift();
		}
	}
} );
