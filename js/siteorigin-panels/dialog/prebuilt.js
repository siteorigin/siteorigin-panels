var panels = window.panels, $ = jQuery;

module.exports = panels.view.dialog.extend( {

    directoryTemplate : _.template( $('#siteorigin-panels-directory-items').html().panelsProcessTemplate() ),

    builder: null,
    dialogClass : 'so-panels-dialog-prebuilt-layouts',

    layoutCache : {},
    currentTab : false,
    directoryPage : 1,

    events: {
        'click .so-close': 'closeDialog',
        'click .so-sidebar-tabs li a' : 'tabClickHandler',
        'click .so-content .layout' : 'layoutClickHandler',
        'keyup .so-sidebar-search' : 'searchHandler',

        // The directory items
        'click .so-content .so-directory-item .so-button-use' : 'directoryClickHandler',
    },

    /**
     * Initialize the prebuilt dialog.
     */
    initializeDialog: function(){
        var thisView = this;

        this.on('open_dialog', function(){
            thisView.$('.so-sidebar-tabs li a').first().click();
            thisView.$('.so-status').removeClass('so-panels-loading');
        });
    },

    /**
     * Render the prebuilt layouts dialog
     */
    render: function(){
        this.renderDialog( this.parseDialogContent( $('#siteorigin-panels-dialog-prebuilt').html(), {} ) );
    },

    /**
     *
     * @param e
     * @return {boolean}
     */
    tabClickHandler: function(e){
        this.$('.so-sidebar-tabs li').removeClass('tab-active');

        var $$ = $(e.target);
        var tab = $$.attr('href').split('#')[1];
        $$.parent().addClass( 'tab-active' );

        var thisView = this;

        // Empty everything
        this.$('.so-content').empty();

        thisView.currentTab = tab;
        if( tab == 'import' ) {
            this.displayImportExport();
        }
        else {
            this.displayLayoutDirectory('', 1, tab);
        }

        thisView.$('.so-sidebar-search').val('');

        return false;
    },

    /**
     * Display and setup the import/export form
     */
    displayImportExport: function(){
        var c = this.$( '.so-content').empty().removeClass( 'so-panels-loading' );
        c.html( $('#siteorigin-panels-dialog-prebuilt-importexport').html() );

        var thisView = this;
        var uploadUi = thisView.$('.import-upload-ui').hide();

        // Create the uploader
        var uploader = new plupload.Uploader({
            runtimes : 'html5,silverlight,flash,html4',

            browse_button : uploadUi.find('.file-browse-button').get(0),
            container : uploadUi.get(0),
            drop_element : uploadUi.find('.drag-upload-area').get(0),

            file_data_name : 'panels_import_data',
            multiple_queues : false,
            max_file_size : panelsOptions.plupload.max_file_size,
            url : panelsOptions.plupload.url,
            flash_swf_url : panelsOptions.plupload.flash_swf_url,
            silverlight_xap_url : panelsOptions.plupload.silverlight_xap_url,
            filters : [
                { title : panelsOptions.plupload.filter_title, extensions : 'json' }
            ],

            multipart_params : {
                action : 'so_panels_import_layout'
            },

            init: {
                PostInit: function(uploader){
                    if( uploader.features.dragdrop ) {
                        uploadUi.addClass('has-drag-drop');
                    }
                    uploadUi.show().find('.progress-precent').css('width', '0%');
                },
                FilesAdded: function(uploader){
                    uploadUi.find('.file-browse-button').blur();
                    uploadUi.find('.drag-upload-area').removeClass('file-dragover');
                    uploadUi.find('.progress-bar').fadeIn('fast');
                    uploader.start();
                },
                UploadProgress: function(uploader, file){
                    uploadUi.find('.progress-precent').css('width', file.percent + '%');
                },
                FileUploaded : function(uploader, file, response){
                    var layout = JSON.parse( response.response );
                    if( typeof layout.widgets !== 'undefined' ) {
                        thisView.builder.addHistoryEntry('prebuilt_loaded');
                        thisView.builder.model.loadPanelsData(layout, thisView.builder.model.layoutPosition.AFTER);
                        thisView.closeDialog();
                    }
                    else {
                        alert( panelsOptions.plupload.error_message );
                    }
                },
                Error: function(){
                    alert( panelsOptions.plupload.error_message );
                }
            }
        });
        uploader.init();

        // This is
        uploadUi.find('.drag-upload-area')
            .on('dragover', function(){
                $(this).addClass('file-dragover');
            })
            .on('dragleave', function(){
                $(this).removeClass('file-dragover');
            });

        // Handle exporting the file
        c.find('.so-export').submit( function(e){
            var $$ = jQuery(this);
            $$.find('input[name="panels_export_data"]').val( JSON.stringify( thisView.builder.model.getPanelsData() ) );
        } );

    },

    /**
     * Display the layout directory tab.
     *
     * @param query
     */
    displayLayoutDirectory: function( search, page, type ){
        var thisView = this;
        var c = this.$( '.so-content').empty().addClass('so-panels-loading');

        if( search === undefined ) {
            search = '';
        }
        if( page === undefined ) {
            page = 1;
        }
        if( type === undefined ) {
            type = 'directory';
        }

        if( type === 'directory' && !panelsOptions.directory_enabled ) {
            // Display the button to enable the prebuilt layout
            c.removeClass( 'so-panels-loading' ).html( $('#siteorigin-panels-directory-enable').html() );
            c.find('.so-panels-enable-directory').click( function(e){
                e.preventDefault();
                // Sent the query to enable the directory, then enable the directory
                $.get(
                    panelsOptions.ajaxurl,
                    { action: 'so_panels_directory_enable' },
                    function(){

                    }
                );

                // Enable the layout directory
                panelsOptions.directory_enabled = true;
                c.addClass( 'so-panels-loading' );
                thisView.displayLayoutDirectory( search, page );
            } );
            return;
        }

        // Get all the items for the current query
        $.get(
            panelsOptions.ajaxurl,
            {
                action: 'so_panels_layouts_query',
                search: search,
                page: page,
                type: type,
            },
            function( data ){
                // Skip this if we're no longer viewing the layout directory
                if( thisView.currentTab !== type ) return;

                // Add the directory items
                c.removeClass( 'so-panels-loading').html( thisView.directoryTemplate( data ) );

                // Lets setup the next and previous buttons
                var prev = c.find('.so-previous'), next = c.find('.so-next');

                if( page <= 1 ) {
                    prev.addClass('button-disabled');
                }
                else {
                    prev.click(function(e){
                        e.preventDefault();
                        thisView.displayLayoutDirectory( search, page - 1, thisView.currentTab );
                    });
                }
                if( page === data.max_num_pages || data.max_num_pages === 0 ) {
                    next.addClass('button-disabled');
                }
                else {
                    next.click(function(e){
                        e.preventDefault();
                        thisView.displayLayoutDirectory( search, page + 1, thisView.currentTab );
                    });
                }

                if( search !== '' ) {
                    c.find('.so-directory-browse').html( panelsOptions.loc.search_results_header + '"<em>' + _.escape(search) + '</em>"' );
                }

                // Handle nice preloading of the screenshots
                c.find('.so-screenshot').each( function(){
                    var $$ = $(this), $a = $$.find('.so-screenshot-wrapper');
                    $a.css( 'height', ($a.width()/4*3) + 'px' ).addClass('so-loading');

                    if( $$.data('src') !== '' ) {
                        // Set the initial height
                        var $img = $('<img/>').attr('src', $$.data('src')).load(function(){
                            $a.removeClass('so-loading').css('height', 'auto');
                            $img.appendTo($a).hide().fadeIn('fast');
                        });
                    }
                    else {
                        $('<img/>').attr('src', panelsOptions.prebuiltDefaultScreenshot).appendTo($a).hide().fadeIn('fast');
                    }

                } );
            },
            'json'
        );
    },

    /**
     * Load a particular layout into the builder.
     *
     * @param id
     */
    directoryClickHandler: function( e ){
        e.preventDefault();
        var $$ = $(e.currentTarget), thisView = this;

        if( !confirm(panelsOptions.loc.prebuilt_confirm) ) {
            return false;
        }
        this.setStatusMessage(panelsOptions.loc.prebuilt_loading, true);

        $.get(
            panelsOptions.ajaxurl,
            {
                action : 'so_panels_get_layout',
                lid : $$.data('layout-id'),
                type : $$.data('layout-type')
            },
            function(layout){
                if( layout.error !== undefined ) {
                    // There was an error
                    alert( layout.error );
                }
                else {
                    thisView.setStatusMessage('', false);
                    thisView.builder.addHistoryEntry('prebuilt_loaded');
                    thisView.builder.model.loadPanelsData(layout, thisView.builder.model.layoutPosition.AFTER);
                    thisView.closeDialog();
                }
            }
        );
    },

    /**
     * Handle an update to the search
     */
    searchHandler: function( e ){
        if( e.keyCode === 13 ) {
            this.displayLayoutDirectory( $(e.currentTarget).val(), 1, this.currentTab );
        }
    }
} );
