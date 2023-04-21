module.exports = {
	/**
	 * Check if we have copy paste available.
	 * @returns {boolean|*}
	 */
	canCopyPaste: function(){
		return typeof(Storage) !== "undefined" && panelsOptions.user;
	},

	/**
	 * Set the model that we're going to store in the clipboard
	 */
	setModel: function( model ){
		if( ! this.canCopyPaste() ) {
			return false;
		}

		var serial = panels.helpers.serialize.serialize( model );
		if( model instanceof  panels.model.row ) {
			serial.thingType = 'row-model';
		} else if( model instanceof  panels.model.widget ) {
			serial.thingType = 'widget-model';
		}

		// Can Page Builder cross domain copy paste?
		if (
			typeof SiteOriginPremium == 'object' &&
			typeof SiteOriginPremium.CrossDomainCopyPasteAddon == 'function' &&
			typeof SiteOriginPremium.CrossDomainCopyPasteAddon.allowed == 'boolean'
		) {
			SiteOriginPremium.CrossDomainCopyPasteAddon().copy( serial );
		}

		// Store this in local storage
		localStorage[ 'panels_clipboard_' + panelsOptions.user ] = JSON.stringify( serial );
		return true;
	},

	/**
	 * Check if the current model stored in the clipboard is the expected type
	 */
	isModel: function( expected ){
		if( ! this.canCopyPaste() ) {
			return false;
		}

		var clipboardObject = localStorage[ 'panels_clipboard_' + panelsOptions.user ];
		if( clipboardObject !== undefined ) {
			clipboardObject = JSON.parse(clipboardObject);
			return clipboardObject.thingType && clipboardObject.thingType === expected;
		}

		return false;
	},

	/**
	 * Get the model currently stored in the clipboard
	 */
	getModel: function( expected ){
		if( ! this.canCopyPaste() ) {
			return null;
		}

		var clipboardObject = localStorage[ 'panels_clipboard_' + panelsOptions.user ];
		if( clipboardObject !== undefined ) {
			clipboardObject = JSON.parse( clipboardObject );
			if( clipboardObject.thingType && clipboardObject.thingType === expected ) {
				return panels.helpers.serialize.unserialize( clipboardObject, clipboardObject.thingType, null );
			}
		}

		return null;
	},
};
