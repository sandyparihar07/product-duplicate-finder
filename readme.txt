=== Duplicate Product Finder ===
Contributor: Sandeep Parihar
Tags: woocommerce, duplicate products, sku, product management, inventory
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find duplicate WooCommerce product SKUs, review conflicts, and safely resolve them.

== Installation ==

1. Upload the plugin files to the /wp-content/plugins/product-duplicate-finder directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Run your first scan from the Duplicate Product Finder menu in the WordPress admin sidebar.

== Frequently Asked Questions ==

= Does this plugin automatically delete products? =
No, the plugin is designed as a safe review tool. You choose which products to move to Trash or permanently delete after confirmation.

= Does this plugin require an external API? =
No, the free WordPress.org version does not require any external API, account, or license key.

= Can I scan trashed products? =
Yes, you can enable inclusion of trashed products in the scan scope settings.

= What if WooCommerce is not active? =
The plugin will show an admin notice and disable scan and resolution features until WooCommerce is activated.

== Changelog ==

= 1.0.0 =
* Initial release
* Duplicate SKU scan
* Product and variation support
* Scope filters
* Move to Trash
* SKU editing
* CSV export
* Audit log
* Settings