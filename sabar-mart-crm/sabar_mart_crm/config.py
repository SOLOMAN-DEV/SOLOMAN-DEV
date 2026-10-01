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
REQUIRED_VENDOR_DOCUMENTS = ("business_registration", "tax_id", "bank_details", "identity_proof")
STORE_SETUP_MILESTONES = ("profile_completed", "first_product_listed", "shipping_configured", "payout_account_linked")

DEFAULT_COMMISSION_RATE = Decimal("0.10")  # Sabar Mart marketplace take rate
PAYOUT_CYCLE_DAYS = 7

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
