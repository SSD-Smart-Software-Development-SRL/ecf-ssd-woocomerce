=== ECF DGII Invoicing for WooCommerce ===
Contributors: ssdsmartdev
Tags: woocommerce, invoicing, dominican republic, dgii, ecf
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Electronic fiscal documents (ECF) for Dominican Republic via ECF SSD API. Automatically sends invoices to DGII when WooCommerce orders are paid.

== Description ==

ECF DGII Invoicing for WooCommerce automates the submission of electronic fiscal documents (Comprobantes Fiscales Electronicos) to the Dominican Republic tax authority (DGII) through the ECF SSD API.

**Features:**

* Automatic ECF submission on order payment (E31, E32 types)
* Credit note generation (E34) on refunds
* eNCF sequence management with expiration tracking
* Contingencia mode (B-series) with batch resubmission
* PDF invoice generation with QR codes
* Per-environment API tokens (test/production)
* Full HPOS (High-Performance Order Storage) compatibility

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ecf-dgii-invoicing/` or install via the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > Settings > ECF DGII to configure your API token and company RNC.
4. Add eNCF sequences via WooCommerce > ECF Sequences.

== Frequently Asked Questions ==

= What is an ECF? =

ECF (Electronic Fiscal Document) is the electronic invoicing system mandated by the Dominican Republic tax authority (DGII).

= Do I need an ECF SSD API account? =

Yes. You need an API token from ECF SSD to use this plugin. Visit [ecf.ssd.com.do](https://ecf.ssd.com.do) to register.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
