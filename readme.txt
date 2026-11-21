=== W System E-Invoice for WooCommerce ===
Contributors: wsystemdev
Tags: xrechnung, e-invoice, e-rechnung, peppol, en 16931
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create XRechnung 3.0 and EN 16931 e-invoices for WooCommerce orders, validated against the official rules, attached to the order email.

== Description ==

Since 1 January 2025 every business in Germany must be able to receive e-invoices. From 1 January 2027 businesses with more than EUR 800,000 turnover in the previous year must send them for domestic B2B sales, and from 1 January 2028 all businesses must. A PDF is no longer enough: the invoice has to be a structured file such as XRechnung.

W System E-Invoice for WooCommerce turns each order into a structured e-invoice and checks it before it leaves your shop.

* **XRechnung 3.0 (UBL)** for Germany, plain **EN 16931 (UBL)** for the rest of the EU, or **Peppol BIS Billing 3.0** structure.
* **Validated on every order** against the official XML Schema, the CEN EN 16931 Schematron rules and the KoSIT XRechnung rules. Errors are shown on the order with what to fix.
* **Automatic:** created when an order is completed (or when it is paid), or manually from the order screen and the order actions menu.
* **Attached to the customer email** ("Completed order" and "Customer invoice") when it passed validation. Download the XML from the order screen at any time.
* **Handles common shop cases:** several VAT rates (19 % and 7 %), shipping and fees, discounts, orders already paid online, bank transfer with your IBAN, intra-EU reverse charge for business customers with a VAT ID, exports outside the EU, and the small business scheme (Kleinunternehmerregelung, section 19 UStG).
* **Leitweg-ID / buyer reference** field on the order for public-sector customers.
* **Uses the invoice number of WP Overnight "PDF Invoices & Packing Slips"** if it is installed, so the XML and the PDF carry the same number. Otherwise the order number is used (filter `wsei_invoice_number`).
* **Optional Peppol sending:** a sandbox mode to test the full flow (nothing is delivered), and live sending once your W System account is set up for it. Every send first checks the customer's VAT ID in VIES and screens the name against sanctions lists.
* **Reads the customer VAT ID** from W System EU VAT Check for WooCommerce and other VAT plugins (configurable order meta keys).
* HPOS (custom order tables) compatible. English and German.

Settings: WooCommerce > Settings > E-Invoice.

= What this plugin does not do =

* It does not create ZUGFeRD / Factur-X PDF files (PDF with embedded XML) yet. XRechnung is one of the formats accepted for e-invoices in Germany.
* It does not create credit notes for refunds yet.
* Peppol BIS 3 specific rules (PEPPOL-EN16931-*) are not checked yet; the EN 16931 rules are.
* It is not tax or legal advice. A technically valid file does not replace checking that the content of your invoices is correct for your business.

== External services ==

This plugin needs the W System API (https://api.wsystem.dev), operated by W System (legal entity details: https://wsystem.dev/imprint). The API builds the e-invoice XML from the order data, validates it against the official rule sets and, only if you turn Peppol sending on, transmits it. Without this service the plugin cannot create e-invoices.

* **What is sent:** the invoice data of the order: your seller details from the settings (company name, address, VAT ID, tax or registration number, contact name, phone, email, IBAN/BIC), the customer's billing name, company, address, email and VAT ID, the order and invoice number, dates, currency, line items (name, SKU, quantity, net price, VAT rate), shipping and fees, payment method and payment status, and the buyer reference if you entered one. The API key is sent as the X-API-Key header only if you entered one. As with any HTTP request, the API also sees your server's IP address, which it uses to count the free daily quota.
* **When it is sent:** when an e-invoice is created, automatically on the order status you choose (or never, if you set it to manual) or when you click "Create e-invoice" or choose the order action; and when you send an invoice over Peppol (by button, or automatically if you turned that on). Nothing is sent on activation, on the checkout, or on other pages.
* **Endpoints:** https://api.wsystem.dev/einvoice/create and, for Peppol sending only, https://api.wsystem.dev/einvoice/send
* **Terms of service:** https://wsystem.dev/terms
* **Privacy policy:** https://wsystem.dev/privacy

For a Peppol send the API checks the customer's VAT ID with the European Commission VIES service (https://ec.europa.eu/taxation_customs/vies/) and looks the customer up in the Peppol Directory (https://directory.peppol.eu/). In live mode the invoice is delivered to the customer over the Peppol network through W System's access point partner.

== Installation ==

1. Install and activate the plugin (Plugins > Add New, search for "W System E-Invoice").
2. Go to WooCommerce > Settings > E-Invoice and fill in your company details, including contact name, phone and email (XRechnung requires them).
3. Optional: paste a free API key for a higher quota.
4. Complete a test order and open it: the E-invoice box shows the result and a download button.

== Frequently Asked Questions ==

= Do I need an API key? =

No. Without a key the W System API free tier allows 20 calls per day per server IP address, enough to try the plugin and for small shops. For more, get a free API key (email only, no card) at https://api.wsystem.dev/account/free-key?ref=woo-einvoice. Paid plans for higher volume: https://wsystem.dev/pricing.

= Is XRechnung accepted for B2B invoices in Germany? =

Yes. Under the German rules in force since 2025 an e-invoice is a structured electronic invoice that follows EN 16931; XRechnung is one of those formats, ZUGFeRD (from version 2.0.1, except the MINIMUM and BASIC WL profiles) is another. Many customers' accounting software reads XRechnung directly.

= Do consumers (B2C) need an e-invoice? =

No, the German obligation is for invoices between businesses. You can set the plugin to create e-invoices only for business customers (billing company or VAT ID present).

= Why does my invoice fail validation? =

The E-invoice box lists each failed rule with a short explanation and what to change, for example a missing seller phone number. Fix it in the settings and click "Create again".

= Does it send invoices over Peppol? =

Only if you turn it on. Sandbox mode runs the whole flow, including the VAT and Peppol checks, but delivers nothing. Live sending needs an API key, a Peppol mandate for your VAT ID and an approved W System account; the API tells you exactly what is missing.

== Screenshots ==

1. The E-invoice box on the order: validation result, download and Peppol send.
2. Settings: format, automation, seller details and Peppol.

== Changelog ==

= 1.0.0 =
* First release: XRechnung 3.0, EN 16931 and Peppol BIS 3 (UBL) from WooCommerce orders, official rule validation, email attachment, download, Leitweg-ID, optional Peppol sending (sandbox and live).
