# Lambda Testing

The Lambda ships **223 pytest tests** spread across unit-style route tests,
validation tests, and live-WordPress integration tests.

## Layout

```
lambda/tests/
├── conftest.py
├── fixtures/
├── test_auth.py
├── test_cors.py
├── test_database.py
├── test_health.py
├── test_items.py
├── test_lambda.py
├── test_models.py
├── test_registry_e2e.py            # requires live WP
├── test_registry_routes.py
├── test_registry_story.py
├── test_registry_wp_integration.py # requires live WP
├── integration/
│   └── test_wp_client_resilience.py
└── validation/
    └── test_xss_inputs.py
```

## Running the suite

```bash
cd lambda
uv sync
.venv/bin/pytest                                 # full suite (needs live WP)

# Skip the WP-integration tests:
.venv/bin/pytest \
  --ignore=tests/test_registry_e2e.py \
  --ignore=tests/test_registry_wp_integration.py
```

The `make` targets:

```bash
make lambda-test           # local pytest (delegates to lambda/Makefile test)
make lambda-test-staging   # runs against the staging Lambda + WP
make lambda-test-prod      # runs against prod (read-only smoke tests)
```

## Key fixtures (`tests/conftest.py`)

| Fixture | What it gives you |
|---------|-------------------|
| `client` | `TestClient(app)` with `get_current_user` overridden to return a non-admin `WPUser`. No network round-trip happens. |
| `admin_client` | Same, but the injected user has the `administrator` role — drives the admin response shape. |
| `unauthed_client` | Same, but **no** override for `get_current_user`. Use this to test the 401 paths. |

The pattern in each fixture:

```python
@pytest.fixture(scope="function")
def client():
    app.dependency_overrides[get_current_user] = lambda: _make_wp_user(admin=False)
    with TestClient(app) as test_client:
        yield test_client
    app.dependency_overrides.clear()
```

`unauthed_client` snapshots and restores any pre-existing overrides so it can
be combined with the others in a single test session.

## Writing a route test

```python
def test_create_item_returns_201(client):
    resp = client.post("/items", json={
        "registry_id": 1,
        "name": "Chef's Knife",
        "url": "https://example.com/knife",
        "price": 79.99,
    })
    assert resp.status_code == 201
    assert resp.json()["data"]["name"] == "Chef's Knife"
```

For admin-only behavior, swap to `admin_client`:

```python
def test_admin_sees_affiliate_fields(admin_client):
    resp = admin_client.get("/items/1")
    assert "affiliate_url" in resp.json()["data"]
```

## Validation tests

`tests/validation/test_xss_inputs.py` exercises the pydantic validators for
length limits, escaping, and the affiliate-URL constraints. New
input-validation rules should add a row here, not in the route tests.

## Integration tests (`tests/integration/`)

`test_wp_client_resilience.py` tests `wp_python` failure modes — timeouts,
401s, malformed responses — to make sure `validate_credentials()` returns
`None` (not a partial `WPUser`) in every error path.

## Live-WordPress tests

Two files require a real WordPress instance:

- `test_registry_e2e.py` — full create/list/update/delete flow against a
  real WP REST API.
- `test_registry_wp_integration.py` — exercise `_client_for_user` and
  `_post_to_registry` round-trips.

Run them with `make up && make seed` first, then:

```bash
cd lambda
.venv/bin/pytest tests/test_registry_e2e.py tests/test_registry_wp_integration.py
```

These use the credentials in `.env` (`WP_LOCAL_URL`, `WP_LOCAL_APP_PWD` for
local; `WP_STAGING_*` for staging via `make lambda-test-staging`).

## Linting + types

```bash
cd lambda
uv run ruff check .
uv run mypy app/
```

Both are wired into `make lint` and `make typecheck` from the top level.

## CI

`ci.yml` runs `make lambda-test` (skipping live-WP tests) on every push.
`deploy-prod.yml` runs the same suite again before the build/deploy step —
no untested code makes it to prod.
