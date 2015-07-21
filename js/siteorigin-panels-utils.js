/**
 * Various utilities for Page Builder
 *
 * @copyright Greg Priday 2015 - <https://siteorigin.com/>
 * @license GPL 3.0 http://www.gnu.org/licenses/gpl.html
 */

/* global Backbone, _, jQuery, tinyMCE, soPanelsOptions, confirm */

( function( $, _, panelsOptions ){

    var panels = window.siteoriginPanels;

    panels.utils = {};

    /**
     * A contextual menu for right clicks
     */
    panels.utils.menu = Backbone.View.extend({
        wrapperTemplate: _.template( $('#siteorigin-panels-context-menu').html().panelsProcessTemplate() ),

        contexts: [],
        active: false,

        /**
         * Intialize the context menu
         */
        initialize: function(){
            this.listenContextMenu();
            this.render();
            this.attach();
        },

        listenContextMenu: function(){
            var thisView = this;

            $(window).on('contextmenu', function(e){
                thisView.active = false;

                // Other components should listen to activate_context
                thisView.trigger('activate_context', e, thisView);

                if( thisView.active ) {
                    // We don't want the default event to happen.
                    e.preventDefault();

                    thisView.openMenu( {
                        left: e.pageX,
                        top: e.pageY
                    } );
                }
            } );
        },

        render: function(){
            console.log( this.wrapperTemplate() );
            this.setElement( this.wrapperTemplate() );
        },

        attach: function(){
            this.$el.appendTo('body');
        },

        openMenu: function( position ){
            this.trigger('open_menu');

            // Start listening for situations when we should close the menu
            $(window).on('keyup', {menu: this}, this.keyboardListen);
            $(window).on('click', {menu: this}, this.clickOutsideListen);

            // position the contextual menu
            this.$el.css({
                left: position.left,
                top: position.top
            }).show();
        },

        closeMenu: function(){
            this.trigger('close_menu');

            // Stop listening for situations when we should close the menu
            $(window).off('keyup', this.keyboardListen);
            $(window).off('click', this.clickOutsideListen);

            this.$el.hide();
        },

        /**
         * Keyboard events handler
         */
        keyboardListen: function(e) {
            var menu = e.data.menu;
            if (e.which === 27) {
                menu.closeMenu();
            }
        },

        clickOutsideListen: function(e){
            var menu = e.data.menu;
            if( menu.$el.is(':visible') && !menu.isOverEl( menu.$el, e ) ) {
                menu.closeMenu();
            }
        },

        addSection: function( settings, items, callback ){
            this.active = true;
        },

        /**
         * Check if the given mouse event is over the element
         * @param el
         * @param event
         */
        isOverEl: function(el, event) {
            var elPos = [
                [ el.offset().left, el.offset().top ],
                [ el.offset().left + el.outerWidth(), el.offset().top + el.outerHeight() ]
            ];

            // Return if this event is over the given element
            return (
                event.pageX >= elPos[0][0] && event.pageX <= elPos[1][0] &&
                event.pageY >= elPos[0][1] && event.pageY <= elPos[1][1]
            );
        }

    });

} )( jQuery, _, soPanelsOptions );