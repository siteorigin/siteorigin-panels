var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {

	stylesLoaded: false,

	initialize: function () {

	},

	/**
	 * Render the visual styles object.
	 *
	 * @param type
	 * @param postId
	 */
	render: function ( stylesType, postId, args ) {
		if ( _.isUndefined( stylesType ) ) {
			return;
		}

		// Add in the default args
		args = _.extend( {
			builderType: '',
			dialog: null
		}, args );

		this.$el.addClass( 'so-visual-styles' );

		// Load the form
		var thisView = this;
		$.post(
			panelsOptions.ajaxurl,
			{
				action: 'so_panels_style_form',
				type: stylesType,
				style: this.model.get( 'style' ),
				args: JSON.stringify( {
					builderType: args.builderType
				} ),
				postId: postId
			},
			function ( response ) {
				thisView.$el.html( response );
				thisView.setupFields();
				thisView.stylesLoaded = true;
				thisView.trigger( 'styles_loaded', ! _.isEmpty( response ) );
				if ( ! _.isNull( args.dialog ) ) {
					args.dialog.trigger( 'styles_loaded', ! _.isEmpty( response ) );
				}
			}
		);

		return this;
	},

	/**
	 * Attach the style view to the DOM.
	 *
	 * @param wrapper
	 */
	attach: function ( wrapper ) {
		wrapper.append( this.$el );
	},

	/**
	 * Detach the styles view from the DOM
	 */
	detach: function () {
		this.$el.detach();
	},

	/**
	 * Setup all the fields
	 */
	setupFields: function () {

		// Set up the sections as collapsible
		this.$( '.style-section-wrapper' ).each( function () {
			var $s = $( this );

			$s.find( '.style-section-head' ).click( function ( e ) {
				e.preventDefault();
				$s.find( '.style-section-fields' ).slideToggle( 'fast' );
			} );
		} );

		// Set up the color fields
		if ( ! _.isUndefined( $.fn.wpColorPicker ) ) {
			if ( _.isObject( panelsOptions.wpColorPickerOptions.palettes ) && ! $.isArray( panelsOptions.wpColorPickerOptions.palettes ) ) {
				panelsOptions.wpColorPickerOptions.palettes = $.map( panelsOptions.wpColorPickerOptions.palettes, function ( el ) {
					return el;
				} );
			}
			this.$( '.so-wp-color-field' ).wpColorPicker( panelsOptions.wpColorPickerOptions );
		}

		// Set up the image select fields
		this.$( '.style-field-image' ).each( function () {
			var frame = null;
			var $s = $( this );

			$s.find( '.so-image-selector' ).click( function ( e ) {
				e.preventDefault();

				if ( frame === null ) {
					// Create the media frame.
					frame = wp.media( {
						// Set the title of the modal.
						title: 'choose',

						// Tell the modal to show only images.
						library: {
							type: 'image'
						},

						// Customize the submit button.
						button: {
							// Set the text of the button.
							text: 'Done',
							close: true
						}
					} );

					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().attributes;

						var url = attachment.url;
						if ( ! _.isUndefined( attachment.sizes ) ) {
							try {
								url = attachment.sizes.thumbnail.url;
							}
							catch ( e ) {
								// We'll use the full image instead
								url = attachment.sizes.full.url;
							}
						}
						$s.find( '.current-image' ).css( 'background-image', 'url(' + url + ')' );

						// Store the ID
						$s.find( 'input' ).val( attachment.id )
					} );
				}

				frame.open();

			} );

			// Handle clicking on remove
			$s.find( '.remove-image' ).click( function ( e ) {
				e.preventDefault();
				$s.find( '.current-image' ).css( 'background-image', 'none' );
				$s.find( 'input' ).val( '' );
			} );
		} );

		// Set up all the measurement fields
		this.$( '.style-field-measurement' ).each( function () {
			var $$ = $( this );

			var text = $$.find( 'input[type="text"]' );
			var unit = $$.find( 'select' );
			var hidden = $$.find( 'input[type="hidden"]' );

			text.focus( function(){
				$(this).select();
			} );

			/**
			 * Load value into the visible input fields.
			 * @param value
             */
			var loadValue = function( value ) {
				if( value === '' ) {
					return;
				}

				var re = /(?:([0-9\.,]+)(.*))+/;
				var valueList = hidden.val().split( ' ' );
				var valueListValue = [];
				for ( var i in valueList ) {
					var match = re.exec( valueList[i] );
					if ( ! _.isNull( match ) && ! _.isUndefined( match[1] ) && ! _.isUndefined( match[2] ) ) {
						valueListValue.push( match[1] );
						unit.val( match[2] );
					}
				}

				if( text.length === 1 ) {
					// This is a single input text field
					text.val( valueListValue.join( ' ' ) );
				}
				else {
					// We're dealing with a multiple field
					if( valueListValue.length === 1 ) {
						valueListValue = [ valueListValue[0], valueListValue[0], valueListValue[0], valueListValue[0] ];
					}
					else if( valueListValue.length === 2 ) {
						valueListValue = [ valueListValue[0], valueListValue[1], valueListValue[0], valueListValue[1] ];
					}
					else if( valueListValue.length === 3 ) {
						valueListValue = [ valueListValue[0], valueListValue[1], valueListValue[2], valueListValue[1] ];
					}

					// Store this in the visible fields
					text.each( function( i, el ) {
						$( el ).val( valueListValue[i] );
					} );
				}
			};
			loadValue( hidden.val() );

			/**
			 * Set value of the hidden field based on inputs
			 */
			var setValue = function( e ){
				var i;

				if( text.length === 1 ) {
					// We're dealing with a single measurement
					var fullString = text
						.val()
						.split( ' ' )
						.filter( function ( value ) {
							return value !== '';
						} )
						.map( function ( value ) {
							return value + unit.val();
						} )
						.join( ' ' );
					hidden.val( fullString );
				}
				else {
					var target = $( e.target ),
						valueList = [],
						emptyIndex = [],
						fullIndex = [];

					text.each( function( i, el ) {
						var value = $( el ).val( ) !== '' ? parseFloat( $( el ).val( ) ) : null;
						valueList.push( value );

						if( value === null ) {
							emptyIndex.push( i );
						}
						else {
							fullIndex.push( i );
						}
					} );

					if( emptyIndex.length === 3 && fullIndex[0] === text.index( target ) ) {
						text.val( target.val() );
						valueList = [ target.val(), target.val(), target.val(), target.val() ];
					}

					if( JSON.stringify( valueList ) === JSON.stringify( [ null, null, null, null ] ) ) {
						hidden.val('');
					}
					else {
						hidden.val( valueList.map( function( k ){
							return ( k === null ? 0 : k ) + unit.val();
						} ).join( ' ' ) );
					}
				}
			};

			// Set the value when ever anything changes
			text.change( setValue );
			unit.change( setValue );
		} );
	}

} );
