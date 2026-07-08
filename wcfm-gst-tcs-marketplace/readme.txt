=== WCFM GST & TCS for Multivendor Marketplace ===
Contributors: solomandev
Tags: wcfm, multivendor, gst, tcs, india tax
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.5
Stable tag: 1.0.0
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
* A simple printable GST invoice per order (HSN, per-vendor tax breakup, GSTIN) linked from
  the order-received/order-details page.

== Notes ==

* This plugin computes and reports GST-TCS under GST law (Section 52, CGST Act, no minimum
  threshold). It does not implement Income Tax Act Section 52 TCS (0.1%/1% above the ₹5 lakh
  annual threshold) — that is a separate compliance requirement and out of scope here.
* GSTIN validation checks structure only (state code + PAN pattern + entity/checksum
  characters), not the real checksum digit or GSTN portal verification.
* Configure Settings > GST & TCS with your marketplace operator GSTIN/state before going live,
  and ask each vendor to fill in their GSTIN/state so tax is calculated correctly.
* Always confirm the final GST/TCS treatment with a qualified CA before filing — this plugin
  automates the arithmetic, it isn't a substitute for professional tax advice.

== Changelog ==

= 1.0.0 =
* Initial release.
