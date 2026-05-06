# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Run development server
uvicorn app.main:app --host 0.0.0.0 --port 5000 --reload

# Run all tests
pytest tests/ -v

# Run a single test file
pytest tests/test_items.py -v

# Run a single test
pytest tests/test_items.py::test_create_item -v

# Install dependencies
uv sync
```

## Deployment (Makefile)

All deploy operations use `make`. Credentials are read from `.env` automatically.

```bash
# Build Lambda zip from current HEAD
make build

# Build from a specific branch
make build BRANCH=main

# Build and verify the handler can be imported from the zip
make build-test [BRANCH=main]

# Run unit tests (in-memory, no network)
make test

# Run WP integration + e2e tests against local Docker stack (requires local_wordpress running)
# First time: run 'make wp-snapshot' to capture the baseline DB state
make test-local

# Run WP integration + e2e tests against staging
make test-staging

# Run WP integration + e2e tests against production
make test-prod

# Deploy to staging Lambda
make deploy-staging [BRANCH=feature-x]

# Deploy to production Lambda (interactive confirmation)
make deploy-prod [BRANCH=main]

# Tag the current commit and save a named build artifact
make tag TAG=v1.2.3 [BRANCH=main]

# Remove build artifacts
make clean
```

**Key Makefile variables** (override on CLI or via env):
- `BRANCH` — git ref to build (branch name, tag, or SHA; default: `HEAD`)
- `FUNCTION_PROD` / `FUNCTION_STAGING` — Lambda function names
- `FORCE=yes` — skip the deploy-prod confirmation (for CI)

## Architecture

This is an AWS Lambda API for **the-restart.co Registry app** — a gift registry platform that integrates with a WordPress backend via the `wp-python` library.

**Request flow:** API Gateway → Mangum (ASGI adapter) → FastAPI → SQLite

### Key files
- `app/main.py` — FastAPI app instantiation, lifespan (DB init/close), Mangum `handler` (Lambda entry point)
- `app/database/connection.py` — singleton SQLite connection; uses `DATABASE_PATH` env var (defaults to `data.db`)
- `app/routes/` — FastAPI `APIRouter` modules, exported from `__init__.py` and registered in `main.py`
- `app/models/` — Pydantic v2 models; `item.py` has the full Item CRUD models, `registry.py` is in development

### Database pattern
The DB uses a module-level singleton connection (`_connection`). Tests set `DATABASE_PATH=:memory:` before importing `app.main` to use in-memory SQLite. Each test calls `init_db()` / `close_db()` via fixtures in `tests/conftest.py`.

### In-progress: Registry feature
`app/models/registry.py` and `app/routes/registry.py` are under active development. The `Registry` model maps to a WordPress custom post type (`restart-registry`) via the `wp-python` package. The `Item` model (in SQLite) represents wishlist items that belong to a registry. The `item.py` model has fields `url`, `retailer`, and `affiliate_status` not yet reflected in the DB schema in `connection.py`.
