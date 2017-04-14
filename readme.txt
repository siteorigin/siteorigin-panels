=== Page Builder by SiteOrigin ===
Tags: page builder, responsive, widget, widgets, builder, page, admin, gallery, content, cms, pages, post, css, layout, grid
Requires at least: 4.4
Tested up to: 4.7.3
Stable tag: trunk
Build time: unbuilt
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Donate link: https://siteorigin.com/downloads/contribution/
Contributors: gpriday, braam-genis

Build responsive page layouts using the widgets you know and love using this simple drag and drop page builder.

== Description ==

SiteOrigin Page Builder is the most popular page creation plugin for WordPress. It makes it easy to create responsive column based content, using the widgets you know and love. Your content will accurately adapt to all mobile devices, ensuring your site is mobile-ready. Read more on [SiteOrigin](https://siteorigin.com/page-builder/).

We've created an intuitive interface that looks just like WordPress itself. It's easy to learn, so you'll be building beautiful, responsive content in no time.

[vimeo https://vimeo.com/114529361]

Page Builder works with standard WordPress widgets, so you'll always find the widget you need. We've created the [SiteOrigin Widgets Bundle](https://wordpress.org/plugins/so-widgets-bundle/) to give you all the most common widgets, and with a world of plugins out there, you'll always find the widget you need.

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

We've tried to ensure that Page Builder is compatible with most plugin widgets. It's best to just download Page Builder and test for yourself.

== Changelog ==

= 2.5.1 - 14 April 2017 =
* Added null function for Sydney theme compatibility.
* Added method for including additional external layout directories.
* Added fix for old Vantage PB layout compatibility.
* Fixed Firefox layout issues.
* Fixed positioning of edit row dropdown.
* Fixed warning coming from legacy widgets.

= 2.5 - 11 April 2017 =
* Large code refactoring for improved performance.
* Added row and widget labelling, and color labels for rows.
* Added cell specific styling.
* Redesign of main interface.
* Fixed performance issues with larger pages.
* Changed layouts to flexbox to remove need for negative margins.
* Added various cell vertical alignment settings.
* Add loop check to prevent rendering from running too soon.
* Page Builder can now more easily go to and from the WordPress editor.
* Added row and widget copy/paste. Currently only within a single site.
* Allow row and cell styles to be edited in add row dialog.
* Fixed visual jump before making rows full width.
* Added option to cache generated content. Can improve compatibility with shortcode based plugins.
* Added option to cache generated CSS in post_content. Allows page rendering without Page Builder active.
* Fixed namespace widgets in Live Editor.
* Increased maximum cell count to 12.
* Added prominent legacy widgets notice.
* Accept negative values in measurement style fields.
* Fixed Live Editor conflict with Layout Widgets in footer.
* Added mobile CSS style settings for rows, cells and widgets.
* Added a mechanism for including theme layouts as JSON files.
* Added buttons for free courses. Removed all references to premium addon.
* Removed translation files. These will be pulled from Glotpress instead.
* Widget update function is properly passed old widget instance.
* Various filters added for theme/plugin developers.
* Various minor bug fixes.
* Various small UX tweaks and improvements.

= 2.4.25 - 21 February 2017 =
* Fixed how widget wrapper IDs are generated.

= 2.4.24 - 3 February 2017 =
* Add row ID to style wrapper instead of actual row.
* Use more specific selectors for padding CSS.

= 2.4.23 - 31 January 2017 =
* Fixed padding issue introduced by new mobile padding setting.

= 2.4.22 - 31 January 2017 =
* Add WP Color Picker as a dependency for admin script.
* Include and check post ID in Live Editor. Fixes some issues with widgets using the_excerpt in Live Editor.
* Added mobile padding settings.
* Made all learning links/buttons removable in Page Builder settings.

= 2.4.21 - 19 December 2016 =
* Removed course toolbar links.
* Added filter for post loop query.
* Replace TinyMCE _.isUndefined() check with a typeof to prevent JS errors.

= 2.4.20 - 7 December 2016 =
* Removed Premium and contribution links.
* Added course links.

= 2.4.19 - 22 November 2016 =
* Added fixed background support.
* Cycle addon and contribution link.
* Small type and translation fixes.

= 2.4.18 - 7 November 2016 =
* Fixes for PHP 7 checker.
* Properly provide post ID on custom home page.
* Fixed CSS and JS URLs.
* Corrected post__not_in issue for query builder.

= 2.4.17 - 14 October 2016 =
* Removed old Stellar JS library.
* Added parallax setup after small timeout.
* Added way to add affiliate ID.
* Added tips signup link.

= 2.4.16 - 27 September 2016 =
* Added disableable upgrade notice.

= 2.4.15 - 6 September 2016 =
* Fixed legacy widgets check.

= 2.4.14 - 1 September 2016 =
* Fixes to sidebar emulator to prevent early rewrite rule building.
* Added option to completely disable sidebar emulator.

= 2.4.13 - 18 August 2016 =
* Fixed: layout directory imports in WordPress 4.6

= 2.4.12 - 17 August 2016 =
* Fixed layout directory requests for WordPress 4.6

= 2.4.11 - 15 August 2016 =
* Added esc_url to all add_query_arg calls.
* Improved measurement style field to handle multiple values.
* Hide empty columns after mobile collapse.

= 2.4.10 - 4 July 2016 =
* Made Live Editor quick link optional from Page Builder settings page.
* Added option to specify parallax motion.
* Fixed settings help link.
* Renamed Prebuilt to Layouts
* Reverted sidebars emulator change.
* Skip empty attributes in CSS generator class.

= 2.4.9 - May 26 2016 =
* Improved parallax library to upscale images to ensure enough of a parallax.
* Allow negative values in measurement fields.

= 2.4.8 - May 13 2016 =
* Reverted Wordfence fix from 2.4.7 - it raised other issues.

= 2.4.7 - May 13 2016 =
* Replaced parallax with custom implementation.
* Added more filters and actions.
* Allow other plugins to enable/disable certain builder functionality.
* Added unique IDs (UUID) to all widgets.
* Added fallback previewer for Live Editor.
* Prevent double filtering of $panels_data.
* Developer support for read-only widgets.
* Fixed issue that resulted in Wordfence blocking some Page Builder requests.
* Small interface improvements.

= 2.4.6 - April 13 2016 =
* Fixed Javascript errors with layout builder widget.

= 2.4.5 - April 13 2016 =
* Only trigger contextual menu for topmost dialog.
* Improved design of Live Editor preview.
* Added Live Editor link in the admin menu bar.

= 2.4.4 - April 6 2016 =
* Fixed ordering of new rows, widgets and cells in builder interface.
* Fixed Layout Builder widget sanitization error. Was causing fatal error on older versions of PHP.

= 2.4.3 - April 6 2016 =
* Fixed measurement style fields.
* Properly process raw widgets in Live Editor.
* Remove empty widgets from raw widget processing.

= 2.4.2 - April 4 2016 =
* Improved error handling and reporting.
* Don't add widget class for TwentySixteen theme.

= 2.4.1 - April 2 2016 =
* Fixed: Copying content from standard editor to Page Builder
* Fixed: Plugin conflict with Jetpack Widget Visibility and other plugins.

= 2.4 - April 1 2016 =
* Created new Live Editor.
* Changes to Page Builder admin HTML structure for Live Editor.
* New layout for prebuilt dialog.
* Now possible to append, prepend and replace layouts in prebuilt dialog.
* Fixed contextual menu in Layout Builder widget.
* Added row/widget actions to contextual menu.
* Clarified functionality of "Switch to Editor" button by renaming to "Revert to Editor".
* refreshPanelsData function is called more consistently.
* Various background performance enhancements.
* Full JS code refactoring.
* Fixed cell bottom margins with reverse collapse order.
* Improved window scroll locking for dialogs.
* Added `in_widget_form` action when rendering widget forms
* Custom home page now saves revisions.

= 2.3.2 - March 11 2016 =
* Fixed compatibility with WordPress 4.5

= 2.3.1 - February 10 2016 =
* Fixed fatal error on RTL sites.
* Made setting to enable tablet layout. Disabled by default.

= 2.3 - February 10 2016 =
* Delete preview panels data if there are no widgets.
* Added a collapse order field.
* Added custom row ID field.
* Fixed copy content setting.
* Added tablet responsive level.
* Fixed admin templates.
* Fix to ensure live editor works with HTTPs admin requests.
* Fix for Yoast SEO compatibility.
* Removed use of filter_input for HHVM issues.
* Added panelsStretchRows event after frontend row stretch event.
* Minor performance enhancements.
* Merged all separate JS files into a single Browserify compiled file.
* Added version numbers to some JS files to ensure cache busting.

= 2.2.2 - December 09 2015 =
* Fix tab name for WordPress 4.4. Was displaying undefined.
* Fix to ensure siteorigin-panels class is added to Page Builder pages.

= 2.2.1 - October 22 2015 =
* Various fixes to widget class names.
* Added option to remove default `widget` class from Page Builder widgets.
* Added action to saving home page.
* Added support for defaults in widget and row styles.
* Improve check for the homepage in sidebars simulator.
* Changed parallax library to improve theme compatibility.
* List privately published posts and pages under the prebuilt layout dialog Clone options.

= 2.2 - September 7 2015 =
* Added prebuilt layout directory.
* Added contextual menu for quick actions.
* Added parallax background images.
* Properly handle missing widgets when saving forms.
* Don't revert to default page template when using custom home page interface.
* Various minor bug fixes and improvements.

= 2.1.5 - August 19 2015 =
* Fixed handling of checkboxes and array fields.
* Properly position Page Builder tab in WordPress 4.3.

= 2.1.4 =
* Fixed handling of raw forms.

= 2.1.3 =
* Removed use of filter_input for compatibility with HHVM
* Fixed checkbox handling in forms.
* Removed unnecessary sprintf calls to lower chance of translations causing issues.
* More generic handling of builder instances to allow them to be used in different places.
* Use implicit check for whether editor is undefined or null.
* Added optional $widget_id parameter to siteorigin_panels_render_form.
* Improved checking for home page in sidebars emulator.
* Added a builder "type" to allow more targetted instances.

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
