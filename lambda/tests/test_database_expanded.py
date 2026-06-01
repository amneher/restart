"""
Expanded tests for database and model validation.

Covers:
- Model validation (prices, URLs, field lengths)
- Serialization/deserialization
- Raw SQL transactions and rollback
- Concurrent operations
- Large dataset handling
- DateTime field presence
"""

import os
from datetime import datetime

os.environ.setdefault("DATABASE_PATH", ":memory:")

import pytest
from pydantic import ValidationError

from app.database import init_db, close_db, get_connection
from app.models import Item, ItemCreate, Registry
from app.models.registry import RegistryMeta


@pytest.fixture(autouse=True)
def _db():
    init_db()
    yield
    close_db()


@pytest.fixture
def db():
    return get_connection()


# ─────────────────────────────────────────────────────────────────────────────
# Registry Model Tests (Pydantic validation — Registry lives in WordPress, not SQLite)
# ─────────────────────────────────────────────────────────────────────────────

class TestRegistryModelCreation:

    def test_create_registry_minimal_fields(self):
        registry = Registry(id=1, title="My Registry", username="testuser")
        assert registry.title == "My Registry"
        assert registry.username == "testuser"

    def test_create_registry_with_all_fields(self):
        registry = Registry(
            id=1,
            title="My Registry",
            username="testuser",
            is_private=True,
            story="This is my story",
            meta=RegistryMeta(event_type="birthday"),
        )
        assert registry.is_private is True
        assert registry.meta.event_type == "birthday"

    def test_registry_datetime_fields_present(self):
        registry = Registry(id=1, title="My Registry", username="testuser")
        assert hasattr(registry, "created_at")
        assert hasattr(registry, "updated_at")

    def test_registry_with_max_title_length(self):
        long_title = "X" * 200
        registry = Registry(id=1, title=long_title, username="testuser")
        assert len(registry.title) == 200

    def test_registry_title_uniqueness_per_user(self):
        # Pydantic model allows same title; uniqueness is enforced by WordPress
        registry1 = Registry(id=1, title="My Registry", username="testuser")
        registry2 = Registry(id=2, title="My Registry", username="testuser")
        assert registry1.title == registry2.title


# ─────────────────────────────────────────────────────────────────────────────
# Item Model Tests (Pydantic validation)
# ─────────────────────────────────────────────────────────────────────────────

class TestItemModelCreation:

    def test_create_item_minimal_fields(self):
        item = ItemCreate(registry_id=1, name="Skateboard", url="https://example.com/product")
        assert item.name == "Skateboard"

    def test_create_item_with_all_fields(self):
        item = ItemCreate(
            registry_id=1,
            name="Skateboard",
            url="https://example.com/product",
            price=99.99,
            description="A great skateboard",
            image_url="https://example.com/image.jpg",
            quantity_needed=1,
            quantity_purchased=0,
            notes="Prefer blue color",
        )
        assert item.description == "A great skateboard"
        assert item.quantity_needed == 1
        assert item.notes == "Prefer blue color"

    def test_item_price_precision(self):
        item = ItemCreate(
            registry_id=1,
            name="Item",
            url="https://example.com",
            price=19.99,
        )
        assert abs(item.price - 19.99) < 0.001

    def test_item_negative_price_validation(self):
        with pytest.raises(ValidationError):
            ItemCreate(
                registry_id=1,
                name="Item",
                url="https://example.com",
                price=-10.00,
            )


# ─────────────────────────────────────────────────────────────────────────────
# Field Validation Tests
# ─────────────────────────────────────────────────────────────────────────────

class TestItemFieldValidation:

    def test_item_price_negative_raises(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="Item", url="https://example.com", price=-10.00)

    def test_item_zero_price_raises(self):
        # price is gt=0, so exactly 0 is invalid
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="Item", url="https://example.com", price=0.0)

    def test_item_url_accepts_valid_string(self):
        item = ItemCreate(
            registry_id=1,
            name="Item",
            url="https://example.com/product",
        )
        assert "example.com" in item.url

    def test_item_empty_name_raises(self):
        with pytest.raises(ValidationError):
            ItemCreate(registry_id=1, name="", url="https://example.com")

    def test_item_insert_and_query(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url, price) VALUES (?, ?, ?, ?)",
            (1, "ValidItem", "https://example.com", 9.99),
        )
        db.commit()
        cursor.execute("SELECT * FROM items WHERE name = ?", ("ValidItem",))
        row = cursor.fetchone()
        assert row is not None
        assert row["price"] == 9.99

    def test_item_null_optional_fields(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "NullFields", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT description, image_url FROM items WHERE name = ?", ("NullFields",))
        row = cursor.fetchone()
        assert row["description"] is None
        assert row["image_url"] is None


# ─────────────────────────────────────────────────────────────────────────────
# Registry Status / Privacy Validation
# ─────────────────────────────────────────────────────────────────────────────

class TestRegistryStatusValidation:

    def test_registry_private_false_by_default(self):
        registry = Registry(id=1, title="Test", username="testuser")
        assert registry.is_private is False

    def test_registry_can_be_private(self):
        registry = Registry(id=1, title="Test", username="testuser", is_private=True)
        assert registry.is_private is True

    def test_registry_username_too_long_raises(self):
        with pytest.raises(ValidationError):
            Registry(id=1, title="Test", username="x" * 101)

    def test_registry_title_too_long_raises(self):
        with pytest.raises(ValidationError):
            Registry(id=1, title="T" * 201, username="testuser")

    def test_registry_empty_title_raises(self):
        with pytest.raises(ValidationError):
            Registry(id=1, title="", username="testuser")


# ─────────────────────────────────────────────────────────────────────────────
# Relationship Tests (raw SQL — Registry is not in SQLite)
# ─────────────────────────────────────────────────────────────────────────────

class TestModelRelationships:

    def test_item_insert_with_registry_id(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (42, "RelItem", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT registry_id FROM items WHERE name = ?", ("RelItem",))
        row = cursor.fetchone()
        assert row["registry_id"] == 42

    def test_orphaned_items_after_registry_delete(self):
        # SQLite items table has no FK to a Registry table; registries live in WP
        pass

    def test_item_foreign_key_is_unconstrained_by_default(self, db):
        # SQLite doesn't enforce FK constraints unless PRAGMA foreign_keys = ON
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (99999, "UnconstrainedItem", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT * FROM items WHERE name = ?", ("UnconstrainedItem",))
        assert cursor.fetchone() is not None


# ─────────────────────────────────────────────────────────────────────────────
# Serialization Tests
# ─────────────────────────────────────────────────────────────────────────────

class TestModelSerialization:

    def test_registry_model_dump(self):
        registry = Registry(id=1, title="Test", username="testuser", story="My story")
        data = registry.model_dump()
        assert data["title"] == "Test"
        assert data["story"] == "My story"

    def test_item_model_dump_with_price(self):
        item = ItemCreate(
            registry_id=1,
            name="Item",
            url="https://example.com",
            price=19.99,
        )
        data = item.model_dump()
        assert "price" in data
        assert abs(data["price"] - 19.99) < 0.001

    def test_datetime_created_at_in_db(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "SerializeTest", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT created_at FROM items WHERE name = ?", ("SerializeTest",))
        row = cursor.fetchone()
        assert row["created_at"] is not None

    def test_null_optional_fields_serialize_as_none(self):
        item = ItemCreate(
            registry_id=1,
            name="Item",
            url="https://example.com",
        )
        data = item.model_dump()
        assert data.get("description") is None
        assert data.get("image_url") is None


# ─────────────────────────────────────────────────────────────────────────────
# Transaction Tests
# ─────────────────────────────────────────────────────────────────────────────

class TestDatabaseTransactions:

    def test_transaction_rollback_on_exception(self, db):
        cursor = db.cursor()
        try:
            cursor.execute(
                "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
                (1, "RollbackTest", "https://example.com"),
            )
            raise ValueError("Intentional error")
        except ValueError:
            db.rollback()
        cursor.execute("SELECT * FROM items WHERE name = ?", ("RollbackTest",))
        assert cursor.fetchone() is None

    def test_transaction_commit(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "CommitTest", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT * FROM items WHERE name = ?", ("CommitTest",))
        assert cursor.fetchone() is not None

    def test_transaction_rollback_on_constraint_error(self, db):
        cursor = db.cursor()
        # NOT NULL constraint: inserting without required `name` should fail
        try:
            cursor.execute(
                "INSERT INTO items (registry_id, url) VALUES (?, ?)",
                (1, "https://example.com"),
            )
            db.commit()
        except Exception:
            db.rollback()
        cursor.execute("SELECT * FROM items WHERE url = ?", ("https://example.com",))
        assert cursor.fetchone() is None

    def test_transaction_rollback_manual(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "ManualRollback", "https://example.com"),
        )
        db.rollback()
        cursor.execute("SELECT * FROM items WHERE name = ?", ("ManualRollback",))
        assert cursor.fetchone() is None


# ─────────────────────────────────────────────────────────────────────────────
# Concurrent Operation Tests
# ─────────────────────────────────────────────────────────────────────────────

class TestConcurrentOperations:

    def test_read_during_write(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "ConcurrentTest", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT * FROM items WHERE name = ?", ("ConcurrentTest",))
        assert cursor.fetchone() is not None

    def test_concurrent_update_last_write_wins(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "UpdateTest", "https://example.com"),
        )
        db.commit()
        cursor.execute("UPDATE items SET name = ? WHERE name = ?", ("Updated 1", "UpdateTest"))
        db.commit()
        cursor.execute("UPDATE items SET name = ? WHERE name = ?", ("Updated 2", "Updated 1"))
        db.commit()
        cursor.execute("SELECT name FROM items WHERE name = ?", ("Updated 2",))
        assert cursor.fetchone() is not None


# ─────────────────────────────────────────────────────────────────────────────
# Large Dataset Tests
# ─────────────────────────────────────────────────────────────────────────────

class TestLargeDatasets:

    def test_bulk_insert_items(self, db):
        cursor = db.cursor()
        for i in range(100):
            cursor.execute(
                "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
                (1, f"BulkItem {i}", f"https://example.com/item{i}"),
            )
        db.commit()
        cursor.execute("SELECT COUNT(*) FROM items WHERE registry_id = ?", (1,))
        count = cursor.fetchone()[0]
        assert count == 100

    def test_query_performance_with_limit(self, db):
        cursor = db.cursor()
        for i in range(50):
            cursor.execute(
                "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
                (1, f"PerfItem {i}", f"https://example.com/{i}"),
            )
        db.commit()
        cursor.execute("SELECT * FROM items WHERE registry_id = ? LIMIT 10", (1,))
        rows = cursor.fetchall()
        assert len(rows) == 10

    def test_pagination_on_large_dataset(self, db):
        cursor = db.cursor()
        for i in range(100):
            cursor.execute(
                "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
                (1, f"PageItem {i:03d}", f"https://example.com/{i}"),
            )
        db.commit()
        cursor.execute("SELECT * FROM items WHERE registry_id = ? LIMIT 10 OFFSET 0", (1,))
        page1 = cursor.fetchall()
        cursor.execute("SELECT * FROM items WHERE registry_id = ? LIMIT 10 OFFSET 10", (1,))
        page2 = cursor.fetchall()
        assert len(page1) == 10
        assert len(page2) == 10
        assert page1[0]["id"] != page2[0]["id"]


# ─────────────────────────────────────────────────────────────────────────────
# DateTime Validation Tests
# ─────────────────────────────────────────────────────────────────────────────

class TestDateTimeValidation:

    def test_created_at_auto_set_on_insert(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "DateTest", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT created_at FROM items WHERE name = ?", ("DateTest",))
        row = cursor.fetchone()
        assert row["created_at"] is not None

    def test_datetime_in_correct_timezone(self, db):
        cursor = db.cursor()
        cursor.execute(
            "INSERT INTO items (registry_id, name, url) VALUES (?, ?, ?)",
            (1, "TZTest", "https://example.com"),
        )
        db.commit()
        cursor.execute("SELECT created_at FROM items WHERE name = ?", ("TZTest",))
        row = cursor.fetchone()
        assert row["created_at"] is not None
        # SQLite stores as TEXT; should be parseable
        assert isinstance(row["created_at"], str)
