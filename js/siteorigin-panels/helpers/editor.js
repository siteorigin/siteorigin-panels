module.exports = {
	isBlockEditor: function() {
		return typeof wp.blocks !== 'undefined';
	},

	isClassicEditor: function( builder ) {
		return builder.attachedToEditor && builder.$el.is( ':visible' );
	},
}
