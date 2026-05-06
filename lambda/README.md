> [!NOTE]
> **This repository has been archived.** Development continues in the [restart monorepo](https://github.com/amneher/restart).

# Restart Registry — Lambda API

A FastAPI service that runs on AWS Lambda, managing gift-registry **items** in SQLite. Registry metadata (ownership, invitees, event info) lives in WordPress as a custom post type; this service stores the items and exposes them over HTTP.

---

## Architecture

```
Browser / WP Plugin
       │
       ├─── WordPress REST API (/wp/v2/restart-registry)
       │         WP CPT post   →  title, author, status (public/private)
       │         WP post meta  →  restart_item_ids (JSON list of Lambda IDs)
       │                          restart_invitees  (JSON list of usernames/emails)
       │                          restart_event_type
       │                          restart_event_date
       │
       └─── Lambda FastAPI (/items/*)
                 SQLite row    →  name, url, price, description, retailer,
                                  affiliate_status, quantity_needed,
                                  quantity_purchased, is_active
```

A **registry** is a WordPress CPT post (`restart-registry`). Its `restart_item_ids` meta field holds a JSON list of Lambda item IDs. The Lambda service is the single source of truth for item data; WordPress is the single source of truth for registry identity and access control.

**Request flow (production):** API Gateway → Mangum → FastAPI → SQLite

---

## Project Structure

```
app/
├── main.py                   FastAPI app + Mangum Lambda handler
├── database/
│   └── connection.py         Singleton SQLite connection; creates schema on init
├── models/
│   ├── item.py               Pydantic models: ItemBase, ItemCreate, ItemUpdate, Item, ItemResponse
│   └── registry.py           Pydantic models: RegistryMeta, RegistryBase, Registry (WP-side)
└── routes/
    ├── health.py             GET /health
    └── items.py              CRUD for /items

tests/
├── conftest.py               Shared fixtures (in-memory SQLite, TestClient, sample data)
├── test_database.py          SQLite schema and trigger tests
├── test_health.py            Health endpoint tests
├── test_items.py             Item CRUD endpoint tests
├── test_lambda.py            Mangum Lambda handler integration tests
├── test_models.py            Pydantic model validation tests
├── test_registry_wp_integration.py   WP REST API integration (requires local WP)
└── test_registry_e2e.py      Full cross-system E2E suite (requires local WP)

local_wordpress/              Docker Compose WordPress stack (sibling repo/directory)
├── src/
│   ├── mu-plugins/
│   │   └── restart-registry-cpt.php   Registers CPT + meta; enables app passwords over HTTP
│   ├── plugins/restart-registry/      Full WP plugin: shortcodes, AJAX, admin UI
│   │   ├── includes/
│   │   │   ├── class-lambda-api-client.php      HTTP client for this Lambda service
│   │   │   ├── class-restart-registry-controller.php  CPT + Lambda orchestration
│   │   │   └── class-affiliate-converter.php    URL → affiliate URL conversion
│   │   ├── public/
│   │   │   ├── class-restart-registry-public.php  Shortcodes + AJAX handlers
│   │   │   ├── css/restart-registry-public.css
│   │   │   └── js/restart-registry-public.js
│   │   └── admin/class-restart-registry-admin.php
│   └── themes/theRestart/
│       └── templates/
│           ├── single-restart-registry.html   FSE template for /registry/<slug>/
│           └── archive-restart-registry.html  FSE template for registry listing
└── nginx/default.conf        try_files rewrite for /wp-json/ pretty URLs
```

---

## Configuration

| Variable | Where set | Purpose |
|---|---|---|
| `DATABASE_PATH` | env var | SQLite file path. Defaults to `data.db`. Set to `:memory:` in tests. |
| `RESTART_LAMBDA_URL` | env var or WP option | Base URL of this Lambda service, used by the WP plugin to call Lambda. No trailing slash. |
| `WP_LOCAL_URL` | env var (tests only) | URL of the local WordPress instance. Default: `http://localhost:8082`. |
| `WP_LOCAL_USER` | env var (tests only) | WordPress username for API authentication. |
| `WP_LOCAL_APP_PWD` | env var (tests only) | WordPress Application Password for the above user. |

The **Lambda URL** can also be set from the WP admin: **Gift Registry → Settings → Lambda API URL**.

---

## Development

### Prerequisites

```bash
uv sync          # install Python deps
```

### Run locally

```bash
uvicorn app.main:app --host 0.0.0.0 --port 5000 --reload
```

The API is then at `http://localhost:5000`. OpenAPI docs at `http://localhost:5000/docs`.

---

## API Reference

All item responses use the `ItemResponse` envelope:

```json
{ "success": true, "data": { ...item fields... }, "message": "..." }
```

### `GET /health`

Returns service status and database connectivity.

```json
{ "status": "healthy", "database": "connected", "version": "0.1.0" }
```

### `GET /items`

List all active items.

Query params: `skip` (default 0), `limit` (default 100).

Returns a JSON array of `Item` objects (no envelope).

### `POST /items`

Create an item.

```json
{
  "name": "KitchenAid Stand Mixer",
  "url": "https://www.amazon.com/dp/B00005UP2P",
  "price": 449.99,
  "description": "5-quart, Empire Red",
  "retailer": "Amazon",
  "affiliate_status": "converted",
  "quantity_needed": 1,
  "quantity_purchased": 0,
  "is_active": true
}
```

Required: `name`, `url`, `price` (> 0). Returns 201 with `ItemResponse`.

### `GET /items/{id}`

Get a single item. Returns 404 if not found.

### `PUT /items/{id}`

Partial update. All fields optional:

```json
{
  "name": "...",
  "description": "...",
  "price": 399.99,
  "quantity_needed": 2,
  "quantity_purchased": 1,
  "is_active": true
}
```

`quantity_purchased` must be ≥ 0. Returns 200 with updated `ItemResponse`.

### `DELETE /items/{id}`

Soft-deletes by removing the row. Returns 200 with the deleted item in `ItemResponse`, or 404.

---

## Data Models

### Item (Lambda / SQLite)

```python
class Item(BaseModel):
    id: int
    name: str                        # max 100 chars
    url: str                         # product link (may be affiliate URL)
    price: float                     # > 0
    description: Optional[str]       # max 500 chars
    retailer: Optional[str]          # e.g. "Amazon", "Target"
    affiliate_status: Optional[str]  # "converted" if affiliate link applied
    quantity_needed: int             # default 1
    quantity_purchased: int          # default 0, >= 0
    is_active: bool                  # default True
    created_at: datetime
    updated_at: datetime
```

### RegistryMeta (WordPress post meta)

```python
class RegistryMeta(BaseModel):
    invitees: list[str]          # WP usernames or email addresses
    item_ids: list[int]          # Lambda SQLite IDs for this registry's items
    event_type: Optional[str]    # e.g. "Wedding", "Baby Shower"
    event_date: Optional[str]    # ISO 8601 date string
```

Serialized to/from WP post meta via `to_wp_meta()` / `from_wp_meta()`:

| Python field | WP meta key |
|---|---|
| `invitees` | `restart_invitees` (JSON string) |
| `item_ids` | `restart_item_ids` (JSON string) |
| `event_type` | `restart_event_type` |
| `event_date` | `restart_event_date` |

---

## Testing

### Unit tests (no external dependencies)

```bash
pytest tests/ -v
```

Runs all 8 test files. Uses an in-memory SQLite database via `DATABASE_PATH=:memory:` set in `conftest.py`.

| File | Coverage |
|---|---|
| `test_database.py` | Schema creation, INSERT/SELECT, `updated_at` trigger |
| `test_health.py` | `GET /health` response shape |
| `test_items.py` | Full CRUD via FastAPI TestClient |
| `test_lambda.py` | Mangum event → Lambda handler → response |
| `test_models.py` | Pydantic validation (required fields, constraints) |

### WordPress integration tests

Tests the `restart-registry` CPT, post meta, and user management via the WP REST API. Requires the local WordPress Docker stack (see below).

```bash
WP_LOCAL_URL=http://localhost:8082 \
WP_LOCAL_USER=andrew \
WP_LOCAL_APP_PWD="xxxx xxxx xxxx xxxx xxxx xxxx" \
pytest tests/test_registry_wp_integration.py -v
```

Covers: create post, read/write meta round-trip, empty meta defaults, update invitees, public/private status, list, delete.

Tests are skipped automatically when WordPress is not reachable.

### End-to-end tests

10 stateful, ordered tests covering the full cross-system workflow. Both WordPress and the Lambda FastAPI service (via TestClient) are exercised together.

```bash
WP_LOCAL_URL=http://localhost:8082 \
WP_LOCAL_USER=andrew \
WP_LOCAL_APP_PWD="xxxx xxxx xxxx xxxx xxxx xxxx" \
pytest tests/test_registry_e2e.py -v
```

| Test | What it does |
|---|---|
| `test_01_create_wp_users` | Creates 12 WP subscriber accounts (`rr_e2e_01`–`rr_e2e_12`). Pre-cleans leftover users from failed runs. |
| `test_02_create_registries` | Creates one WP CPT post per user with `event_type=e2e-test` in meta. |
| `test_03_add_items_to_registries` | Creates 3 items per registry in Lambda; links IDs into WP `restart_item_ids` meta. |
| `test_04_invite_internal_users` | Invites 2 neighbouring WP usernames per registry via `restart_invitees` meta. |
| `test_05_invite_external_users` | Appends 2 external emails per registry (4 invitees total). |
| `test_06_increment_item_quantities` | Increments `quantity_purchased` +1 on all 36 items via `PUT /items/{id}`. |
| `test_07_decrement_item_quantities` | Decrements every-other registry's first item; asserts 422 for `quantity_purchased=-1`. |
| `test_08_toggle_registry_privacy` | Sets even-indexed registries to `private` WP post status, odd to `publish`. |
| `test_09_cross_system_consistency` | Verifies WP `restart_item_ids` == Lambda item IDs; checks `post_author` matches user. |
| `test_10_full_registry_lifecycle` | Complete workflow: user → registry → 5 items → invite → purchase 3 → verify → private → delete all. |

All WP resources (users + registry posts) are deleted on teardown even when tests fail.

---

## Local WordPress Setup

The WordPress stack lives at `~/projects/local_wordpress` (Docker Compose: nginx + php-fpm + mysql).

### First-time setup

```bash
# Start containers
docker compose -f ~/projects/local_wordpress/docker-compose.yml up -d

# Install WP-CLI
docker exec local_wordpress-wordpress sh -c \
  'curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar'

# Set pretty permalinks (required for /wp-json/ routing)
docker exec local_wordpress-wordpress sh -c \
  'php wp-cli.phar rewrite structure "/%postname%/" --allow-root'

# Activate the restart-registry plugin
docker exec local_wordpress-wordpress sh -c \
  'php wp-cli.phar plugin activate restart-registry --allow-root'

# Set theRestart as the active theme (for FSE templates)
docker exec local_wordpress-wordpress sh -c \
  'php wp-cli.phar theme activate theRestart --allow-root'

# Create an Application Password for API access
docker exec local_wordpress-wordpress sh -c \
  'php wp-cli.phar user application-password create andrew test-key --allow-root --porcelain'
# Copy the output — shown only once
```

The mu-plugin at `local_wordpress/src/mu-plugins/restart-registry-cpt.php` auto-loads and:
- Registers the `restart-registry` CPT with `show_in_rest: true` and rewrite slug `registry`
- Registers all `restart_*` post meta fields in the REST API
- Enables Application Password auth over plain HTTP (safe for local only)

### Configure the Lambda URL in WordPress

After starting the Lambda service locally, set the endpoint in WP:

**WP Admin → Gift Registry → Settings → Lambda API URL** → `http://host.docker.internal:5000`

(Use `host.docker.internal` so the WP container can reach the Lambda service on your host.)

### WordPress shortcodes

| Shortcode | Behaviour |
|---|---|
| `[restart_registry]` | Main entry point. On a CPT single page: owner sees manage view, guests see read view. On any other page: shows the logged-in user's registry (or create form). With `?registry=<post_id>`: shows that registry's read view. |
| `[restart_registry_view registry="123"]` | Read-only view of registry post ID 123. |
| `[restart_registry_create]` | Create form only. |

### Block theme templates

| Template | Route |
|---|---|
| `single-restart-registry.html` | `/registry/<slug>/` — embeds `[restart_registry]` shortcode |
| `archive-restart-registry.html` | `/registry/` — grid of public registry cards with pagination |

---

## WordPress Plugin Flow

```
User visits /registry/my-registry/
        │
        └── FSE template: single-restart-registry.html
                │
                └── [restart_registry] shortcode
                        │
                        ├── is owner?  →  render_manage_registry()
                        │                   ├── add-item form → ajax_add_item()
                        │                   │     └── Lambda POST /items
                        │                   │           + update restart_item_ids meta
                        │                   ├── delete-item → ajax_delete_item()
                        │                   │     └── Lambda DELETE /items/{id}
                        │                   │           + update restart_item_ids meta
                        │                   └── send-invite → ajax_send_invite()
                        │                         └── update restart_invitees meta
                        │                               + wp_mail() if email
                        │
                        └── is invitee / public? → render_registry_view_html()
                                                       └── "Buy This Gift" → item['url']
                                                       └── "Mark as Purchased" → ajax_mark_purchased()
                                                             └── Lambda GET /items/{id}
                                                                   + PUT quantity_purchased + 1
```

---

## AWS Lambda Deployment

The `handler` object in `app/main.py` is the Lambda entry point:

```python
handler = Mangum(app, lifespan="off")
```

**Required Lambda environment variables:**

| Variable | Example |
|---|---|
| `DATABASE_PATH` | `/mnt/efs/registry.db` or `/tmp/data.db` |

For persistent storage across Lambda invocations, mount an EFS volume at `/mnt/efs` and point `DATABASE_PATH` there. The `/tmp` directory resets between cold starts.

**Deployment checklist:**
1. Package dependencies: `uv export --no-dev | pip install -r /dev/stdin -t package/ && cp -r app package/`
2. Zip and upload to Lambda, or use SAM/CDK
3. Set `DATABASE_PATH` environment variable
4. Attach API Gateway and configure routes to `ANY /{proxy+}`
5. Set `RESTART_LAMBDA_URL` in WordPress to the API Gateway invoke URL

## Local Manual Testing

Everything is wired up. Here's a summary of what's running and how to use it:

Services

Service	URL
WordPress	http://localhost:8082
WordPress Admin	http://localhost:8082/wp-admin
Lambda API (direct)	http://localhost:5000
Lambda API docs	http://localhost:5000/docs
How it's connected

The restart_lambda-nginx container is on the local_wordpress_internal Docker network, so the WP container reaches Lambda at http://restart_lambda-nginx. The restart_lambda_url WP option is set to that value.

From your browser, Lambda is at http://localhost:5000 (nginx → uvicorn).

To test the registry in a browser

Go to http://localhost:8082/wp-admin → log in
Visit Gift Registry → Settings to confirm the Lambda URL shows http://restart_lambda-nginx
Navigate to http://localhost:8082/registry/ to see the archive page
Create a registry, add items, test the full flow
Useful commands


# Follow lambda logs (uvicorn with --reload, so code changes hot-reload)
docker logs -f restart_lambda-app

# Stop everything
docker compose down

# Restart after code changes
docker compose restart app
