=== WooCommerce Percentage Shipping ===
Contributors: tobiashaas
Tags: woocommerce, shipping, percentage, php8, secure, jquery-free
Requires at least: 5.6
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.6.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calculate shipping costs as a percentage of physical products - PHP 8+ Secure, jQuery-free, Multilingual

== Description ==

WooCommerce Percentage Shipping is a modern, secure WordPress plugin that calculates shipping costs as a percentage of physical products in your WooCommerce store. Built with PHP 8+ features and comprehensive security measures, this plugin is completely jQuery-free and multilingual.

= Key Features =

* **PHP 8+ Compatible**: Built with modern PHP 8+ features including enums, union types, and constructor property promotion
* **Secure by Design**: CSRF protection, input sanitization, XSS protection, capability checks, and rate limiting
* **jQuery-Free**: Uses modern vanilla JavaScript with no jQuery dependency
* **Multilingual**: Supports German and English with WordPress i18n standards
* **HPOS Compatible**: Fully compatible with WooCommerce High-Performance Order Storage
* **Digital Products Control**: Option to include/exclude virtual and downloadable products
* **Category Exclusions**: Exclude specific product categories from shipping calculation
* **Live Preview**: Real-time calculation preview in admin interface
* **Debug Mode**: Detailed logging for troubleshooting

= How It Works =

1. Analyzes all products in the cart
2. Filters products according to your settings (physical/digital)
3. Calculates shipping costs as a percentage of filtered products
4. Applies minimum and maximum fee limits

= Security Features =

* CSRF Protection with nonce verification
* Input Sanitization and validation
* XSS Protection with proper output escaping
* Capability Checks for admin access
* Rate Limiting for AJAX requests
* Secure Headers implementation

= Requirements =

* WordPress 5.6 or higher
* WooCommerce 5.0 or higher
* PHP 8.0 or higher

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woocommerce-percentage-shipping/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce → Percentage Shipping to configure settings
4. Add the "Percentage Shipping" method to your WooCommerce shipping zones

== Frequently Asked Questions ==

= Does this plugin require jQuery? =

No, this plugin is completely jQuery-free and uses modern vanilla JavaScript.

= Is this plugin secure? =

Yes, it implements comprehensive security measures including CSRF protection, input sanitization, XSS protection, and rate limiting.

= Does it work with WooCommerce 10? =

Yes, it's fully compatible with WooCommerce 10 and High-Performance Order Storage (HPOS).

= Can I exclude digital products? =

Yes, you can choose to include or exclude virtual and downloadable products from the shipping calculation.

= Does it support multiple languages? =

Yes, it supports German and English with full WordPress i18n implementation.

== Screenshots ==

1. Admin settings page with comprehensive configuration options
2. Security features overview
3. Live calculation preview
4. Shipping method configuration in WooCommerce zones

== Changelog ==

= 1.6.0 =
* Complete rewrite with PHP 8+ features
* Added comprehensive security measures
* Removed jQuery dependency
* Added multilingual support (German/English)
* Added HPOS compatibility
* Added digital products control option
* Enhanced admin interface with live preview
* Added debug mode with detailed logging

= 1.5.0 =
* Added PHP 8+ compatibility
* Enhanced security features
* Fixed render_settings_page method

= 1.4.0 =
* Removed jQuery dependency
* Added vanilla JavaScript implementation

= 1.3.0 =
* Added multilingual support
* Enhanced UI with better descriptions

= 1.2.0 =
* Added digital products checkbox
* Enhanced admin interface

= 1.1.0 =
* Added HPOS compatibility
* Updated plugin headers

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.6.0 =
Major update with PHP 8+ features, enhanced security, and jQuery-free implementation. Requires PHP 8.0 or higher.

== Support ==

For support and bug reports, please visit the plugin's support forum or GitHub repository.

== License ==

This plugin is licensed under the GPL v2 or later.
