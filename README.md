# ECF DGII Invoicing for WooCommerce

Automatically generate and submit electronic fiscal documents (ECF) to the Dominican Republic tax authority (DGII) from your WooCommerce store.

Built and maintained by [SSD Smart Software Development SRL](https://ssd.com.do).

| | |
|---|---|
| **Requires WordPress** | 6.2+ |
| **Requires WooCommerce** | 8.0+ |
| **Requires PHP** | 8.1+ |
| **Tested up to** | WordPress 6.9, WooCommerce 10.6 |
| **License** | [GPLv3 or later](LICENSE) |

## Description

**WooCommerce ECF DGII** connects your WooCommerce store to the Dominican Republic's electronic fiscal document system (Comprobantes Fiscales Electrónicos) through the ECF SSD API. Every time a customer completes a purchase, the plugin generates the required fiscal document and submits it to the DGII — no manual data entry needed.

## Supported Document Types

| Type | Name | Use Case |
|------|------|----------|
| E31 | Crédito Fiscal | B2B transactions — buyer provides RNC or Cédula |
| E32 | Consumo | B2C sales — no tax ID required |
| E34 | Nota de Crédito | Refunds — references the original invoice |

## Key Features

- **Automatic ECF submission** — Invoices are sent to DGII the moment an order is paid. No extra steps.
- **Refund credit notes** — E34 documents are generated and submitted when you create a refund.
- **eNCF sequence management** — Add, monitor, and manage your authorized number ranges from the admin panel. Low-stock warnings keep you from running out.
- **Real-time status tracking** — See ECF status (Pending, Submitting, Accepted, Rejected, Error) directly on the order detail page.
- **On-demand status checks** — The plugin checks DGII for updated status every time you view an order, so you always see the latest result.
- **PDF invoices** — Download fiscal invoices with QR codes directly from the order page.
- **Contingencia mode** — If the DGII API is temporarily unavailable, orders automatically receive B-series fallback codes. When the API recovers, the plugin converts them to real eNCFs — automatically or via a batch submission page.
- **Retry failed submissions** — One-click retry for rejected or failed ECFs.
- **HPOS compatible** — Fully compatible with WooCommerce High-Performance Order Storage.

## How It Works

1. Customer places an order and completes payment.
2. The plugin determines the ECF type (E31 or E32) based on whether the customer provided a tax ID.
3. An eNCF is claimed from your authorized sequence range.
4. The fiscal document is submitted to DGII.
5. The plugin polls for acceptance and stores the security code on the order.
6. If you later issue a refund, an E34 credit note is automatically generated and submitted.

## Installation

1. Upload the `ecf-dgii-invoicing` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce > Settings > ECF DGII** and enter your API credentials.
4. Set your company information (RNC, legal name, address).
5. Go to **WooCommerce > ECF Sequences** and add your authorized eNCF number ranges.
6. Start selling — invoices will be submitted automatically.

### Requirements

- WordPress 6.2 or higher
- WooCommerce 8.0 or higher
- PHP 8.1 or higher
- Active ECF SSD API credentials from your authorized provider
- Authorized eNCF number ranges from the DGII

## RNC/Cédula at Checkout

The plugin adds optional RNC (tax ID) and Razón Social (legal name) fields to the WooCommerce checkout. When a customer fills these in, the order is invoiced as E31 (Crédito Fiscal) instead of E32 (Consumo).

## FAQ

**Do I need to register with the DGII first?**
Yes. You must be registered as an electronic fiscal document issuer with the DGII and have authorized eNCF number ranges before using this plugin.

**What happens if the DGII API is down?**
The plugin enters contingencia mode automatically. Orders receive temporary B-series codes so your store keeps operating. When the API recovers, B-series orders are converted to real eNCFs either automatically or through the batch submission page.

**Can I retry a failed submission?**
Yes. On the order detail page, you will see a "Send ECF" button for any order that failed or was rejected. You can also view the specific error message returned by the DGII.

**Does this work with WooCommerce HPOS?**
Yes. The plugin declares full compatibility with High-Performance Order Storage (Custom Order Tables).

**What tax rates are supported?**
The plugin supports the standard ITBIS rate of 18%, the reduced rate of 16%, and tax-exempt items. Tax indicators are set per line item based on your WooCommerce tax configuration.

**Can I use this in a test environment?**
Yes. The plugin supports Test, Certification, and Production API environments. Test and Certification environments are available when `WP_DEBUG` is enabled.

## Changelog

### 1.0.0

- Initial release
- E31 (Crédito Fiscal) and E32 (Consumo) automatic submission
- E34 (Nota de Crédito) for refunds
- eNCF sequence management with admin UI
- Contingencia B-series fallback with automatic recovery
- On-demand DGII status polling from order detail page
- PDF invoice generation with QR codes
- RNC/Cédula checkout fields for B2B orders
- HPOS compatibility

## Support

For questions, issues, or feature requests:

- **Email**: [contacto@ssd.com.do](mailto:contacto@ssd.com.do)
- **Website**: [ssd.com.do](https://ssd.com.do)
- **Issues**: [GitHub Issues](https://github.com/SSD-Smart-Software-Development-SRL/ecf-ssd-woocomerce/issues)

## License

This plugin is licensed under the [GNU General Public License v3.0](LICENSE).
