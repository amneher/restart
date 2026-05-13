# Development

This page is the day-to-day reference once the stack is running.

## Daily workflow

```bash
make up            # bring the stack up (idempotent)
make test          # run all three suites
make seed-reset    # nuke volumes, bring up, re-seed (when state goes weird)
```

After `make up`, the `lambda` service runs `uvicorn ... --reload` so any change
under `lambda/app/` triggers an automatic reload. WordPress containers mount
`./plugin/` and `./theme/` as volumes, so plugin/theme PHP edits are visible
immediately on the next request.

!!! tip "When to `seed-reset`"
    Use it whenever the local data drifts from your expectations: a half-broken
    test left the DB in a strange state, you swapped branches and migrations
    look weird, you want a clean demo for a screencast. It is destructive — it
    drops the WP DB and the Lambda SQLite volume.

## Hot reload notes

| Container | Reload behavior |
|-----------|------------------|
| `wordpress` (FPM) | `./plugin` and `./theme` are bind-mounts. PHP changes apply on next request. |
| `nginx` | Static config; restart with `docker compose restart nginx` if you change `docker/nginx.conf`. |
| `lambda` | `uvicorn --reload` watches `./lambda` (also a bind-mount). Reloads on save. |
| `database` | MySQL — restart only on config change. Data lives in the `dbdata` volume. |

## Running individual test suites

The top-level `Makefile` delegates to component sub-makes:

```bash
make plugin-test           # PHP (PHPUnit) + JS (Jest) for the plugin
make plugin-test-php       # only PHP
make plugin-test-js        # only JS
make plugin-test-scraper   # scraper integration suite (real HTTP — not run in CI)

make lambda-test           # pytest

make theme-test            # PHP + JS
make theme-test-php
make theme-test-js
```

Component-local invocations work too — see [Plugin Testing](plugin/testing.md),
[Lambda Testing](lambda/testing.md), [Theme Testing](theme/testing.md).

## Linting and type-checking

```bash
make lint          # phpcs (plugin), ruff (lambda), phpcs (theme)
make typecheck     # mypy on lambda/app/
```

The plugin and theme both ship a `.phpcs.xml` with WordPress-Extra rules.
`phpcs` is wired up as a Composer dev-dependency in both, so the binary is at
`./vendor/bin/phpcs` after `composer install`.

## Environment-variable reference

All variables that affect behavior, by component:

### Top-level `.env`

| Variable | Default | Used by |
|----------|---------|---------|
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | `wordpress` / `wordpress` / `wordpress` / `rootpassword` | MySQL container |
| `WP_BASE_URL` | _(unset)_ | Lambda — set in deploy environments only |
| `WP_PROD_USERNAME`, `WP_PROD_APP_PWD` | _(unset)_ | `make lambda-test-prod` |
| `WP_STAGING_BASE_URL`, `WP_STAGING_APP_PWD` | _(unset)_ | `make lambda-test-staging` |
| `WP_LOCAL_URL` | `http://localhost:8083` | local Lambda integration tests |
| `WP_LOCAL_APP_PWD` | _(unset)_ | local Lambda integration tests |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | _(unset)_ | `make deploy-staging` / `make deploy-prod` |

### Lambda runtime (set on the Lambda function or in `docker-compose.yml`)

| Variable | Default | Purpose |
|----------|---------|---------|
| `WP_BASE_URL` | `http://localhost:8082` | URL the Lambda calls back to validate credentials |
| `WP_AUTH_CACHE_TTL` | `300` (seconds) | TTL for the in-memory credential cache |
| `DATABASE_PATH` | `data.db` (default), `/data/registry.db` (Docker), `/mnt/efs/data.db` (prod) | Where the SQLite file lives |

### Plugin runtime (WP options first, then env)

| Option / env | Purpose |
|--------------|---------|
| `restart_lambda_url` / `RESTART_LAMBDA_URL` | Lambda base URL |
| `restart_lambda_api_key` / `RESTART_LAMBDA_API_KEY` | API Gateway `x-api-key` |
| `restart_lambda_username` / `RESTART_LAMBDA_USERNAME` | WP user for Basic auth |
| `restart_lambda_app_password` / `RESTART_LAMBDA_APP_PASSWORD` | WP app password for Basic auth |
| `restart_registry_amazon_tag`, `restart_registry_etsy_id`, _etc._ | Affiliate IDs by retailer |
| `restart_registry_email_from`, `restart_registry_email_name` | Outbound notification email defaults |

### Theme runtime

| Variable | Purpose |
|----------|---------|
| `RESTART_LAMBDA_URL` (PHP `define()`) | Surfaced into `start-registry.js` via `wp_localize_script` so the browser can POST to the Lambda directly |

## Version bumping

Each component has its own version file. Helpers wrap `scripts/bump.sh`:

```bash
make bump-plugin-patch     # 1.0.8 → 1.0.9
make bump-plugin-minor
make bump-plugin-major

make bump-lambda-patch
make bump-lambda-minor
make bump-lambda-major

make bump-theme-patch
make bump-theme-minor
make bump-theme-major

make bump-all-patch        # bump all three at once
make bump-all-minor
make bump-all-major
```

The script:

1. Edits the version in `plugin/restart-registry.php`, `lambda/pyproject.toml`,
   or `theme/style.css`.
2. Commits the bump.
3. Creates a scoped tag (`plugin/vX.Y.Z`, `lambda/vX.Y.Z`, `theme/vX.Y.Z`).

Pushing a `lambda/v*` tag triggers the production deploy workflow; pushing a
`plugin/v*` tag triggers the plugin release workflow. `theme/v*` currently has
no automated pipeline — see [Architecture](architecture.md#release-process).

## Building artifacts

```bash
make plugin-build       # plugin/dist/restart-registry.zip (uses .distignore + rsync)
make lambda-build       # lambda/build/lambda.zip (function code only)
make lambda-build-layer # lambda/build/layer.zip  (deps as a Lambda Layer)
make theme-pack         # theme/dist/theRestart.zip
```

## Documentation

```bash
make docs             # mkdocs serve at http://localhost:8000
make docs-build       # mkdocs build → docs/site/
make docs-php-ref     # phpDocumentor for plugin and theme into docs/site/api-reference/
make docs-deploy      # mkdocs gh-deploy (publishes to gh-pages branch)
make docs-screenshots # node docs/scripts/screenshots.js (requires `make up && make seed`)
```

Install the docs dependencies once with `pip install -r docs/requirements.txt`.
