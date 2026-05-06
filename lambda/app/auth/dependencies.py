"""FastAPI dependencies for WordPress authentication."""

import asyncio
from typing import Callable

from fastapi import Depends, HTTPException, Request, status

from app.auth.models import WPUser
from app.auth.wp_client import validate_credentials


async def get_current_user(request: Request) -> WPUser:
    """FastAPI dependency: extract and validate WordPress credentials.

    Expects an Authorization header (Basic auth with WP Application Password).
    Returns the authenticated WPUser or raises 401.
    """
    authorization = request.headers.get("Authorization")
    if not authorization:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing Authorization header",
            headers={"WWW-Authenticate": "Basic"},
        )

    # validate_credentials is synchronous (blocking HTTP call to WP).
    # Run it in a thread pool so it doesn't stall the event loop while
    # waiting on the network, which would block all other requests.
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
