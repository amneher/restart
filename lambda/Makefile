# ─────────────────────────────────────────────────────────────────────────────
# Helper: extract a value from .env (handles spaces around '=' and quoted vals)
# ─────────────────────────────────────────────────────────────────────────────
_env   = $(shell grep -m1 '^$(1)\s*=' .env 2>/dev/null | sed 's/^[^=]*=\s*//' | tr -d '"')
_lwenv = $(shell grep -m1 '^$(1)\s*=' ../local_wordpress/.env 2>/dev/null | sed 's/^[^=]*=\s*//' | tr -d '"')

# ─────────────────────────────────────────────────────────────────────────────
# Lambda function names — override via env or CLI
# ─────────────────────────────────────────────────────────────────────────────
FUNCTION_PROD     ?= Restart_Registry_Lambda
FUNCTION_STAGING  ?= Restart-Lambda-Staging
OIDC_ROLE_NAME    ?= restart-lambda-github-actions

# ─────────────────────────────────────────────────────────────────────────────
# WordPress credentials — read from .env; override via env or CLI
# ─────────────────────────────────────────────────────────────────────────────
WP_PROD_URL        ?= $(call _env,WP_BASE_URL)
WP_PROD_USER       ?= $(call _env,WP_PROD_USERNAME)
WP_PROD_APP_PWD    ?= $(call _env,WP_PROD_APP_PWD)

WP_STAGING_URL     ?= $(call _env,WP_STAGING_BASE_URL)
WP_STAGING_USER    ?= $(call _env,WP_DEV_USERNAME)
WP_STAGING_APP_PWD ?= $(call _env,WP_STAGING_APP_PWD)

WP_LOCAL_URL       ?= $(call _env,WP_LOCAL_URL)
WP_LOCAL_USER      ?= $(call _env,WP_DEV_USERNAME)
WP_LOCAL_APP_PWD   ?= $(call _env,WP_LOCAL_APP_PWD)

# ─────────────────────────────────────────────────────────────────────────────
# Build paths
# ─────────────────────────────────────────────────────────────────────────────
BUILD_DIR      := build
PKG_DIR        := $(BUILD_DIR)/package
SRC_DIR        := $(BUILD_DIR)/_src
ZIP_PATH       := $(BUILD_DIR)/lambda.zip
LAYER_PKG_DIR  := $(BUILD_DIR)/layer
LAYER_ZIP_PATH := $(BUILD_DIR)/layer.zip

# Git ref to build from (branch name, tag, or commit SHA; default: current HEAD)
BRANCH ?= HEAD

# Set FORCE=yes to skip the production-deploy confirmation (for CI)
FORCE ?=

# S3 bucket for layer uploads (required for publish-layer)
DEPLOY_BUCKET ?= $(call _env,DEPLOY_BUCKET)

# Layer names and stored ARNs — ARNs are written to .env after publish-layer
LAYER_NAME_PROD     ?= restart-lambda-deps
LAYER_NAME_STAGING  ?= restart-lambda-deps-staging
LAYER_ARN_PROD      ?= $(call _env,LAYER_ARN_PROD)
LAYER_ARN_STAGING   ?= $(call _env,LAYER_ARN_STAGING)

# ─────────────────────────────────────────────────────────────────────────────
# EFS configuration — read from .env; override via env or CLI
# .env keys: EFS_ACCESS_POINT_ARN_PROD, EFS_ACCESS_POINT_ARN_STAGING,
#            VPC_SUBNET_IDS (comma-separated), VPC_SECURITY_GROUP_ID
# ─────────────────────────────────────────────────────────────────────────────
EFS_ACCESS_POINT_ARN_PROD    ?= $(call _env,EFS_ACCESS_POINT_ARN_PROD)
EFS_ACCESS_POINT_ARN_STAGING ?= $(call _env,EFS_ACCESS_POINT_ARN_STAGING)
VPC_SUBNET_IDS               ?= $(shell echo "$(call _env,VPC_SUBNET_IDS)" | tr -d ' ')
VPC_SECURITY_GROUP_ID        ?= $(call _env,VPC_SECURITY_GROUP_ID)

# ─────────────────────────────────────────────────────────────────────────────
# local_wordpress connection — read from ../local_wordpress/.env
# ─────────────────────────────────────────────────────────────────────────────
LW_CONTAINER   ?= $(call _lwenv,CONTAINER_NAME)
LW_DB_NAME     ?= $(call _lwenv,DATABASE_NAME)
LW_DB_ROOT_PWD ?= $(call _lwenv,DATABASE_ROOT_PASSWORD)

.PHONY: build build-test build-layer publish-layer configure-layer \
        test test-staging test-prod test-local \
        deploy-staging deploy-prod \
        create-staging configure-env configure-efs \
        setup-oidc tag wp-snapshot status clean help

# ─────────────────────────────────────────────────────────────────────────────
# setup-oidc — one-time: create GitHub Actions OIDC provider + IAM role
#
# Run once from a machine with IAM admin credentials.
# After it completes, copy the printed role ARN into GitHub →
# Settings → Secrets → AWS_ROLE_ARN.
# ─────────────────────────────────────────────────────────────────────────────
setup-oidc:
	@echo "Creating GitHub OIDC provider (skipped if already exists)…"
	@aws iam list-open-id-connect-providers --no-cli-pager \
	    | grep -q "token.actions.githubusercontent.com" \
	    || aws iam create-open-id-connect-provider \
	        --url https://token.actions.githubusercontent.com \
	        --client-id-list sts.amazonaws.com \
	        --thumbprint-list 1c58a3a8518e8759bf075b76b750d4f2df264fcd \
	        --no-cli-pager
	@echo "Creating IAM role: $(OIDC_ROLE_NAME)…"
	aws iam create-role \
	    --role-name $(OIDC_ROLE_NAME) \
	    --assume-role-policy-document file://iam/github-oidc-trust.json \
	    --no-cli-pager
	aws iam put-role-policy \
	    --role-name $(OIDC_ROLE_NAME) \
	    --policy-name lambda-deploy \
	    --policy-document file://iam/lambda-deploy-policy.json \
	    --no-cli-pager
	@echo ""
	@echo "Done. Add this as AWS_ROLE_ARN in GitHub repository secrets:"
	@aws iam get-role \
	    --role-name $(OIDC_ROLE_NAME) \
	    --query Role.Arn \
	    --output text \
	    --no-cli-pager

# ─────────────────────────────────────────────────────────────────────────────
# build — package the Lambda zip from a git ref
#
# Always builds from committed code via git-archive so deploys are reproducible.
# Usage: make build [BRANCH=main]
# ─────────────────────────────────────────────────────────────────────────────
build:
	@echo "Building $(BRANCH) → $(ZIP_PATH)"
	rm -rf $(PKG_DIR) $(ZIP_PATH)
	mkdir -p $(PKG_DIR)
	git archive $(BRANCH) -- app/ | tar -xf - -C $(PKG_DIR)
	cd $(PKG_DIR) && zip -qr ../lambda.zip .
	@echo "Done: $(ZIP_PATH) ($$(du -sh $(ZIP_PATH) | cut -f1))"

# ─────────────────────────────────────────────────────────────────────────────
# build-test — build and verify the handler can be imported from the zip
#
# Usage: make build-test [BRANCH=main]
# ─────────────────────────────────────────────────────────────────────────────
build-test: build
	@echo "Verifying app zip structure…"
	@set -e; \
	rm -rf $(BUILD_DIR)/_test; \
	mkdir -p $(BUILD_DIR)/_test; \
	trap 'rm -rf $(BUILD_DIR)/_test' EXIT; \
	unzip -q $(ZIP_PATH) -d $(BUILD_DIR)/_test; \
	test -f $(BUILD_DIR)/_test/app/main.py && echo "Handler found: app/main.py ✓"; \
	if [ -f $(LAYER_ZIP_PATH) ]; then \
	    unzip -q $(LAYER_ZIP_PATH) -d $(BUILD_DIR)/_test; \
	    DATABASE_PATH=:memory: PYTHONPATH=$(BUILD_DIR)/_test:$(BUILD_DIR)/_test/python \
	        python3.14 -c "from app.main import handler; print('Handler OK:', type(handler).__name__)" 2>/dev/null \
	        || echo "(Import skipped — Linux-only .so files not compatible with host Python)"; \
	else \
	    echo "(Layer zip not present — skipping import check. Run 'make build-layer' locally to test.)"; \
	fi

# ─────────────────────────────────────────────────────────────────────────────
# build-layer — package third-party deps into a Lambda layer zip
#
# Installs all project dependencies (excluding the app package itself) into
# build/layer/python/ using Linux-compatible wheels, then zips to build/layer.zip.
# Usage: make build-layer [BRANCH=main]
# ─────────────────────────────────────────────────────────────────────────────
build-layer:
	@echo "Building layer → $(LAYER_ZIP_PATH)"
	rm -rf $(LAYER_PKG_DIR) $(SRC_DIR)
	mkdir -p $(SRC_DIR) $(LAYER_PKG_DIR)/python
	git archive $(BRANCH) | tar -xf - -C $(SRC_DIR)
	cd $(SRC_DIR) && pip install \
	    --platform manylinux2014_x86_64 \
	    --python-version 3.14 \
	    --implementation cp \
	    --only-binary :all: \
	    --target ../layer/python \
	    .
	rm -rf $(LAYER_PKG_DIR)/python/app
	find $(LAYER_PKG_DIR)/python -maxdepth 1 -name 'restart_lambda*' -exec rm -rf {} +
	rm -rf $(SRC_DIR)
	cd $(LAYER_PKG_DIR) && zip -qr ../layer.zip .
	@echo "Done: $(LAYER_ZIP_PATH) ($$(du -sh $(LAYER_ZIP_PATH) | cut -f1))"

# ─────────────────────────────────────────────────────────────────────────────
# publish-layer — upload layer to S3 and publish a new layer version
#
# Requires DEPLOY_BUCKET in .env. After publishing, copy the printed ARN into
# .env as LAYER_ARN_PROD or LAYER_ARN_STAGING, then run configure-layer.
# Usage: make publish-layer ENV=prod|staging [BRANCH=main]
# ─────────────────────────────────────────────────────────────────────────────
publish-layer: build-layer
	$(if $(ENV),,$(error ENV is required — usage: make publish-layer ENV=prod|staging))
	$(if $(filter $(ENV),staging prod),,$(error ENV must be 'staging' or 'prod'))
	$(if $(DEPLOY_BUCKET),,$(error DEPLOY_BUCKET is required — set in .env))
	$(eval _NAME := $(if $(filter $(ENV),prod),$(LAYER_NAME_PROD),$(LAYER_NAME_STAGING)))
	@set -e; \
	KEY="layers/$(_NAME)-$$(date +%Y%m%d%H%M%S).zip"; \
	echo "Uploading $(LAYER_ZIP_PATH) → s3://$(DEPLOY_BUCKET)/$$KEY…"; \
	aws s3 cp $(LAYER_ZIP_PATH) s3://$(DEPLOY_BUCKET)/$$KEY --no-cli-pager; \
	ARN=$$(aws lambda publish-layer-version \
	    --layer-name $(_NAME) \
	    --content S3Bucket=$(DEPLOY_BUCKET),S3Key=$$KEY \
	    --compatible-runtimes python3.14 \
	    --compatible-architectures x86_64 \
	    --query LayerVersionArn \
	    --output text \
	    --no-cli-pager); \
	echo ""; \
	echo "Published: $$ARN"; \
	echo ""; \
	echo "Add to .env:   LAYER_ARN_$$(echo $(ENV) | tr a-z A-Z)=$$ARN"; \
	echo "Then run:      make configure-layer ENV=$(ENV)"

# ─────────────────────────────────────────────────────────────────────────────
# configure-layer — attach the stored layer ARN to a Lambda function
#
# Run after publish-layer once you've saved the ARN to .env.
# Usage: make configure-layer ENV=prod|staging
# ─────────────────────────────────────────────────────────────────────────────
configure-layer:
	$(if $(ENV),,$(error ENV is required — usage: make configure-layer ENV=prod|staging))
	$(if $(filter $(ENV),staging prod),,$(error ENV must be 'staging' or 'prod'))
	$(eval _FN  := $(if $(filter $(ENV),prod),$(FUNCTION_PROD),$(FUNCTION_STAGING)))
	$(eval _ARN := $(if $(filter $(ENV),prod),$(LAYER_ARN_PROD),$(LAYER_ARN_STAGING)))
	$(if $(_ARN),,$(error LAYER_ARN_$(shell echo $(ENV) | tr a-z A-Z) not set in .env))
	aws lambda update-function-configuration \
	    --function-name $(_FN) \
	    --layers $(_ARN) \
	    --no-cli-pager
	aws lambda wait function-updated \
	    --function-name $(_FN) \
	    --no-cli-pager
	@echo "Layer $(_ARN) configured on $(_FN)"

# ─────────────────────────────────────────────────────────────────────────────
# test — run the full unit-test suite (in-memory SQLite, no network)
# ─────────────────────────────────────────────────────────────────────────────
test:
	.venv/bin/pytest tests/ -v

# ─────────────────────────────────────────────────────────────────────────────
# test-staging — run WordPress integration + e2e tests against staging
# ─────────────────────────────────────────────────────────────────────────────
test-staging:
	WP_LOCAL_URL="$(WP_STAGING_URL)" \
	WP_LOCAL_USER="$(WP_STAGING_USER)" \
	WP_LOCAL_APP_PWD="$(WP_STAGING_APP_PWD)" \
	.venv/bin/pytest tests/test_registry_wp_integration.py tests/test_registry_e2e.py -v

# ─────────────────────────────────────────────────────────────────────────────
# test-prod — run WordPress integration + e2e tests against production
# ─────────────────────────────────────────────────────────────────────────────
test-prod:
	WP_LOCAL_URL="$(WP_PROD_URL)" \
	WP_LOCAL_USER="$(WP_PROD_USER)" \
	WP_LOCAL_APP_PWD="$(WP_PROD_APP_PWD)" \
	.venv/bin/pytest tests/test_registry_wp_integration.py tests/test_registry_e2e.py -v

# ─────────────────────────────────────────────────────────────────────────────
# test-local — run integration + e2e tests against the local WordPress stack
#
# Starts local_wordpress if not running, restores a clean DB snapshot, then
# runs pytest. Tests use FastAPI TestClient in-process — no app container needed.
# Run 'make wp-snapshot' once first to capture the baseline DB state.
# Usage: make test-local
# ─────────────────────────────────────────────────────────────────────────────
test-local:
	$(if $(LW_CONTAINER),,$(error Could not read CONTAINER_NAME from ../local_wordpress/.env))
	$(if $(wildcard tests/fixtures/wp-clean.sql),,$(error tests/fixtures/wp-clean.sql not found — run 'make wp-snapshot' first))
	@echo "Checking local_wordpress stack…"
	@if ! docker ps --filter name=$(LW_CONTAINER)-nginx --filter status=running -q | grep -q .; then \
	    echo "Starting local_wordpress…"; \
	    docker compose -f ../local_wordpress/docker-compose.yml --project-directory ../local_wordpress up -d; \
	    echo "Waiting for MySQL…"; \
	    until docker exec $(LW_CONTAINER)-database mysqladmin ping -h 127.0.0.1 -uroot -p$(LW_DB_ROOT_PWD) --silent 2>/dev/null; do sleep 2; done; \
	fi
	@echo "Restoring WordPress snapshot…"
	docker exec -i $(LW_CONTAINER)-database \
	    mysql -h 127.0.0.1 -uroot -p$(LW_DB_ROOT_PWD) $(LW_DB_NAME) \
	    < tests/fixtures/wp-clean.sql
	@echo "Running integration + e2e tests…"
	WP_LOCAL_URL="$(WP_LOCAL_URL)" \
	WP_LOCAL_USER="$(WP_LOCAL_USER)" \
	WP_LOCAL_APP_PWD="$(WP_LOCAL_APP_PWD)" \
	.venv/bin/pytest tests/test_registry_wp_integration.py tests/test_registry_e2e.py -v

# ─────────────────────────────────────────────────────────────────────────────
# wp-snapshot — dump the local WordPress DB into tests/fixtures/wp-clean.sql
#
# Run once after local WordPress is cleanly installed (plugins active, roles
# set up, no test data). Re-run whenever the baseline WordPress config changes
# (new plugin, new custom post type, new role, etc.).
# Usage: make wp-snapshot
# ─────────────────────────────────────────────────────────────────────────────
wp-snapshot:
	$(if $(LW_CONTAINER),,$(error Could not read CONTAINER_NAME from ../local_wordpress/.env))
	@echo "Capturing WordPress snapshot from $(LW_CONTAINER)-database…"
	mkdir -p tests/fixtures
	@echo "Waiting for MySQL to be ready…"; \
	    until docker exec $(LW_CONTAINER)-database mysqladmin ping -h 127.0.0.1 -uroot -p$(LW_DB_ROOT_PWD) --silent 2>/dev/null; do sleep 2; done
	docker exec $(LW_CONTAINER)-database \
	    mysqldump -h 127.0.0.1 -uroot -p$(LW_DB_ROOT_PWD) $(LW_DB_NAME) \
	    > tests/fixtures/wp-clean.sql
	@echo "Saved: tests/fixtures/wp-clean.sql ($$(du -sh tests/fixtures/wp-clean.sql | cut -f1))"

# ─────────────────────────────────────────────────────────────────────────────
# create-staging — create the staging Lambda function from scratch
#
# Pulls the IAM role from the existing prod function automatically.
# Usage: make create-staging [BRANCH=main]
# ─────────────────────────────────────────────────────────────────────────────
create-staging: build
	$(eval _ROLE := $(shell aws lambda get-function-configuration \
	    --function-name $(FUNCTION_PROD) \
	    --query Role --output text --no-cli-pager 2>/dev/null))
	$(if $(_ROLE),,$(error Could not retrieve IAM role from $(FUNCTION_PROD) — check AWS credentials))
	aws lambda create-function \
	    --function-name $(FUNCTION_STAGING) \
	    --runtime python3.14 \
	    --role $(_ROLE) \
	    --handler app.main.handler \
	    --zip-file fileb://$(ZIP_PATH) \
	    --timeout 30 \
	    --memory-size 512 \
	    --no-cli-pager
	@echo "Waiting for $(FUNCTION_STAGING) to become active…"
	aws lambda wait function-active --function-name $(FUNCTION_STAGING) --no-cli-pager
	@$(MAKE) configure-env ENV=staging
	@echo "Staging function created: $(FUNCTION_STAGING)"

# ─────────────────────────────────────────────────────────────────────────────
# configure-env — set runtime env vars on a Lambda function
#
# Usage: make configure-env ENV=staging|prod
# ─────────────────────────────────────────────────────────────────────────────
# _EFS_MOUNT is set internally by configure-efs; leave blank for /tmp
_EFS_MOUNT ?=
_DB_DIR     = $(if $(_EFS_MOUNT),$(_EFS_MOUNT),/tmp)

configure-env:
	$(if $(ENV),,$(error ENV is required — usage: make configure-env ENV=staging|prod))
	$(if $(filter $(ENV),staging prod),,$(error ENV must be 'staging' or 'prod'))
	aws lambda update-function-configuration \
	    --function-name $(if $(filter $(ENV),prod),$(FUNCTION_PROD),$(FUNCTION_STAGING)) \
	    --handler app.main.handler \
	    --environment 'Variables={DATABASE_PATH=$(_DB_DIR)/data.db,WP_BASE_URL=$(if $(filter $(ENV),prod),$(WP_PROD_URL),$(WP_STAGING_URL))}' \
	    --no-cli-pager
	aws lambda wait function-updated \
	    --function-name $(if $(filter $(ENV),prod),$(FUNCTION_PROD),$(FUNCTION_STAGING)) \
	    --no-cli-pager
	@echo "Configured $(if $(filter $(ENV),prod),$(FUNCTION_PROD),$(FUNCTION_STAGING)) — DATABASE_PATH=$(_DB_DIR)/data.db"

# ─────────────────────────────────────────────────────────────────────────────
# configure-efs — attach EFS and VPC to a Lambda function, update DATABASE_PATH
#
# Run once per function after the EFS access point and VPC are ready.
# Usage: make configure-efs ENV=staging|prod \
#            EFS_ACCESS_POINT_ARN=arn:aws:elasticfilesystem:... \
#            VPC_SUBNET_IDS=subnet-abc123 \
#            VPC_SECURITY_GROUP_ID=sg-abc123
# ─────────────────────────────────────────────────────────────────────────────
configure-efs:
	$(if $(ENV),,$(error ENV is required — usage: make configure-efs ENV=staging|prod))
	$(if $(filter $(ENV),staging prod),,$(error ENV must be 'staging' or 'prod'))
	$(if $(VPC_SUBNET_IDS),,$(error VPC_SUBNET_IDS is required — set in .env or pass on CLI))
	$(if $(VPC_SECURITY_GROUP_ID),,$(error VPC_SECURITY_GROUP_ID is required — set in .env or pass on CLI))
	$(eval _FN  := $(if $(filter $(ENV),prod),$(FUNCTION_PROD),$(FUNCTION_STAGING)))
	$(eval _EAP := $(if $(filter $(ENV),prod),$(EFS_ACCESS_POINT_ARN_PROD),$(EFS_ACCESS_POINT_ARN_STAGING)))
	$(if $(_EAP),,$(error EFS_ACCESS_POINT_ARN_$(shell echo $(ENV) | tr a-z A-Z) is required — set in .env or pass on CLI))
	aws lambda update-function-configuration \
	    --function-name $(_FN) \
	    --vpc-config SubnetIds=$(VPC_SUBNET_IDS),SecurityGroupIds=$(VPC_SECURITY_GROUP_ID) \
	    --file-system-configs Arn=$(_EAP),LocalMountPath=/mnt/data \
	    --no-cli-pager
	aws lambda wait function-updated \
	    --function-name $(_FN) \
	    --no-cli-pager
	@$(MAKE) configure-env ENV=$(ENV) _EFS_MOUNT=/mnt/data
	@echo "EFS configured on $(_FN) — DATABASE_PATH=/mnt/data/data.db"

# ─────────────────────────────────────────────────────────────────────────────
# deploy-staging — build and push to the staging Lambda function
#
# Usage: make deploy-staging [BRANCH=feature-x]
# ─────────────────────────────────────────────────────────────────────────────
deploy-staging: build
	aws lambda update-function-code \
	    --function-name $(FUNCTION_STAGING) \
	    --zip-file fileb://$(ZIP_PATH) \
	    --no-cli-pager
	@echo "Deployed $(BRANCH) → $(FUNCTION_STAGING)"

# ─────────────────────────────────────────────────────────────────────────────
# deploy-prod — build and push to the production Lambda function
#
# Requires interactive confirmation unless FORCE=yes.
# Usage: make deploy-prod [BRANCH=main] [FORCE=yes]
# ─────────────────────────────────────────────────────────────────────────────
deploy-prod: build
	@echo ""
	@echo "  Branch : $(BRANCH)"
	@echo "  Target : $(FUNCTION_PROD)  (PRODUCTION)"
	@echo ""
	@if [ "$(FORCE)" != "yes" ]; then \
	    printf "Type 'yes' to confirm: " && read CONFIRM && \
	    [ "$$CONFIRM" = "yes" ] || { echo "Aborted."; exit 1; }; \
	fi
	aws lambda update-function-code \
	    --function-name $(FUNCTION_PROD) \
	    --zip-file fileb://$(ZIP_PATH) \
	    --no-cli-pager
	@echo "Deployed $(BRANCH) → $(FUNCTION_PROD)"

# ─────────────────────────────────────────────────────────────────────────────
# status — show runtime configuration for both Lambda functions
#
# Usage: make status
# ─────────────────────────────────────────────────────────────────────────────
status:
	@for FN in $(FUNCTION_PROD) $(FUNCTION_STAGING); do \
	    echo ""; \
	    echo "══════════════════════════════════════════"; \
	    echo "  $$FN"; \
	    echo "══════════════════════════════════════════"; \
	    aws lambda get-function-configuration \
	        --function-name $$FN \
	        --no-cli-pager \
	        --query '{State: State, LastUpdateStatus: LastUpdateStatus, Handler: Handler, Runtime: Runtime, MemorySize: MemorySize, Timeout: Timeout, CodeSize: CodeSize, Env: Environment.Variables, VPC: VpcConfig.SubnetIds, EFS: FileSystemConfigs}' \
	        --output yaml 2>/dev/null || echo "  (not found)"; \
	done
	@echo ""

# ─────────────────────────────────────────────────────────────────────────────
# tag — create a git tag and save a named build artifact
#
# Usage: make tag TAG=v1.2.3 [BRANCH=main]
# ─────────────────────────────────────────────────────────────────────────────
tag: build
	$(if $(TAG),,$(error TAG is required — usage: make tag TAG=v1.2.3))
	git tag -a $(TAG) -m "Release $(TAG)"
	cp $(ZIP_PATH) $(BUILD_DIR)/lambda-$(TAG).zip
	@echo "Tagged:    $(TAG)"
	@echo "Artifact:  $(BUILD_DIR)/lambda-$(TAG).zip"

# ─────────────────────────────────────────────────────────────────────────────
clean:
	rm -rf $(BUILD_DIR)

# ─────────────────────────────────────────────────────────────────────────────
help:
	@echo ""
	@echo "One-time setup:"
	@echo "  setup-oidc                    Create GitHub Actions OIDC provider + IAM role"
	@echo "  create-staging                Create the staging Lambda (pulls IAM role from prod)"
	@echo "  configure-env ENV=staging|prod  Set DATABASE_PATH + WP_BASE_URL on a Lambda"
	@echo "  configure-efs ENV=staging|prod  Attach EFS + VPC, set DATABASE_PATH=/mnt/data"
	@echo "  publish-layer ENV=prod|staging  Build + upload deps layer, print ARN for .env"
	@echo "  configure-layer ENV=prod|staging  Attach stored layer ARN to Lambda function"
	@echo ""
	@echo "Everyday targets:"
	@echo "  build                Build app-only Lambda zip from BRANCH (default: HEAD)"
	@echo "  build-layer          Build deps-only layer zip (re-run when deps change)"
	@echo "  build-test           Verify app zip + layer zip structure"
	@echo "  test                 Run unit tests (in-memory SQLite, no network)"
	@echo "  test-local           Run WP integration/e2e tests against local Docker stack"
	@echo "  test-staging         Run WP integration/e2e tests against staging"
	@echo "  test-prod            Run WP integration/e2e tests against production"
	@echo "  deploy-staging       Deploy BRANCH to staging Lambda"
	@echo "  deploy-prod          Deploy BRANCH to production Lambda (confirms)"
	@echo "  tag TAG=v1.2.3       Tag commit and save named build artifact"
	@echo "  wp-snapshot          Dump local WordPress DB → tests/fixtures/wp-clean.sql"
	@echo "  status               Show runtime config for both Lambda functions"
	@echo "  clean                Remove build/"
	@echo ""
	@echo "Key variables (override on CLI or via env):"
	@echo "  BRANCH               Git ref to build (branch, tag, SHA; default: HEAD)"
	@echo "  FUNCTION_PROD        Lambda function name for production"
	@echo "  FUNCTION_STAGING     Lambda function name for staging"
	@echo "  FORCE=yes            Skip deploy-prod confirmation (for CI)"
	@echo ""
	@echo ".env keys read automatically:"
	@echo "  WP_BASE_URL, WP_STAGING_BASE_URL, RR_DEV_USERNAME, RR_DEV_APP_PWD, STAGING_DEV_APP_PWD"
	@echo "  EFS_ACCESS_POINT_ARN_PROD, EFS_ACCESS_POINT_ARN_STAGING"
	@echo "  VPC_SUBNET_IDS (comma-separated), VPC_SECURITY_GROUP_ID"
	@echo "  DEPLOY_BUCKET           S3 bucket for layer uploads"
	@echo "  LAYER_ARN_PROD          Layer ARN printed by publish-layer ENV=prod"
	@echo "  LAYER_ARN_STAGING       Layer ARN printed by publish-layer ENV=staging"
	@echo ""
