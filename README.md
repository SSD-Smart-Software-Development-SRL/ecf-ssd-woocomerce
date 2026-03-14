=== WooCommerce ECF DGII ===
Contributors: puntoos
Tags: woocommerce, ecf, dgii, dominican republic, electronic invoicing, fiscal documents, factura electronica
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 10.6
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically generate and submit electronic fiscal documents (ECF) to the Dominican Republic tax authority (DGII) from your WooCommerce store.

== Description ==

**WooCommerce ECF DGII** connects your WooCommerce store to the Dominican Republic's electronic fiscal document system (Comprobantes Fiscales Electrónicos) through the [ECF SSD API](https://ecf.dgii.gov.do). Every time a customer completes a purchase, the plugin generates the required fiscal document and submits it to the DGII — no manual data entry needed.

= Supported Document Types =

* **E31 — Crédito Fiscal**: For B2B transactions where the buyer provides their RNC or Cédula.
* **E32 — Consumo**: For B2C sales. Used automatically when no tax ID is provided.
* **E34 — Nota de Crédito**: Generated automatically when you process a WooCommerce refund.

= Key Features =

* **Automatic ECF submission** — Invoices are sent to DGII the moment an order is paid. No extra steps.
* **Refund credit notes** — E34 documents are generated and submitted when you create a refund.
* **eNCF sequence management** — Add, monitor, and manage your authorized number ranges from the admin panel. Low-stock warnings keep you from running out.
* **Real-time status tracking** — See ECF status (Pending, Submitting, Accepted, Rejected, Error) directly on the order detail page.
* **On-demand status checks** — The plugin checks DGII for updated status every time you view an order, so you always see the latest result.
* **PDF invoices** — Download fiscal invoices with QR codes directly from the order page.
* **Contingencia mode** — If the DGII API is temporarily unavailable, orders automatically receive B-series fallback codes. When the API recovers, the plugin converts them to real eNCFs — automatically or via a batch submission page.
* **Retry failed submissions** — One-click retry for rejected or failed ECFs.
* **HPOS compatible** — Fully compatible with WooCommerce High-Performance Order Storage.

= How It Works =

1. Customer places an order and completes payment.
2. The plugin determines the ECF type (E31 or E32) based on whether the customer provided a tax ID.
3. An eNCF is claimed from your authorized sequence range.
4. The fiscal document is submitted to DGII.
5. The plugin polls for acceptance and stores the security code on the order.
6. If you later issue a refund, an E34 credit note is automatically generated and submitted.

= RNC/Cédula at Checkout =

The plugin adds optional RNC (tax ID) and Razón Social (legal name) fields to the WooCommerce checkout. When a customer fills these in, the order is invoiced as E31 (Crédito Fiscal) instead of E32 (Consumo).

== Installation ==

1. Upload the `woo-ecf-dgii` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce > Settings > ECF DGII** and enter your API credentials.
4. Set your company information (RNC, legal name, address).
5. Go to **WooCommerce > ECF Sequences** and add your authorized eNCF number ranges.
6. Start selling — invoices will be submitted automatically.

= Requirements =

* WordPress 6.2 or higher
* WooCommerce 8.0 or higher
* PHP 8.1 or higher
* Active ECF SSD API credentials from your authorized provider
* Authorized eNCF number ranges from the DGII

== Frequently Asked Questions ==

= Do I need to register with the DGII first? =

Yes. You must be registered as an electronic fiscal document issuer with the DGII and have authorized eNCF number ranges before using this plugin.

= What happens if the DGII API is down? =

The plugin enters contingencia mode automatically. Orders receive temporary B-series codes so your store keeps operating. When the API recovers, B-series orders are converted to real eNCFs either automatically or through the batch submission page.

= Can I retry a failed submission? =

Yes. On the order detail page, you will see a "Send ECF" button for any order that failed or was rejected. You can also view the specific error message returned by the DGII.

= Does this work with WooCommerce HPOS? =

Yes. The plugin declares full compatibility with High-Performance Order Storage (Custom Order Tables).

= What tax rates are supported? =

The plugin supports the standard ITBIS rate of 18%, the reduced rate of 16%, and tax-exempt items. Tax indicators are set per line item based on your WooCommerce tax configuration.

= Can I use this in a test environment? =

Yes. The plugin supports Test, Certification, and Production API environments. Test and Certification environments are available when `WP_DEBUG` is enabled.

== Screenshots ==

1. ECF status metabox on the order detail page showing accepted invoice with security code.
2. Credit note (E34) status displayed below the original invoice for refunded orders.
3. ECF Sequences admin page — manage your authorized eNCF number ranges.
4. Contingencia admin page — batch submit B-series orders when the API recovers.
5. Plugin settings page with API credentials and company information.
6. RNC and Razón Social fields at checkout for B2B customers.

== Changelog ==

= 1.0.0 =
* Initial release.
* E31 (Crédito Fiscal) and E32 (Consumo) automatic submission.
* E34 (Nota de Crédito) for refunds.
* eNCF sequence management with admin UI.
* Contingencia B-series fallback with automatic recovery.
* On-demand DGII status polling from order detail page.
* PDF invoice generation with QR codes.
* RNC/Cédula checkout fields for B2B orders.
* HPOS compatibility.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
