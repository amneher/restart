.DEFAULT_GOAL := help

.PHONY: help up down logs reset seed seed-reset \
        test plugin-test plugin-test-php plugin-test-js \
        lambda-test lambda-test-staging lambda-test-prod \
        theme-test theme-test-php theme-test-js \
        install lint typecheck clean \
        plugin-build lambda-build lambda-build-layer theme-pack \
        docs docs-build docs-php-ref docs-deploy docs-screenshots \
        deploy-staging deploy-prod publish-layer configure-layer configure-efs configure-env \
        bump-plugin-patch bump-plugin-minor bump-plugin-major \
        bump-lambda-patch bump-lambda-minor bump-lambda-major \
        bump-theme-patch bump-theme-minor bump-theme-major \
        bump-all-patch bump-all-minor bump-all-major \
        wp-snapshot

# ── Dev environment ───────────────────────────────────────────────────────────

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

reset:
	docker compose down -v
	docker compose up -d

seed:
	./scripts/seed.sh

seed-reset: reset
	@echo "Waiting for stack to be healthy..."
	@sleep 10
	./scripts/seed.sh

wp-snapshot:
	$(MAKE) -C lambda/ wp-snapshot

# ── Testing ───────────────────────────────────────────────────────────────────

test: plugin-test lambda-test theme-test

plugin-test: plugin-test-php plugin-test-js

plugin-test-php:
	cd plugin && ./vendor/bin/phpunit

plugin-test-js:
	cd plugin && npm test

lambda-test:
	$(MAKE) -C lambda/ test

lambda-test-staging:
	$(MAKE) -C lambda/ test-staging

lambda-test-prod:
	$(MAKE) -C lambda/ test-prod

theme-test: theme-test-php theme-test-js

theme-test-php:
	cd theme && ./vendor/bin/phpunit

theme-test-js:
	cd theme && npm test

# ── Building ──────────────────────────────────────────────────────────────────

plugin-build:
	cd plugin && $(MAKE) build

lambda-build:
	$(MAKE) -C lambda/ build

lambda-build-layer:
	$(MAKE) -C lambda/ build-layer

theme-pack:
	cd theme && $(MAKE) pack

# ── Code quality ──────────────────────────────────────────────────────────────

install:
	cd plugin && composer install --no-interaction && npm ci
	cd lambda && uv sync
	cd theme && composer install --no-interaction && npm ci

lint:
	cd plugin && ./vendor/bin/phpcs
	cd lambda && uv run ruff check .
	cd theme && ./vendor/bin/phpcs

typecheck:
	cd lambda && uv run mypy app/

clean:
	rm -rf plugin/dist theme/dist lambda/build docs/site
	cd plugin && rm -rf vendor node_modules
	cd lambda && rm -rf .venv
	cd theme && rm -rf vendor node_modules

# ── Documentation ─────────────────────────────────────────────────────────────

docs:
	cd docs && mkdocs serve

docs-build:
	cd docs && mkdocs build

docs-php-ref:
	docker run --rm -v "$(PWD):/data" phpdoc/phpdoc:3 \
		-d plugin/includes -d plugin/public -d plugin/admin \
		-t docs/site/api-reference/plugin --template=default
	docker run --rm -v "$(PWD):/data" phpdoc/phpdoc:3 \
		-d theme \
		-t docs/site/api-reference/theme --template=default

docs-deploy:
	cd docs && mkdocs gh-deploy

docs-screenshots:
	@which node >/dev/null 2>&1 || (echo "Node.js required for screenshots"; exit 1)
	@cd docs && npm install --silent
	node docs/scripts/screenshots.js

# ── Deployment (delegates to lambda/Makefile) ─────────────────────────────────

deploy-staging:
	$(MAKE) -C lambda/ deploy-staging FORCE=yes

deploy-prod:
	$(MAKE) -C lambda/ deploy-prod FORCE=yes

publish-layer:
	$(MAKE) -C lambda/ publish-layer

configure-layer:
	$(MAKE) -C lambda/ configure-layer

configure-efs:
	$(MAKE) -C lambda/ configure-efs

configure-env:
	$(MAKE) -C lambda/ configure-env

# ── Versioning ────────────────────────────────────────────────────────────────

bump-plugin-patch:
	./scripts/bump.sh plugin patch

bump-plugin-minor:
	./scripts/bump.sh plugin minor

bump-plugin-major:
	./scripts/bump.sh plugin major

bump-lambda-patch:
	./scripts/bump.sh lambda patch

bump-lambda-minor:
	./scripts/bump.sh lambda minor

bump-lambda-major:
	./scripts/bump.sh lambda major

bump-theme-patch:
	./scripts/bump.sh theme patch

bump-theme-minor:
	./scripts/bump.sh theme minor

bump-theme-major:
	./scripts/bump.sh theme major

bump-all-patch:
	./scripts/bump.sh all patch

bump-all-minor:
	./scripts/bump.sh all minor

bump-all-major:
	./scripts/bump.sh all major

# ── Help ──────────────────────────────────────────────────────────────────────

help:
	@echo ""
	@echo "Dev environment:"
	@echo "  up                   Start the local Docker stack"
	@echo "  down                 Stop the local Docker stack"
	@echo "  logs                 Follow logs from the local stack"
	@echo "  reset                Tear down volumes and restart the stack"
	@echo "  seed                 Run scripts/seed.sh against the local stack"
	@echo "  seed-reset           Reset the stack and re-seed it"
	@echo "  wp-snapshot          Capture local WordPress DB → lambda/tests/fixtures/wp-clean.sql"
	@echo ""
	@echo "Testing:"
	@echo "  test                 Run plugin, lambda, and theme test suites"
	@echo "  plugin-test          Run plugin PHP + JS tests"
	@echo "  plugin-test-php      Run plugin PHPUnit suite"
	@echo "  plugin-test-js       Run plugin JS tests"
	@echo "  lambda-test          Run lambda unit tests (in-memory SQLite)"
	@echo "  lambda-test-staging  Run lambda WP integration/e2e tests against staging"
	@echo "  lambda-test-prod     Run lambda WP integration/e2e tests against production"
	@echo "  theme-test           Run theme PHP + JS tests"
	@echo "  theme-test-php       Run theme PHPUnit suite"
	@echo "  theme-test-js        Run theme JS tests"
	@echo ""
	@echo "Building:"
	@echo "  plugin-build         Build the plugin distribution"
	@echo "  lambda-build         Build the lambda zip"
	@echo "  lambda-build-layer   Build the lambda deps layer zip"
	@echo "  theme-pack           Pack the theme distribution"
	@echo ""
	@echo "Code quality:"
	@echo "  install              Install plugin, lambda, and theme dependencies"
	@echo "  lint                 Run PHPCS (plugin + theme) and ruff (lambda)"
	@echo "  typecheck            Run mypy on lambda/app"
	@echo "  clean                Remove build artifacts and vendored deps"
	@echo ""
	@echo "Documentation:"
	@echo "  docs                 Serve docs locally with mkdocs"
	@echo "  docs-build           Build the docs site"
	@echo "  docs-php-ref         Generate PHP API reference (plugin + theme)"
	@echo "  docs-deploy          Deploy docs via mkdocs gh-deploy"
	@echo "  docs-screenshots     Generate docs screenshots"
	@echo ""
	@echo "Deployment (delegates to lambda/Makefile):"
	@echo "  deploy-staging       Deploy lambda to staging (FORCE=yes)"
	@echo "  deploy-prod          Deploy lambda to production (FORCE=yes)"
	@echo "  publish-layer        Publish lambda deps layer (ENV=prod|staging)"
	@echo "  configure-layer      Attach stored layer ARN (ENV=prod|staging)"
	@echo "  configure-efs        Attach EFS + VPC to a Lambda (ENV=prod|staging)"
	@echo "  configure-env        Set runtime env vars on a Lambda (ENV=prod|staging)"
	@echo ""
	@echo "Versioning (scripts/bump.sh):"
	@echo "  bump-plugin-{patch,minor,major}  Bump plugin version"
	@echo "  bump-lambda-{patch,minor,major}  Bump lambda version"
	@echo "  bump-theme-{patch,minor,major}   Bump theme version"
	@echo "  bump-all-{patch,minor,major}     Bump all three versions"
	@echo ""
	@echo "For lambda-specific targets, run:  make -C lambda help"
	@echo ""
