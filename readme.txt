=== API WooImporter ===
Contributors: fakhrul
Tags: woocommerce, import, rest api, product sync, importer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect multiple REST APIs simultaneously, each fully isolated, and import products into WooCommerce automatically — no coding required.

== Description ==

API WooImporter allows you to connect any external REST API that provides product data and seamlessly import those products into your WooCommerce store. It supports connecting multiple APIs simultaneously, with each connection acting as a fully isolated channel. Products from different APIs will never conflict.

Features:
*   **Multiple Connections**: Connect to multiple suppliers or APIs at the same time.
*   **Visual Field Mapper**: Map API fields to WooCommerce product fields (title, price, description, images, etc.) using an intuitive UI.
*   **Automated Syncing**: Use WP-Cron to schedule regular imports (hourly, daily, weekly).
*   **Detailed History & Logs**: Keep track of every import run, view success and failure metrics, and inspect raw API logs for easy troubleshooting.
*   **Fully Isolated**: Each API connection has its own independent configuration, field mapping, product list, and schedule.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/api-woo-importer` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > API Importer to create your first connection.

== Frequently Asked Questions ==

= Does this support variable products? =
Currently, it supports importing simple products. Full support for variations and attributes is planned for a future release.

= Can I import images? =
Yes, you can map an API field that contains image URLs, and the plugin will automatically fetch and attach them to the WooCommerce products.

== Screenshots ==

1. The main dashboard showing API connections and real-time syncing status.

== Changelog ==

= 3.0.0 =
*   Major Release: Complete redesign to support multiple isolated API connections.
*   Added visual field mapper.
*   Added auto-detect for API fields.
*   Added comprehensive connection history and logs.

= 2.0.0 =
*   Initial stable release.
