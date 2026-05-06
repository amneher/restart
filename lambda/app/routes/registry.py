"""Registry CRUD routes — backed by WordPress custom post type via wp_python."""

from datetime import datetime
from typing import Optional

from fastapi import APIRouter, Depends, HTTPException, Query, Request, Response, status
from pydantic import BaseModel, Field

from app.auth import WPUser, get_current_user, get_wp_client
from app.auth.wp_client import _parse_basic_auth
from app.database import get_db
from app.models.item import Item, ItemPublic, ItemRegistryCreate, ItemResponse
from app.models.registry import (
    Registry,
    RegistryCreate,
    RegistryMeta,
    RegistryUpdate,
)
from app.routes.items import _item_response, row_to_item
from wp_python.exceptions import NotFoundError, PermissionError, WordPressError

router = APIRouter(prefix="/registries", tags=["registries"])

CPT_SLUG = "restart-registry"


def _client_for_user(request: Request):
    """Build a wp_python client using the request's Authorization header."""
    auth_header = request.headers.get("Authorization", "")
    creds = _parse_basic_auth(auth_header)
    if not creds:
        raise HTTPException(status_code=401, detail="Invalid credentials")
    return get_wp_client(*creds)


def _post_to_registry(post: dict) -> Registry:
    """Convert a WP REST API post response dict to a Registry model."""
    meta = RegistryMeta.from_wp_meta(post.get("meta", {}))
    status_val = post.get("status", "publish")

    created_at = None
    if post.get("date"):
        try:
            created_at = datetime.fromisoformat(post["date"])
        except (ValueError, TypeError):
            pass

    updated_at = None
    if post.get("modified"):
        try:
            updated_at = datetime.fromisoformat(post["modified"])
        except (ValueError, TypeError):
            pass

    raw_content = post.get("content", {})
    story = raw_content.get("raw") or raw_content.get("rendered") if isinstance(raw_content, dict) else raw_content
    story = story.strip() if story else None

    return Registry(
        id=post["id"],
        title=post.get("title", {}).get("rendered", "") if isinstance(post.get("title"), dict) else post.get("title", ""),
        username=str(post.get("author_name", post.get("author", ""))),
        is_private=status_val == "private",
        story=story or None,
        meta=meta,
        created_at=created_at,
        updated_at=updated_at,
    )


# ---------------------------------------------------------------------------
# CRUD endpoints
# ---------------------------------------------------------------------------


@router.get("", response_model=list[Registry])
async def list_registries(
    request: Request,
    response: Response,
    user: WPUser = Depends(get_current_user),
    page: int = Query(1, ge=1),
    per_page: int = Query(10, ge=1, le=100),
):
    """List registries owned by the authenticated user."""
    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            posts = cpt.list(page=page, per_page=per_page, author=user.id, status="any")
            response.headers["X-WP-Total"] = str(wp.transport.last_total_items)
            response.headers["X-WP-TotalPages"] = str(wp.transport.last_total_pages)
            return [_post_to_registry(p) for p in posts]
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")


@router.get("/{registry_id}", response_model=Registry)
async def get_registry(
    registry_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Get a single registry by ID. Must be owner or invitee."""
    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            post = cpt.get(registry_id)
    except NotFoundError:
        raise HTTPException(status_code=404, detail="Registry not found")
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")

    registry = _post_to_registry(post)

    # Access control: must be owner, admin, or invitee
    is_owner = post.get("author") == user.id
    is_invitee = user.username in registry.meta.invitees
    if not (is_owner or user.is_admin or is_invitee):
        raise HTTPException(status_code=403, detail="Not authorized to view this registry")

    return registry


@router.post("", response_model=Registry, status_code=status.HTTP_201_CREATED)
async def create_registry(
    data: RegistryCreate,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Create a new registry. The authenticated user becomes the owner."""
    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            post_data = {
                "title": data.title,
                "status": "private" if data.is_private else "publish",
                "author": user.id,
                "meta": data.meta.to_wp_meta(),
                **({"content": data.story} if data.story else {}),
            }
            post = cpt.create(post_data)
            return _post_to_registry(post)
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")


@router.patch("/{registry_id}", response_model=Registry)
async def update_registry(
    registry_id: int,
    data: RegistryUpdate,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Update a registry. Must be owner or admin."""
    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)

            # Verify ownership
            existing = cpt.get(registry_id)
            if existing.get("author") != user.id and not user.is_admin:
                raise HTTPException(status_code=403, detail="Not authorized to update this registry")

            update_payload: dict = {}
            if data.title is not None:
                update_payload["title"] = data.title
            if data.is_private is not None:
                update_payload["status"] = "private" if data.is_private else "publish"
            if data.story is not None:
                update_payload["content"] = data.story
            if data.meta is not None:
                update_payload["meta"] = data.meta.to_wp_meta()

            if not update_payload:
                return _post_to_registry(existing)

            post = cpt.update(registry_id, update_payload)
            return _post_to_registry(post)
    except NotFoundError:
        raise HTTPException(status_code=404, detail="Registry not found")
    except HTTPException:
        raise
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")


@router.delete("/{registry_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_registry(
    registry_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Delete a registry. Must be owner or admin."""
    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)

            existing = cpt.get(registry_id)
            if existing.get("author") != user.id and not user.is_admin:
                raise HTTPException(status_code=403, detail="Not authorized to delete this registry")

            cpt.delete(registry_id, force=True)
    except NotFoundError:
        raise HTTPException(status_code=404, detail="Registry not found")
    except HTTPException:
        raise
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")


# ---------------------------------------------------------------------------
# Registry Items — nested CRUD
# ---------------------------------------------------------------------------


def _verify_registry_access(request: Request, registry_id: int, user: WPUser) -> dict:
    """Fetch a registry from WP and verify the user has access. Returns the WP post."""
    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            post = cpt.get(registry_id)
    except NotFoundError:
        raise HTTPException(status_code=404, detail="Registry not found")
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")

    meta = RegistryMeta.from_wp_meta(post.get("meta", {}))
    is_owner = post.get("author") == user.id
    is_invitee = user.username in meta.invitees
    if not (is_owner or user.is_admin or is_invitee):
        raise HTTPException(status_code=403, detail="Not authorized to access this registry")

    return post


def _sync_item_ids_to_wp(request: Request, registry_id: int) -> None:
    """Sync the item IDs from SQLite to the WP post meta."""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT id FROM items WHERE registry_id = ? ORDER BY id",
            (registry_id,),
        )
        item_ids = [row["id"] for row in cursor.fetchall()]

    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            post = cpt.get(registry_id)
            meta = RegistryMeta.from_wp_meta(post.get("meta", {}))
            meta.item_ids = item_ids
            cpt.update(registry_id, {"meta": meta.to_wp_meta()})
    except WordPressError:
        pass  # Best-effort sync; item is already persisted in SQLite


@router.get("/{registry_id}/items", response_model=None)
async def list_registry_items(
    registry_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """List all active items belonging to a registry."""
    _verify_registry_access(request, registry_id, user)

    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT * FROM items WHERE registry_id = ? AND is_active = 1 ORDER BY id",
            (registry_id,),
        )
        items = [row_to_item(row) for row in cursor.fetchall()]

    if user.is_admin:
        return items
    return [ItemPublic.model_validate(i) for i in items]


@router.post(
    "/{registry_id}/items",
    response_model=ItemResponse,
    status_code=status.HTTP_201_CREATED,
)
async def add_item_to_registry(
    registry_id: int,
    item: ItemRegistryCreate,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Create an item and link it to a registry. Must be owner or admin."""
    post = _verify_registry_access(request, registry_id, user)

    # Only owner or admin can add items
    if post.get("author") != user.id and not user.is_admin:
        raise HTTPException(status_code=403, detail="Only the registry owner can add items")

    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            """
            INSERT INTO items (registry_id, name, description, url, retailer, affiliate_url, affiliate_status, image_url, price, quantity_needed, quantity_purchased, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                registry_id,
                item.name,
                item.description,
                item.url,
                item.retailer,
                item.affiliate_url,
                item.affiliate_status,
                item.image_url,
                item.price,
                item.quantity_needed,
                item.quantity_purchased,
                int(item.is_active),
            ),
        )
        item_id = cursor.lastrowid
        cursor.execute("SELECT * FROM items WHERE id = ?", (item_id,))
        row = cursor.fetchone()

    _sync_item_ids_to_wp(request, registry_id)

    return _item_response(row_to_item(row), user, "Item added to registry")


@router.delete("/{registry_id}/items/{item_id}", status_code=status.HTTP_204_NO_CONTENT)
async def remove_item_from_registry(
    registry_id: int,
    item_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Remove an item from a registry (deletes it). Must be owner or admin."""
    post = _verify_registry_access(request, registry_id, user)

    if post.get("author") != user.id and not user.is_admin:
        raise HTTPException(status_code=403, detail="Only the registry owner can remove items")

    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT id FROM items WHERE id = ? AND registry_id = ? AND is_active = 1",
            (item_id, registry_id),
        )
        if not cursor.fetchone():
            raise HTTPException(status_code=404, detail="Item not found in this registry")
        cursor.execute("UPDATE items SET is_active = 0 WHERE id = ?", (item_id,))

    _sync_item_ids_to_wp(request, registry_id)


# ---------------------------------------------------------------------------
# Invitees — manage who can view a registry
# ---------------------------------------------------------------------------


class InviteRequest(BaseModel):
    """Add one or more invitees (WP usernames or email addresses)."""
    invitees: list[str] = Field(..., min_length=1)


class InviteesResponse(BaseModel):
    invitees: list[str]


@router.get("/{registry_id}/invitees", response_model=InviteesResponse)
async def list_invitees(
    registry_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """List all invitees for a registry. Must be owner, admin, or invitee."""
    post = _verify_registry_access(request, registry_id, user)
    meta = RegistryMeta.from_wp_meta(post.get("meta", {}))
    return InviteesResponse(invitees=meta.invitees)


@router.post("/{registry_id}/invitees", response_model=InviteesResponse)
async def add_invitees(
    registry_id: int,
    data: InviteRequest,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Add invitees to a registry. Must be owner or admin."""
    post = _verify_registry_access(request, registry_id, user)

    if post.get("author") != user.id and not user.is_admin:
        raise HTTPException(status_code=403, detail="Only the registry owner can manage invitees")

    meta = RegistryMeta.from_wp_meta(post.get("meta", {}))
    # Add new invitees (deduplicate)
    existing = set(meta.invitees)
    for invitee in data.invitees:
        existing.add(invitee)
    meta.invitees = sorted(existing)

    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            cpt.update(registry_id, {"meta": meta.to_wp_meta()})
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")

    return InviteesResponse(invitees=meta.invitees)


@router.delete("/{registry_id}/invitees/{invitee}", response_model=InviteesResponse)
async def remove_invitee(
    registry_id: int,
    invitee: str,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Remove an invitee from a registry. Must be owner or admin."""
    post = _verify_registry_access(request, registry_id, user)

    if post.get("author") != user.id and not user.is_admin:
        raise HTTPException(status_code=403, detail="Only the registry owner can manage invitees")

    meta = RegistryMeta.from_wp_meta(post.get("meta", {}))
    if invitee not in meta.invitees:
        raise HTTPException(status_code=404, detail="Invitee not found")

    meta.invitees = [i for i in meta.invitees if i != invitee]

    try:
        with _client_for_user(request) as wp:
            cpt = wp.custom_post_type(CPT_SLUG)
            cpt.update(registry_id, {"meta": meta.to_wp_meta()})
    except PermissionError:
        raise HTTPException(status_code=403, detail="Not authorized")
    except WordPressError as e:
        raise HTTPException(status_code=502, detail=f"WordPress error: {e}")

    return InviteesResponse(invitees=meta.invitees)


# ---------------------------------------------------------------------------
# Affiliate Links — manage affiliate URLs on items
# ---------------------------------------------------------------------------


class AffiliateUpdate(BaseModel):
    """Set or update the affiliate link for an item."""
    affiliate_url: str = Field(..., min_length=10, max_length=500)
    affiliate_status: Optional[str] = Field("active", max_length=50)


class AffiliateResponse(BaseModel):
    item_id: int
    url: str
    affiliate_url: Optional[str]
    affiliate_status: Optional[str]
    retailer: Optional[str]


@router.get("/{registry_id}/items/{item_id}/affiliate", response_model=AffiliateResponse)
async def get_affiliate_link(
    registry_id: int,
    item_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Get the affiliate link for an item. Must have registry access."""
    _verify_registry_access(request, registry_id, user)

    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT id, url, affiliate_url, affiliate_status, retailer FROM items WHERE id = ? AND registry_id = ?",
            (item_id, registry_id),
        )
        row = cursor.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Item not found in this registry")

    return AffiliateResponse(
        item_id=row["id"],
        url=row["url"],
        affiliate_url=row["affiliate_url"],
        affiliate_status=row["affiliate_status"],
        retailer=row["retailer"],
    )


@router.put("/{registry_id}/items/{item_id}/affiliate", response_model=AffiliateResponse)
async def set_affiliate_link(
    registry_id: int,
    item_id: int,
    data: AffiliateUpdate,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Set or update the affiliate link for an item. Must be owner or admin."""
    post = _verify_registry_access(request, registry_id, user)

    if post.get("author") != user.id and not user.is_admin:
        raise HTTPException(status_code=403, detail="Only the registry owner can manage affiliate links")

    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT id FROM items WHERE id = ? AND registry_id = ?",
            (item_id, registry_id),
        )
        if not cursor.fetchone():
            raise HTTPException(status_code=404, detail="Item not found in this registry")

        cursor.execute(
            "UPDATE items SET affiliate_url = ?, affiliate_status = ? WHERE id = ?",
            (data.affiliate_url, data.affiliate_status, item_id),
        )
        cursor.execute(
            "SELECT id, url, affiliate_url, affiliate_status, retailer FROM items WHERE id = ?",
            (item_id,),
        )
        row = cursor.fetchone()

    return AffiliateResponse(
        item_id=row["id"],
        url=row["url"],
        affiliate_url=row["affiliate_url"],
        affiliate_status=row["affiliate_status"],
        retailer=row["retailer"],
    )


@router.delete("/{registry_id}/items/{item_id}/affiliate", status_code=status.HTTP_204_NO_CONTENT)
async def remove_affiliate_link(
    registry_id: int,
    item_id: int,
    request: Request,
    user: WPUser = Depends(get_current_user),
):
    """Remove the affiliate link from an item. Must be owner or admin."""
    post = _verify_registry_access(request, registry_id, user)

    if post.get("author") != user.id and not user.is_admin:
        raise HTTPException(status_code=403, detail="Only the registry owner can manage affiliate links")

    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT id FROM items WHERE id = ? AND registry_id = ?",
            (item_id, registry_id),
        )
        if not cursor.fetchone():
            raise HTTPException(status_code=404, detail="Item not found in this registry")

        cursor.execute(
            "UPDATE items SET affiliate_url = NULL, affiliate_status = NULL WHERE id = ?",
            (item_id,),
        )

