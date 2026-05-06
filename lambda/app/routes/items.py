from datetime import datetime
from typing import List, Union

from fastapi import APIRouter, Depends, HTTPException, status

from app.auth import WPUser, get_current_user
from app.database import get_db
from app.models import Item, ItemCreate, ItemPublic, ItemPublicResponse, ItemResponse, ItemUpdate

router = APIRouter(prefix="/items", tags=["Items"])


def row_to_item(row) -> Item:
    return Item(
        id=row["id"],
        registry_id=row["registry_id"],
        name=row["name"],
        description=row["description"],
        url=row["url"],
        retailer=row["retailer"],
        affiliate_url=row["affiliate_url"],
        affiliate_status=row["affiliate_status"],
        image_url=row["image_url"],
        price=row["price"],
        quantity_needed=row["quantity_needed"],
        quantity_purchased=row["quantity_purchased"],
        is_active=bool(row["is_active"]),
        created_at=datetime.fromisoformat(row["created_at"]),
        updated_at=datetime.fromisoformat(row["updated_at"]),
    )


def _item_response(item: Item, user: WPUser, message: str = None) -> Union[ItemResponse, ItemPublicResponse]:
    if user.is_admin:
        return ItemResponse(success=True, data=item, message=message)
    return ItemPublicResponse(success=True, data=ItemPublic.model_validate(item), message=message)


@router.get("", response_model=None)
def list_items(
    skip: int = 0,
    limit: int = 100,
    user: WPUser = Depends(get_current_user),
) -> Union[List[Item], List[ItemPublic]]:
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT * FROM items WHERE is_active = 1 ORDER BY id DESC LIMIT ? OFFSET ?",
            (limit, skip),
        )
        rows = cursor.fetchall()
        items = [row_to_item(row) for row in rows]
        if user.is_admin:
            return items
        return [ItemPublic.model_validate(i) for i in items]


@router.get("/{item_id}", response_model=None)
def get_item(
    item_id: int,
    user: WPUser = Depends(get_current_user),
):
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM items WHERE id = ? AND is_active = 1", (item_id,))
        row = cursor.fetchone()
        if not row:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Item with id {item_id} not found",
            )
        return _item_response(row_to_item(row), user)


@router.post("", response_model=None, status_code=status.HTTP_201_CREATED)
def create_item(
    item: ItemCreate,
    user: WPUser = Depends(get_current_user),
):
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute(
            """
            INSERT INTO items (registry_id, name, description, url, retailer, affiliate_url, affiliate_status, image_url, price, quantity_needed, quantity_purchased, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                item.registry_id,
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
        return _item_response(row_to_item(row), user, "Item created successfully")


@router.put("/{item_id}", response_model=None)
def update_item(
    item_id: int,
    item: ItemUpdate,
    user: WPUser = Depends(get_current_user),
):
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM items WHERE id = ?", (item_id,))
        existing = cursor.fetchone()
        if not existing:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Item with id {item_id} not found",
            )

        update_data = item.model_dump(exclude_unset=True)
        if not update_data:
            return _item_response(row_to_item(existing), user)

        if "is_active" in update_data:
            update_data["is_active"] = int(update_data["is_active"])

        set_clause = ", ".join(f"{k} = ?" for k in update_data.keys())
        values = list(update_data.values()) + [item_id]

        cursor.execute(f"UPDATE items SET {set_clause} WHERE id = ?", values)
        cursor.execute("SELECT * FROM items WHERE id = ?", (item_id,))
        row = cursor.fetchone()
        return _item_response(row_to_item(row), user, "Item updated successfully")


@router.delete("/{item_id}", response_model=None)
def delete_item(
    item_id: int,
    user: WPUser = Depends(get_current_user),
):
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM items WHERE id = ?", (item_id,))
        existing = cursor.fetchone()
        if not existing:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Item with id {item_id} not found",
            )

        cursor.execute("UPDATE items SET is_active = 0 WHERE id = ?", (item_id,))
        cursor.execute("SELECT * FROM items WHERE id = ?", (item_id,))
        row = cursor.fetchone()
        return _item_response(row_to_item(row), user, "Item deleted successfully")
