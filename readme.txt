=== Page Builder by SiteOrigin ===
Contributors: gpriday
Tags: page builder, responsive, widget, widgets, builder, page, admin, gallery, content, cms, pages, post, css, layout, grid
Requires at least: 3.7
Tested up to: 4.0
Stable tag: trunk
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Donate link: http://siteorigin.com/page-builder/#donate

Build responsive page layouts using the widgets you know and love using this simple drag and drop page builder.

== Description ==

[vimeo http://vimeo.com/59561067]

WordPress has evolved into a fully functional CMS. Page Builder (previously called Panels) completes the transition by giving you a way to create responsive column layouts using the widgets you know and love.

= Use Your Widgets =

You know widgets. They're the things you add to your sidebars. Page Builder makes all your widgets even more useful by turning them into the building blocks of your pages.

We've included a few useful widgets, but it works with a lot of other widgets and plugins out there.

= Works with Most Themes =

Page Builder works with most well made themes. The only requirement is that your theme supports pages. And if your theme is responsive, change a few settings and boom, your layouts will work with your theme and collapse into a single column on mobile devices.

There are loads free and premium themes that work with the Page Builder, we have our own collection of [free themes](http://siteorigin.com/) if you'd like to use one of ours.

Page Builder [Documentation](http://siteorigin.com/page-builder/documentation/) is available on SiteOrigin and we offer free support on our [support forum](http://siteorigin.com/threads/plugin-page-builder/). If you're having strange issues, try following [this guide](http://siteorigin.com/troubleshooting/identifying-plugin-conflicts/).

= Bundled Widgets =

To get you started, we've include a few widgets:

* Gallery widget for inserting image galleries.
* Image widget for inserting standard images.
* Self hosted video widget for embedding your own videos.
* Post Loop to display a list of posts. This requires that your theme supports it.

As well as some essential page elements widgets:

* Button
* Call to Action
* List
* Price Box
* Animated Image
* Testimonial

= 3rd Party Widgets =

Most standard widgets work with Page Builder, but here are some of our favorites.

* [SiteOrigin Widget Bundle](http://wordpress.org/plugins/so-widgets-bundle/) for growing collection of widgets like buttons, price tables and images.
* [Black Studio TinyMCE](http://wordpress.org/plugins/black-studio-tinymce-widget/) for a visual content editing widget.
* [Meta Slider](http://wordpress.org/plugins/ml-slider/) for a responsive slider widget.

== Installation ==

1. Upload and install Page Builder in the same way you'd install any other plugin.
2. Read the [usage documentation](http://siteorigin.com/page-builder/documentation/) on SiteOrigin.

== Screenshots ==

1. The page builder interface.
2. Adding a new widget. This includes a live search filter to help you keep control if you have a lot of widgets.
3. Editing a widget's settings.
4. Easily undo mistakes.

== Documentation ==

[Documentation](http://siteorigin.com/page-builder/documentation/) is available on SiteOrigin.

== Frequently Asked Questions ==

= How do I move a site created with Page Builder from one server to another? =

We recommend the [duplicator plugin](https://wordpress.org/plugins/duplicator/). We've tested it in several instances and it always works well with Page Builder data.

= Can I bundle Page Builder with my theme? =

Yes, provided your theme is licensed under GPL or a compatible license. If you're publishing your theme on ThemeForest, you must select the GPL license instead of their regular license.

Page Builder is actively developed and updated, so generally I'd recommend that you have your users install the actual plugin so they can receive updates. You can try [TGM Plugin Activation](http://tgmpluginactivation.com/).

= Will plugin X work with Page Builder? =

I've tried to ensure that Page Builder is compatible with most plugin widgets. It's best to just download Page Builder and test for yourself.

== Changelog ==

= 2.0 =
* Complete rewrite of Page Builder Javascript using Backbone.

= 1.5.4 =
* Readded inline CSS setting.
* Improved handling of missing widgets in prebuilt layouts.

= 1.5.3 =
* Fixed post loop widget issue.
* Fixed settings issue.

= 1.5.2 =
* Changed to custom settings system to fix a few settings bugs.
* Added option to display more link in post loop widget.
* Fixed SSL in widget images.

= 1.5.1 =
* Compatibility with WordPress 4.0 - needed to change how tabs function.
* Compatibility with Black Studio TinyMCE Widget 2.0.
* Namespaced Tooltip to avoid conflicts.

= 1.5 =
* Increased size of widget dialog boxes.
* Updated incompatible plugins list.
* Updated to latest version of Chosen.
* Custom Home Page feature now uses standard pages.
* Improvements to preview handling.

= 1.4.12 =
* Improved how missing widgets are handled.
* General code clean up.
* Prebuilt layouts are no longer all filtered by siteorigin_panels_data. Filtered by siteorigin_panels_prebuilt_layout when fetched.
* Added more hooks and filters.
* Incompatible plugins now includes more link to give details about incompatibility.

= 1.4.11 =
* Fixed: Issue with setting up a home page, switching themes, then not being able to disable the home page.
* Updated to be compatible with latest Black Studio TinyMCE widget.
* Added a plugin incompatibility check with an admin notice.
* Improved bundled language files.

= 1.4.10 =
* Fixed: Fixed z-indexes so that TinyMCE dropdowns (like formatting) aren't hidden.

= 1.4.9 =
* Fixed: jQuery UI dialog wasn't being enqueued properly in WordPress 3.9.

= 1.4.8 =
* Updated Post Loop widget so it now accepts post__in in additional args field.
* Added update notification.
* Added filters for before and after the row content.
* Removed references to legacy widgets.

= 1.4.7 =
* Fixed size problem in gallery widget.
* Compatibility fixes with WordPress 3.9.

= 1.4.6 =
* Widgets are now only run through their update function when modified.
* Fixed gallery widget.

= 1.4.5 =
* Fixed an issue with copy content.
* Improved handling of styles in prebuilt layouts.
* Improved error handling in Javascript.
* Fixed issue with checkboxes.

= 1.4.4 =
* Generating Page Builder content in admin is now generated with a separate request to properly handle fatal errors from widgets.
* Fixed potential issue when loading home page interface.
* Added a way for themes to specify more advanced row styles.
* Dialogs and widget forms are now only loaded when needed in order to improve performance on large pages.
* Fixed several performance bottle necks.
* Page Builder data is now saved with auto save and revisions.

= 1.4.3 =
* Improved HTML5 validation be moving styles to header and footer.
* Basic improvements to memory efficiency.
* Black Studio TinyMCE height set to 350 pixels by default.
* Fixed: Black Studio TinyMCE update error.

= 1.4.2 =
* All existing widget forms are loaded with the initial interface, rather than through AJAX. Improves performance.
* Added safety check to ensure Page Builder data loaded before into the interface before saving into the database. Helps prevent content loss.
* Small usability improvements.
* Fixed: Embedded video widget.
* Fixed: Conflict with GPP Slideshow plugin.
* Fixed: Possible z-index conflicts with other plugins that have jQuery UI CSS.
* Fixed: Constant notification about autosave being more recent than current version.

= 1.4.1 =
* Fixed: Issue that was removing content for widgets with a lot of data.
* Fixed: Issue with duplicating widgets.

= 1.4.0 =
* Changed how widget forms are loaded to improve page load times.
* Several improvements to increase compatibility with various plugins and widgets.
* Properly handle widgets with form arrays.
* CSS fixes.
* Fixed compatibility issues with Black Studio TinyMCE.
* Added more development hooks and filters.