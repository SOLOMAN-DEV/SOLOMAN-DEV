=== SOLOMAN TAXES FOR WCFM ===
Contributors: solomandev
Tags: wcfm, multivendor, gst, tcs, india tax
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.5
Stable tag: 2.3.0
License: GPLv2 or later

Adds India GST (CGST/SGST/IGST) tax calculation and GST-TCS (Section 52, CGST Act) compliance
to a WCFM Marketplace multivendor store.

== Requirements ==

* WooCommerce
* WCFM – Frontend Manager (wc-frontend-manager)
* WCFM Marketplace (wc-multivendor-marketplace)

== What it does ==

* Per-product HSN/SAC code and GST rate, editable from the WCFM vendor product manager
  and from the wp-admin product edit screen (Tax tab). The GST rate is a dropdown of Indian
  GST slabs (0%, 0.25%, 3%, 5%, 12%, 18%, 28%, 40%) — the same "pick a tax code, don't type a
  number" pattern Amazon/Flipkart use for seller listings — reflecting the post-September-2025
  GST 2.0 structure: 5%/18% are the current standard rates, 40% is the de-merit rate for select
  luxury/sin goods, 0.25%/3% remain for precious stones/gold, and 12%/28% are kept only for
  legacy items still taxed at the old rates. A live preview under the dropdown shows the
  CGST+SGST split for same-state buyers and the IGST rate for other states, and an inline
  "tax info complete/incomplete" status on the product form itself catches a problem before
  publishing rather than after.
* Bulk HSN/GST update via CSV (Settings > GST & TCS > Bulk HSN/GST Update for admins, and on
  each vendor's own GST & TCS tab): export current listing tax data, edit it in a spreadsheet,
  and re-upload to update many products in one pass — mirroring the bulk tax-code sheets
  Amazon/Flipkart sellers use instead of editing listings one at a time. Vendor uploads are
  always restricted to their own products, verified per row, never trusted from the file.
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
  vendor filterable, with CSV export, plus a one-click "Previous Month" quick filter on every
  report page. GST sales and GSTR-1 exports both split B2B (registered buyer) and B2C figures
  into separate CSV downloads, matching how GSTR-1 itself must be filed; the TCS report shows
  the same B2B/B2C breakdown for the operator's own reconciliation (GSTR-8 filing itself is
  per-vendor only and doesn't require the split).
* An "HSN & Rate Summary" report (admin, and on each vendor's own GST & TCS tab): an HSN-wise
  summary of outward supplies matching GSTR-1 Table 12 (HSN, quantity, taxable value, tax
  split), and a rate-wise summary matching GSTR-3B Table 3.1, both per vendor with CSV export.
  UQC (unit of measure) isn't tracked by WooCommerce, so it's always shown as NOS (Numbers) —
  flagged in the report for vendors who sell by weight, length, or volume to double-check.
* A vendor-facing "GST & TCS" tab under My Account, date-range filterable, with two CSV
  downloads: a summary report (orders, net taxable value, GST collected, TCS deducted) and a
  detailed sales report (every order line, with full order details — customer name/email/
  phone, billing/shipping address, payment method, order status, product/SKU, quantity,
  pricing, subtotal/discount/shipping/order total — plus HSN, taxable value, CGST/SGST/IGST
  and buyer GSTIN for business purchases) for the vendor's own bookkeeping/accountant — plus a
  `[wgt_vendor_gst_report]` shortcode for custom placement. Large date ranges queue in the
  background and email a download link, same as the admin exports. Includes the same
  "Previous Month" quick filter and separate B2B/B2C detailed-invoice CSV downloads as the
  admin reports, since GSTR-1 filing requires those figures reported separately.
* A printable GST invoice per order (HSN, per-vendor tax breakup, GSTIN), viewable in the
  browser or downloaded as a PDF, linked from the order-received/order-details page.
* Manual e-invoice fields (IRN, Ack No, Ack Date, QR text) per vendor per order, for stores
  where a vendor is above the e-invoicing turnover threshold and generates these on the govt
  e-invoice portal — recorded here and printed on the invoice, not auto-generated.
* A GSTR-1 style invoice-level CSV export doubling as a full sales report (one row per order
  line: order status, payment method, customer name/email/phone, billing/shipping address,
  product/SKU, quantity, pricing, vendor, buyer GSTIN if a business purchase, place of
  supply, HSN, taxable value, CGST/SGST/IGST, and order subtotal/discount/shipping/total) to
  help vendors/CAs populate their own GSTR-1 B2B/B2CS filing as well as reconcile sales. This
  is a convenience export, not the GSTN portal's JSON upload format.
* A "Compliance check" panel on Settings > GST & TCS flagging vendors missing a GSTIN and
  published products missing an HSN/SAC code, plus the same nudge on the vendor's own
  GST & TCS account tab.
* HSN/SAC codes must be exactly 6 digits when entered — enforced on save in both the WCFM
  frontend product form and wp-admin, with an admin product-list warning for any legacy value
  saved before this rule existed. Requiring an HSN/SAC at all to publish a product is a
  separate, still-optional toggle (Settings > GST & TCS).
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
* An automated monthly email to every vendor with their previous month's sales & GST summary
  (orders, net taxable value, CGST/SGST/IGST, TCS deducted if enabled) plus a detailed
  line-item invoice CSV attached, for their own GST filing. Configurable send day, an option
  to skip vendors with no sales, and a "Send Now" button to test or catch up a missed run.
  Scheduled via WooCommerce's Action Scheduler using a real day-of-month cron expression.
* The same vendor reports (summary + detailed CSV downloads) also appear as a "GST & TCS" tab
  inside the WCFM vendor dashboard itself (Settings > Products > Orders sidebar), not only
  under WooCommerce My Account — most WCFM vendors never visit My Account at all. Registered
  via WCFM's own extension points (wcfm_menus, wcfm_query_vars, wcfm_load_views), with an
  automatic one-time permalink flush so it works immediately even on a site upgrading the
  plugin in place.
* Optional commission reporting for the WCFM Delivery and WCFM Affiliate add-ons (only
  activates if the corresponding add-on is installed): admin report pages ("Delivery
  Commissions" / "Affiliate Commissions") with date-range filtering, a "Previous Month" quick
  filter and CSV export, plus an opt-in monthly email to each delivery person/affiliate with
  their commission earned and order count for the previous month and an order-level CSV
  attached. This is deliberately an earnings summary, not a GST report — delivery persons and
  affiliates earn a service commission rather than selling anything themselves, so none of
  this plugin's GST/TCS-on-product-sales calculation applies to them; see Notes below.
* The Settings > GST & TCS compliance panel flags published products still saved at a
  GST-2.0-legacy rate (12% or 28%) — not necessarily wrong, but worth a CA confirming each one
  wasn't simply missed during reclassification after the September 2025 reform. The same flag
  appears next to the rate on the product list column, and the affected products link straight
  to Bulk HSN/GST Update for review.
* A TCS Reconciliation report (Settings > GST & TCS > TCS Reconciliation): upload the
  vendor-wise TCS credit the government actually shows for a period (GSTR-2X, compiled from
  your GSTR-8 filing) as a CSV, and get a side-by-side comparison against this plugin's own
  computed TCS ledger per vendor/period — matched, mismatched, or unmatched (no vendor found
  for that GSTIN) — so a discrepancy is caught early instead of only at filing time or in a
  vendor dispute. Doesn't fetch anything from the GST portal automatically.
* Warns an admin on nginx (via SERVER_SOFTWARE detection) that the .htaccess "Deny from all"
  rule protecting the large-export file folder doesn't do anything for them — nginx ignores
  .htaccess — and gives the equivalent nginx server block to add. The one-time download token
  is the real access control regardless of webserver; this is defense in depth.

== Notes ==

* This plugin computes and reports GST-TCS under GST law (Section 52, CGST Act, no minimum
  threshold). It does not implement Income Tax Act Section 52 TCS (0.1%/1% above the ₹5 lakh
  annual threshold) — that is a separate compliance requirement and out of scope here.
* The delivery person / affiliate commission reports and emails are an earnings summary only.
  This plugin does not calculate, collect, or deduct any GST on delivery or affiliate
  commission — if a delivery person or affiliate is themselves GST-registered, invoicing the
  marketplace for their commission (including any reverse-charge treatment) is between them
  and the marketplace operator, and is outside this plugin's scope.
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

= 2.3.0 =
* CRITICAL FIX: HSN/SAC and GST Rate entered on a product through the WCFM frontend product
  manager were never actually saved — they'd appear blank again (or the GST Rate dropdown
  would misleadingly show "0%" with no option actually selected) after saving/publishing.
  Root cause: WCFM's frontend product form submits the entire form as one serialized string,
  which WCFM's own controller parses and hands to plugins as the *second argument* of the
  'after_wcfm_products_manage_meta_save' action — this plugin was instead reading the field
  values straight from $_POST, where they never appear as flat keys in that flow, so every
  save silently did nothing. Fixed to read from WCFM's own parsed form data (falling back to
  parsing the raw submission if a given WCFM version/context doesn't populate it), and fixed
  the same root cause in enforce_hsn_rules() (the "block publish without a valid HSN" check,
  which ran on a different, earlier hook with no access to WCFM's parsed data at all).
* Same root cause, same fix: vendor GSTIN/PAN/legal name/state entered through the WCFM
  vendor Settings > General tab had the identical problem and are now saved correctly too.
* If you were on an earlier version and your vendors' product HSN/GST or GSTIN/PAN/state
  appear empty despite having been entered through the WCFM frontend (not wp-admin, which was
  never affected), that data was never actually written — it will need to be re-entered after
  updating to this version. Check Settings > GST & TCS's compliance panel for a count of
  products still missing an HSN/SAC code or vendors without a GSTIN on file.

= 2.2.0 =
* Made every screen this plugin renders responsive on narrow/mobile screens: wide data tables
  (GST report, TCS report, B2B/B2C breakdowns, HSN & Rate Summary, TCS Reconciliation, the
  vendor dashboard, delivery/affiliate commission reports) now scroll horizontally within their
  own box on a narrow screen instead of breaking the page layout; label:value tables (Sales
  Summary, GST-TCS Deducted) stack into readable blocks instead of cramming two columns into a
  phone-width row; filter forms and button rows wrap and, below 600px, stack to full width for
  easier tapping.
* The printable/emailed GST invoice (a standalone HTML document, not inside wp-admin or the
  site theme) was missing a viewport meta tag entirely — on a phone it was rendering at desktop
  width and shrinking to fit, forcing pinch-zoom to read anything. Fixed with a proper viewport
  meta tag plus a mobile-only font-size/padding/margin adjustment and a horizontal-scroll
  wrapper for the line-item table. Scoped so none of this affects the PDF output (the PDF
  renderer never matches the mobile media query, and gets no viewport meta tag at all).

= 2.1.0 =
* Renamed the plugin folder and main file to match the new brand: `wcfm-gst-tcs-marketplace/`
  is now `soloman-taxes-for-wcfm/`, and the main plugin file is now `soloman-taxes-for-wcfm.php`
  (was `wcfm-gst-tcs-marketplace.php`). This is the actual WordPress plugin slug, unlike the
  2.0.0 rename which only changed the display name.
* IMPORTANT if you already have the previous version active: WordPress identifies a plugin by
  its folder path, so this is a new slug as far as WordPress is concerned. Uploading this zip
  will install it alongside the old one rather than replacing it. To switch over: deactivate
  and delete the old "WCFM GST & TCS for Multivendor Marketplace" / "SOLOMAN TAXES FOR WCFM"
  (wcfm-gst-tcs-marketplace folder) plugin, then install and activate this zip. All of your
  data is safe either way — HSN/GST product meta, vendor GSTIN/state settings, the TCS ledger,
  and all plugin settings live in the database under unchanged internal keys, independent of
  the plugin folder name, so nothing is lost by deactivating the old folder and activating the
  new one.
* Internal class name prefixes (WGT_*), the settings option key, meta keys, the TCS ledger
  table name, and the text domain are still deliberately unchanged, for the same reason.

= 2.0.0 =
* Renamed the plugin to "SOLOMAN TAXES FOR WCFM" (Plugin Name header, readme title, and
  on-screen dependency notice). Internal identifiers — class name prefixes (WGT_*), post/order
  meta keys (_wgt_hsn_code, _wgt_gst_rate, etc.), the wgt_settings option, the TCS ledger table,
  and the text domain — were deliberately left unchanged, so existing HSN/GST data, vendor
  GSTIN/state settings, and the TCS ledger on an already-live site keep working without any
  migration step. The plugin folder/slug also wasn't renamed, for the same reason.
* Added a legacy-GST-rate flag to the compliance panel and product list: published products
  still at 12% or 28% (phased out of general use by the September 2025 GST 2.0 reform) are
  surfaced for a CA to confirm, with a link straight to Bulk HSN/GST Update for review.
* Added a TCS Reconciliation report (Settings > GST & TCS > TCS Reconciliation): upload
  GSTR-2X-style vendor TCS credit data as a CSV and get a side-by-side match/mismatch/unmatched
  comparison against this plugin's own computed TCS ledger, per vendor and period.
* Added an nginx detection notice: the large-export file folder's .htaccess "Deny from all"
  rule is Apache-only and silently does nothing on nginx — an admin on nginx now gets a warning
  with the equivalent server block to add, since PHP can't write nginx config itself. The
  one-time download token remains the real access control regardless of webserver.

= 1.10.0 =
* Extended the GST 2.0 slab update (1.9.0) to the rest of the admin/vendor surfaces instead of
  just the per-product form: Settings > GST & TCS > "Default GST rate" is now the same slab
  dropdown (0/0.25/3/5/12/18/28/40%), with server-side validation falling back to "no default"
  if a tampered submission sends a rate outside that list.
* Bulk HSN/GST CSV import now validates the GST Rate column against the same slab list,
  rejecting (and reporting) any row with a rate that isn't a recognized current or legacy
  slab, instead of accepting any numeric value — keeping bulk-imported data to the same
  standard as the single-product dropdown. The upload page lists the valid slabs up front.

= 1.9.0 =
* Updated the product GST rate dropdown to add the 40% de-merit rate introduced by the
  September 2025 GST reform ("GST 2.0"), and refreshed the on-screen guidance to reflect the
  current slab structure: 5%/18% are now the standard rates, 40% covers select luxury/sin
  goods, 0.25%/3% remain for precious stones/gold, and 12%/28% are kept in the list (not
  removed) only for legacy items still taxed at the old rates. No products or historical
  orders are affected — this only changes what's offered when picking a rate going forward.

= 1.8.0 =
* Product listing polish, informed by how Amazon/Flipkart guide sellers through GST setup:
  a live tax-split preview under the GST rate dropdown (CGST+SGST for same-state buyers, IGST
  for other states), and an inline "tax info complete/incomplete" status on the product form
  itself so a vendor catches a missing/invalid HSN before attempting to publish.
* Bulk HSN/GST update via CSV — export current listing tax data, edit offline, re-upload to
  update many products at once — for admins (any vendor) and for each vendor (their own
  products only, ownership verified per row on import). New Settings > GST & TCS > Bulk
  HSN/GST Update admin page; a matching section on the vendor GST & TCS tab.
* New "HSN & Rate Summary" report (admin + vendor-facing): HSN-wise summary of outward
  supplies (GSTR-1 Table 12 shape) and a rate-wise summary (GSTR-3B Table 3.1 shape), both
  per vendor with CSV export and the existing Previous Month quick filter.
* Refactored HSN/GST-rate save logic in WGT_Product_Fields into reusable static methods so
  the new bulk-import tool updates products through the exact same validation as the regular
  product forms, instead of duplicating it.

= 1.7.0 =
* Added optional commission reporting for the WCFM Delivery (wc-frontend-manager-delivery) and
  WCFM Affiliate (wc-frontend-manager-affiliate) add-ons, auto-detected and only activating
  when the corresponding add-on is installed: new admin pages "Delivery Commissions" and
  "Affiliate Commissions" (date-range filter, Previous Month quick filter, CSV export), and
  an opt-in extension of the monthly automated email so delivery persons and affiliates also
  receive their previous month's commission earned, order count, and an order-level CSV, for
  their own tax records. Two new settings toggles (off by default) under Settings > GST & TCS
  control this, only shown when the respective add-on is active. This is deliberately an
  earnings summary rather than a GST report — the plugin doesn't calculate GST on commission
  income for either role.

= 1.6.0 =
* Added a one-click "Previous Month" quick filter to every report page (admin GST report,
  admin TCS report, admin GSTR-1 export, and the vendor-facing GST & TCS tab/dashboard),
  filling in the exact From/To dates for last calendar month so month-end GST filing doesn't
  require manually working out date boundaries.
* GST Tax Summary and GSTR-1 exports (both admin and vendor-facing) now show a B2B/B2C
  breakdown and offer separate "B2B Only" / "B2C Only" CSV downloads, alongside the existing
  combined export — GSTR-1 must be filed with B2B (registered buyer) and B2C (unregistered)
  supplies reported in separate sections, so these line up directly with that filing.
* The GSTR-8 style TCS report now also shows a B2B/B2C breakdown table and separate B2B/B2C
  CSV exports, with a note that GSTR-8 itself is filed per-vendor only and doesn't require
  this split — it's provided for the operator's own reconciliation against each vendor's
  GSTR-1.
* HSN/SAC codes must now be exactly 6 digits: enforced on save (WCFM frontend and wp-admin),
  with `maxlength`/`pattern` on both input fields and a legacy-data warning icon in the admin
  product list for any previously saved HSN that isn't 6 digits. This is separate from, and
  applies regardless of, the existing "HSN mandatory to publish" toggle.

= 1.5.0 =
* Vendor GST/TCS reports (summary + detailed CSV downloads) now also appear inside the WCFM
  vendor dashboard itself as a "GST & TCS" sidebar tab, using WCFM's own extension hooks
  (wcfm_menus/wcfm_query_vars/wcfm_load_views) — previously only reachable via WooCommerce's
  My Account page, which most WCFM vendors rarely use. Both surfaces render identical content.
  Includes a one-time automatic permalink flush so the new tab works right away for sites
  updating the plugin in place, not just fresh installs.

= 1.4.1 =
* The detailed/GSTR-1 sales report (admin export, vendor download, and monthly email
  attachment) now includes full order details, not just tax figures: order status, payment
  method, customer name/email/phone, billing and shipping address, product name/SKU,
  quantity, unit price, item subtotal, and order subtotal/discount/shipping/total — alongside
  the existing vendor/GST/tax-split columns. All three CSV outputs share one column
  definition so they can't drift out of sync with each other.

= 1.4.0 =
* Automated monthly vendor email: sales & GST summary + detailed invoice CSV for the previous
  month, sent to every vendor with sales in that period (Settings > GST & TCS to enable, pick
  the send day, and toggle skipping vendors with no sales). Includes a "Send Now" button to
  test immediately or catch up a missed run. Requires Action Scheduler (bundled with
  WooCommerce); the plugin no-ops this feature if it's unavailable rather than erroring.

= 1.3.1 =
* Fixed the vendor "GST & TCS" tab showing 0.00 for Net Taxable Value/GST Collected whenever
  GST-TCS collection was switched off — those figures were being read from the TCS ledger,
  which only gets rows when TCS is enabled, even though GST itself may still be calculated
  and charged. Sales/GST figures now come from the vendor's actual orders directly; the
  TCS-deducted figures and TCS ledger CSV only appear when TCS collection is on.

= 1.3.0 =
* Vendor "GST & TCS" account tab now uses a From/To date-range filter (replacing the old
  Financial Year text box) and adds a second download: a detailed invoice-level CSV (HSN,
  taxable value, CGST/SGST/IGST, buyer name/GSTIN for business purchases) alongside the
  existing summary report — every export is always forced to the logged-in vendor's own ID.
* Large vendor-triggered exports now use the same background-job + email-link path as admin
  exports; fixed the download-link permission check so the requesting vendor (not just
  administrators) can retrieve their own queued file, and added a frontend notice for it.

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
