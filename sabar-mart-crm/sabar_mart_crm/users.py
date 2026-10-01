"""Per-person API keys. Only a SHA-256 hash of each key is stored."""

from __future__ import annotations

import hashlib
import re
import secrets
from datetime import datetime

from .models import ApiUser
from .rbac import Role
from .store import Store

USERNAME_RE = re.compile(r"^[a-z0-9][a-z0-9_.-]{1,62}$")


def hash_key(api_key: str) -> str:
    return hashlib.sha256(api_key.encode()).hexdigest()


def new_key() -> str:
    return "smc_" + secrets.token_urlsafe(32)


def create_user(store: Store, username: str, role: Role, now: datetime) -> str:
    """Create a user and return their API key. The key cannot be recovered later, only rotated."""
    if not USERNAME_RE.match(username):
        raise ValueError("username must be 2-63 chars of a-z, 0-9, '_', '.', '-' and start with a letter or digit")
    if username in store.users:
        raise ValueError(f"user '{username}' already exists")
    key = new_key()
    store.users[username] = ApiUser(username, Role(role).value, hash_key(key), now)
    return key


def rotate_key(store: Store, username: str) -> str:
    user = store.users[username]
    key = new_key()
    user.key_hash = hash_key(key)
    user.active = True
    return key


def deactivate(store: Store, username: str) -> None:
    store.users[username].active = False


def find_by_key(store: Store, api_key: str) -> ApiUser | None:
    return next((u for u in store.find("users", key_hash=hash_key(api_key)) if u.active), None)
