"""
Shared test fixtures and utilities for Lambda tests.

Provides:
- Common test users (admin, subscriber, editor, etc.)
- Common test registries and items
- Database state generators
- Mock/mock utilities
- Assertion helpers
"""

import base64
from decimal import Decimal
from datetime import datetime, timedelta
from typing import Optional, List
import pytest
from sqlalchemy.orm import Session

from app.auth.models import WPUser
from app.models import Item, Registry


# ─────────────────────────────────────────────────────────────────────────────
# User Factories
# ─────────────────────────────────────────────────────────────────────────────

def create_test_user(
    id: int = 1,
    username: str = "testuser",
    email: Optional[str] = None,
    roles: Optional[List[str]] = None,
    capabilities: Optional[dict] = None,
) -> WPUser:
    """Create a test WP user."""
    return WPUser(
        id=id,
        username=username,
        email=email or f"{username}@example.com",
        display_name=username.title(),
        roles=roles or ["subscriber"],
        capabilities=capabilities or {"read": True},
    )


def create_admin_user(id: int = 1) -> WPUser:
    """Create admin test user."""
    return create_test_user(
        id=id,
        username="admin",
        roles=["administrator"],
        capabilities={
            "manage_options": True,
            "edit_others_posts": True,
            "delete_others_posts": True,
            "read": True,
        },
    )


def create_editor_user(id: int = 1) -> WPUser:
    """Create editor test user."""
    return create_test_user(
        id=id,
        username="editor",
        roles=["editor"],
        capabilities={
            "edit_posts": True,
            "delete_posts": True,
            "publish_posts": True,
            "read": True,
        },
    )


def create_contributor_user(id: int = 1) -> WPUser:
    """Create contributor test user."""
    return create_test_user(
        id=id,
        username="contributor",
        roles=["contributor"],
        capabilities={
            "edit_posts": True,
            "delete_posts": True,
            "read": True,
        },
    )


def create_subscriber_user(id: int = 1) -> WPUser:
    """Create subscriber test user."""
    return create_test_user(
        id=id,
        username="subscriber",
        roles=["subscriber"],
        capabilities={"read": True},
    )


# ─────────────────────────────────────────────────────────────────────────────
# Registry Factories
# ─────────────────────────────────────────────────────────────────────────────

def create_test_registry(
    db: Session,
    wp_post_id: int = 1,
    title: str = "Test Registry",
    description: str = "A test registry",
    status: str = "published",
    visibility: str = "public",
    created_by: int = 1,
    modified_by: Optional[int] = None,
) -> Registry:
    """Create a test registry."""
    registry = Registry(
        wp_post_id=wp_post_id,
        title=title,
        description=description,
        status=status,
        visibility=visibility,
        created_by=created_by,
        modified_by=modified_by or created_by,
    )
    db.add(registry)
    db.flush()
    return registry


def create_draft_registry(db: Session, created_by: int = 1) -> Registry:
    """Create draft registry."""
    return create_test_registry(
        db,
        title="Draft Registry",
        status="draft",
        visibility="private",
        created_by=created_by,
    )


def create_public_registry(db: Session, created_by: int = 1) -> Registry:
    """Create public registry."""
    return create_test_registry(
        db,
        title="Public Registry",
        status="published",
        visibility="public",
        created_by=created_by,
    )


def create_private_registry(db: Session, created_by: int = 1) -> Registry:
    """Create private registry."""
    return create_test_registry(
        db,
        title="Private Registry",
        status="published",
        visibility="private",
        created_by=created_by,
    )


# ─────────────────────────────────────────────────────────────────────────────
# Item Factories
# ─────────────────────────────────────────────────────────────────────────────

def create_test_item(
    db: Session,
    registry_id: int = 1,
    name: str = "Test Item",
    url: str = "https://example.com/product",
    price: Decimal = Decimal("19.99"),
    description: Optional[str] = None,
    image_url: Optional[str] = None,
    quantity: int = 1,
    quantity_purchased: int = 0,
    notes: Optional[str] = None,
) -> Item:
    """Create a test item."""
    item = Item(
        registry_id=registry_id,
        name=name,
        url=url,
        price=price,
        description=description,
        image_url=image_url,
        quantity=quantity,
        quantity_purchased=quantity_purchased,
        notes=notes,
    )
    db.add(item)
    db.flush()
    return item


def create_cheap_item(db: Session, registry_id: int = 1) -> Item:
    """Create low-priced item."""
    return create_test_item(
        db,
        registry_id=registry_id,
        name="Cheap Item",
        price=Decimal("5.99"),
    )


def create_expensive_item(db: Session, registry_id: int = 1) -> Item:
    """Create high-priced item."""
    return create_test_item(
        db,
        registry_id=registry_id,
        name="Expensive Item",
        price=Decimal("500.00"),
    )


def create_purchased_item(db: Session, registry_id: int = 1) -> Item:
    """Create fully purchased item."""
    return create_test_item(
        db,
        registry_id=registry_id,
        name="Purchased Item",
        quantity=2,
        quantity_purchased=2,
    )


def create_partially_purchased_item(db: Session, registry_id: int = 1) -> Item:
    """Create partially purchased item."""
    return create_test_item(
        db,
        registry_id=registry_id,
        name="Partial Item",
        quantity=5,
        quantity_purchased=2,
    )


# ─────────────────────────────────────────────────────────────────────────────
# Bulk Data Generators
# ─────────────────────────────────────────────────────────────────────────────

def create_items_for_registry(
    db: Session,
    registry_id: int,
    count: int = 10,
    price_range: tuple = (5.00, 100.00),
) -> List[Item]:
    """Create multiple items for a registry."""
    items = []
    for i in range(count):
        price = Decimal(price_range[0]) + Decimal(
            (price_range[1] - price_range[0]) * (i / count)
        )
        item = create_test_item(
            db,
            registry_id=registry_id,
            name=f"Item {i+1}",
            url=f"https://example.com/product/{i+1}",
            price=price,
        )
        items.append(item)
    db.commit()
    return items


def create_registries_for_user(
    db: Session,
    created_by: int,
    count: int = 5,
) -> List[Registry]:
    """Create multiple registries for a user."""
    registries = []
    for i in range(count):
        registry = create_test_registry(
            db,
            wp_post_id=i + 1,
            title=f"Registry {i+1}",
            created_by=created_by,
        )
        registries.append(registry)
    db.commit()
    return registries


# ─────────────────────────────────────────────────────────────────────────────
# HTTP Auth Helpers
# ─────────────────────────────────────────────────────────────────────────────

def basic_auth_header(username: str = "testuser", password: str = "xxxx") -> dict:
    """Create basic auth header."""
    creds = base64.b64encode(f"{username}:{password}".encode()).decode()
    return {"Authorization": f"Basic {creds}"}


def bearer_auth_header(token: str = "test-token") -> dict:
    """Create bearer auth header."""
    return {"Authorization": f"******"}


# ─────────────────────────────────────────────────────────────────────────────
# Assertion Helpers
# ─────────────────────────────────────────────────────────────────────────────

def assert_registry_equal(actual: dict, expected: dict, ignore_fields: set = None) -> None:
    """Assert registry data matches, optionally ignoring fields."""
    ignore = ignore_fields or {"id", "created_at", "modified_at"}
    
    for key, expected_value in expected.items():
        if key not in ignore:
            assert actual.get(key) == expected_value, f"Field {key}: {actual.get(key)} != {expected_value}"


def assert_item_equal(actual: dict, expected: dict, ignore_fields: set = None) -> None:
    """Assert item data matches, optionally ignoring fields."""
    ignore = ignore_fields or {"id", "created_at", "modified_at"}
    
    for key, expected_value in expected.items():
        if key not in ignore:
            assert actual.get(key) == expected_value, f"Field {key}: {actual.get(key)} != {expected_value}"


# ─────────────────────────────────────────────────────────────────────────────
# Test State Helpers
# ─────────────────────────────────────────────────────────────────────────────

@pytest.fixture
def populated_db(db: Session):
    """Fixture providing database with test data."""
    # Create test users
    user1 = create_subscriber_user(id=1)
    user2 = create_subscriber_user(id=2)
    
    # Create test registries
    registry1 = create_test_registry(db, wp_post_id=1, created_by=1)
    registry2 = create_test_registry(db, wp_post_id=2, created_by=2)
    
    # Create test items
    create_test_item(db, registry_id=registry1.id, name="Item 1", price=Decimal("10.00"))
    create_test_item(db, registry_id=registry1.id, name="Item 2", price=Decimal("20.00"))
    create_test_item(db, registry_id=registry2.id, name="Item 3", price=Decimal("30.00"))
    
    db.commit()
    
    return db
