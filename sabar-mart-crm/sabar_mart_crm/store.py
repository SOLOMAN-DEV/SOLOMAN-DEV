"""In-memory single source of truth shared by every CRM module.

Swap this class for a database-backed implementation with the same attributes
to persist data; the managers only read and write through it.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from .models import (
    Affiliate,
    Customer,
    LedgerEntry,
    MarketingAsset,
    Order,
    ReferralClick,
    SupportTicket,
    Vendor,
)


@dataclass
class Product:
    product_id: str
    vendor_id: str
    name: str
    category: str
    popularity: int = 0


@dataclass
class Store:
    customers: dict[str, Customer] = field(default_factory=dict)
    orders: dict[str, Order] = field(default_factory=dict)
    tickets: dict[str, SupportTicket] = field(default_factory=dict)
    products: dict[str, Product] = field(default_factory=dict)
    vendors: dict[str, Vendor] = field(default_factory=dict)
    ledger: list[LedgerEntry] = field(default_factory=list)
    affiliates: dict[str, Affiliate] = field(default_factory=dict)
    clicks: list[ReferralClick] = field(default_factory=list)
    assets: dict[str, MarketingAsset] = field(default_factory=dict)

    def affiliate_by_code(self, code: str) -> Affiliate | None:
        return next((a for a in self.affiliates.values() if a.referral_code == code), None)

    def orders_for_vendor(self, vendor_id: str) -> list[Order]:
        return [o for o in self.orders.values() if o.vendor_id == vendor_id]

    def orders_for_customer(self, customer_id: str) -> list[Order]:
        return [o for o in self.orders.values() if o.customer_id == customer_id]
