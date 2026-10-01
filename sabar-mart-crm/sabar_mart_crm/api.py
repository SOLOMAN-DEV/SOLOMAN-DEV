"""FastAPI REST layer over CRMEngine.

    SABAR_CRM_API_KEYS="sk-admin-123:admin,sk-fin-456:finance" uvicorn sabar_mart_crm.api:app

Every request (except /health) must send ``Authorization: Bearer <key>``. The key
decides the caller's role, and the role decides what the caller may see or do.
Callers never choose their own role.

Endpoints are ``async def`` with no awaits on purpose: they all run on the single
event-loop thread, so calls into the (non-thread-safe) in-memory engine are
serialized without a lock.
"""

import hmac
import os
from collections.abc import Callable, Mapping
from datetime import datetime, timezone
from decimal import Decimal
from typing import Annotated, Any

from fastapi import Depends, FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel, Field

from . import config
from .engine import CRMEngine
from .models import (
    Affiliate,
    Customer,
    MarketingAsset,
    Order,
    SupportTicket,
    Vendor,
    to_jsonable,
)
from .rbac import AccessDenied, Permission, Role, has_permission, mask_pii, require, require_team
from .store import Product

Clock = Callable[[], datetime]

ID = Field(min_length=1, max_length=64, pattern=r"^[A-Za-z0-9_.-]+$")
EMAIL = Field(max_length=254, pattern=r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
MONEY = Field(gt=0, max_digits=14, decimal_places=2)


def utc_now() -> datetime:
    # The engine works in naive UTC datetimes.
    return datetime.now(timezone.utc).replace(tzinfo=None)


def parse_api_keys(raw: str) -> dict[str, Role]:
    """Parse ``key:role,key:role`` into a key -> Role map."""
    keys: dict[str, Role] = {}
    for pair in filter(None, (p.strip() for p in raw.split(","))):
        key, sep, role = pair.rpartition(":")
        if not sep or not key:
            raise ValueError(f"malformed API key entry (expected key:role): {pair!r}")
        keys[key] = Role(role)
    return keys


# --- Request bodies ----------------------------------------------------------

class CustomerIn(BaseModel):
    customer_id: str = ID
    name: str = Field(min_length=1, max_length=200)
    email: str = EMAIL
    phone: str = Field(min_length=4, max_length=20, pattern=r"^\+?[0-9 -]+$")


class BrowseIn(BaseModel):
    category: str = Field(min_length=1, max_length=64)


class CartItemIn(BaseModel):
    price: Decimal = MONEY


class ProductIn(BaseModel):
    product_id: str = ID
    vendor_id: str = ID
    name: str = Field(min_length=1, max_length=200)
    category: str = Field(min_length=1, max_length=64)


class OrderIn(BaseModel):
    order_id: str = ID
    customer_id: str = ID
    product_id: str = ID
    amount: Decimal = MONEY
    referral_code: str | None = Field(default=None, max_length=64)


class ShipIn(BaseModel):
    late: bool = False


class RefundIn(BaseModel):
    amount: Decimal = MONEY


class TicketIn(BaseModel):
    ticket_id: str = ID
    customer_id: str = ID
    subject: str = Field(min_length=1, max_length=200)
    body: str = Field(min_length=1, max_length=10_000)
    order_id: str | None = Field(default=None, max_length=64)


class VendorIn(BaseModel):
    vendor_id: str = ID
    store_name: str = Field(min_length=1, max_length=200)
    contact_email: str = EMAIL
    commission_rate: Decimal = Field(default=config.DEFAULT_COMMISSION_RATE, ge=0, le=Decimal("0.5"))


class DocumentReviewIn(BaseModel):
    approved: bool


class ReviewIn(BaseModel):
    score: Decimal = Field(ge=1, le=5, decimal_places=2)


class AffiliateIn(BaseModel):
    affiliate_id: str = ID
    name: str = Field(min_length=1, max_length=200)
    email: str = EMAIL
    referral_code: str = Field(min_length=3, max_length=32, pattern=r"^[A-Za-z0-9_-]+$")


class ClickIn(BaseModel):
    referral_code: str = Field(min_length=1, max_length=64)
    product_id: str = ID
    visitor_fingerprint: str = Field(min_length=1, max_length=128)


class AssetIn(BaseModel):
    asset_id: str = ID
    kind: str = Field(pattern=r"^(banner|discount_code|collateral)$")
    title: str = Field(min_length=1, max_length=200)
    payload: str = Field(min_length=1, max_length=2000)
    restricted_to_tier: str | None = Field(default=None, pattern="^(" + "|".join(t[0] for t in config.AFFILIATE_TIERS) + ")$")


# --- App factory --------------------------------------------------------------

def create_app(
    engine: CRMEngine | None = None,
    api_keys: Mapping[str, Role] | None = None,
    clock: Clock = utc_now,
) -> FastAPI:
    crm = engine or CRMEngine()
    keys = dict(api_keys) if api_keys is not None else parse_api_keys(os.environ.get("SABAR_CRM_API_KEYS", ""))
    store = crm.store
    bearer = HTTPBearer(auto_error=False)

    app = FastAPI(
        title="Sabar Mart CRM API",
        version="1.0.0",
        description="REST access to the Sabar Mart CRM engine: customers, vendors, affiliates and internal tasks.",
    )
    app.state.crm = crm

    @app.exception_handler(AccessDenied)
    async def _forbidden(_: Request, exc: AccessDenied) -> JSONResponse:
        return JSONResponse(status_code=403, content={"detail": str(exc)})

    @app.exception_handler(ValueError)
    async def _conflict(_: Request, exc: ValueError) -> JSONResponse:
        # The engine raises ValueError for business-rule violations (bad state transition, closed window...).
        return JSONResponse(status_code=409, content={"detail": str(exc)})

    async def current_role(creds: HTTPAuthorizationCredentials | None = Depends(bearer)) -> Role:
        if creds is not None:
            for key, role in keys.items():
                if hmac.compare_digest(creds.credentials.encode(), key.encode()):
                    return role
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "invalid or missing API key",
                            headers={"WWW-Authenticate": "Bearer"})

    RoleDep = Annotated[Role, Depends(current_role)]

    def found(mapping: Mapping[str, Any], key: str, kind: str) -> Any:
        if key not in mapping:
            raise HTTPException(status.HTTP_404_NOT_FOUND, f"{kind} '{key}' not found")
        return mapping[key]

    def absent(mapping: Mapping[str, Any], key: str, kind: str) -> None:
        if key in mapping:
            raise HTTPException(status.HTTP_409_CONFLICT, f"{kind} '{key}' already exists")

    def period(start: datetime | None, end: datetime | None) -> tuple[datetime, datetime]:
        def naive(d: datetime) -> datetime:
            return d.astimezone(timezone.utc).replace(tzinfo=None) if d.tzinfo else d
        return naive(start) if start else datetime.min, naive(end) if end else datetime.max

    # --- Meta -----------------------------------------------------------------

    @app.get("/health", tags=["meta"])
    async def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/me", tags=["meta"])
    async def me(role: RoleDep) -> dict[str, Any]:
        return {"role": role.value, "permissions": sorted(p.value for p in Permission if has_permission(role, p))}

    # --- Customers --------------------------------------------------------------

    @app.post("/customers", status_code=201, tags=["customers"])
    async def register_customer(body: CustomerIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        absent(store.customers, body.customer_id, "customer")
        crm.customers.register(Customer(body.customer_id, body.name, body.email, body.phone, clock()))
        return {"customer_id": body.customer_id}

    @app.get("/customers/abandoned-carts", tags=["customers"])
    async def abandoned_carts(role: RoleDep) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_CUSTOMER_PROFILE)
        return crm.customers.abandoned_carts(clock())

    @app.get("/customers/{customer_id}", tags=["customers"])
    async def customer_profile(customer_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_CUSTOMER_PROFILE)
        found(store.customers, customer_id, "customer")
        return crm.customer_profile(role, customer_id, clock())

    @app.get("/customers/{customer_id}/recommendations", tags=["customers"])
    async def recommendations(customer_id: str, role: RoleDep, limit: int = 5) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_RECOMMENDATIONS)
        found(store.customers, customer_id, "customer")
        return crm.customers.recommendations(customer_id, max(1, min(limit, 50)))

    @app.post("/customers/{customer_id}/browse", status_code=204, tags=["customers"])
    async def record_browse(customer_id: str, body: BrowseIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.customers, customer_id, "customer")
        crm.customers.record_browse(customer_id, body.category)

    @app.put("/customers/{customer_id}/cart/{product_id}", status_code=204, tags=["customers"])
    async def add_to_cart(customer_id: str, product_id: str, body: CartItemIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.customers, customer_id, "customer")
        found(store.products, product_id, "product")
        crm.customers.update_cart(customer_id, product_id, body.price, clock())

    @app.delete("/customers/{customer_id}/cart/{product_id}", status_code=204, tags=["customers"])
    async def remove_from_cart(customer_id: str, product_id: str, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.customers, customer_id, "customer")
        crm.customers.update_cart(customer_id, product_id, None, clock())

    # --- Products & orders --------------------------------------------------------

    @app.post("/products", status_code=201, tags=["orders"])
    async def add_product(body: ProductIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        absent(store.products, body.product_id, "product")
        found(store.vendors, body.vendor_id, "vendor")
        store.products[body.product_id] = Product(body.product_id, body.vendor_id, body.name, body.category)
        return {"product_id": body.product_id}

    @app.post("/orders", status_code=201, tags=["orders"])
    async def place_order(body: OrderIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        absent(store.orders, body.order_id, "order")
        found(store.customers, body.customer_id, "customer")
        product = found(store.products, body.product_id, "product")
        # Vendor and category come from the catalog, never from the client.
        order = Order(body.order_id, body.customer_id, product.vendor_id, product.product_id, product.category,
                      body.amount, clock(), referral_code=body.referral_code)
        return to_jsonable(crm.place_order(order))

    @app.post("/orders/{order_id}/ship", status_code=204, tags=["orders"])
    async def ship_order(order_id: str, body: ShipIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.orders, order_id, "order")
        crm.ship_order(order_id, late=body.late, now=clock())

    @app.post("/orders/{order_id}/deliver", tags=["orders"])
    async def deliver_order(order_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        found(store.orders, order_id, "order")
        return to_jsonable(crm.deliver_order(order_id, now=clock()))

    @app.post("/orders/{order_id}/return", status_code=204, tags=["orders"])
    async def return_order(order_id: str, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.orders, order_id, "order")
        crm.return_order(order_id, now=clock())

    @app.post("/orders/{order_id}/cancel", status_code=204, tags=["orders"])
    async def cancel_order(order_id: str, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.orders, order_id, "order")
        crm.cancel_order(order_id, now=clock())

    @app.post("/orders/{order_id}/refunds", tags=["support"])
    async def request_refund(order_id: str, body: RefundIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.REQUEST_REFUNDS)
        found(store.orders, order_id, "order")
        return crm.customers.request_refund(order_id, body.amount, clock())

    # --- Support -------------------------------------------------------------------

    @app.post("/tickets", status_code=201, tags=["support"])
    async def submit_ticket(body: TicketIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.SUBMIT_TICKETS)
        absent(store.tickets, body.ticket_id, "ticket")
        found(store.customers, body.customer_id, "customer")
        if body.order_id:
            order = found(store.orders, body.order_id, "order")
            if order.customer_id != body.customer_id:
                raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, "order does not belong to this customer")
        now = clock()
        ticket = crm.submit_ticket(
            SupportTicket(body.ticket_id, body.customer_id, body.subject, body.body, now, body.order_id), now)
        # Ingesting systems get the routing outcome only; agents read details from the queue.
        return to_jsonable({"ticket_id": ticket.ticket_id, "priority": ticket.priority,
                            "assigned_team": ticket.assigned_team})

    @app.get("/tickets", tags=["support"])
    async def support_queue(role: RoleDep) -> list[dict[str, Any]]:
        return crm.support_queue(role)

    # --- Vendors ---------------------------------------------------------------------

    @app.post("/vendors", status_code=201, tags=["vendors"])
    async def onboard_vendor(body: VendorIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        absent(store.vendors, body.vendor_id, "vendor")
        crm.vendors.onboard(Vendor(body.vendor_id, body.store_name, body.contact_email, body.commission_rate))
        return crm.vendor_scorecard(role, body.vendor_id)

    @app.get("/vendors/payouts", tags=["finance"])
    async def vendor_payouts(role: RoleDep) -> list[dict[str, Any]]:
        return crm.vendor_payout_report(role, clock())

    @app.get("/vendors/{vendor_id}", tags=["vendors"])
    async def vendor_scorecard(vendor_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_VENDOR_PROFILE)
        found(store.vendors, vendor_id, "vendor")
        return crm.vendor_scorecard(role, vendor_id)

    @app.put("/vendors/{vendor_id}/documents/{doc_type}", status_code=204, tags=["vendors"])
    async def submit_document(vendor_id: str, doc_type: str, role: RoleDep) -> None:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        found(store.vendors, vendor_id, "vendor")
        crm.vendors.submit_document(vendor_id, doc_type)

    @app.post("/vendors/{vendor_id}/documents/{doc_type}/review", status_code=204, tags=["vendors"])
    async def review_document(vendor_id: str, doc_type: str, body: DocumentReviewIn, role: RoleDep) -> None:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        found(store.vendors, vendor_id, "vendor")
        crm.vendors.review_document(vendor_id, doc_type, body.approved)

    @app.put("/vendors/{vendor_id}/milestones/{milestone}", status_code=204, tags=["vendors"])
    async def complete_milestone(vendor_id: str, milestone: str, role: RoleDep) -> None:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        found(store.vendors, vendor_id, "vendor")
        crm.vendors.complete_milestone(vendor_id, milestone, clock())

    @app.post("/vendors/{vendor_id}/reviews", status_code=204, tags=["vendors"])
    async def add_review(vendor_id: str, body: ReviewIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(store.vendors, vendor_id, "vendor")
        crm.vendors.add_review(vendor_id, body.score, clock())

    @app.get("/vendors/{vendor_id}/ledger", tags=["finance"])
    async def vendor_ledger(vendor_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_VENDOR_LEDGER)
        found(store.vendors, vendor_id, "vendor")
        return to_jsonable(crm.vendors.ledger_summary(vendor_id, clock()))

    @app.post("/vendors/{vendor_id}/payouts", tags=["finance"])
    async def run_payout(vendor_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.RUN_PAYOUTS)
        found(store.vendors, vendor_id, "vendor")
        return to_jsonable(crm.vendors.run_payout(vendor_id, clock()))

    # --- Affiliates ----------------------------------------------------------------------

    def affiliate_tier(affiliate_id: str) -> str:
        now = clock()
        return crm.affiliates.commission_statement(affiliate_id, datetime.min, datetime.max, now)["tier"]

    @app.post("/affiliates", status_code=201, tags=["affiliates"])
    async def register_affiliate(body: AffiliateIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_AFFILIATES)
        absent(store.affiliates, body.affiliate_id, "affiliate")
        crm.affiliates.register(Affiliate(body.affiliate_id, body.name, body.email, body.referral_code))
        return {"affiliate_id": body.affiliate_id, "approved": False}

    @app.get("/affiliates/payouts", tags=["finance"])
    async def affiliate_payouts(role: RoleDep, start: datetime | None = None,
                                end: datetime | None = None) -> list[dict[str, Any]]:
        return crm.affiliate_payout_report(role, *period(start, end), clock())

    @app.get("/affiliates/{affiliate_id}", tags=["affiliates"])
    async def get_affiliate(affiliate_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_AFFILIATES)
        a = found(store.affiliates, affiliate_id, "affiliate")
        record = {"affiliate_id": a.affiliate_id, "name": a.name, "email": a.email,
                  "referral_code": a.referral_code, "approved": a.approved,
                  "tier": affiliate_tier(affiliate_id), "assets": list(a.assets)}
        return record if has_permission(role, Permission.MANAGE_AFFILIATES) else mask_pii(record)

    @app.post("/affiliates/{affiliate_id}/approve", status_code=204, tags=["affiliates"])
    async def approve_affiliate(affiliate_id: str, role: RoleDep) -> None:
        require(role, Permission.MANAGE_AFFILIATES)
        found(store.affiliates, affiliate_id, "affiliate")
        crm.affiliates.approve(affiliate_id)

    @app.get("/affiliates/{affiliate_id}/commission", tags=["finance"])
    async def affiliate_commission(affiliate_id: str, role: RoleDep, start: datetime | None = None,
                                   end: datetime | None = None) -> dict[str, Any]:
        require(role, Permission.VIEW_AFFILIATE_PAYOUTS)
        found(store.affiliates, affiliate_id, "affiliate")
        return to_jsonable(crm.affiliates.commission_statement(affiliate_id, *period(start, end), clock()))

    @app.post("/referrals/clicks", tags=["affiliates"])
    async def track_click(body: ClickIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        return crm.affiliates.track_click(body.referral_code, body.product_id, body.visitor_fingerprint, clock())

    @app.post("/affiliate-assets", status_code=201, tags=["affiliates"])
    async def add_asset(body: AssetIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_AFFILIATES)
        absent(store.assets, body.asset_id, "asset")
        crm.affiliates.add_asset(MarketingAsset(body.asset_id, body.kind, body.title, body.payload,
                                                body.restricted_to_tier))
        return {"asset_id": body.asset_id}

    @app.post("/affiliates/{affiliate_id}/assets/{asset_id}", tags=["affiliates"])
    async def distribute_asset(affiliate_id: str, asset_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.DISTRIBUTE_ASSETS)
        found(store.affiliates, affiliate_id, "affiliate")
        found(store.assets, asset_id, "asset")
        # Tier is derived from completed orders, never supplied by the caller.
        result = crm.affiliates.distribute_asset(affiliate_id, asset_id, affiliate_tier(affiliate_id))
        if not result["distributed"]:
            raise HTTPException(status.HTTP_409_CONFLICT, result["reason"])
        return result

    # --- Internal tasks & analytics --------------------------------------------------------

    @app.get("/tasks", tags=["internal"])
    async def task_queue(team: str, role: RoleDep) -> list[dict[str, Any]]:
        return crm.task_queue(role, team)

    @app.post("/tasks/{task_id}/resolve", tags=["internal"])
    async def resolve_task(task_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.RESOLVE_TASKS)
        task = found(crm.escalations.tasks, task_id, "task")
        require_team(role, task.owner_team)
        return to_jsonable(crm.escalations.resolve(task_id))

    @app.get("/analytics", tags=["internal"])
    async def analytics(role: RoleDep) -> dict[str, Any]:
        return crm.global_analytics(role, clock())

    return app


def _default_app() -> FastAPI:
    engine = None
    if os.environ.get("SABAR_CRM_SEED_DEMO") == "1":
        from .__main__ import seed
        engine = seed(utc_now())
    return create_app(engine)


app = _default_app()
