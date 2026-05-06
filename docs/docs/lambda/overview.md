# Lambda Overview

The Lambda is a FastAPI app deployed to AWS Lambda behind API Gateway. It
persists wishlist **items** (registries themselves live in WordPress) and
serves a small REST API consumed by the WordPress plugin and — for the
start-a-registry flow — by the browser directly.

## At a glance

| | |
|--|--|
| **Runtime** | Python 3.14 on AWS Lambda |
| **Framework** | FastAPI + Mangum |
| **Persistence** | SQLite on EFS (`/mnt/efs/data.db`) |
| **Auth** | WordPress Basic auth, validated via `wp-python` |
| **Version** | `1.0.3` |

## Layout

```
lambda/
├── pyproject.toml
├── app/
│   ├── main.py                # FastAPI app + Mangum handler
│   ├── auth/
│   │   ├── dependencies.py    # get_current_user, require_roles
│   │   ├── models.py          # WPUser
│   │   └── wp_client.py       # validate_credentials(), 5min cache
│   ├── database/
│   │   ├── connection.py      # SQLite singleton, busy_timeout, journal_mode
│   │   └── migrations/        # Versioned migrations + run_migrations()
│   ├── models/
│   │   ├── item.py            # Item, ItemPublic, ItemCreate, ItemUpdate, ...
│   │   └── registry.py        # Registry, RegistryMeta, ...
│   └── routes/
│       ├── health.py          # /, /health
│       ├── items.py           # /items/*
│       └── registry.py        # /registries/*
└── tests/                     # 223 pytest tests
```

## Composition

```python
# lambda/app/main.py (excerpt)
app = FastAPI(
    title="Restart Registry API",
    description="A FastAPI application designed for AWS Lambda with SQLite",
    version="0.1.0",
    lifespan=lifespan,            # init_db() on startup, close_db() on shutdown
)
app.add_middleware(CORSMiddleware, allow_origins=["*"], ...)
app.include_router(health_router)
app.include_router(items_router)
app.include_router(registry_router)
handler = Mangum(app, lifespan="off")
```

`Mangum` is the AWS Lambda adapter — `handler` is what API Gateway invokes.
`lifespan="off"` is intentional: the Lambda execution environment is reused
across invocations, so we initialize the DB once at module import time (via
the FastAPI lifespan) and never tear it down per-invocation.

## Key design choices

### SQLite on EFS instead of RDS

A single SQLite file mounted on EFS keeps operational complexity tiny. The
trade-off is that EFS does not support memory-mapped files, so:

- `journal_mode = DELETE` (not WAL — WAL needs shared memory).
- `busy_timeout = 5000` so writers queue up rather than fail under contention.

These are set on every connection in `database/connection.py`.

### No user table

The Lambda has no users database. Every request carries WP Basic auth
(`Authorization: Basic ...`); `get_current_user` calls back to WordPress via
`wp-python` (`WordPressClient.users.me(context="edit")`) and caches the
result keyed on the raw header for `WP_AUTH_CACHE_TTL` seconds (default 300).

### Two response shapes for items

- `Item` / `ItemResponse` — admin response, includes `affiliate_url` and
  `affiliate_status`.
- `ItemPublic` / `ItemPublicResponse` — non-admin response, omits affiliate
  fields entirely.

Each route checks `user.is_admin` and returns the appropriate model. See
[Endpoints](api.md).

### Registries delegate to WordPress

The `/registries/*` routes do not touch SQLite for registry metadata. They
build a `wp_python.WordPressClient` from the request's auth header and
read/write the `restart-registry` custom post type via the WP REST API. The
Lambda only adds the SQLite-backed item operations on top.

## Health endpoints

| Endpoint | Response |
|----------|----------|
| `GET /` | `{"message": "Welcome to AWS Lambda FastAPI"}` |
| `GET /health` | `{"status": "healthy", "service": "aws-lambda-fastapi"}` |

Both are unauthenticated. The Docker compose health check curls `/health`.
