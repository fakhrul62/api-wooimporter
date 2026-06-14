=== ApiroSync Product Sync for WooCommerce ===
Contributors: fakhrulalam16
Tags: woocommerce, import, rest api, product sync, products
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect multiple REST APIs simultaneously, each fully isolated, and import products into WooCommerce automatically.

== Description ==

ApiroSync Product Sync for WooCommerce connects external REST APIs that provide product data and imports those products into your WooCommerce store. It supports multiple API connections at the same time, with each connection acting as an isolated channel.

Features:

* Multiple API connections for suppliers or product feeds.
* Visual field mapper for product title, price, description, images, SKU, stock, categories, tags, and brand.
* Scheduled imports using WP-Cron.
* Import history, rollback support, activity logs, and raw API troubleshooting details.
* Per-connection mapping, schedule, webhook secret, and product source tracking.

This plugin is not affiliated with or endorsed by WooCommerce.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/apirosync-product-sync-for-woocommerce` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to ApiroSync to create your first connection.

== Frequently Asked Questions ==

= Does this support variable products? =

Currently, it supports importing simple products. Full support for variations and attributes is planned for a future release.

= Can I import images? =

Yes. You can map an API field that contains image URLs, and the plugin will fetch and attach them to imported products.

= Are webhooks public? =

Webhook endpoints require a saved webhook secret for the connection. Requests without a valid secret are rejected.

== Screenshots ==

1. The main dashboard showing API connections and syncing status.

== Changelog ==

= 1.0.0 =
* Initial WordPress.org release.
