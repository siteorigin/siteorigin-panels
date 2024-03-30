=== Page Builder by SiteOrigin ===
Tags: page builder, responsive, parallax, widgets, blocks, gallery, layout, grid, cms, builder, widget
Requires at least: 4.7
Tested up to: 6.4
Requires PHP: 5.6.20
Stable tag: trunk
Build time: unbuilt
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html
Donate link: https://siteorigin.com/downloads/premium/
Contributors: gpriday, braam-genis, alexgso

Build responsive page layouts using the widgets you know and love using this simple drag and drop page builder.

== Description ==

SiteOrigin Page Builder is a powerful content creation interface, instantly recognizable, astonishingly different. SiteOrigin Page Builder makes it easy to create responsive column-based content using the widgets you know and love. Your content will accurately adapt to all mobile devices, ensuring your site is mobile-ready. Read more on [SiteOrigin](https://siteorigin.com/page-builder/).

We've created an intuitive interface that looks just like WordPress itself. It's easy to learn, so you'll be building beautiful, responsive content in no time.

[vimeo https://vimeo.com/114529361]

Page Builder works with standard WordPress widgets, so you'll always find the widget you need. We've created the [SiteOrigin Widgets Bundle](https://wordpress.org/plugins/so-widgets-bundle/) to give you all the most common widgets, and with a world of plugins out there, you'll always find the widget you need.

= Ready to Be Used Anywhere =

Choose your editor; Page Builder is ready to be used anywhere. Build in the traditional Page Builder interface or insert a Page Builder Layout into the Block Editor. Insert the SiteOrigin Layout Widget into sidebar and footer widget areas or use the SiteOrigin Layout Block in block-based widget areas.

= It Works With Your Theme =

Page Builder gives you complete freedom to choose any WordPress theme you like. It's not a commitment to a single theme or theme developer. The advantage is that you're free to change themes as often as you like. Your content will always come along with you.

We've also made some fantastic [free themes](https://siteorigin.com/theme/) that work well with Page Builder.

= No Coding Required =

Page Builder's simple drag and drop interface means you'll never need to write a single line of code. Page Builder generates all the highly efficient code for you.

= Live Editing =

Page Builder supports live editing. This tool lets you see your content and edit widgets in real time. It's the fastest way to adjust your content quickly and easily.

= History Browser =

This tool lets you roll forward and back through your changes. It gives you the freedom to experiment with different layouts and content without the fear of breaking your content.

= Row, Cell, and Widget Styles =

Row, cell, and widget styles give you all the control you need to make your content uniquely your own. Change attributes like paddings, background colors, and column spacing. You can also enter custom CSS and CSS classes if you need even finer-grained control.

= Focussed on Performance =

We've built a lightweight framework, focusing on small page sizes and fast load time. Page Builder is compatible with [Autoptimize](https://wordpress.org/plugins/autoptimize/) and all other major performance plugins.

= SEO Optimized =

Page Builder uses modern SEO best practices and seamlessly integrates with all major SEO plugins, including Yoast SEO and Rank Math.

= It's Free, and Always Will Be =

Page Builder is our commitment to the democratization of content creation. Like WordPress, Page Builder is, and always will be, free. We'll continue supporting and developing it for many years to come. It'll only get better from here.

= Accessibility Ready =

Page Builder is accessibility-ready. Tab through all form fields and settings, and make changes without a mouse.

= Actively Developed =

SiteOrigin has been creating magical tools for your WordPress website since 2011. Page Builder is actively developed with new features and exciting enhancements every month. Keep track on the [Page Builder GitHub repository](https://github.com/siteorigin/siteorigin-panels).

Read the [Page Builder developer docs](https://siteorigin.com/docs/page-builder/) if you'd like to develop for Page Builder.

== Documentation ==

[Documentation](https://siteorigin.com/page-builder/documentation/) is available on SiteOrigin.

== Support ==

Free support is available on the [SiteOrigin support forums](https://siteorigin.com/thread/).

== SiteOrigin Premium ==

[SiteOrigin Premium](https://siteorigin.com/downloads/premium/) enhances Page Builder by SiteOrigin, the SiteOrigin Widgets Bundle, and all SiteOrigin themes with a vast array of additional features and settings. Take your layouts to the next level with SiteOrigin Premium Addons.

SiteOrigin Premium includes access to our professional email support service, perfect for those times when you need fast and effective technical support. We're standing by to assist you in any way we can.

== Screenshots ==

1. The Page Builder editing interface.
2. Editing a Page Builder Layout Block in the Block Editor.
3. Powerful widget insert interface with groups and search.
4. Live Editor that lets you change your content in real-time.
5. Row Builder that gives unlimited flexibility.
6. Undo changes with the History Browser.

== Frequently Asked Questions ==

= How Do I Install Page Builder? =

Go to Plugins > Add New within WordPress. Search for "SiteOrigin Page Builder" using the field at the top right of the page. Alternatively, manually install the [plugin ZIP file](https://downloads.wordpress.org/plugin/siteorigin-panels.zip) from Plugins > Add New > Upload Plugin. If you'd like to install Page Builder manually and use Safari, disable the Safari auto-unzip feature in Safari > Preferences before downloading.

= Is Page Builder Compatible With My Theme? =

Page Builder is compatible with all standardized WordPress themes. A curated list with enhanced Page Builder integration is available on [SiteOrigin.com](https://siteorigin.com/theme/).

= Can I Use Page Builder With the WordPress Block Editor =

Yes, you can insert SiteOrigin Layout Block into the Block Editor. If you have the SiteOrigin Widgets Bundle installed, a SiteOrigin Widget Block is also available for use in the Block Editor.

= Does Page Builder Work With Custom Post Types? =

Page Builder can be activated for all post types from Settings > Page Builder > General > Post Types.

= Does Page Builder Work With Third-Party Plugins and Widgets? =

Page Builder is compatible with the vast majority of third-party plugins and widgets. If you encounter a compatibility issue, please, let us know via our [free support forum](https://siteorigin.com/thread/) or if you have an active SiteOrigin Premium license, directly via email.

= Does Page Builder Have a Pro Version? =

SiteOrigin offers a single premium plugin that enhances and extends Page Builder, the Widgets Bundle, SiteOrigin CSS and all of our free themes. Find out more about [SiteOrigin Premium](https://siteorigin.com/downloads/premium/) and the powerful addons it offers.

== Changelog ==

= 2.29.6 - 05 March 2024 =
* Added a dismiss button to the Classic Editor notice in the admin panel.
* Improved saving functionality of the Layouts Block with server-side validation for post types, enhanced rendering, block sanitization methods, and improved functionality for locating layout blocks.
* Ensured that errors are not processed and returned as part of the layout in the Layouts Block.

= 2.29.5 - 16 February 2024 =
* Media Style: Adjust border color to match other fields.
* Background Overlay: Added `border-radius` support.
* Border Radius: Added a context class for additional styling.
* CSS Output Location: Added Block Editor support.
* Inline CSS Styles: Update to apply widget margin directly to the widget.

= 2.29.4 - 19 January 2024 =
* Added compatibility with Pagelayer Templates. The panels filter is selectively enabled or disabled based on template usage.
* Prevented the row overlay from covering widget contents by adjusting CSS rules in `front-flex.less`.
* Developer: Added `siteorigin_panels_data` filter. Allows for the filtering of `$panels_data` when `generate_css` is run.

= 2.29.3 - 03 January 2024 =
* Vantage Theme: Account for unmigrated legacy row layouts.
* Toggle style field accessibility improvements.
* Save mode accessibility improvements.
* Live Editor Redirection: Resolve PHP 8 warning and deprecated notice.
* Color Field: Minor border color adjustment.
* Removed legacy content cache cleanup.

= 2.29.2 - 03 January 2024 =
* Vantage Theme: Prevented a type error if empty rows are present.

= 2.29.1 - 01 January 2024 =
* Vantage Theme: Prevented a potential Full Width Stretched display issue when no padding is set.

[View full changelog.](https://siteorigin.com/page-builder/changelog/)
