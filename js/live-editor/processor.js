var $ = jQuery;

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
			this.steps[ step ].apply( this, [ newData ] );
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
			var widgetDiff, $widget;
			for( var i = 0; i < newData.widgets.length; i++ ) {
				widgetDiff = this.diff( this.currentData.widgets[i], newData.widgets[i] );
				if( widgetDiff !== undefined ) {
					// TODO give widgets a chance to handle their own live edits
					$widget = this.getElements( 'widgets' ).eq( i );

					console.log( $widget );

					$widget.addClass( 'live-editor-reloading' );
					$.post(
						liveEditor.ajaxUrl,
						{
							action: 'so_panels_live_partial_widget',
							widget: JSON.stringify( newData.widgets[i] ),
							post_id: liveEditor.postId,
						},
						function( r ){
							$widget.replaceWith( r );
						}
					);

					this.currentData.widgets[ i ] = newData.widgets[i];
				}
			}
		}

	},

	getElements: function( type ) {
		var $layout = this.$layout;
		var elementFilter = function(){
			return $( this ).closest( '.panel-layout' ).is( $layout );
		};

		var elements;

		switch( type ) {
			case 'rows':
				elements = this.$layout.find( '.panel-grid' ).filter( elementFilter );
				break;
			case 'cells':
				elements = this.$layout.find( '.panel-grid-cell' ).filter( elementFilter );
				break;
			case 'widgets':
				elements = this.$layout.find( '.so-panel' ).filter( elementFilter );
				break;
			default:
				elements = this.$layout;
				break;
		}

		return elements;
	},

};