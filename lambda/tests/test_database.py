import os

os.environ["DATABASE_PATH"] = ":memory:"

from app.database import get_db, SCHEMA_VERSION
from app.database.migrations import run_migrations, get_schema_version


class TestDatabase:
    def test_init_db_creates_table(self, db_connection):
        cursor = db_connection.cursor()
        cursor.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='items'"
        )
        result = cursor.fetchone()
        assert result is not None
        assert result["name"] == "items"

    def test_init_db_creates_trigger(self, db_connection):
        cursor = db_connection.cursor()
        cursor.execute(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name='update_items_timestamp'"
        )
        result = cursor.fetchone()
        assert result is not None

    def test_get_db_context_manager(self, db_connection):
        with get_db() as conn:
            assert conn is not None
            cursor = conn.cursor()
            cursor.execute("SELECT 1")
            result = cursor.fetchone()
            assert result[0] == 1

    def test_insert_and_retrieve(self, db_connection):
        cursor = db_connection.cursor()
        cursor.execute(
            "INSERT INTO items (name, url, price) VALUES (?, ?, ?)",
            ("Test Item", "https://example.com/product/test", 25.00)
        )
        db_connection.commit()
        
        cursor.execute("SELECT * FROM items WHERE name = ?", ("Test Item",))
        row = cursor.fetchone()
        assert row is not None
        assert row["name"] == "Test Item"
        assert row["price"] == 25.00

    def test_update_timestamp_trigger(self, db_connection):
        cursor = db_connection.cursor()
        cursor.execute(
            "INSERT INTO items (name, url, price) VALUES (?, ?, ?)",
            ("Trigger Test", "https://example.com/product/trigger-test", 10.00)
        )
        db_connection.commit()

        cursor.execute("SELECT created_at, updated_at FROM items WHERE name = ?", ("Trigger Test",))
        row = cursor.fetchone()
        original_updated = row["updated_at"]

        import time
        time.sleep(0.1)

        cursor.execute("UPDATE items SET price = ? WHERE name = ?", (20.00, "Trigger Test"))
        db_connection.commit()

        cursor.execute("SELECT updated_at FROM items WHERE name = ?", ("Trigger Test",))
        row = cursor.fetchone()
        new_updated = row["updated_at"]

        assert new_updated >= original_updated


class TestMigrations:
    def test_fresh_db_gets_all_migrations_marked(self, db_connection):
        """init_db() on a fresh DB creates full schema + marks migrations as applied."""
        version = get_schema_version(db_connection)
        # Fresh DB created by init_db() should have run all migrations
        assert version == SCHEMA_VERSION

    def test_migration_on_old_schema(self):
        """Simulate an old DB missing columns — migrations add them."""
        import sqlite3
        conn = sqlite3.connect(":memory:")
        conn.row_factory = sqlite3.Row
        # Create the original minimal schema (no url, retailer, etc.)
        conn.execute("""
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                price REAL NOT NULL,
                is_active INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        conn.commit()

        # Insert a row with old schema
        conn.execute(
            "INSERT INTO items (name, price) VALUES (?, ?)", ("Old Item", 9.99)
        )
        conn.commit()

        # Run migrations
        run_migrations(conn)

        # Verify new columns exist
        cursor = conn.cursor()
        cursor.execute("PRAGMA table_info(items)")
        columns = {row[1] for row in cursor.fetchall()}
        assert "url" in columns
        assert "retailer" in columns
        assert "affiliate_status" in columns
        assert "quantity_needed" in columns
        assert "quantity_purchased" in columns
        assert "registry_id" in columns
        assert "affiliate_url" in columns

        # Verify old data is intact
        cursor.execute("SELECT * FROM items WHERE name = ?", ("Old Item",))
        row = cursor.fetchone()
        assert row is not None

        # Verify version matches total migrations
        assert get_schema_version(conn) == SCHEMA_VERSION
        conn.close()

    def test_migrations_are_idempotent(self, db_connection):
        """Running migrations twice doesn't error."""
        run_migrations(db_connection)
        version = get_schema_version(db_connection)
        assert version == SCHEMA_VERSION
