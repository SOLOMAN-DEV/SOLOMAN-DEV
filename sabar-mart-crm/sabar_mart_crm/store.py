"""Single source of truth shared by every CRM module.

``Store`` keeps everything in memory. ``sabar_mart_crm.db.DBStore`` has the same
interface but loads from and saves to a SQL database (MySQL/MariaDB, PostgreSQL
or SQLite). The managers only ever read and write through these attributes and
methods, so they work unchanged with either one.
"""

from __future__ import annotations

from collections.abc import MutableMapping
from dataclasses import dataclass, field
from typing import Any

from .models import (
    Affiliate,
    Attribution,
    Customer,
    InternalTask,
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


# collection name -> (record class, primary key attribute, indexed attributes)
COLLECTIONS: dict[str, tuple[type, str, tuple[str, ...]]] = {
    "customers": (Customer, "customer_id", ()),
    "orders": (Order, "order_id", ("customer_id", "vendor_id")),
    "tickets": (SupportTicket, "ticket_id", ("customer_id",)),
    "products": (Product, "product_id", ("vendor_id",)),
    "vendors": (Vendor, "vendor_id", ()),
    "ledger": (LedgerEntry, "entry_id", ("vendor_id",)),
    "affiliates": (Affiliate, "affiliate_id", ("referral_code",)),
    "clicks": (ReferralClick, "click_id", ("referral_code",)),
    "attributions": (Attribution, "order_id", ("affiliate_id",)),
    "assets": (MarketingAsset, "asset_id", ()),
    "tasks": (InternalTask, "task_id", ("owner_team", "subject_id")),
}


@dataclass
class Store:
    customers: MutableMapping[str, Customer] = field(default_factory=dict)
    orders: MutableMapping[str, Order] = field(default_factory=dict)
    tickets: MutableMapping[str, SupportTicket] = field(default_factory=dict)
    products: MutableMapping[str, Product] = field(default_factory=dict)
    vendors: MutableMapping[str, Vendor] = field(default_factory=dict)
    ledger: MutableMapping[str, LedgerEntry] = field(default_factory=dict)
    affiliates: MutableMapping[str, Affiliate] = field(default_factory=dict)
    clicks: MutableMapping[str, ReferralClick] = field(default_factory=dict)
    attributions: MutableMapping[str, Attribution] = field(default_factory=dict)
    assets: MutableMapping[str, MarketingAsset] = field(default_factory=dict)
    tasks: MutableMapping[str, InternalTask] = field(default_factory=dict)
    sequences: dict[str, int] = field(default_factory=dict)

    def next_id(self, name: str) -> int:
        """Next value of a named counter (task, ledger and click IDs)."""
        self.sequences[name] = self.sequences.get(name, 0) + 1
        return self.sequences[name]

    def find(self, collection: str, **criteria: Any) -> list[Any]:
        """Records whose attributes equal every criterion. DBStore answers indexed ones with SQL."""
        return [r for r in getattr(self, collection).values()
                if all(getattr(r, k) == v for k, v in criteria.items())]

    def affiliate_by_code(self, code: str) -> Affiliate | None:
        return next(iter(self.find("affiliates", referral_code=code)), None)

    def orders_for_vendor(self, vendor_id: str) -> list[Order]:
        return self.find("orders", vendor_id=vendor_id)

    def orders_for_customer(self, customer_id: str) -> list[Order]:
        return self.find("orders", customer_id=customer_id)
