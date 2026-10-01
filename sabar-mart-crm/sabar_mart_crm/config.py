"""Central policy thresholds for the Sabar Mart CRM engine.

Every business rule that triggers an alert, tier change or payout reads its
limits from here, so policy changes never require touching module logic.
"""

from decimal import Decimal

# --- Customer management -------------------------------------------------
CART_ABANDONMENT_HOURS = 24          # cart untouched this long counts as abandoned
CHURN_RISK_DAYS = 90                 # no purchase in this window -> at-risk
LOYAL_MIN_ORDERS = 3                 # completed orders to reach "loyal"
VIP_MIN_LIFETIME_VALUE = Decimal("50000.00")

LOYALTY_POINTS_PER_100 = 1           # 1 point per 100 currency units spent
LOYALTY_MILESTONES = (100, 500, 1000)  # point balances that fire a reward trigger

STANDARD_REFUND_LIMIT = Decimal("10000.00")

# --- Vendor management ---------------------------------------------------
REQUIRED_VENDOR_DOCUMENTS = ("business_registration", "tax_id", "pan_card", "bank_details", "identity_proof")
STORE_SETUP_MILESTONES = ("profile_completed", "first_product_listed", "shipping_configured", "payout_account_linked")

DEFAULT_COMMISSION_RATE = Decimal("0.10")  # Sabar Mart marketplace take rate
PAYOUT_CYCLE_DAYS = 7

# --- Indian marketplace tax withholding on vendor payouts ----------------
# !! Confirm every rate and its base with your Chartered Accountant before go-live. !!
# Defaults reflect the rates as amended by Budget 2024 (in force from Oct 2024):
GST_ON_COMMISSION_RATE = Decimal("0.18")  # GST charged on Sabar Mart's commission (services)
GST_TCS_RATE = Decimal("0.005")           # TCS, CGST Act s.52 (0.25% CGST + 0.25% SGST, or 0.5% IGST)
TDS_194O_RATE = Decimal("0.001")          # TDS, Income-tax Act s.194-O, vendor with PAN on file
TDS_194O_NO_PAN_RATE = Decimal("0.05")    # s.206AA: higher rate when the vendor's PAN is not verified
# Base for TCS/TDS: the taxable value of the sale, i.e. excluding GST. Sabar Mart prices include
# GST, so the taxable value is price / (1 + the product's GST rate). Commission is charged on the
# GST-inclusive price.
PRICES_INCLUDE_GST = True
DEFAULT_GST_RATE = Decimal("0.18")  # only used when a product is created without a rate (the API requires one)
MAX_GST_RATE = Decimal("0.40")

MIN_REVIEW_SCORE = Decimal("3.5")
MIN_FULFILLMENT_RATE = Decimal("0.95")
MAX_SHIPPING_DELAY_RATE = Decimal("0.10")
MAX_RETURN_RATE = Decimal("0.15")

# --- Affiliate management ------------------------------------------------
RETURN_WINDOW_DAYS = 14              # commission is only payable after this window
ATTRIBUTION_WINDOW_DAYS = 30         # a click attributes conversions for this long

# (minimum completed orders in period, commission rate) - highest match wins.
AFFILIATE_TIERS = (
    ("Bronze", 0, Decimal("0.03")),
    ("Silver", 25, Decimal("0.05")),
    ("Gold", 100, Decimal("0.07")),
    ("Platinum", 500, Decimal("0.10")),
)

MONEY_QUANT = Decimal("0.01")
