# W System E-Invoice for WooCommerce

WordPress plugin that creates **XRechnung 3.0** and **EN 16931** e-invoices (UBL XML) for WooCommerce orders, validates them against the official XML Schema, CEN EN 16931 Schematron and KoSIT XRechnung rules, attaches them to the customer email and can send them over **Peppol**. Powered by the [W System API](https://wsystem.dev).

* Landing page and guide: https://wsystem.dev/woocommerce-xrechnung (German: https://wsystem.dev/de/woocommerce-xrechnung)
* Settings: WooCommerce > Settings > E-Invoice
* Free for 50 invoices a month, then from €12 (shop plans: https://wsystem.dev/online-shops).
* Free tier: 20 API calls per day per server IP, no key. Free API key: https://api.wsystem.dev/account/free-key?ref=woo-einvoice

## Features

- Automatic creation on order completion (or payment), manual button and order action
- Validation result and fix hints in an "E-invoice" box on the order (classic and HPOS screens)
- XML attached to the "Completed order" / "Customer invoice" emails, download from admin
- Several VAT rates, shipping, fees, discounts, prepaid orders, bank transfer (IBAN), intra-EU reverse charge (AE), exports (G), small business scheme (section 19 UStG, category E)
- Leitweg-ID / buyer reference per order
- Uses the invoice number from WP Overnight PDF Invoices & Packing Slips when present
- Optional Peppol sending: sandbox (simulated, nothing delivered) or live (needs an API key, Peppol mandate and approved account)
- English and German (de_DE)

Not yet: ZUGFeRD / Factur-X PDF output, credit notes for refunds, Peppol BIS specific (PEPPOL-EN16931-*) rule checks.

## Filters

- `wsei_invoice_number` (string $number, WC_Order $order)
- `wsei_invoice_body` (array $body, WC_Order $order): the JSON sent to `POST /einvoice/create`
- `wsei_attach_email_ids` (array $email_ids)
- `wsei_intra_eu_category` (`AE` or `K`)

## Development

```
php -l w-system-e-invoice.php includes/*.php
# end-to-end smoke test in a throwaway WordPress + WooCommerce site (calls the live API):
wp plugin activate woocommerce w-system-e-invoice && wp eval-file tests/e2e.php
./build-zip.sh   # dist/w-system-e-invoice.zip
```

License: GPLv2 or later. Not tax or legal advice.
