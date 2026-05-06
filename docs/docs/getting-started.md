# Getting Started

This page walks you from a fresh clone to a fully-seeded local stack with WordPress,
the Lambda, the plugin, and the theme all running and talking to each other.

## 1. Prerequisites

You need the following on your machine:

| Tool | Minimum version | Why |
|------|------------------|-----|
| **Docker Desktop** (or Docker Engine + Compose v2) | recent | Runs the WordPress, MySQL, nginx, and Lambda containers |
| **Node.js** | 20 | Plugin and theme JS test runners (Jest), screenshot script (Playwright) |
| **PHP** | 8.2 | Plugin and theme PHPUnit suites |
| **Composer** | 2.x | PHP dependencies for plugin and theme |
| **Python** | 3.14 | Lambda runtime — the AWS Lambda function uses 3.14 too |
| **uv** | recent | Python dependency manager used by the Lambda |
| **WP-CLI** | recent | Only needed if you want to run the seed script outside the container |

!!! note "Docker does most of the heavy lifting"
    PHP, Python, and the WordPress install all run inside containers. You only
    need the host versions of PHP/Python/Node when running tests directly on
    the host (the typical TDD loop) instead of inside Docker.

## 2. Clone and install

```bash
git clone https://github.com/amneher/restart.git
cd restart
make install
```

`make install` runs `composer install` and `npm ci` for the plugin and theme,
and `uv sync` for the Lambda. It does not start any containers.

## 3. Environment

Copy the example env file and edit it:

```bash
cp .env.example .env
```

The defaults work for local development. The variables you may want to override:

| Variable | Default | Used by |
|----------|---------|---------|
| `DB_NAME` | `wordpress` | MySQL container |
| `DB_USER` | `wordpress` | MySQL container |
| `DB_PASSWORD` | `wordpress` | MySQL container |
| `DB_ROOT_PASSWORD` | `rootpassword` | MySQL container |
| `WP_BASE_URL` | _(unset for local)_ | Lambda — used for staging/prod deploys, not local |
| `WP_LOCAL_URL` | `http://localhost:8083` | Lambda tests pointed at local WP |
| `WP_LOCAL_APP_PWD` | _(unset)_ | Lambda integration tests pointed at local WP |
| `WP_PROD_USERNAME` / `WP_PROD_APP_PWD` | _(unset)_ | `make lambda-test-prod` |
| `WP_STAGING_BASE_URL` / `WP_STAGING_APP_PWD` | _(unset)_ | `make lambda-test-staging` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | _(unset)_ | `make deploy-staging`, `make deploy-prod` |

!!! note "Container-internal URLs"
    Inside the Docker network the Lambda reaches WordPress as `http://nginx`
    (set on the `lambda` service in `docker-compose.yml`). The plugin reaches
    the Lambda as `http://lambda:5000` via `RESTART_LAMBDA_URL`. Those are set
    on the containers and do not need to be in your `.env`.

### Lambda runtime variables

The Lambda reads a few of its own variables. In local Docker these are set on
the `lambda` service in `docker-compose.yml`; for staging/prod they come from
GitHub Secrets:

| Variable | Default | Purpose |
|----------|---------|---------|
| `WP_BASE_URL` | `http://nginx` (in Docker) | URL the Lambda calls back to for credential validation |
| `DATABASE_PATH` | `/data/registry.db` (Docker) / `/mnt/efs/data.db` (prod) | SQLite file location |
| `WP_AUTH_CACHE_TTL` | `300` (seconds) | How long to cache validated WP credentials |

### Plugin runtime variables

The plugin reads these via `get_option()` first, falling back to env vars:

| Variable / option | Purpose |
|--------------------|---------|
| `RESTART_LAMBDA_URL` / `restart_lambda_url` | Base URL of the Lambda |
| `RESTART_LAMBDA_API_KEY` / `restart_lambda_api_key` | Optional API Gateway `x-api-key` header |
| `RESTART_LAMBDA_USERNAME` / `restart_lambda_username` | WP username for Basic auth to the Lambda |
| `RESTART_LAMBDA_APP_PASSWORD` / `restart_lambda_app_password` | WP application password for Basic auth |

## 4. First run

```bash
make up        # boot the stack (nginx, WordPress, MySQL, Lambda)
# wait ~30s for the WordPress install to settle
make seed      # install theme + plugin, create demo + admin users, seed pages and items
```

When `make seed` finishes you will see:

```
✓ Demo content seeded.

  WordPress:  http://localhost:8083
  Lambda API: http://localhost:5000

  Demo login: demo / demo
  Admin login: admin / admin
```

Open the URLs:

- **Site:** <http://localhost:8083>
- **WP admin:** <http://localhost:8083/wp-admin> (log in as `admin` / `admin`)
- **Lambda health:** <http://localhost:5000/health>
- **Lambda OpenAPI:** <http://localhost:5000/docs>

## 5. Verify

```bash
curl http://localhost:5000/health
# {"status":"healthy","service":"aws-lambda-fastapi"}
```

Then log in as `demo` / `demo` and you should see two seeded registries
("Alex's Fresh Start Registry" and "Alex's New Chapter") with eight Amazon items
each.

## 6. Running components standalone

You do not always need the full stack. Common patterns:

=== "Plugin tests only"

    ```bash
    cd plugin
    composer install
    ./vendor/bin/phpunit          # PHPUnit (Brain\Monkey + integration)
    npm test                      # Jest (admin JS)
    ```

    The plugin's PHPUnit suite never hits the live Lambda — `LambdaClientFake`
    is injected via the controller's constructor.

=== "Lambda tests only"

    ```bash
    cd lambda
    uv sync
    .venv/bin/pytest \
      --ignore=tests/test_registry_e2e.py \
      --ignore=tests/test_registry_wp_integration.py
    ```

    Excluding those two files skips the WordPress-integration tests, which
    require `make up` to be running.

=== "Theme pack"

    ```bash
    cd theme
    make pack          # produces theme/dist/theRestart.zip ready to upload
    ```

=== "Plugin build"

    ```bash
    cd plugin
    make build         # produces plugin/dist/restart-registry.zip
    ```

## 7. Common issues

!!! warning "Port 8083 or 5000 already in use"
    Both ports are exposed by `docker-compose.yml`. If something else on your
    machine has them, edit the `ports:` section before `make up`. The seed
    script honors `WP_URL` and `LAMBDA_URL` env vars if you change them.

!!! warning "Seed script fails on Lambda items"
    The seed script needs WordPress to issue a fresh application password for
    the demo user, and uses that to call the Lambda. If `WP user
    application-password create` fails (for example, because one already exists
    from a previous seed), the script will skip Lambda item creation and print
    a warning. To recover:

    ```bash
    make reset      # drops all volumes
    make up
    make seed
    ```

!!! warning "`Lambda DB not found` or empty registries"
    The Lambda DB lives in the `lambda_data` named volume at `/data/registry.db`
    inside the container. If you mounted a different `DATABASE_PATH` and the
    file did not exist, the FastAPI startup hook (`lifespan` in `app/main.py`)
    creates it. If you see "no such table: items" you can simply restart
    the container — `init_db()` runs at startup and applies all migrations.

!!! warning "WordPress not ready"
    `make seed` waits up to ~60s for WordPress to come up. If you see
    `WordPress did not become ready in time`, run `docker compose logs wordpress`
    to see what is wrong (almost always the DB container is still booting).
