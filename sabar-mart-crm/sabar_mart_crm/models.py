"""Core data structures shared by every CRM module."""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime
from decimal import Decimal
from enum import Enum
from typing import Any

from . import config


def money(value: Decimal | int | float | str) -> Decimal:
    return Decimal(str(value)).quantize(config.MONEY_QUANT)


def to_jsonable(obj: Any) -> Any:
    """Recursively convert dataclasses/Decimals/datetimes/enums to JSON-safe values."""
    if hasattr(obj, "__dataclass_fields__"):
        return to_jsonable(asdict(obj))
    if isinstance(obj, dict):
        return {k: to_jsonable(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple, set)):
        return [to_jsonable(v) for v in obj]
    if isinstance(obj, Decimal):
        return str(obj)
    if isinstance(obj, datetime):
        return obj.isoformat()
    if isinstance(obj, Enum):
        return obj.value
    return obj


# --- Customers -----------------------------------------------------------

class LifecycleStage(str, Enum):
    SIGNED_UP = "signed_up"
    BROWSING = "browsing"
    CART_ABANDONED = "cart_abandoned"
    FIRST_PURCHASE = "first_purchase"
    REPEAT = "repeat"
    LOYAL = "loyal"
    VIP = "vip"
    AT_RISK = "at_risk"


class OrderStatus(str, Enum):
    PLACED = "placed"
    SHIPPED = "shipped"
    DELIVERED = "delivered"
    RETURNED = "returned"
    CANCELLED = "cancelled"


@dataclass
class Order:
    order_id: str
    customer_id: str
    vendor_id: str
    product_id: str
    category: str
    amount: Decimal
    placed_at: datetime
    status: OrderStatus = OrderStatus.PLACED
    delivered_at: datetime | None = None
    shipped_late: bool = False
    referral_code: str | None = None
    refunded: Decimal = Decimal("0.00")  # total refunded so far (partial refunds + returns)
    gst_rate: Decimal | None = None  # copied from the product when the order is placed

    @property
    def net_amount(self) -> Decimal:
        return self.amount - self.refunded

    def taxable_value(self, amount: Decimal | None = None) -> Decimal:
        """GST-exclusive value of ``amount`` (default: the full order amount)."""
        amount = self.amount if amount is None else amount
        if not config.PRICES_INCLUDE_GST:
            return amount
        rate = config.DEFAULT_GST_RATE if self.gst_rate is None else self.gst_rate
        return money(amount / (1 + rate))


@dataclass
class Customer:
    customer_id: str
    name: str
    email: str
    phone: str
    signed_up_at: datetime
    browsed_categories: dict[str, int] = field(default_factory=dict)
    cart: dict[str, Decimal] = field(default_factory=dict)  # product_id -> price
    cart_updated_at: datetime | None = None
    order_ids: list[str] = field(default_factory=list)
    loyalty_points: int = 0
    milestones_awarded: list[int] = field(default_factory=list)


class Sentiment(str, Enum):
    POSITIVE = "positive"
    NEUTRAL = "neutral"
    NEGATIVE = "negative"


class Priority(str, Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


@dataclass
class SupportTicket:
    ticket_id: str
    customer_id: str
    subject: str
    body: str
    created_at: datetime
    order_id: str | None = None
    sentiment: Sentiment | None = None
    priority: Priority | None = None
    assigned_team: str | None = None
    routing_reasons: list[str] = field(default_factory=list)


# --- Vendors -------------------------------------------------------------

class VerificationStatus(str, Enum):
    PENDING = "pending"
    UNDER_REVIEW = "under_review"
    VERIFIED = "verified"
    REJECTED = "rejected"


@dataclass
class Vendor:
    vendor_id: str
    store_name: str
    contact_email: str
    commission_rate: Decimal = config.DEFAULT_COMMISSION_RATE
    documents: dict[str, str] = field(default_factory=dict)  # doc -> pending/approved/rejected
    milestones: dict[str, datetime] = field(default_factory=dict)
    verification_status: VerificationStatus = VerificationStatus.PENDING
    review_scores: list[Decimal] = field(default_factory=list)
    is_flagged: bool = False


@dataclass
class LedgerEntry:
    entry_id: str
    vendor_id: str
    kind: str  # sale | commission | refund | payout
    amount: Decimal  # signed: positive credits the vendor, negative debits
    created_at: datetime
    reference: str


# --- Affiliates ----------------------------------------------------------

@dataclass
class Affiliate:
    affiliate_id: str
    name: str
    email: str
    referral_code: str
    approved: bool = False
    assets: list[str] = field(default_factory=list)


@dataclass
class ReferralClick:
    click_id: str
    referral_code: str
    product_id: str
    clicked_at: datetime
    visitor_fingerprint: str


@dataclass
class MarketingAsset:
    asset_id: str
    kind: str  # banner | discount_code | collateral
    title: str
    payload: str
    restricted_to_tier: str | None = None


class RefundStatus(str, Enum):
    PENDING = "pending_review"
    APPROVED = "approved"
    REJECTED = "rejected"


@dataclass
class Refund:
    refund_id: str
    order_id: str
    customer_id: str
    vendor_id: str
    amount: Decimal
    reason: str
    requested_by: str
    requested_at: datetime
    status: RefundStatus
    decided_by: str | None = None
    decided_at: datetime | None = None
    task_id: str | None = None


@dataclass
class Attribution:
    order_id: str
    affiliate_id: str
    click_id: str
    amount: Decimal
    commission_paid: Decimal = Decimal("0.00")  # cumulative, across payouts and clawbacks
    commission_rate: Decimal | None = None  # locked when the order is first paid out


@dataclass
class AffiliatePayout:
    payout_id: str
    affiliate_id: str
    created_at: datetime
    run_by: str
    tier: str
    commission_rate: Decimal
    amount: Decimal
    lines: list[dict[str, str]] = field(default_factory=list)  # order_id, net_sales, commission, kind


# --- Access & audit -----------------------------------------------------

@dataclass
class ApiUser:
    username: str
    role: str
    key_hash: str  # sha256 of the API key; the key itself is never stored
    created_at: datetime
    active: bool = True


@dataclass
class AuditEntry:
    audit_id: str
    at: datetime
    actor: str
    role: str
    method: str
    path: str
    outcome: str  # success | denied | not_found | conflict | invalid | error


@dataclass
class IdempotencyRecord:
    key_id: str        # sha256(actor + key): keys are scoped to the caller
    actor: str
    fingerprint: str   # sha256 of method, path, query and body
    response: str      # JSON of the original result, replayed on retries
    created_at: datetime


# --- Internal workflow ---------------------------------------------------

class TaskStatus(str, Enum):
    OPEN = "open"
    IN_PROGRESS = "in_progress"
    RESOLVED = "resolved"


@dataclass
class InternalTask:
    task_id: str
    rule: str
    title: str
    owner_team: str
    priority: Priority
    subject_type: str
    subject_id: str
    created_at: datetime
    details: dict[str, Any] = field(default_factory=dict)
    status: TaskStatus = TaskStatus.OPEN
