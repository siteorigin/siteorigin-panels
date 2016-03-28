var iframe = window.frameElement;

if (iframe){
	iframe.contentDocument = document;

	var parent = window.parent;
	jQuery( parent.document ).ready( function(){//wait for parent to make sure it has jQuery ready
		var parentjQuery = parent.jQuery;

		parentjQuery(iframe).trigger("iframeloading");

		jQuery( function(){
			parentjQuery(iframe).trigger("iframeready");
		} );

	} );
}
