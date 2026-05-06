import sqlite3
from contextlib import contextmanager
from typing import Generator
import os

from app.database.migrations import run_migrations

DATABASE_PATH = os.environ.get("DATABASE_PATH", "data.db")

_connection: sqlite3.Connection | None = None


def get_connection() -> sqlite3.Connection:
    global _connection
    if _connection is None:
        _connection = sqlite3.connect(DATABASE_PATH, check_same_thread=False)
        _connection.row_factory = sqlite3.Row
        # On EFS, multiple Lambda instances share the file via POSIX locking.
        # busy_timeout lets writers queue up rather than fail immediately.
        # WAL mode is intentionally avoided — it requires shared memory (mmap)
        # which doesn't work across instances on NFS.
        _connection.execute("PRAGMA busy_timeout = 5000")
        _connection.execute("PRAGMA journal_mode = DELETE")
    return _connection


@contextmanager
def get_db() -> Generator[sqlite3.Connection, None, None]:
    conn = get_connection()
    try:
        yield conn
    except Exception:
        conn.rollback()
        raise
    else:
        conn.commit()


def init_db() -> None:
    conn = get_connection()
    cursor = conn.cursor()

    # Create the full schema for fresh databases
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registry_id INTEGER,
            name TEXT NOT NULL,
            description TEXT,
            url TEXT NOT NULL,
            retailer TEXT,
            affiliate_url TEXT,
            affiliate_status TEXT,
            image_url TEXT,
            price REAL,
            quantity_needed INTEGER NOT NULL DEFAULT 1,
            quantity_purchased INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """)
    cursor.execute("""
        CREATE TRIGGER IF NOT EXISTS update_items_timestamp
        AFTER UPDATE ON items
        BEGIN
            UPDATE items SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END
    """)
    conn.commit()

    # For fresh DBs, mark all migrations as applied (schema is already complete).
    # For existing DBs, run any pending migrations to add missing columns.
    run_migrations(conn)

    # Create indexes after migrations so columns exist
    cursor.execute("""
        CREATE INDEX IF NOT EXISTS idx_items_registry_id ON items(registry_id)
    """)
    conn.commit()


def close_db() -> None:
    global _connection
    if _connection is not None:
        _connection.close()
        _connection = None
