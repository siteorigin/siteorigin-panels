module.exports = {

	liveEditor: null,
	diff: require('deep-diff').diff,
	queue: null,
	$layout: null,

	// The data represented by the current $layout
	currentData: null,

	/**
	 * Run through all the steps to try convert currentData to newData
	 * @param currentData
	 * @param newData
	 */
	runSteps: function( currentData, newData ) {
		this.currentData = JSON.parse( JSON.stringify( currentData ) );

		for( var step in this.steps ) {
			this.steps[ step ].apply( this );
		}

		// Check if we've been able to convert the data properly
		return JSON.stringify( this.currentData ) === JSON.stringify( newData );
	},

	steps: {

		/**
		 * Delete any widgets that no longer exist
		 * @param newData
		 */
		deleteWidgets: function( newData ) {

		},

		/**
		 * Delete any rows that no longer exist
		 * @param newData
		 */
		deleteRows: function( newData ) {

		},

		/**
		 * Handle resizing of cells
		 * @param newData
		 */
		resizeCells: function( newData ) {

		},

		/**
		 * Move rows into their new positions.
		 * @param newData
		 */
		moveRows: function( newData ){

		},

		/**
		 * Move widgets into their new position.
		 * @param newData
		 */
		moveWidgets: function( newData ) {

		},

		/**
		 * A more agressive action where we reload the entire layout.
		 * @param newData
		 */
		reloadLayout: function( newData ){

		},

		/**
		 * Reload any changed widgets.
		 * @param newData
		 */
		reloadWidgets: function( newData ) {

		}

	},

};