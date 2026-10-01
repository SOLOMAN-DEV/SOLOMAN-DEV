"""FastAPI REST layer over CRMEngine.

    SABAR_CRM_API_KEYS="sk-admin-123:admin,sk-fin-456:finance" uvicorn sabar_mart_crm.api:app

Every request (except /health) must send ``Authorization: Bearer <key>``. The key
decides the caller's role, and the role decides what the caller may see or do.
Callers never choose their own role.

Storage comes from ``DATABASE_URL`` (see ``sabar_mart_crm.db``); without it data is
kept in memory. Each data endpoint runs inside one backend session (``@tx``), which
holds the storage lock and commits before the response is returned, so a client
never receives a success for a write that was not saved.
"""

import functools
import hashlib
import logging
import re
import time
import traceback
import uuid
import hmac
import json
from dataclasses import dataclass
import inspect
import os
from collections.abc import Callable, Mapping
from datetime import datetime, timezone
from decimal import Decimal
from typing import Annotated, Any

from fastapi import Depends, FastAPI, HTTPException, Query, Request, status
from fastapi.responses import JSONResponse, Response
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel, Field, ValidationError

from . import config
from .engine import CRMEngine
from .models import (
    AuditEntry,
    Affiliate,
    Customer,
    IdempotencyRecord,
    MarketingAsset,
    Order,
    SupportTicket,
    Vendor,
    to_jsonable,
)
from . import users
from .db import MemoryBackend, SQLBackend, StorageBusy, backend_from_env
from .monitoring import Alerter, configure_logging
from .rbac import AccessDenied, Permission, Role, has_permission, mask_pii, require, require_team
from .store import Product

Clock = Callable[[], datetime]

log = logging.getLogger("sabar_crm")
REQUEST_ID_RE = re.compile(r"^[A-Za-z0-9_.-]{8,64}$")
SLOW_REQUEST_SECONDS = 3.0

ID = Field(min_length=1, max_length=64, pattern=r"^[A-Za-z0-9_.-]+$")
EMAIL = Field(max_length=254, pattern=r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
MONEY = Field(gt=0, max_digits=14, decimal_places=2)


def utc_now() -> datetime:
    # The engine works in naive UTC datetimes.
    return datetime.now(timezone.utc).replace(tzinfo=None)


@dataclass(frozen=True)
class Actor:
    name: str
    role: Role


def parse_api_keys(raw: str) -> dict[str, Actor]:
    """Parse ``key:role[:name],...`` into a key -> Actor map.

    These environment keys are meant for bootstrap and service accounts (e.g. the storefront).
    People should get their own database keys (``python -m sabar_mart_crm.db user-add``).
    """
    keys: dict[str, Actor] = {}
    for n, entry in enumerate(filter(None, (p.strip() for p in raw.split(","))), start=1):
        parts = entry.split(":")
        if len(parts) not in (2, 3) or not parts[0]:
            raise ValueError(f"malformed API key entry #{n} (expected key:role or key:role:name)")
        role = Role(parts[1])
        keys[parts[0]] = Actor(parts[2] if len(parts) == 3 else f"env-{role.value}-{n}", role)
    return keys


def _as_actors(api_keys: Mapping[str, Role | Actor]) -> dict[str, Actor]:
    return {k: v if isinstance(v, Actor) else Actor(f"env-{Role(v).value}-{n}", Role(v))
            for n, (k, v) in enumerate(api_keys.items(), start=1)}


# --- Request bodies ----------------------------------------------------------

class CustomerIn(BaseModel):
    customer_id: str = ID
    name: str = Field(min_length=1, max_length=200)
    email: str = EMAIL
    phone: str = Field(min_length=4, max_length=20, pattern=r"^\+?[0-9 -]+$")
    marketing_consent: bool = Field(default=False, description="Customer opted in to marketing at signup")


class ConsentIn(BaseModel):
    marketing: bool
    source: str = Field(min_length=2, max_length=64, description="Where consent was given or withdrawn, e.g. account_settings")


class BrowseIn(BaseModel):
    category: str = Field(min_length=1, max_length=64)


class CartItemIn(BaseModel):
    price: Decimal = MONEY


class ProductUpsertIn(BaseModel):
    vendor_id: str = ID
    name: str = Field(min_length=1, max_length=200)
    category: str = Field(min_length=1, max_length=64)
    gst_rate: Decimal = Field(ge=0, le=config.MAX_GST_RATE, decimal_places=4,
                              description="GST rate included in the price, e.g. 0.18 for 18%")


class ProductIn(ProductUpsertIn):
    product_id: str = ID


class EventIn(BaseModel):
    id: str = Field(min_length=1, max_length=200, description="Unique per event; retries must reuse it")
    type: str = Field(min_length=1, max_length=64)
    data: dict[str, Any] = Field(default_factory=dict)


class EventBatchIn(BaseModel):
    events: list[EventIn] = Field(min_length=1, max_length=100)


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
    reason: str = Field(min_length=3, max_length=500)


class UserIn(BaseModel):
    username: str = Field(pattern=users.USERNAME_RE.pattern)
    role: Role


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


# --- Idempotency -------------------------------------------------------------

def request_fingerprint(request: Request, kwargs: Mapping[str, Any]) -> str:
    bodies = {k: v.model_dump(mode="json") for k, v in sorted(kwargs.items()) if isinstance(v, BaseModel)}
    payload = json.dumps([request.method, request.url.path, request.url.query, bodies], sort_keys=True, default=str)
    return hashlib.sha256(payload.encode()).hexdigest()


def run_once(crm: CRMEngine, actor: str, key: str, fingerprint: str,
             action: Callable[[], Any]) -> tuple[Any, bool]:
    """Run ``action`` at most once per (actor, key). Returns (result, replayed).

    The record is written in the caller's transaction, so it exists if and only if the
    action's changes were committed.
    """
    key_id = hashlib.sha256(f"{actor}\0{key}".encode()).hexdigest()
    existing = crm.store.idempotency.get(key_id)
    if existing is not None:
        if existing.fingerprint != fingerprint:
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY,
                                "Idempotency-Key was already used for a different request")
        return json.loads(existing.response), True
    result = to_jsonable(action())
    crm.store.idempotency[key_id] = IdempotencyRecord(key_id, actor, fingerprint,
                                                      json.dumps(result, default=str), utc_now())
    return result, False


# --- App factory --------------------------------------------------------------

def create_app(
    engine: CRMEngine | None = None,
    api_keys: Mapping[str, Role | Actor] | None = None,
    clock: Clock = utc_now,
    backend: MemoryBackend | SQLBackend | None = None,
    alerter: Alerter | None = None,
) -> FastAPI:
    """Build the app. Storage: ``backend`` if given, else ``engine`` in memory, else from ``DATABASE_URL``."""
    if backend is None:
        backend = MemoryBackend(engine) if engine is not None else backend_from_env()
    keys = _as_actors(api_keys) if api_keys is not None else parse_api_keys(os.environ.get("SABAR_CRM_API_KEYS", ""))
    bearer = HTTPBearer(auto_error=False)

    docs = os.environ.get("SABAR_CRM_DOCS", "1") == "1"
    app = FastAPI(
        docs_url="/docs" if docs else None,
        redoc_url="/redoc" if docs else None,
        openapi_url="/openapi.json" if docs else None,
        title="Sabar Mart CRM API",
        version="1.4.0",
        description="REST access to the Sabar Mart CRM engine: customers, vendors, affiliates and internal tasks.",
    )
    app.state.backend = backend
    alerter = alerter if alerter is not None else Alerter.from_env()
    app.state.alerter = alerter

    @app.middleware("http")
    async def request_context(request: Request, call_next: Callable[..., Any]) -> Any:
        incoming = request.headers.get("X-Request-ID", "")
        request.state.request_id = incoming if REQUEST_ID_RE.match(incoming) else uuid.uuid4().hex[:16]
        started = time.perf_counter()
        response = await call_next(request)
        elapsed = time.perf_counter() - started
        if elapsed > SLOW_REQUEST_SECONDS:
            log.warning("slow request %s %s took %.1fs (request %s)", request.method, request.url.path, elapsed,
                        request.state.request_id)
        response.headers["X-Request-ID"] = request.state.request_id
        return response

    @app.exception_handler(Exception)
    async def _internal_error(request: Request, exc: Exception) -> JSONResponse:
        error_id = getattr(request.state, "request_id", None) or uuid.uuid4().hex[:16]
        route = getattr(request.scope.get("route"), "path", request.url.path)
        actor = getattr(request.state, "actor", None)
        trace = "".join(traceback.format_exception(type(exc), exc, exc.__traceback__))
        alerter.send(f"500:{type(exc).__name__}:{request.method}:{route}",
                     f"500 {type(exc).__name__} on {request.method} {route}",
                     f"error id: {error_id}\npath: {request.url.path}\n"
                     f"caller: {actor.name if actor else 'anonymous'}\n\n{trace}")
        return JSONResponse(status_code=500, content={"detail": "internal error", "error_id": error_id},
                            headers={"X-Request-ID": error_id})

    def audit(crm: CRMEngine, request: Request, outcome: str) -> None:
        actor: Actor | None = getattr(request.state, "actor", None)
        entry = AuditEntry(f"AUD-{crm.store.next_id('audit'):010d}", clock(),
                           actor.name if actor else "anonymous", actor.role.value if actor else "-",
                           request.method, request.url.path, outcome)
        crm.store.audit[entry.audit_id] = entry

    def tx(fn: Callable[..., Any] | None = None, *, audit_reads: bool = False) -> Any:
        """Run the endpoint inside one backend session and audit it.

        FastAPI sees the endpoint's signature without ``crm``. Changes (and reads marked
        ``audit_reads``) are logged in the same transaction; refused or failed requests are
        logged in a separate one, since their own transaction is rolled back.
        """
        if fn is None:
            return functools.partial(tx, audit_reads=audit_reads)
        sig = inspect.signature(fn)

        @functools.wraps(fn)
        def wrapper(*args: Any, _audit_request: Request, _idem_response: Response, **kwargs: Any) -> Any:
            logged = audit_reads or _audit_request.method != "GET"
            idem_key = _audit_request.headers.get("Idempotency-Key")
            if idem_key is not None and _audit_request.method == "GET":
                idem_key = None  # reads are naturally safe to retry
            if idem_key is not None and not 1 <= len(idem_key) <= 255:
                raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, "Idempotency-Key must be 1-255 characters")
            try:
                with backend.session() as crm:
                    if idem_key is None:
                        result = fn(crm, *args, **kwargs)
                    else:
                        actor: Actor = _audit_request.state.actor
                        fingerprint = request_fingerprint(_audit_request, kwargs)
                        result, replayed = run_once(crm, actor.name, idem_key, fingerprint,
                                                    lambda: fn(crm, *args, **kwargs))
                        if replayed:
                            _idem_response.headers["Idempotent-Replayed"] = "true"
                            if logged:
                                audit(crm, _audit_request, "replayed")
                            return result
                    if logged:
                        audit(crm, _audit_request, "success")
                    return result
            except (AccessDenied, HTTPException, ValueError) as exc:
                code = 403 if isinstance(exc, AccessDenied) else exc.status_code if isinstance(exc, HTTPException) else 409
                if logged or code == 403:
                    with backend.session() as crm:
                        audit(crm, _audit_request, {403: "denied", 404: "not_found", 409: "conflict",
                                                    422: "invalid"}.get(code, "error"))
                raise

        params = [p for n, p in sig.parameters.items() if n != "crm"]
        params.append(inspect.Parameter("_audit_request", inspect.Parameter.KEYWORD_ONLY, annotation=Request))
        params.append(inspect.Parameter("_idem_response", inspect.Parameter.KEYWORD_ONLY, annotation=Response))
        wrapper.__signature__ = sig.replace(parameters=params)
        return wrapper

    @app.exception_handler(AccessDenied)
    async def _forbidden(_: Request, exc: AccessDenied) -> JSONResponse:
        return JSONResponse(status_code=403, content={"detail": str(exc)})

    @app.exception_handler(StorageBusy)
    async def _busy(request: Request, exc: StorageBusy) -> JSONResponse:
        alerter.send("storage-busy", "CRM database lock timeouts",
                     f"{request.method} {request.url.path} waited too long for the database lock; "
                     "a request may be stuck or the server overloaded.")
        return JSONResponse(status_code=503, content={"detail": str(exc)}, headers={"Retry-After": "5"})

    @app.exception_handler(ValueError)
    async def _conflict(_: Request, exc: ValueError) -> JSONResponse:
        # The engine raises ValueError for business-rule violations (bad state transition, closed window...).
        return JSONResponse(status_code=409, content={"detail": str(exc)})

    def current_role(request: Request, creds: HTTPAuthorizationCredentials | None = Depends(bearer)) -> Role:
        actor = None
        if creds is not None:
            for key, env_actor in keys.items():
                if hmac.compare_digest(creds.credentials.encode(), key.encode()):
                    actor = env_actor
            if actor is None:
                user = backend.lookup_user(creds.credentials)
                if user is not None:
                    actor = Actor(user.username, Role(user.role))
        if actor is None:
            raise HTTPException(status.HTTP_401_UNAUTHORIZED, "invalid or missing API key",
                                headers={"WWW-Authenticate": "Bearer"})
        request.state.actor = actor
        return actor.role

    RoleDep = Annotated[Role, Depends(current_role)]

    def current_actor(request: Request, role: RoleDep) -> Actor:
        return request.state.actor

    ActorDep = Annotated[Actor, Depends(current_actor)]

    def found(mapping: Mapping[str, Any], key: str, kind: str) -> Any:
        if key not in mapping:
            raise HTTPException(status.HTTP_404_NOT_FOUND, f"{kind} '{key}' not found")
        return mapping[key]

    def active_customer(crm: CRMEngine, customer_id: str) -> Any:
        """The customer, unless unknown (404) or erased under DPDP (409: no new activity is accepted)."""
        customer = found(crm.store.customers, customer_id, "customer")
        if customer.erased_at is not None:
            raise HTTPException(status.HTTP_409_CONFLICT, f"customer '{customer_id}' has been erased")
        return customer

    def absent(mapping: Mapping[str, Any], key: str, kind: str) -> None:
        if key in mapping:
            raise HTTPException(status.HTTP_409_CONFLICT, f"{kind} '{key}' already exists")

    def period(start: datetime | None, end: datetime | None) -> tuple[datetime, datetime]:
        def naive(d: datetime) -> datetime:
            return d.astimezone(timezone.utc).replace(tzinfo=None) if d.tzinfo else d
        return naive(start) if start else datetime.min, naive(end) if end else datetime.max

    # --- Meta -----------------------------------------------------------------

    @app.get("/health", tags=["meta"])
    def health(deep: bool = False) -> Any:
        """Liveness; with ``?deep=true`` also checks the database (for uptime monitors)."""
        if not deep:
            return {"status": "ok"}
        try:
            return {"status": "ok", "version": app.version, **backend.ping()}
        except Exception as exc:
            alerter.send("health-db", "CRM health check failed: database unreachable", f"{type(exc).__name__}: {exc}")
            return JSONResponse(status_code=503, content={"status": "error", "database": "unreachable"})

    @app.get("/me", tags=["meta"])
    async def me(actor: ActorDep) -> dict[str, Any]:
        return {"user": actor.name, "role": actor.role.value,
                "permissions": sorted(p.value for p in Permission if has_permission(actor.role, p))}

    # --- Customers --------------------------------------------------------------

    @app.post("/customers", status_code=201, tags=["customers"])
    @tx
    def register_customer(crm: CRMEngine, body: CustomerIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        absent(crm.store.customers, body.customer_id, "customer")
        now = clock()
        crm.customers.register(Customer(body.customer_id, body.name, body.email, body.phone, now))
        if body.marketing_consent:
            crm.privacy.set_marketing_consent(body.customer_id, True, "registration", now)
        return {"customer_id": body.customer_id}

    @app.get("/customers/abandoned-carts", tags=["customers"])
    @tx
    def abandoned_carts(crm: CRMEngine, role: RoleDep) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_CUSTOMER_PROFILE)
        return crm.customers.abandoned_carts(clock())

    @app.get("/customers/{customer_id}", tags=["customers"])
    @tx(audit_reads=True)
    def customer_profile(crm: CRMEngine, customer_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_CUSTOMER_PROFILE)
        found(crm.store.customers, customer_id, "customer")
        return crm.customer_profile(role, customer_id, clock())

    @app.get("/customers/{customer_id}/recommendations", tags=["customers"])
    @tx
    def recommendations(crm: CRMEngine, customer_id: str, role: RoleDep, limit: int = 5) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_RECOMMENDATIONS)
        found(crm.store.customers, customer_id, "customer")
        return crm.customers.recommendations(customer_id, max(1, min(limit, 50)))

    @app.post("/customers/{customer_id}/browse", status_code=204, tags=["customers"])
    @tx
    def record_browse(crm: CRMEngine, customer_id: str, body: BrowseIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        active_customer(crm, customer_id)
        crm.customers.record_browse(customer_id, body.category)

    @app.put("/customers/{customer_id}/cart/{product_id}", status_code=204, tags=["customers"])
    @tx
    def add_to_cart(crm: CRMEngine, customer_id: str, product_id: str, body: CartItemIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        active_customer(crm, customer_id)
        found(crm.store.products, product_id, "product")
        crm.customers.update_cart(customer_id, product_id, body.price, clock())

    @app.delete("/customers/{customer_id}/cart/{product_id}", status_code=204, tags=["customers"])
    @tx
    def remove_from_cart(crm: CRMEngine, customer_id: str, product_id: str, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        active_customer(crm, customer_id)
        crm.customers.update_cart(customer_id, product_id, None, clock())

    # --- Products & orders --------------------------------------------------------

    @app.post("/products", status_code=201, tags=["orders"])
    @tx
    def add_product(crm: CRMEngine, body: ProductIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        absent(crm.store.products, body.product_id, "product")
        found(crm.store.vendors, body.vendor_id, "vendor")
        crm.store.products[body.product_id] = Product(body.product_id, body.vendor_id, body.name, body.category,
                                                     gst_rate=body.gst_rate)
        return {"product_id": body.product_id}

    @app.put("/products/{product_id}", tags=["orders"])
    @tx
    def upsert_product(crm: CRMEngine, product_id: str, body: ProductUpsertIn, role: RoleDep) -> dict[str, Any]:
        """Create the product, or update its name, category and GST rate (past orders keep their rate)."""
        require(role, Permission.INGEST_EVENTS)
        found(crm.store.vendors, body.vendor_id, "vendor")
        existing = crm.store.products.get(product_id)
        if existing is None:
            crm.store.products[product_id] = Product(product_id, body.vendor_id, body.name, body.category,
                                                     gst_rate=body.gst_rate)
            return {"product_id": product_id, "created": True}
        if existing.vendor_id != body.vendor_id:
            raise HTTPException(status.HTTP_409_CONFLICT, f"product '{product_id}' belongs to another vendor")
        existing.name, existing.category, existing.gst_rate = body.name, body.category, body.gst_rate
        return {"product_id": product_id, "created": False}

    @app.post("/orders", status_code=201, tags=["orders"])
    @tx
    def place_order(crm: CRMEngine, body: OrderIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        absent(crm.store.orders, body.order_id, "order")
        active_customer(crm, body.customer_id)
        product = found(crm.store.products, body.product_id, "product")
        # Vendor and category come from the catalog, never from the client.
        order = Order(body.order_id, body.customer_id, product.vendor_id, product.product_id, product.category,
                      body.amount, clock(), referral_code=body.referral_code)
        return to_jsonable(crm.place_order(order))

    @app.post("/orders/{order_id}/ship", status_code=204, tags=["orders"])
    @tx
    def ship_order(crm: CRMEngine, order_id: str, body: ShipIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(crm.store.orders, order_id, "order")
        crm.ship_order(order_id, late=body.late, now=clock())

    @app.post("/orders/{order_id}/deliver", tags=["orders"])
    @tx
    def deliver_order(crm: CRMEngine, order_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        found(crm.store.orders, order_id, "order")
        return to_jsonable(crm.deliver_order(order_id, now=clock()))

    @app.post("/orders/{order_id}/return", status_code=204, tags=["orders"])
    @tx
    def return_order(crm: CRMEngine, order_id: str, actor: ActorDep) -> None:
        require(actor.role, Permission.INGEST_EVENTS)
        found(crm.store.orders, order_id, "order")
        crm.return_order(order_id, now=clock(), actor=actor.name)

    @app.post("/orders/{order_id}/cancel", status_code=204, tags=["orders"])
    @tx
    def cancel_order(crm: CRMEngine, order_id: str, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(crm.store.orders, order_id, "order")
        crm.cancel_order(order_id, now=clock())

    @app.post("/orders/{order_id}/refunds", status_code=201, tags=["refunds"])
    @tx
    def request_refund(crm: CRMEngine, order_id: str, body: RefundIn, actor: ActorDep) -> dict[str, Any]:
        require(actor.role, Permission.REQUEST_REFUNDS)
        found(crm.store.orders, order_id, "order")
        return to_jsonable(crm.request_refund(order_id, body.amount, body.reason, actor.name, clock()))

    @app.get("/refunds", tags=["refunds"])
    @tx
    def list_refunds(crm: CRMEngine, role: RoleDep, status_filter: str | None = Query(None, alias="status"),
                     order_id: str | None = None) -> list[dict[str, Any]]:
        if not has_permission(role, Permission.REQUEST_REFUNDS):
            require(role, Permission.APPROVE_REFUNDS)  # support agents (requesters) and finance (approvers)
        refunds = crm.store.find("refunds", order_id=order_id) if order_id else crm.store.refunds.values()
        return to_jsonable(sorted((r for r in refunds if status_filter in (None, r.status.value)),
                                  key=lambda r: r.refund_id))

    @app.post("/refunds/{refund_id}/approve", tags=["refunds"])
    @tx
    def approve_refund(crm: CRMEngine, refund_id: str, actor: ActorDep) -> dict[str, Any]:
        require(actor.role, Permission.APPROVE_REFUNDS)
        found(crm.store.refunds, refund_id, "refund")
        return to_jsonable(crm.decide_refund(refund_id, True, actor.name, clock()))

    @app.post("/refunds/{refund_id}/reject", tags=["refunds"])
    @tx
    def reject_refund(crm: CRMEngine, refund_id: str, actor: ActorDep) -> dict[str, Any]:
        require(actor.role, Permission.APPROVE_REFUNDS)
        found(crm.store.refunds, refund_id, "refund")
        return to_jsonable(crm.decide_refund(refund_id, False, actor.name, clock()))

    # --- Support -------------------------------------------------------------------

    @app.post("/tickets", status_code=201, tags=["support"])
    @tx
    def submit_ticket(crm: CRMEngine, body: TicketIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.SUBMIT_TICKETS)
        absent(crm.store.tickets, body.ticket_id, "ticket")
        active_customer(crm, body.customer_id)
        if body.order_id:
            order = found(crm.store.orders, body.order_id, "order")
            if order.customer_id != body.customer_id:
                raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, "order does not belong to this customer")
        now = clock()
        ticket = crm.submit_ticket(
            SupportTicket(body.ticket_id, body.customer_id, body.subject, body.body, now, body.order_id), now)
        # Ingesting systems get the routing outcome only; agents read details from the queue.
        return to_jsonable({"ticket_id": ticket.ticket_id, "priority": ticket.priority,
                            "assigned_team": ticket.assigned_team})

    @app.get("/tickets", tags=["support"])
    @tx
    def support_queue(crm: CRMEngine, role: RoleDep) -> list[dict[str, Any]]:
        return crm.support_queue(role)

    # --- Vendors ---------------------------------------------------------------------

    @app.post("/vendors", status_code=201, tags=["vendors"])
    @tx
    def onboard_vendor(crm: CRMEngine, body: VendorIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        absent(crm.store.vendors, body.vendor_id, "vendor")
        crm.vendors.onboard(Vendor(body.vendor_id, body.store_name, body.contact_email, body.commission_rate))
        return crm.vendor_scorecard(role, body.vendor_id)

    @app.get("/vendors/payouts", tags=["finance"])
    @tx(audit_reads=True)
    def vendor_payouts(crm: CRMEngine, role: RoleDep) -> list[dict[str, Any]]:
        return crm.vendor_payout_report(role, clock())

    @app.get("/vendors/{vendor_id}", tags=["vendors"])
    @tx
    def vendor_scorecard(crm: CRMEngine, vendor_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_VENDOR_PROFILE)
        found(crm.store.vendors, vendor_id, "vendor")
        return crm.vendor_scorecard(role, vendor_id)

    @app.put("/vendors/{vendor_id}/documents/{doc_type}", status_code=204, tags=["vendors"])
    @tx
    def submit_document(crm: CRMEngine, vendor_id: str, doc_type: str, role: RoleDep) -> None:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        found(crm.store.vendors, vendor_id, "vendor")
        crm.vendors.submit_document(vendor_id, doc_type)

    @app.post("/vendors/{vendor_id}/documents/{doc_type}/review", status_code=204, tags=["vendors"])
    @tx
    def review_document(crm: CRMEngine, vendor_id: str, doc_type: str, body: DocumentReviewIn, role: RoleDep) -> None:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        found(crm.store.vendors, vendor_id, "vendor")
        crm.vendors.review_document(vendor_id, doc_type, body.approved)

    @app.put("/vendors/{vendor_id}/milestones/{milestone}", status_code=204, tags=["vendors"])
    @tx
    def complete_milestone(crm: CRMEngine, vendor_id: str, milestone: str, role: RoleDep) -> None:
        require(role, Permission.MANAGE_VENDOR_ONBOARDING)
        found(crm.store.vendors, vendor_id, "vendor")
        crm.vendors.complete_milestone(vendor_id, milestone, clock())

    @app.post("/vendors/{vendor_id}/reviews", status_code=204, tags=["vendors"])
    @tx
    def add_review(crm: CRMEngine, vendor_id: str, body: ReviewIn, role: RoleDep) -> None:
        require(role, Permission.INGEST_EVENTS)
        found(crm.store.vendors, vendor_id, "vendor")
        crm.vendors.add_review(vendor_id, body.score, clock())

    @app.get("/vendors/{vendor_id}/ledger", tags=["finance"])
    @tx(audit_reads=True)
    def vendor_ledger(crm: CRMEngine, vendor_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_VENDOR_LEDGER)
        found(crm.store.vendors, vendor_id, "vendor")
        return to_jsonable(crm.vendors.ledger_summary(vendor_id, clock()))

    @app.post("/vendors/{vendor_id}/payouts", tags=["finance"])
    @tx
    def run_payout(crm: CRMEngine, vendor_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.RUN_PAYOUTS)
        found(crm.store.vendors, vendor_id, "vendor")
        return to_jsonable(crm.vendors.run_payout(vendor_id, clock()))

    # --- Affiliates ----------------------------------------------------------------------

    def affiliate_tier(crm: CRMEngine, affiliate_id: str) -> str:
        return crm.affiliates.lifetime_tier(affiliate_id, clock())[0]

    @app.post("/affiliates", status_code=201, tags=["affiliates"])
    @tx
    def register_affiliate(crm: CRMEngine, body: AffiliateIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_AFFILIATES)
        absent(crm.store.affiliates, body.affiliate_id, "affiliate")
        crm.affiliates.register(Affiliate(body.affiliate_id, body.name, body.email, body.referral_code))
        return {"affiliate_id": body.affiliate_id, "approved": False}

    @app.get("/affiliates/payouts", tags=["finance"])
    @tx(audit_reads=True)
    def affiliate_payouts(crm: CRMEngine, role: RoleDep, start: datetime | None = None,
                                end: datetime | None = None) -> list[dict[str, Any]]:
        return crm.affiliate_payout_report(role, *period(start, end), clock())

    @app.get("/affiliates/{affiliate_id}", tags=["affiliates"])
    @tx
    def get_affiliate(crm: CRMEngine, affiliate_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_AFFILIATES)
        a = found(crm.store.affiliates, affiliate_id, "affiliate")
        record = {"affiliate_id": a.affiliate_id, "name": a.name, "email": a.email,
                  "referral_code": a.referral_code, "approved": a.approved,
                  "tier": affiliate_tier(crm, affiliate_id), "assets": list(a.assets)}
        return record if has_permission(role, Permission.MANAGE_AFFILIATES) else mask_pii(record)

    @app.post("/affiliates/{affiliate_id}/approve", status_code=204, tags=["affiliates"])
    @tx
    def approve_affiliate(crm: CRMEngine, affiliate_id: str, role: RoleDep) -> None:
        require(role, Permission.MANAGE_AFFILIATES)
        if found(crm.store.affiliates, affiliate_id, "affiliate").erased_at is not None:
            raise HTTPException(status.HTTP_409_CONFLICT, f"affiliate '{affiliate_id}' has been erased")
        crm.affiliates.approve(affiliate_id)

    @app.get("/affiliates/{affiliate_id}/commission", tags=["finance"])
    @tx(audit_reads=True)
    def affiliate_commission(crm: CRMEngine, affiliate_id: str, role: RoleDep, start: datetime | None = None,
                                   end: datetime | None = None) -> dict[str, Any]:
        require(role, Permission.VIEW_AFFILIATE_PAYOUTS)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        return to_jsonable(crm.affiliates.commission_statement(affiliate_id, *period(start, end), clock()))

    @app.post("/referrals/clicks", tags=["affiliates"])
    @tx
    def track_click(crm: CRMEngine, body: ClickIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.INGEST_EVENTS)
        return crm.affiliates.track_click(body.referral_code, body.product_id, body.visitor_fingerprint, clock())

    @app.post("/affiliate-assets", status_code=201, tags=["affiliates"])
    @tx
    def add_asset(crm: CRMEngine, body: AssetIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_AFFILIATES)
        absent(crm.store.assets, body.asset_id, "asset")
        crm.affiliates.add_asset(MarketingAsset(body.asset_id, body.kind, body.title, body.payload,
                                                body.restricted_to_tier))
        return {"asset_id": body.asset_id}

    @app.post("/affiliates/{affiliate_id}/assets/{asset_id}", tags=["affiliates"])
    @tx
    def distribute_asset(crm: CRMEngine, affiliate_id: str, asset_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.DISTRIBUTE_ASSETS)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        found(crm.store.assets, asset_id, "asset")
        # Tier is derived from completed orders, never supplied by the caller.
        result = crm.affiliates.distribute_asset(affiliate_id, asset_id, affiliate_tier(crm, affiliate_id))
        if not result["distributed"]:
            raise HTTPException(status.HTTP_409_CONFLICT, result["reason"])
        return result

    @app.get("/affiliates/{affiliate_id}/payout-preview", tags=["finance"])
    @tx
    def affiliate_payout_preview(crm: CRMEngine, affiliate_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.VIEW_AFFILIATE_PAYOUTS)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        return to_jsonable(crm.affiliates.payout_preview(affiliate_id, clock()))

    @app.post("/affiliates/{affiliate_id}/payouts", tags=["finance"])
    @tx
    def run_affiliate_payout(crm: CRMEngine, affiliate_id: str, actor: ActorDep) -> dict[str, Any]:
        require(actor.role, Permission.RUN_PAYOUTS)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        return to_jsonable(crm.affiliates.run_payout(affiliate_id, actor.name, clock()))

    @app.get("/affiliates/{affiliate_id}/payouts", tags=["finance"])
    @tx(audit_reads=True)
    def list_affiliate_payouts(crm: CRMEngine, affiliate_id: str, role: RoleDep) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_AFFILIATE_PAYOUTS)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        payouts = crm.store.find("affiliate_payouts", affiliate_id=affiliate_id)
        return to_jsonable(sorted(payouts, key=lambda p: p.payout_id))

    @app.get("/finance/tax-report", tags=["finance"])
    @tx(audit_reads=True)
    def tax_report(crm: CRMEngine, role: RoleDep, start: datetime | None = None,
                   end: datetime | None = None) -> dict[str, Any]:
        require(role, Permission.VIEW_VENDOR_LEDGER)
        return to_jsonable(crm.vendors.tax_report(*period(start, end)))

    # --- Users & audit -----------------------------------------------------------------------

    def user_view(u: Any) -> dict[str, Any]:
        return {"username": u.username, "role": u.role, "active": u.active, "created_at": u.created_at}

    @app.post("/users", status_code=201, tags=["admin"])
    @tx
    def create_user(crm: CRMEngine, body: UserIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_USERS)
        absent(crm.store.users, body.username, "user")
        key = users.create_user(crm.store, body.username, body.role, clock())
        return to_jsonable({**user_view(crm.store.users[body.username]), "api_key": key,
                            "note": "store this key now; it cannot be shown again"})

    @app.get("/users", tags=["admin"])
    @tx(audit_reads=True)
    def list_users(crm: CRMEngine, role: RoleDep) -> list[dict[str, Any]]:
        require(role, Permission.MANAGE_USERS)
        return to_jsonable([user_view(u) for u in sorted(crm.store.users.values(), key=lambda u: u.username)])

    @app.post("/users/{username}/deactivate", tags=["admin"])
    @tx
    def deactivate_user(crm: CRMEngine, username: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_USERS)
        found(crm.store.users, username, "user")
        users.deactivate(crm.store, username)
        return to_jsonable(user_view(crm.store.users[username]))

    @app.post("/users/{username}/rotate-key", tags=["admin"])
    @tx
    def rotate_user_key(crm: CRMEngine, username: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_USERS)
        found(crm.store.users, username, "user")
        key = users.rotate_key(crm.store, username)
        return to_jsonable({**user_view(crm.store.users[username]), "api_key": key})

    @app.get("/audit", tags=["admin"])
    @tx(audit_reads=True)
    def audit_log(crm: CRMEngine, role: RoleDep, actor: str | None = None, limit: int = 100) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_AUDIT)
        limit = max(1, min(limit, 1000))
        entries = (sorted(crm.store.find("audit", actor=actor), key=lambda e: e.audit_id, reverse=True)[:limit]
                   if actor else crm.store.recent("audit", limit))
        return to_jsonable(entries)

    # --- Privacy (DPDP Act 2023) ------------------------------------------------------------

    @app.post("/customers/{customer_id}/consent", tags=["privacy"])
    @tx
    def set_consent(crm: CRMEngine, customer_id: str, body: ConsentIn, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.MANAGE_CONSENT)
        active_customer(crm, customer_id)
        return to_jsonable(crm.privacy.set_marketing_consent(customer_id, body.marketing, body.source, clock()))

    @app.get("/customers/{customer_id}/export", tags=["privacy"])
    @tx(audit_reads=True)
    def export_customer(crm: CRMEngine, customer_id: str, role: RoleDep) -> dict[str, Any]:
        """Everything held about the customer (DPDP right of access)."""
        require(role, Permission.EXPORT_PERSONAL_DATA)
        found(crm.store.customers, customer_id, "customer")
        return crm.privacy.export_customer(customer_id, clock())

    @app.post("/customers/{customer_id}/erase", tags=["privacy"])
    @tx
    def erase_customer(crm: CRMEngine, customer_id: str, actor: ActorDep) -> dict[str, Any]:
        """Anonymise the customer (DPDP right to erasure); tax records are kept without personal data."""
        require(actor.role, Permission.ERASE_PERSONAL_DATA)
        found(crm.store.customers, customer_id, "customer")
        return to_jsonable(crm.privacy.erase_customer(customer_id, actor.name, clock()))

    @app.get("/affiliates/{affiliate_id}/export", tags=["privacy"])
    @tx(audit_reads=True)
    def export_affiliate(crm: CRMEngine, affiliate_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.EXPORT_PERSONAL_DATA)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        return crm.privacy.export_affiliate(affiliate_id, clock())

    @app.post("/affiliates/{affiliate_id}/erase", tags=["privacy"])
    @tx
    def erase_affiliate(crm: CRMEngine, affiliate_id: str, actor: ActorDep) -> dict[str, Any]:
        require(actor.role, Permission.ERASE_PERSONAL_DATA)
        found(crm.store.affiliates, affiliate_id, "affiliate")
        return to_jsonable(crm.privacy.erase_affiliate(affiliate_id, actor.name, clock()))

    # --- Batched storefront events ------------------------------------------------------------

    # event type -> (endpoint, fields of ``data`` that are path parameters, body model or None)
    event_types: dict[str, tuple[Callable[..., Any], tuple[str, ...], type[BaseModel] | None]] = {
        "customer.registered": (register_customer, (), CustomerIn),
        "customer.browsed": (record_browse, ("customer_id",), BrowseIn),
        "cart.item_added": (add_to_cart, ("customer_id", "product_id"), CartItemIn),
        "cart.item_removed": (remove_from_cart, ("customer_id", "product_id"), None),
        "product.upserted": (upsert_product, ("product_id",), ProductUpsertIn),
        "order.placed": (place_order, (), OrderIn),
        "order.shipped": (ship_order, ("order_id",), ShipIn),
        "order.delivered": (deliver_order, ("order_id",), None),
        "order.returned": (return_order, ("order_id",), None),
        "order.cancelled": (cancel_order, ("order_id",), None),
        "referral.clicked": (track_click, (), ClickIn),
        "vendor.reviewed": (add_review, ("vendor_id",), ReviewIn),
        "ticket.created": (submit_ticket, (), TicketIn),
        "customer.consent_updated": (set_consent, ("customer_id",), ConsentIn),
    }

    def event_call(event: EventIn, actor: Actor) -> Callable[[CRMEngine], Any]:
        """Validate an event and bind it to its endpoint; raises HTTPException(422) if invalid."""
        if event.type not in event_types:
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"unknown event type '{event.type}'")
        endpoint, path_fields, model = event_types[event.type]
        raw = endpoint.__wrapped__  # the endpoint without its own transaction
        kwargs: dict[str, Any] = {}
        for name in path_fields:
            value = event.data.get(name)
            if not isinstance(value, str) or not 1 <= len(value) <= 64:
                raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"data.{name} must be a 1-64 char string")
            kwargs[name] = value
        if model is not None:
            try:
                kwargs["body"] = model.model_validate({k: v for k, v in event.data.items() if k not in path_fields})
            except ValidationError as exc:
                raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY,
                                    json.loads(exc.json(include_url=False))) from None
        params = inspect.signature(raw).parameters
        if "role" in params:
            kwargs["role"] = actor.role
        if "actor" in params:
            kwargs["actor"] = actor
        return lambda crm: raw(crm, **kwargs)

    def audit_event(crm: CRMEngine, actor: Actor, event_type: str, outcome: str) -> None:
        entry = AuditEntry(f"AUD-{crm.store.next_id('audit'):010d}", clock(), actor.name, actor.role.value,
                           "EVENT", f"/events/{event_type}", outcome)
        crm.store.audit[entry.audit_id] = entry

    @app.post("/events", tags=["storefront"])
    def ingest_events(body: EventBatchIn, actor: ActorDep) -> dict[str, Any]:
        """Apply storefront events in order, each in its own transaction and at most once.

        Each event's ``id`` is its idempotency key, so re-sending a batch is safe: events
        already applied are replayed, not repeated. Per-event ``status``: ``ok``, ``replayed``,
        ``error`` (``retryable`` says whether re-sending can help) or ``not_processed``
        (skipped after a retryable error, to keep events in order).
        """
        require(actor.role, Permission.INGEST_EVENTS)
        results: list[dict[str, Any]] = []
        stop = False
        for event in body.events:
            if stop:
                results.append({"id": event.id, "status": "not_processed", "retryable": True})
                continue
            fingerprint = hashlib.sha256(json.dumps([event.type, event.data], sort_keys=True,
                                                    default=str).encode()).hexdigest()
            try:
                call = event_call(event, actor)
                with backend.session() as crm:
                    result, replayed = run_once(crm, actor.name, f"event:{event.id}", fingerprint,
                                                lambda: call(crm))
                    audit_event(crm, actor, event.type, "replayed" if replayed else "success")
                results.append({"id": event.id, "status": "replayed" if replayed else "ok", "result": result})
                continue
            except StorageBusy as exc:
                code, detail = 503, str(exc)
            except AccessDenied as exc:
                code, detail = 403, str(exc)
            except HTTPException as exc:
                code, detail = exc.status_code, exc.detail
            except ValueError as exc:
                code, detail = 409, str(exc)
            retryable = code >= 500
            if retryable:
                stop = True
            else:
                with backend.session() as crm:
                    audit_event(crm, actor, event.type, {403: "denied", 404: "not_found", 409: "conflict",
                                                         422: "invalid"}.get(code, "error"))
            results.append({"id": event.id, "status": "error", "http_status": code, "detail": detail,
                            "retryable": retryable})
        summary = {k: sum(r["status"] == k for r in results) for k in ("ok", "replayed", "error", "not_processed")}
        return {"results": results, "summary": summary}

    # --- Internal tasks & analytics --------------------------------------------------------

    @app.get("/tasks", tags=["internal"])
    @tx
    def task_queue(crm: CRMEngine, team: str, role: RoleDep) -> list[dict[str, Any]]:
        return crm.task_queue(role, team)

    @app.post("/tasks/{task_id}/resolve", tags=["internal"])
    @tx
    def resolve_task(crm: CRMEngine, task_id: str, role: RoleDep) -> dict[str, Any]:
        require(role, Permission.RESOLVE_TASKS)
        task = found(crm.escalations.tasks, task_id, "task")
        require_team(role, task.owner_team)
        return to_jsonable(crm.escalations.resolve(task_id))

    @app.get("/analytics", tags=["internal"])
    @tx
    def analytics(crm: CRMEngine, role: RoleDep) -> dict[str, Any]:
        return crm.global_analytics(role, clock())

    return app


def _default_app() -> FastAPI:
    configure_logging()
    backend = backend_from_env()
    if os.environ.get("SABAR_CRM_SEED_DEMO") == "1":
        from .__main__ import seed
        if isinstance(backend, MemoryBackend):
            seed(utc_now(), backend.engine)
        elif backend.is_empty():
            with backend.session() as crm:
                seed(utc_now(), crm)
    return create_app(backend=backend)


_app: FastAPI | None = None


def __getattr__(name: str) -> Any:
    """``app`` is built on first use (``uvicorn sabar_mart_crm.api:app``, passenger_wsgi.py), not on import."""
    global _app
    if name == "app":
        if _app is None:
            _app = _default_app()
        return _app
    raise AttributeError(name)
