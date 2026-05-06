"""Database migration system.

SCHEMA_VERSION is the single source of truth for the current database version.
It must equal len(MIGRATIONS). All code that needs to know the current version
should import it from here.
"""

import sqlite3

# ---------------------------------------------------------------------------
# Current schema version — update this when adding a new migration.
# ---------------------------------------------------------------------------
SCHEMA_VERSION = 4

# ---------------------------------------------------------------------------
# Migrations — each entry is a list of SQL statements for that version.
# Applied in order; already-applied ones are skipped.
# len(MIGRATIONS) must equal SCHEMA_VERSION.
# ---------------------------------------------------------------------------
MIGRATIONS: list[list[str]] = [
    # Version 1: Add columns missing from the original minimal schema
    [
        "ALTER TABLE items ADD COLUMN url TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE items ADD COLUMN retailer TEXT",
        "ALTER TABLE items ADD COLUMN affiliate_status TEXT",
        "ALTER TABLE items ADD COLUMN quantity_needed INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE items ADD COLUMN quantity_purchased INTEGER NOT NULL DEFAULT 0",
    ],
    # Version 2: Link items to WP registries
    [
        "ALTER TABLE items ADD COLUMN registry_id INTEGER",
    ],
    # Version 3: Affiliate URL for monetized links
    [
        "ALTER TABLE items ADD COLUMN affiliate_url TEXT",
    ],
    # Version 4: Product image URL
    [
        "ALTER TABLE items ADD COLUMN image_url TEXT",
    ],
]

assert len(MIGRATIONS) == SCHEMA_VERSION, (
    f"SCHEMA_VERSION ({SCHEMA_VERSION}) != len(MIGRATIONS) ({len(MIGRATIONS)}). "
    "Update SCHEMA_VERSION when adding migrations."
)


def get_schema_version(conn: sqlite3.Connection) -> int:
    """Return the current schema version from the database (0 if no migrations have run)."""
    cursor = conn.cursor()
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """)
    conn.commit()
    cursor.execute("SELECT MAX(version) FROM schema_migrations")
    row = cursor.fetchone()
    return row[0] or 0


def run_migrations(conn: sqlite3.Connection) -> None:
    """Apply any pending migrations up to SCHEMA_VERSION."""
    current_version = get_schema_version(conn)
    cursor = conn.cursor()

    for i, statements in enumerate(MIGRATIONS, start=1):
        if i <= current_version:
            continue
        for sql in statements:
            try:
                cursor.execute(sql)
            except sqlite3.OperationalError as e:
                # Skip "duplicate column name" errors — column already exists
                if "duplicate column" in str(e).lower():
                    continue
                raise
        cursor.execute(
            "INSERT INTO schema_migrations (version) VALUES (?)", (i,)
        )
        conn.commit()
