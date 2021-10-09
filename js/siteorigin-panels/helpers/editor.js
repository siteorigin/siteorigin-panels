module.exports = {
	isBlockEditor: function() {
		return typeof wp.blocks !== 'undefined' && jQuery( '.block-editor-page' ).length
	},

	isClassicEditor: function( builder ) {
		return builder.attachedToEditor && builder.$el.is( ':visible' );
	},
}
