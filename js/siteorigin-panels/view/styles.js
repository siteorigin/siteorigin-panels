var panels = window.panels, $ = jQuery;

module.exports = Backbone.View.extend( {

	stylesLoaded: false,

	events: {
		'keyup .so-image-selector': function( e ) {
			if ( e.which == 13 ) {
				this.$el.find( '.select-image' ).trigger( 'click' );
			}
		},
	},

	initialize: function () {

	},

	/**
	 * Render the visual styles object.
	 *
	 * @param stylesType
	 * @param postId
	 * @param args
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

		this.$el.addClass( 'so-visual-styles so-' + stylesType + '-styles so-panels-loading' );

		var postArgs = {
			builderType: args.builderType
		};

		if ( stylesType === 'widget' ) {
			postArgs.widget = this.model.get( 'class' );
		}

		if ( stylesType === 'cell') {
			postArgs.index = args.index;
		}

		// Load the form
		$.post(
			panelsOptions.ajaxurl,
			{
				action: 'so_panels_style_form',
				type: stylesType,
				style: this.model.get( 'style' ),
				args: JSON.stringify( postArgs ),
				postId: postId
			},
			null,
			'html'
		).done( function ( response ) {
			this.$el.html( response );
			this.setupFields();
			this.stylesLoaded = true;
			this.trigger( 'styles_loaded', !_.isEmpty( response ) );
			if ( !_.isNull( args.dialog ) ) {
				args.dialog.trigger( 'styles_loaded', !_.isEmpty( response ) );
			}
		}.bind( this ) )
		.fail( function ( error ) {
			var html;
			if ( error && error.responseText ) {
				html = error.responseText;
			} else {
				html = panelsOptions.forms.loadingFailed;
			}

			this.$el.html( html );
		}.bind( this ) )
		.always( function () {
			this.$el.removeClass( 'so-panels-loading' );
		}.bind( this ) );

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

			$s.find( '.style-section-head' ).on( 'click keypress', function( e ) {
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
			$.fn.handleAlphaDefault = function() {
				var $parent = $( this ).parents( '.wp-picker-container' );
				var $colorResult = $parent.find( '.wp-color-result' );
				if ( $parent.find( '.wp-color-picker[data-alpha-enabled]' ).length ) {
					$colorResult.css( 'background-image', $( this ).val() == '' ? 'none' : alphaImage );
				} else {
					$colorResult.css( 'background-image', 'none' );
				}
			}

			// Trigger a change event when user selects a color.
			panelsOptions.wpColorPickerOptions.change = function( e, ui ) {
				setTimeout( function() {
					$( e.target ).handleAlphaDefault();
					$( e.target ).trigger( 'change' );
				}, 100 );
			};

			this.$( '.so-wp-color-field' ).wpColorPicker( panelsOptions.wpColorPickerOptions );
			var alphaImage = this.$( '.wp-color-picker[data-alpha-enabled]' ).parents( '.wp-picker-container' ).find( '.wp-color-result' ).css( 'background-image' );
			this.$( '.wp-color-picker[data-alpha-enabled]' ).on( 'change', function() {
				$( this ).handleAlphaDefault();
			} ).trigger( 'change' );
		}

		// Set up the image select fields
		this.$( '.style-field-image' ).each( function () {
			var frame = null;
			var $s = $( this );

			$s.find( '.so-image-selector' ).on( 'click', function( e ) {
				e.preventDefault();

				if ( frame === null ) {
					// Create the media frame.
					frame = wp.media( {
						// Set the title of the modal.
						title: panelsOptions.add_media,

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
						$s.find( '.so-image-selector > input' ).val( attachment.id ).trigger( 'change' )

						$s.find( '.remove-image' ).removeClass( 'hidden' );
					} );
				}

				// Prevent loop that occurs if you close the frame using the close button while focused on the trigger.
				$( this ).next().focus();

				frame.open();
			} );

			// Handle clicking on remove
			$s.find( '.remove-image' ).on( 'click', function( e ) {
				e.preventDefault();
				$s.find( '.current-image' ).css( 'background-image', 'none' );
				$s.find( '.so-image-selector > input' ).val( '' );
				$s.find( '.remove-image' ).addClass( 'hidden' );
			} );
		} );

		// Set up all the measurement fields
		this.$( '.style-field-measurement' ).each( function () {
			var $$ = $( this );

			var text = $$.find( 'input[type="text"]' );
			var unit = $$.find( 'select' );
			var hidden = $$.find( 'input[type="hidden"]' );

			text.on( 'focus', function(){
				$( this ).trigger( 'select' );
			} );

			/**
			 * Load value into the visible input fields.
			 * @param value
			 */
			var loadValue = function( value ) {
				if( value === '' ) {
					return;
				}

				var re = /(?:([0-9\.,\-]+)(.*))+/;
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
			text.on( 'change', setValue );
			unit.on( 'change', setValue );
		} );

		// Set up all the toggle fields
		this.$( '.style-field-toggle' ).each( function () {
			var $$ = $( this );
			var checkbox = $$.find( '.so-toggle-switch-input' );
			var settings = $$.find( '.so-toggle-fields' );

			checkbox.on( 'change', function() {
				if ( $( this ).prop( 'checked' ) ) {
					settings.slideDown();
				} else {
					settings.slideUp();
				}
			} );
		} );
		this.$( '.style-field-toggle .so-toggle-switch-input' ).trigger( 'change' );

		// Conditionally show Background related settings.
		var $background_image = this.$( '.so-field-background_image_attachment' ),
			$background_image_display = this.$( '.so-field-background_display' ),
			$background_image_size = this.$( '.so-field-background_image_size' );

		if (
			$background_image.length &&
			(
				$background_image_display.length ||
				$background_image_size.length
			)
		) {
			var soBackgroundImageVisibility = function() {
				var hasImage = $background_image.find( '[name="style[background_image_attachment]"]' );

				if ( ! hasImage.val() || hasImage.val() == 0 ) {
					hasImage = $background_image.find( '[name="style[background_image_attachment_fallback]"]' );
				}

				if ( hasImage.val() && hasImage.val() != 0 ) {
					$background_image_display.show();
					$background_image_size.show();
				} else {
					$background_image_display.hide();
					$background_image_size.hide();
				}
			}
			soBackgroundImageVisibility();
			$background_image.find( '[name="style[background_image_attachment]"], [name="style[background_image_attachment_fallback]"]' ).on( 'change', soBackgroundImageVisibility );
			$background_image.find( '.remove-image' ).on( 'click', soBackgroundImageVisibility );
		}

		// Conditionally show Border related settings.
		var $border_color = this.$( '.so-field-border_color' ),
			$border_thickness = this.$( '.so-field-border_thickness' );

		if ( $border_color.length && $border_thickness.length ) {
			var soBorderVisibility = function() {
				if ( $border_color.find( '.so-wp-color-field' ).val() ) {
					$border_thickness.show();
					$border_thickness.show();
				} else {
					$border_thickness.hide();
					$border_thickness.hide();
				}
			}
			soBorderVisibility();
			$border_color.find( '.so-wp-color-field' ).on( 'change', soBorderVisibility );
			$border_color.find( '.wp-picker-clear' ).on( 'click', soBorderVisibility );
		}

		// Allow other plugins to setup custom fields.
		$( document ).trigger( 'setup_style_fields', this );
	}

} );
