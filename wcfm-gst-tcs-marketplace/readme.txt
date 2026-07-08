=== WCFM GST & TCS for Multivendor Marketplace ===
Contributors: solomandev
Tags: wcfm, multivendor, gst, tcs, india tax
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.5
Stable tag: 1.2.1
License: GPLv2 or later

Adds India GST (CGST/SGST/IGST) tax calculation and GST-TCS (Section 52, CGST Act) compliance
to a WCFM Marketplace multivendor store.

== Requirements ==

* WooCommerce
* WCFM – Frontend Manager (wc-frontend-manager)
* WCFM Marketplace (wc-multivendor-marketplace)

== What it does ==

* Per-product HSN/SAC code and GST rate, editable from the WCFM vendor product manager
  and from the wp-admin product edit screen (Tax tab).
* Vendor GSTIN, PAN, legal name and state, captured from WCFM's vendor Settings > General
  tab (with a wp-admin user-profile fallback), with GSTIN format validation and automatic
  state detection from the GSTIN.
* B2B checkout: a "This is a business purchase" option at checkout reveals Company Name
  and GSTIN fields, saved to the order (HPOS-safe), shown in the admin order billing box,
  customer order details/emails, and printed on the GST invoice, so business buyers get
  what they need to claim input tax credit. Toggle on/off, and make GSTIN mandatory or
  optional, from Settings > GST & TCS.
* Automatic CGST+SGST (buyer and seller in the same state) or IGST (different states) tax
  calculation per order line, provisioned as native WooCommerce tax classes so the standard
  WooCommerce tax pipeline (cart, checkout, order storage, refunds, emails) handles the math,
  rounding and display — including inside WCFM's own order screens.
* A GST-TCS ledger: on each order reaching Processing/Completed, records the net taxable
  value and 1% TCS (split CGST+SGST or IGST by comparing the marketplace operator's state to
  the vendor's state) per vendor per order. Cancelled/refunded/failed orders reverse the entry.
* Admin reports: Vendor GST Tax Summary and a GSTR-8 style TCS report, both date-range and
  vendor filterable, with CSV export.
* A vendor-facing "GST & TCS" tab under My Account showing their own GST collected and TCS
  deducted, with CSV export, plus a `[wgt_vendor_gst_report]` shortcode for custom placement.
* A printable GST invoice per order (HSN, per-vendor tax breakup, GSTIN), viewable in the
  browser or downloaded as a PDF, linked from the order-received/order-details page.
* Manual e-invoice fields (IRN, Ack No, Ack Date, QR text) per vendor per order, for stores
  where a vendor is above the e-invoicing turnover threshold and generates these on the govt
  e-invoice portal — recorded here and printed on the invoice, not auto-generated.
* A GSTR-1 style invoice-level CSV export (one row per order line: vendor, buyer GSTIN if a
  business purchase, place of supply, HSN, taxable value, CGST/SGST/IGST) to help vendors/CAs
  populate their own GSTR-1 B2B/B2CS filing. This is a convenience export, not the GSTN
  portal's JSON upload format.
* A "Compliance check" panel on Settings > GST & TCS flagging vendors missing a GSTIN and
  published products missing an HSN/SAC code, plus the same nudge on the vendor's own
  GST & TCS account tab.
* HSN/SAC can be made mandatory to publish a product (Settings > GST & TCS), enforced for
  both the WCFM frontend product form and wp-admin.
* Partial refunds recompute each affected vendor's TCS ledger row from WooCommerce's own
  per-item refunded amounts, instead of only reacting to a full order cancellation.
* Declares WooCommerce High-Performance Order Storage (HPOS) compatibility.
* A checkout-time nudge (non-blocking) if the entered GSTIN's registered state doesn't match
  the billing address state, to catch typos before the order is placed.
* Per-vendor "Exempt from GST-TCS" toggle (admin-only, not vendor-editable) for suppliers who
  are legally exempt, excluding them from the TCS ledger/reports.
* Large GST/GSTR-1 CSV exports (over ~2,000 orders in range) are automatically queued as a
  background job via WooCommerce's Action Scheduler and emailed as a download link instead of
  streaming synchronously, to avoid PHP execution-time limits.
* A `wgt_gstin_is_registered` filter (no-op by default) so a site can wire in a paid GSP/GSTN
  verification API on top of the built-in structural + checksum validation.
* Settings > GST & TCS > "Delete data on uninstall" (off by default) controls whether removing
  the plugin also wipes the TCS ledger, settings, and vendor/product GST meta.
* A small PHPUnit suite (tests/) covering GSTIN validation and the TCS split arithmetic.

== Notes ==

* This plugin computes and reports GST-TCS under GST law (Section 52, CGST Act, no minimum
  threshold). It does not implement Income Tax Act Section 52 TCS (0.1%/1% above the ₹5 lakh
  annual threshold) — that is a separate compliance requirement and out of scope here.
* GSTIN validation checks structure and the real mod-36 check digit, which catches typos and
  fabricated numbers, but does not verify against the GSTN portal that the GSTIN is actually
  registered/active — see the `wgt_gstin_is_registered` filter if you need that.
* E-invoice IRN/Ack/QR fields are for recording what a vendor generated on the government
  e-invoice portal; this plugin does not call the IRP/GSP API to generate an IRN itself.
* Configure Settings > GST & TCS with your marketplace operator GSTIN/state before going live,
  and ask each vendor to fill in their GSTIN/state so tax is calculated correctly.
* Always confirm the final GST/TCS treatment with a qualified CA before filing — this plugin
  automates the arithmetic, it isn't a substitute for professional tax advice.
* Bundles dompdf (vendor/) for PDF invoice generation. Background CSV exports require
  WooCommerce's bundled Action Scheduler (present on any current WooCommerce install); if
  unavailable, exports simply always stream synchronously instead.
* Running the test suite locally: `composer install` (pulls in PHPUnit as a dev dependency)
  then `vendor/bin/phpunit`. The shipped/production vendor/ (installed with --no-dev) does not
  include PHPUnit.

== Changelog ==

= 1.2.1 =
* Fixed a fatal error on activation: class files were only loaded on 'plugins_loaded', but
  WordPress runs the activation hook in the same request where 'plugins_loaded' has already
  fired without this plugin's code loaded, so the activation callback's class didn't exist
  yet. All class files now load unconditionally and immediately; only instantiation (not
  loading) is still gated behind the WooCommerce/WCFM dependency check.

= 1.2.0 =
* GST/GSTR-1 reports now read a `_wgt_tax_type` stamp recorded at checkout instead of
  pattern-matching tax rate labels, with a fallback for orders placed before this update.
* Checkout-time GSTIN/billing-state mismatch warning (non-blocking).
* Per-vendor GST-TCS exemption toggle (admin-only).
* `wgt_gstin_is_registered` filter as an external-verification extension point.
* Opt-in "delete data on uninstall" setting + uninstall.php.
* Background/emailed CSV exports for large date ranges via Action Scheduler.
* Added a PHPUnit test suite for GSTIN validation and TCS split math.

= 1.1.0 =
* B2B checkout: buyer Company Name + GSTIN, shown on invoices/order details/admin.
* PDF GST invoices (dompdf), alongside the existing print view.
* GSTR-1 style invoice-level CSV export.
* Manual e-invoice IRN/Ack/QR fields per vendor per order.
* Real GSTIN mod-36 checksum validation (previously format-only).
* HSN-mandatory enforcement extended to the WCFM frontend product form.
* Partial refunds now proportionally adjust the TCS ledger.
* Vendor/admin nudges for missing GSTIN or HSN.
* Declared HPOS compatibility.

= 1.0.0 =
* Initial release.
