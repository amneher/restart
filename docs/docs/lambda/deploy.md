# Deployment

The Lambda is deployed in two pieces:

1. **The function code** — the FastAPI app, packaged as a small zip
   (`lambda/build/lambda.zip`).
2. **The dependency layer** — `wp-python`, `mangum`, `pydantic`, `fastapi`,
   etc., packaged as a Lambda Layer (`lambda/build/layer.zip`). This is
   updated separately so most function-code deploys are sub-megabyte.

Both are built and pushed by the `lambda/Makefile`. The top-level Makefile
delegates:

```bash
make lambda-build         # → make -C lambda/ build
make lambda-build-layer   # → make -C lambda/ build-layer
make publish-layer        # → make -C lambda/ publish-layer
make deploy-staging       # → make -C lambda/ deploy-staging FORCE=yes
make deploy-prod          # → make -C lambda/ deploy-prod FORCE=yes
```

## Manual deploy

```bash
# 1. Build the zips
make lambda-build
make lambda-build-layer

# 2. Publish a new layer version (uploads via S3, prints the new ARN)
make publish-layer

# 3. Wire the layer to the function
make configure-layer

# 4. Push the function code
make deploy-staging       # to FUNCTION_STAGING (default Restart-Lambda-Staging)
make deploy-prod          # to FUNCTION_PROD    (default Restart_Registry_Lambda)
```

The function name overrides:

```bash
FUNCTION_PROD     ?= Restart_Registry_Lambda
FUNCTION_STAGING  ?= Restart-Lambda-Staging
```

## CI / GitHub Actions

The CI workflows under `.github/workflows/` automate the same flow:

| Workflow | Trigger | What it does |
|----------|---------|--------------|
| `ci.yml` | Push / PR | Runs `make test` and `make lint`. |
| `deploy-staging.yml` | Push to `main` | Builds and deploys to the staging Lambda. |
| `deploy-prod.yml` | Push of a `lambda/v*` tag | Builds + publishes a new layer + deploys to the prod Lambda. |
| `release-plugin.yml` | Push of a `plugin/v*` tag | Builds the plugin zip and attaches to a GitHub Release. |

### Required GitHub secrets

| Secret | Purpose |
|--------|---------|
| `AWS_ACCESS_KEY_ID` | IAM user for Lambda + S3 (or use OIDC, see below) |
| `AWS_SECRET_ACCESS_KEY` | matching secret |
| `AWS_ROLE_ARN` | OIDC role (preferred over long-lived keys) |
| `LAMBDA_FUNCTION_NAME` | Function name override (used in deploy workflows) |
| `API_GATEWAY_KEY` | Static `x-api-key` value injected into the function env |
| `WP_BASIC_AUTH_USER` | WP user for the Lambda's own callbacks |
| `WP_BASIC_AUTH_PASSWORD` | Matching application password |
| `WP_BASE_URL` | URL of the WordPress install for credential validation |
| `DATABASE_PATH` | `/mnt/efs/data.db` on prod |

OIDC is set up once via `make -C lambda/ setup-oidc`, which creates the
`token.actions.githubusercontent.com` provider and an IAM role
(`restart-lambda-github-actions` by default). After setup, copy the printed
role ARN into `AWS_ROLE_ARN` and the workflows can drop the long-lived AWS
keys.

### EFS configuration

The function runs in a VPC and mounts an EFS access point. These come from
`.env` (or are passed on the make command line):

```
EFS_ACCESS_POINT_ARN_PROD=arn:aws:elasticfilesystem:...:access-point/...
EFS_ACCESS_POINT_ARN_STAGING=arn:aws:elasticfilesystem:...:access-point/...
VPC_SUBNET_IDS=subnet-aaa,subnet-bbb
VPC_SECURITY_GROUP_ID=sg-...
```

`make configure-efs` wires the access point and VPC config to the Lambda
function. Run it once after the initial create.

### Function environment

`make configure-env` writes the runtime environment to the Lambda function
config:

- `WP_BASE_URL`
- `WP_BASIC_AUTH_USER` / `WP_BASIC_AUTH_PASSWORD` (only used when the Lambda
  needs to call WP itself; per-request auth is taken from the inbound header)
- `WP_AUTH_CACHE_TTL` (optional override, default 300)
- `DATABASE_PATH` (default `/mnt/efs/data.db` in prod)
- `LOG_LEVEL` (optional)

## Tag-driven prod deploy

Bumping the lambda version creates the right tag:

```bash
make bump-lambda-patch     # → 1.0.3 → 1.0.4, commits, tags lambda/v1.0.4
git push --follow-tags
```

The push of `lambda/v1.0.4` triggers `deploy-prod.yml`. The workflow:

1. Sets up Python 3.14.
2. `uv sync --frozen` to install deps reproducibly from `uv.lock`.
3. Runs the test suite (excluding live-WP integration tests).
4. Builds `lambda.zip` and `layer.zip`.
5. Publishes a new layer version, captures the ARN.
6. Updates the Lambda function code and configuration.
7. (Optional) runs a smoke test against the prod URL.

!!! warning "FORCE=yes"
    Both `deploy-staging` and `deploy-prod` make targets pass `FORCE=yes` so
    the prod-deploy confirmation prompt is skipped in CI. Keep that in mind
    when running them locally — the make targets will deploy without asking.

## Rollback

The fastest rollback is to redeploy a previous tag:

```bash
git checkout lambda/v1.0.2
make deploy-prod
```

The Lambda function publishes versioned aliases for each deploy, so an even
faster path is to flip the alias in the AWS console.

## Local Lambda smoke test

Once `make up` is running:

```bash
curl -s http://localhost:5000/health
# {"status":"healthy","service":"aws-lambda-fastapi"}
```

Or open <http://localhost:5000/docs> for Swagger UI and try the endpoints
interactively.
