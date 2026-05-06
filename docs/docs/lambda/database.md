# Database

The Lambda persists items in a single SQLite file. In production that file
lives on an EFS mount shared by every Lambda instance.

## File location

| Environment | `DATABASE_PATH` |
|-------------|------------------|
| Local dev (Docker) | `/data/registry.db` (set on the `lambda` service) |
| Tests | `:memory:` via the `client` fixture's `tmp_path` (override) |
| Staging | `/mnt/efs/data.db` |
| Production | `/mnt/efs/data.db` |
| Default | `data.db` (cwd-relative) |

`DATABASE_PATH` is read once on import in `app/database/connection.py`.

## Connection settings

```python
_connection = sqlite3.connect(DATABASE_PATH, check_same_thread=False)
_connection.row_factory = sqlite3.Row
_connection.execute("PRAGMA busy_timeout = 5000")
_connection.execute("PRAGMA journal_mode = DELETE")
```

| Setting | Value | Why |
|---------|-------|-----|
| `check_same_thread` | `False` | Mangum may dispatch across worker threads. The connection is single-process so this is safe. |
| `row_factory` | `sqlite3.Row` | Rows behave like dicts (`row["name"]`) — keeps the route code readable. |
| `busy_timeout` | `5000` ms | EFS uses POSIX file locking; multiple Lambda instances writing concurrently queue rather than fail. |
| `journal_mode` | `DELETE` | WAL mode is intentionally **avoided**: WAL needs shared memory (mmap), which doesn't work over NFS/EFS across Lambda instances. |

The connection is module-global (singleton). `init_db()` runs at FastAPI
startup (lifespan) to create the schema and apply migrations; `close_db()`
runs at shutdown.

## `items` schema

Created in `init_db()`:

```sql
CREATE TABLE items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registry_id INTEGER,                            -- WP post ID; nullable for legacy rows
    name TEXT NOT NULL,
    description TEXT,
    url TEXT NOT NULL,
    retailer TEXT,
    affiliate_url TEXT,
    affiliate_status TEXT,                          -- 'active' | 'inactive' | 'expired' | 'none'
    image_url TEXT,
    price REAL,
    quantity_needed INTEGER NOT NULL DEFAULT 1,
    quantity_purchased INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER DEFAULT 1,                    -- 0 = soft-deleted
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER update_items_timestamp
AFTER UPDATE ON items
BEGIN
    UPDATE items SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE INDEX idx_items_registry_id ON items(registry_id);
```

The `update_items_timestamp` trigger keeps `updated_at` honest without route
code having to remember to set it.

## Migrations

`app/database/migrations/__init__.py` defines:

```python
SCHEMA_VERSION = 4

# Version 1: Add columns missing from the original minimal schema
# Version 2: Link items to WP registries (registry_id)
# Version 3: Affiliate URL for monetized links (affiliate_url, affiliate_status)
# Version 4: Product image URL (image_url)
```

The list of `MIGRATIONS` must be exactly `SCHEMA_VERSION` long — this is
asserted at import time:

```python
assert len(MIGRATIONS) == SCHEMA_VERSION, (
    f"SCHEMA_VERSION ({SCHEMA_VERSION}) != len(MIGRATIONS) ({len(MIGRATIONS)}). "
    "Update SCHEMA_VERSION when adding migrations."
)
```

`run_migrations(conn)` is called from `init_db()`. Behavior:

- For **fresh databases**, the full schema has already been created by
  `init_db()`, so the migration system simply records all migrations as
  applied (no-op forward).
- For **existing databases**, `run_migrations` reads the current version
  from `PRAGMA user_version` (or the equivalent stored marker) and applies
  any pending migrations in order, bringing the schema to `SCHEMA_VERSION`.

This split avoids re-running the early "add column X" migrations against a
freshly-created table that already has those columns.

## Adding a migration

1. Add a new migration function to `app/database/migrations/`.
2. Append it to `MIGRATIONS` in the `__init__.py`.
3. Bump `SCHEMA_VERSION` to match the new length.
4. Add a comment alongside the bump describing the migration.
5. Add a regression test under `lambda/tests/test_database.py`.
6. Update the `init_db()` `CREATE TABLE` statement so fresh databases also
   get the new column.

!!! warning "EFS + concurrent migrations"
    All Lambda instances start up at roughly the same time during a deploy.
    `init_db()` runs on the first request handled by each instance; the
    `busy_timeout = 5000` covers the race, but migrations should always be
    idempotent (`ADD COLUMN IF NOT EXISTS`-equivalent — SQLite needs you to
    catch `sqlite3.OperationalError` for the "duplicate column" case).

## Testing

Tests run against an in-memory `:memory:` database via the `client` fixture in
`lambda/tests/conftest.py`. The fixture overrides `get_current_user` to
inject a fake `WPUser` so no WordPress round-trip happens, and uses
`tmp_path` for any test that needs a real on-disk file (e.g. migration
tests).
