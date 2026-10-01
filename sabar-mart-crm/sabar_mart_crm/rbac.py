"""Role-based access control and PII masking."""

from __future__ import annotations

from enum import Enum
from typing import Any


class Role(str, Enum):
    SUPPORT_AGENT = "support_agent"
    FINANCE = "finance"
    VENDOR_MANAGER = "vendor_manager"
    AFFILIATE_MANAGER = "affiliate_manager"
    MARKETING = "marketing"
    ADMIN = "admin"


class Permission(str, Enum):
    VIEW_CUSTOMER_PROFILE = "customer:profile:view"
    VIEW_CUSTOMER_PII = "customer:pii:view"
    VIEW_TICKETS = "support:tickets:view"
    VIEW_VENDOR_PROFILE = "vendor:profile:view"
    MANAGE_VENDOR_ONBOARDING = "vendor:onboarding:manage"
    VIEW_VENDOR_LEDGER = "vendor:ledger:view"
    VIEW_AFFILIATES = "affiliate:profile:view"
    VIEW_AFFILIATE_PAYOUTS = "affiliate:payouts:view"
    DISTRIBUTE_ASSETS = "affiliate:assets:distribute"
    VIEW_RECOMMENDATIONS = "customer:recommendations:view"
    VIEW_GLOBAL_ANALYTICS = "analytics:global:view"
    VIEW_TASKS = "tasks:view"


ROLE_PERMISSIONS: dict[Role, frozenset[Permission]] = {
    Role.SUPPORT_AGENT: frozenset({
        Permission.VIEW_CUSTOMER_PROFILE,
        Permission.VIEW_CUSTOMER_PII,
        Permission.VIEW_TICKETS,
        Permission.VIEW_TASKS,
    }),
    Role.FINANCE: frozenset({
        Permission.VIEW_VENDOR_LEDGER,
        Permission.VIEW_AFFILIATE_PAYOUTS,
        Permission.VIEW_VENDOR_PROFILE,
        Permission.VIEW_TASKS,
    }),
    Role.VENDOR_MANAGER: frozenset({
        Permission.VIEW_VENDOR_PROFILE,
        Permission.MANAGE_VENDOR_ONBOARDING,
        Permission.VIEW_TASKS,
    }),
    Role.AFFILIATE_MANAGER: frozenset({
        Permission.VIEW_AFFILIATES,
        Permission.DISTRIBUTE_ASSETS,
        Permission.VIEW_TASKS,
    }),
    Role.MARKETING: frozenset({
        Permission.VIEW_CUSTOMER_PROFILE,  # PII masked: no VIEW_CUSTOMER_PII
        Permission.VIEW_RECOMMENDATIONS,
        Permission.VIEW_AFFILIATES,
    }),
    Role.ADMIN: frozenset(Permission),
}

# Which team's queue each role may read.
ROLE_TEAMS: dict[Role, frozenset[str]] = {
    Role.SUPPORT_AGENT: frozenset({"customer_support", "trust_and_safety"}),
    Role.FINANCE: frozenset({"finance"}),
    Role.VENDOR_MANAGER: frozenset({"vendor_success"}),
    Role.AFFILIATE_MANAGER: frozenset({"affiliate_ops"}),
    Role.MARKETING: frozenset(),
    Role.ADMIN: frozenset({"customer_support", "trust_and_safety", "finance", "vendor_success", "affiliate_ops"}),
}

PII_FIELDS = ("email", "phone", "contact_email")


class AccessDenied(PermissionError):
    pass


def has_permission(role: Role, permission: Permission) -> bool:
    return permission in ROLE_PERMISSIONS.get(role, frozenset())


def require(role: Role, permission: Permission) -> None:
    if not has_permission(role, permission):
        raise AccessDenied(f"role '{role.value}' lacks permission '{permission.value}'")


def require_team(role: Role, team: str) -> None:
    if team not in ROLE_TEAMS.get(role, frozenset()):
        raise AccessDenied(f"role '{role.value}' may not view the '{team}' task queue")


def mask_value(value: str) -> str:
    if "@" in value:
        local, _, domain = value.partition("@")
        return f"{local[:1]}***@{domain}"
    return "*" * max(len(value) - 2, 0) + value[-2:]


def mask_pii(record: dict[str, Any]) -> dict[str, Any]:
    return {k: (mask_value(v) if k in PII_FIELDS and isinstance(v, str) else v) for k, v in record.items()}
