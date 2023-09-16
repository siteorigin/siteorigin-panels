module.exports = {

	generateUUID: function(){
		var d = new Date().getTime();
		if( window.performance && typeof window.performance.now === "function" ){
			d += performance.now(); //use high-precision timer if available
		}
		var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function(c) {
			var r = (d + Math.random()*16)%16 | 0;
			d = Math.floor(d/16);
			return ( c == 'x' ? r : (r&0x3|0x8) ).toString(16);
		} );
		return uuid;
	},

	processTemplate: function ( s ) {
		if ( _.isUndefined( s ) || _.isNull( s ) ) {
			return '';
		}
		s = s.replace( /{{%/g, '<%' );
		s = s.replace( /%}}/g, '%>' );
		s = s.trim();
		return s;
	},

	// From this SO post: http://stackoverflow.com/questions/6139107/programmatically-select-text-in-a-contenteditable-html-element
	selectElementContents: function( element ) {
		var range = document.createRange();
		range.selectNodeContents( element );
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	},

	saveHeartbeat: function( thisDialog ) {
		jQuery( '.so-saveinline' ).attr( 'disabled', 'disabled' )
		jQuery( document ).one( 'heartbeat-send', function( event, data ) {
			data.panels = JSON.stringify( {
				data: thisDialog.builder.model.getPanelsData(),
				nonce: jQuery( '#_sopanels_nonce' ).val(),
				id: thisDialog.builder.config.postId
			} );
		} );
		jQuery( document ).one( 'heartbeat-tick', function( event, data ) {
			jQuery( '.so-saveinline' ).removeAttr( 'disabled' )
		} );
		wp.autosave.server.triggerSave()
	},

}
