=== WP Web Vitals ===
Contributors: gaborangyal
Tags: performance, core web vitals
Requires at least: 6.0
Tested up to: 6.6.2
Stable tag: 0.3.0
Requires PHP: 7.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Collects Core Web Vitals (TTFB, FCP) and request counts for guest and logged-in users, with admin charts.

== Description ==

This project aims to gather Core Web Vitals and other essential metrics for both guest and logged-in users separately. This data is vital for eCommerce and membership websites seeking to enhance the experience for logged-in users.

The plugin records Time to First Byte (TTFB) and First Contentful Paint (FCP) for each page view, together with the user type (guest or logged-in) and whether the URL contains a query string.

The Tools > Web Vitals admin page shows daily median TTFB and FCP values and daily request counts for the last 90 days. Charts can be filtered by page.

Logs are kept for 90 days and older entries are removed automatically once a day.

Set a path prefix such as /blog to track only pages whose path starts with that prefix. Leave it empty to track all pages.

[GitHub](https://github.com/agabor/wp-web-vitals)

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install it through the Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Open Tools > Web Vitals to review the data and, optionally, set a path prefix.

== Changelog ==

= 0.3.0 =
* Added admin page with daily median TTFB and FCP charts and request counts.
* Added page filter to the admin charts.
* Added path prefix setting to limit tracking to matching pages.
* Added daily removal of logs older than 90 days.
* Added action to clean all collected data.

= 0.1 =
* Initial release: TTFB and FCP logging for guest and logged-in users.