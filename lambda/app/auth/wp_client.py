"""WordPress credential validation via wp_python."""

import base64
import os
import time

from wp_python import ApplicationPasswordAuth, WordPressClient
from wp_python.exceptions import AuthenticationError, WordPressError

from app.auth.models import WPUser

WP_BASE_URL = os.environ.get("WP_BASE_URL", "http://localhost:8082")

# Simple in-memory cache: {credential_hash: (WPUser, expiry_timestamp)}
_cache: dict[str, tuple[WPUser, float]] = {}
CACHE_TTL = float(os.environ.get("WP_AUTH_CACHE_TTL", "300"))  # 5 minutes


def clear_cache() -> None:
    """Clear the auth cache (useful for testing)."""
    _cache.clear()


def _parse_basic_auth(authorization: str) -> tuple[str, str] | None:
    """Extract username and password from a Basic auth header."""
    if not authorization.startswith("Basic "):
        return None
    try:
        decoded = base64.b64decode(authorization[6:]).decode("utf-8")
        username, password = decoded.split(":", 1)
        return username, password
    except (ValueError, UnicodeDecodeError):
        return None


def get_wp_client(username: str, password: str) -> WordPressClient:
    """Create a WordPressClient authenticated with the given credentials."""
    auth = ApplicationPasswordAuth(username, password)
    return WordPressClient(WP_BASE_URL, auth=auth, timeout=10.0)


def validate_credentials(authorization: str) -> WPUser | None:
    """Validate an Authorization header against WordPress via wp_python.

    Parses the Basic auth header, creates a WordPressClient with those
    credentials, and calls users.me(context="edit") to verify them.
    Returns a WPUser on success, None on failure.
    """
    # Check cache first
    cached = _cache.get(authorization)
    if cached:
        user, expires = cached
        if time.time() < expires:
            return user
        del _cache[authorization]

    credentials = _parse_basic_auth(authorization)
    if credentials is None:
        return None

    username, password = credentials

    try:
        with get_wp_client(username, password) as client:
            wp_user = client.users.me(context="edit")
    except (AuthenticationError, WordPressError):
        return None
    except Exception:
        return None

    user = WPUser(
        id=wp_user.id,
        username=wp_user.slug or wp_user.username,
        email=wp_user.email,
        display_name=wp_user.name,
        roles=wp_user.roles,
        capabilities=wp_user.capabilities,
    )

    # Cache the result
    _cache[authorization] = (user, time.time() + CACHE_TTL)
    return user
