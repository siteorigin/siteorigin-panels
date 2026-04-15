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
		var resetButton = function() {
			jQuery( '.so-saveinline' ).removeAttr( 'disabled' );
		};

		jQuery( '.so-saveinline' ).attr( 'disabled', 'disabled' )

		if ( this.shouldUseBlockEditorSave( thisDialog ) ) {
			this.saveBlockEditor( thisDialog, resetButton );
			return;
		}

		jQuery( document ).one( 'heartbeat-send', function( event, data ) {
			data.panels = JSON.stringify( {
				data: thisDialog.builder.model.getPanelsData(),
				nonce: jQuery( '#_sopanels_nonce' ).val(),
				id: thisDialog.builder.config.postId
			} );
		} );
		jQuery( document ).one( 'heartbeat-tick', function( event, data ) {
			resetButton();
		} );

		if (
			typeof wp !== 'undefined' &&
			wp.heartbeat &&
			wp.heartbeat.connectNow
		) {
			wp.heartbeat.connectNow();
			return;
		}

		if (
			typeof wp !== 'undefined' &&
			wp.autosave &&
			wp.autosave.server
		) {
			wp.autosave.server.triggerSave();
			return;
		}

		resetButton();
		if ( window.console && console.warn ) {
			console.warn( 'Unable to save post.' );
		}
	},

	shouldUseBlockEditorSave: function( thisDialog ) {
		return (
			typeof wp !== 'undefined' &&
			wp.data &&
			wp.data.select &&
			wp.data.dispatch &&
			wp.data.select( 'core/editor' ) &&
			thisDialog &&
			thisDialog.builder &&
			thisDialog.builder.config &&
			thisDialog.builder.config.editorType === 'standalone'
		);
	},

	saveBlockEditor: function( thisDialog, resetButton ) {
		var self = this;
		var pendingContentChange = thisDialog &&
			thisDialog.builder &&
			thisDialog.builder.pendingContentChange;

		Promise.resolve( pendingContentChange )
			.catch( function() {} )
			.then( function() {
				return self.waitForPostSavingUnlock();
			} )
			.then( function() {
				return self.dispatchBlockEditorSave();
			} )
			.then( resetButton )
			.catch( function() {
				resetButton();
				if ( window.console && console.warn ) {
					console.warn( 'Unable to save post.' );
				}
			} );
	},

	dispatchBlockEditorSave: function() {
		var self = this;
		var editorSelect = wp.data.select( 'core/editor' );
		var editorDispatch = wp.data.dispatch( 'core/editor' );

		if ( editorSelect.isSavingPost() ) {
			return this.waitForPostSaveCompletion( false )
				.then( function() {
					return self.dispatchBlockEditorSave();
				} );
		}

		if (
			editorSelect.isEditedPostDirty &&
			! editorSelect.isEditedPostDirty()
		) {
			return Promise.resolve();
		}

		editorDispatch.savePost();
		return this.waitForPostSaveCompletion( true );
	},

	waitForPostSavingUnlock: function() {
		var editorSelect = wp.data.select( 'core/editor' );
		if (
			! editorSelect.isPostSavingLocked ||
			! editorSelect.isPostSavingLocked()
		) {
			return Promise.resolve();
		}

		return new Promise( function( resolve ) {
			var unsubscribe = wp.data.subscribe( function() {
				editorSelect = wp.data.select( 'core/editor' );
				if (
					! editorSelect.isPostSavingLocked ||
					! editorSelect.isPostSavingLocked()
				) {
					unsubscribe();
					setTimeout( resolve, 0 );
				}
			} );
		} );
	},

	waitForPostSaveCompletion: function( requireStart ) {
		var editorSelect = wp.data.select( 'core/editor' );
		var isSaving = editorSelect.isSavingPost() ||
			( editorSelect.isAutosavingPost && editorSelect.isAutosavingPost() );

		if ( ! requireStart && ! isSaving ) {
			return Promise.resolve();
		}

		return new Promise( function( resolve ) {
			var saveStarted = isSaving;
			var checks = 0;
			var unsubscribe = wp.data.subscribe( function() {
				editorSelect = wp.data.select( 'core/editor' );
				isSaving = editorSelect.isSavingPost() ||
					( editorSelect.isAutosavingPost && editorSelect.isAutosavingPost() );

				if ( isSaving ) {
					saveStarted = true;
				}

				if ( saveStarted && ! isSaving ) {
					unsubscribe();
					setTimeout( resolve, 0 );
					return;
				}

				checks++;
				if ( checks >= 120 ) {
					unsubscribe();
					resolve();
				}
			} );
		} );
	},

}
