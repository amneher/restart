# Plans

---

## Docker Compose Integration Test Environment

**Goal:** `make test-local` starts the local stack, runs integration and e2e tests against it, and tears down cleanly. No AWS required.

**Context:**
- `restart_lambda/docker-compose.yml` already joins `local_wordpress_internal` network and sets `WP_BASE_URL: http://local_wordpress-nginx`
- `local_wordpress` compose runs nginx (port 8082), WordPress (fpm), MySQL — its internal network is `local_wordpress_internal`
- `.env` already has `WP_LOCAL_URL`, `WP_LOCAL_USER`, `WP_LOCAL_APP_PWD`
- Integration tests: `test_registry_wp_integration.py`, `test_registry_e2e.py`

### Plan

**Step 1 — Compose test override (`docker-compose.test.yml`)**

Add a compose override file that adjusts the app service for test runs:
- Drop nginx (tests hit the app container directly on its internal port)
- Use an ephemeral SQLite DB (`DATABASE_PATH=/tmp/test.db` or `:memory:`)
- Set `WP_LOCAL_USER` and `WP_LOCAL_APP_PWD` from `.env`
- Don't use `--reload` (not needed for tests)

**Step 2 — `make test-local` Makefile target**

Sequence:
1. Check if `local_wordpress` stack is up; if not, start it and wait for WordPress to be healthy
2. Start the app using the test override (`docker compose -f docker-compose.yml -f docker-compose.test.yml up -d`)
3. Wait for the app healthcheck (`/health`) to pass
4. Run `pytest tests/test_registry_wp_integration.py tests/test_registry_e2e.py -v` with `WP_LOCAL_*` env vars pointing at the local stack and `APP_URL` pointing at the local app container
5. Tear down the app container (leave `local_wordpress` running — it's shared and slow to start)
6. Exit with the pytest return code so CI/make reports the correct result

**Step 3 — Test data isolation**

Use a MySQL snapshot for clean state:
1. After a clean WordPress install (plugins active, no test data), dump the DB with `mysqldump` into a committed fixture file (`tests/fixtures/wp-clean.sql`)
2. Before each `make test-local` run, restore the snapshot into the running MySQL container (`docker exec ... mysql < wp-clean.sql`)
3. Add a `make wp-snapshot` target to re-capture the snapshot when the baseline WordPress config changes (new plugin, new custom post type, etc.)

This guarantees every test run starts from identical state regardless of what previous runs created or left behind.

**Step 4 — `make test-local` Makefile documentation**

Update `help` target and `CLAUDE.md` to document the new target.

**Out of scope for now:**
- Running `test-local` in GitHub Actions CI (no local WordPress there — that's the ECS conversation)
- Any changes to `local_wordpress` compose itself

---

### Todo

- [x] Create `docker-compose.test.yml` override — not needed; both test files use FastAPI `TestClient` in-process, no app container required
- [x] Add `make test-local` target to Makefile — starts local_wordpress if needed, restores snapshot, runs pytest with `WP_LOCAL_*` vars
- [ ] Verify `local_wordpress_internal` network is joinable when `local_wordpress` is up (smoke test) — manual step, run `make test-local` to confirm
- [x] Capture initial MySQL snapshot into `tests/fixtures/wp-clean.sql` (add `make wp-snapshot` target) — target added; user must run it once against a clean local WP install
- [x] Add snapshot restore step to `make test-local` (runs before pytest)
- [x] Review `test_registry_wp_integration.py` and `test_registry_e2e.py` — both use `WP_LOCAL_URL/USER/APP_PWD`; existing teardown in `e2e_state` kept (useful within a run; snapshot handles between-run state)
- [x] Update `make help` and `CLAUDE.md` with `test-local` and `wp-snapshot` docs
