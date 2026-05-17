"""FastAPI dependencies for WordPress authentication."""

import asyncio
import os
from typing import Callable

from fastapi import Depends, HTTPException, Request, status

from app.auth.models import WPUser
from app.auth.wp_client import validate_credentials

_SERVICE_KEY = os.environ.get("WP_SERVICE_KEY", "")


def _get_service_user(request: Request) -> WPUser | None:
    """Return a WPUser from trusted service headers, or None if not a service call."""
    if not _SERVICE_KEY:
        return None
    if request.headers.get("X-Service-Key", "") != _SERVICE_KEY:
        return None
    try:
        user_id = int(request.headers.get("X-WP-User-ID", "0"))
    except ValueError:
        return None
    if not user_id:
        return None
    username = request.headers.get("X-WP-Username", "")
    roles = [r.strip() for r in request.headers.get("X-WP-Roles", "subscriber").split(",") if r.strip()]
    return WPUser(id=user_id, username=username, email="", display_name=username, roles=roles)


async def get_current_user(request: Request) -> WPUser:
    """FastAPI dependency: authenticate via service key (server-to-server) or
    WordPress Application Password (browser-to-Lambda).
    """
    service_user = _get_service_user(request)
    if service_user:
        return service_user

    authorization = request.headers.get("Authorization")
    if not authorization:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing Authorization header",
            headers={"WWW-Authenticate": "Basic"},
        )

    loop = asyncio.get_event_loop()
    user = await loop.run_in_executor(None, validate_credentials, authorization)
    if user is None:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid WordPress credentials",
            headers={"WWW-Authenticate": "Basic"},
        )

    return user


def require_roles(*roles: str) -> Callable:
    """Factory for a dependency that checks the user has at least one of the given roles.

    Usage:
        @router.get("/admin-only", dependencies=[Depends(require_roles("administrator"))])
    """

    async def check_roles(user: WPUser = Depends(get_current_user)) -> WPUser:
        if not any(user.has_role(r) for r in roles):
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Requires one of: {', '.join(roles)}",
            )
        return user

    return check_roles
