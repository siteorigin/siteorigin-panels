=== Page Builder by SiteOrigin ===
Tags: page builder, responsive, widget, widgets, builder, page, admin, gallery, content, cms, pages, post, css, layout, grid
Requires at least: 3.9
Tested up to: 4.2
Stable tag: 2.1.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Donate link: http://siteorigin.com/page-builder/#donate
Contributors: gpriday, braam-genis

Build responsive page layouts using the widgets you know and love using this simple drag and drop page builder.

== Description ==

[vimeo https://vimeo.com/114529361]

Page Builder by SiteOrigin is the most popular page creation plugin for WordPress. It makes it easy to create responsive column based content, using the widgets you know and love. Your content will accurately adapt to all mobile devices, ensuring your site is mobile-ready. Read more on [SiteOrigin](https://siteorigin.com/page-builder/).

We've created an intuitive interface that looks just like WordPress itself. It's easy to learn, so you'll be building beautiful, responsive content in no time.

Page Builder works with standard WordPress widgets, so you'll always find the widget you need. We've created the SiteOrigin Widgets Bundle to give you all the most common widgets, and with a world of plugins out there, you'll always find the widget you need.

= It works with your theme. =

Page Builder gives you complete freedom to choose any WordPress theme you like. It's not a commitment to a single theme or theme developer. The advantage is that you're free to change themes as often as you like. Your content will always come along with you.

We've also made some fantastic [free themes](https://siteorigin.com/theme/) that work well with Page Builder.

= No coding required. =

Page Builder's simple drag and drop interface means you'll never need to write a single line of code. Page Builder generates all the highly efficient code for you.

We don't limit you with a set of pre-defined row layouts. Page Builder gives you complete flexibility. You can choose the exact number of columns for each row and the precise weight of each column - down to the decimal point. This flexibility is all possible using our convenient row builder. And, if you're not sure what you like, the Row Builder will guide you towards beautifully proportioned content using advanced ratios.

= Live Editing. =

Page Builder supports live editing. This tool lets you see your content and edit widgets in real-time. It's the fastest way to adjust your content quickly and easily.

= History Browser. =

This tool lets you roll forward and back through your changes. It gives you the freedom to experiment with different layouts and content without the fear of breaking your content.

= Row and widget styles. =

Row and widget styles give you all the control you need to make your content uniquely your own. Change attributes like paddings, background colours and column spacing. You can also enter custom CSS and CSS classes if you need even finer grained control.

= It's free, and always will be. =

Page Builder is our commitment to the democratization of content creation. Like WordPress, Page Builder is, and always will be free. We'll continue supporting and developing it for many years to come. It'll only get better from here.

We offer free support on the [SiteOrigin support forums](https://siteorigin.com/thread/).

= Actively Developed =

Page Builder is actively developed with new features and exciting enhancements all the time. Keep track on the [Page Builder GitHub repository](https://github.com/siteorigin/siteorigin-panels).

Read the [Page Builder developer docs](https://siteorigin.com/docs/page-builder/) if you'd like to develop for Page Builder.

= Available in 17 Languages =

Through the efforts of both professional translators and our community, Page Builder is available in the following languages:  Afrikaans, Bulgarian, Chinese (simplified), Danish, Dutch, English, Finnish, French, German, Hindi, Italian, Japanese, Polish, Portuguese (BR), Russian, Spanish and Swedish.

Join our [translation project](https://poeditor.com/join/project?hash=82847115cc12f5d35ec3d066495dca1a) if you'd like to help improve our translations or add more languages.

== Installation ==

1. Upload and install Page Builder in the same way you'd install any other plugin.
2. Read the [usage documentation](http://siteorigin.com/page-builder/documentation/) on SiteOrigin.

== Screenshots ==

1. The page builder interface.
2. Powerful widget insert dialog with groups and search.
3. Live Editor that lets you change your content in real time.
4. Undo changes with the History Browser.
5. Row Builder that gives unlimited flexibility.

== Documentation ==

[Documentation](http://siteorigin.com/page-builder/documentation/) is available on SiteOrigin.

== Frequently Asked Questions ==

= How do I move a site created with Page Builder from one server to another? =

We recommend the [duplicator plugin](https://wordpress.org/plugins/duplicator/). We've tested it in several instances and it always works well with Page Builder data.

= Can I bundle Page Builder with my theme? =

Yes, provided your theme is licensed under GPL or a compatible license. If you're publishing your theme on ThemeForest, you must select the GPL license instead of their regular license.

Page Builder is actively developed and updated, so generally I'd recommend that you have your users install the original plugin so they can receive updates. You can try [TGM Plugin Activation](http://tgmpluginactivation.com/).

= Will plugin X work with Page Builder? =

I've tried to ensure that Page Builder is compatible with most plugin widgets. It's best to just download Page Builder and test for yourself.

== Changelog ==

= 2.1.2 =
* Removed rendered content cache introduced in 2.1.1

= 2.1.1 =
* Added translations for 16 additional languages
* Modified strings to improve translatability.
* Row and Widget style measurement fields now allow multiple values.
* New rows now added below row of currently selected cell.
* Orphaned widgets in edited rows are now moved into remaining cell.
* Made panels javascript object globally accessible.
* panels_info array now passed into widget rendering function.
* Removed unnecessary action triggers from customizer that was breaking some themes.
* Disabling Page Builder on a page now properly creates history entry.
* Small fixes to sidebars emulator.
* Fixed import/export on custom home page interface.
* Removed call to filter_input from global space.
* Fixed bundled widgets conflict with Yoast SEO.
* Prevented double rendering issue with Yoast SEO.

= 2.1 =
* Improved Page Builder settings page.
* Added sidebar emulation, which makes a Page Builder page appear to be a sidebar. Improves compatibility with other widgets.
* Removed jPlayer. Self hosted widget (legacy) now uses MediaElement.
* Small usability improvements.
* Added legacy widget migration for gallery widget.
* Layout file based import/export feature.
* Added widget title setting to change widget title HTML.
* Added setting to control full width container.
* Fixed: Handling of namespaced widgets.
* Fixed: Layout Builder widget now works in the Customizer.
* Fixed: Custom home page interface now properly uses page_on_front.
* Fixed: Page URL for home page in custom home page.
* Fixed: Custom home page encoding.

= 2.0.7 =
* Fixed issue that prevented prebuilt layouts from showing up.

= 2.0.6 =
* Added nonce to all admin requests.
* Fixed live editor for missing widgets.
* Fixed handling of multi-line row/widget custom CSS.
* Fixed issue with encoding of panels_data.

= 2.0.5 =
* Added proper escaping in widget form.

= 2.0.4 =
* Changed how data is json encoded to prevent malformed Page Builder data.
* Fixed import/export.
* Added layout widget notification (doesn't work in customizer).
* Fixed translation domains.
* Additional hooks and filters.

= 2.0.3 =
* Fixed issue with double calling sidebar_admin_setup that was breaking some widgets.
* Fixed fetching content from TinyMCE in text mode.

= 2.0.2 =
* Fixed fatal error in validation for PHP < 5.5

= 2.0.1 =
* Fixed issue with preview causing content loss in standard editor.
* Fixed issue with Black Studio TinyMCE
* Changed templating tags in js-templates.php to prevent fatal errors with some server configurations.

= 2.0 =
* Complete rewrite of Page Builder Javascript using Backbone.
* Complete UI redesign.
* Grid Engine rewrite for more efficient CSS.
* Various performance enhancements and bug fixes.

== Upgrade Notice ==

Page Builder 2.0 is a major update. Please ensure that you backup your database before updating from a 1.x version. Updating from 1.x to 2.0 is a smooth transition, but it's always better to have a backup.