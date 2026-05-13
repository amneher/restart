
# Plan: Monorepo Restructure ("restart/")

## Architectural Decisions

### Git: Fresh monorepo, originals preserved
Each project is **copied** (not moved) into `restart/`. The originals stay intact in their current directories. Each old repo gets a final empty commit: `"Archived: project continues at restart/ monorepo"`. The new `restart/` starts with `git init` and a clean history from day one.

**Old GitHub repos:** After the monorepo is live on GitHub, archive each old repo (GitHub Settings → Archive repository). This makes them read-only and browsable forever but blocks new pushes. Add a README banner in each pointing to the new repo before archiving.

### Docs: MkDocs + Material
MkDocs handles Markdown natively, `mkdocstrings` auto-documents Python, `mkdocs gh-deploy` publishes to GitHub Pages in one command. phpDocumentor generates the PHP API reference as a separate artifact, linked from MkDocs. The lambda's existing Sphinx dev dep can be removed.

### Releases: Scoped git tags + semver bump tool
Each project releases independently via scoped tags:
- `plugin/v1.x.x` → triggers plugin zip CI
- `lambda/v1.x.x` → triggers lambda deploy
- `theme/v1.x.x` → triggers theme pack

A `scripts/bump.sh` script and corresponding Makefile targets handle semver increments. Version strings live in their canonical files:
- Plugin: `plugin/restart-registry.php` header (`Version: X.Y.Z`)
- Lambda: `lambda/pyproject.toml` (`version = "X.Y.Z"`)
- Theme: `theme/style.css` header (`Version: X.Y.Z`)

### Lambda Makefile: Preserved as-is
The existing 477-line lambda Makefile stays untouched. The root Makefile delegates to it via `$(MAKE) -C lambda/ <target>`.

### AWS migration (no infrastructure changes)
The Lambda function, API Gateway, EFS, and all AWS resources stay exactly as-is. Only CI/CD wiring changes:
- Add GitHub Actions secrets to the new repo (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `LAMBDA_FUNCTION_NAME`, `API_GATEWAY_KEY`, etc.)
- If using GitHub OIDC: update the IAM role trust policy from `repo:org/restart-registry:*` → `repo:org/restart:*`

---

## New Directory Structure

```
restart/
├── Makefile                          ← root orchestrator
├── docker-compose.yml                ← copied from plugin; volume paths updated
├── .env.example                      ← all env vars, all three projects
├── .gitignore
├── scripts/
│   ├── bump.sh                       ← semver increment tool
│   └── seed.sh                       ← WP-CLI demo content seeder
├── .github/
│   └── workflows/
│       ├── ci.yml                    ← unified (5 jobs: plugin-php, plugin-js, lambda, theme-php, theme-js)
│       ├── deploy-staging.yml        ← preserved; working-directory: lambda
│       ├── deploy-prod.yml           ← preserved; working-directory: lambda
│       └── release-plugin.yml        ← trigger: tags plugin/v*; builds zip
├── docs/
│   ├── mkdocs.yml
│   └── docs/
│       ├── index.md
│       ├── getting-started.md        ← full setup + first-run guide (together + separately)
│       ├── architecture.md
│       ├── development.md
│       ├── contributing.md
│       ├── plugin/  (overview, controller-api, lambda-client, affiliate-converter, testing)
│       ├── lambda/  (overview, api, auth, database, deploy, testing)
│       └── theme/
│           ├── overview.md
│           ├── screenshots/          ← visual representations of the running theme
│           ├── shortcodes.md
│           ├── templates.md
│           ├── js.md
│           └── testing.md
├── plugin/                           ← copied from restart-registry/
│   ├── Makefile                      ← install, test-php, test-js, build (zip), clean
│   └── [all existing plugin files]
├── lambda/                           ← copied from restart_lambda/
│   ├── Makefile                      ← preserved 477-line Makefile
│   └── [all existing lambda files]
└── theme/                            ← copied from the-restart-theme/
    ├── Makefile                      ← extended: adds install, test-php, test-js
    ├── style.css                     ← theme header (Version: X.Y.Z canonical version source)
    ├── functions.php
    ├── theme.json
    ├── readme.txt
    ├── assets/
    │   ├── js/
    │   │   ├── auth.js
    │   │   ├── contact-modal.js
    │   │   └── start-registry.js
    │   ├── copy/                     ← faq.md, privacy-policy.md, terms-and-conditions.md
    │   └── [images + logos]          ← hero-sunrise.png, icon-*.png, logo-*.svg/png/jpg
    ├── parts/                        ← header.html, footer.html, post-meta.html, comments.html
    ├── patterns/                     ← call-to-action.php, faq-content.php, footer-default.php, etc.
    ├── templates/                    ← front-page.html, page-*.html, single-*.html, archive-*.html, etc.
    ├── dist/                         ← theRestart.zip (build artifact, gitignored)
    ├── composer.json                 ← NEW: PHPUnit + Brain\Monkey
    ├── package.json                  ← NEW: Jest
    ├── phpunit.xml.dist              ← NEW
    ├── jest.config.js                ← NEW
    └── tests/                        ← NEW: full test scaffold
        ├── bootstrap.php
        ├── unit/Shortcodes/
        ├── unit/Filters/
        ├── unit/AppPassword/
        └── js/
```

---

## Root Makefile Targets

**Dev environment**
- `up` / `down` / `logs` / `reset` — docker compose lifecycle
- `seed` — run `scripts/seed.sh` to populate demo content via WP-CLI (users, registries, items, posts, pages)
- `seed-reset` — `reset` + `seed` in sequence (fresh stack + fresh content)
- `wp-snapshot` — delegates to `$(MAKE) -C lambda/ wp-snapshot`

**Testing**
- `test` — all three projects in sequence
- `plugin-test`, `plugin-test-php`, `plugin-test-js`
- `lambda-test`, `lambda-test-staging`, `lambda-test-prod`
- `theme-test`, `theme-test-php`, `theme-test-js`

**Building**
- `plugin-build` — plugin zip
- `lambda-build`, `lambda-build-layer`
- `theme-pack` — theRestart.zip

**Documentation**
- `docs` — `mkdocs serve` (live preview on :8000)
- `docs-build` — build static site
- `docs-php-ref` — run phpDocumentor → `docs/api-reference/`
- `docs-deploy` — `mkdocs gh-deploy`
- `docs-screenshots` — capture theme screenshots via Playwright → `docs/docs/theme/screenshots/`

**Versioning** (delegates to `scripts/bump.sh`)
- `bump-plugin-patch` / `bump-plugin-minor` / `bump-plugin-major`
- `bump-lambda-patch` / `bump-lambda-minor` / `bump-lambda-major`
- `bump-theme-patch` / `bump-theme-minor` / `bump-theme-major`
- `bump-all-patch` / `bump-all-minor` / `bump-all-major` — bumps all three components together
- Each target: updates version string in canonical file, commits, creates scoped tag

**Deployment** (all delegate to `lambda/Makefile`)
- `deploy-staging`, `deploy-prod`
- `publish-layer`, `configure-layer`, `configure-efs`, `configure-env`

**Code quality** (new)
- `install` — composer install + npm ci + uv sync for all three
- `lint` — phpcs (plugin + theme) + ruff (lambda) + eslint (plugin + theme JS)
- `typecheck` — mypy on lambda/app
- `clean` — remove all build artifacts

---

## Test Plan Summary

**~205 new test cases across ~59 files.** Priority order:

**P0 (do first):**
- Plugin: access control matrix (can_view/can_edit), purchase flow + email, invite flow, lambda client auth (API key + Basic — changed recently), ownership enforcement
- Theme: all 5 shortcode renders, App Password create/reuse, registration JS
- Lambda: WP API timeout/5xx handling, XSS + path traversal input validation

**P1 (do next):**
- Plugin: email body content (purchaser name/note), all AJAX wrappers, JS form submissions
- Theme: template hierarchy filter, query loop filter, auth.js + contact-modal.js
- Lambda: concurrent SQLite writes, orphaned items, env var validation, pagination cap

**P2 (do last):**
- Plugin: copy-to-clipboard, admin reconvert UI, edge cases
- Theme: font enqueue, focus restoration
- Lambda: 10k-item pagination benchmark (nightly only, not PR gate)

**Theme test scaffold needed (from zero):**
- `composer.json` with PHPUnit 12 + Brain\Monkey + Mockery
- `package.json` with Jest 29 + jsdom
- `phpunit.xml.dist`, `jest.config.js`, `tests/bootstrap.php`

**Key mocking strategy:**
- Plugin controller tests: `LambdaClientFake` test double (no HTTP)
- Plugin lambda client tests: mock `wp_remote_request` via Brain\Monkey
- Email tests: `Functions\expect('wp_mail')->once()->with(...)` assertions
- Lambda WP client tests: `pytest-httpx` for transport-level mocking
- Theme PHP tests: Brain\Monkey identical to plugin pattern

---

## docker-compose.yml Volume Path Changes

After move to `restart/`:
- `./plugin` → `wp-content/plugins/restart-registry`
- `./theme` → `wp-content/themes/theRestart`
- `./lambda` → `/app` (lambda service)
- `./docker/nginx.conf` → nginx config (move `docker/` to monorepo root)

---

## Todo

### Phase 0: Archive old repos
- [x] Add README banner to each old repo pointing to new monorepo URL (https://github.com/amneher/restart)
- [x] Make final commit in `restart-registry` with README.md (36edacf)
- [x] Make final commit in `restart_lambda` with README.md (cb72102)
- [x] Make final commit in `the-restart-theme` with README.md (5310bfc) — no GitHub remote, local only
- [x] Push archival commits to GitHub for restart-registry and restart_lambda
- [x] Archive `amneher/restart-registry` on GitHub
- [x] Archive `amneher/restart_lambda` on GitHub
- [ ] Add GitHub Actions secrets to new repo after Phase 1 (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, LAMBDA_FUNCTION_NAME, API_GATEWAY_KEY, etc.)
- [ ] Update IAM role trust policy if using GitHub OIDC (repo path: amneher/restart-registry → amneher/restart)

### Phase 1: Restructure
- [ ] Create `restart/` directory + `git init`
- [ ] Copy `restart-registry/` → `restart/plugin/` (rsync, excluding .git)
- [ ] Copy `restart_lambda/` → `restart/lambda/` (rsync, excluding .git)
- [ ] Copy `the-restart-theme/` → `restart/theme/` (rsync, excluding .git)
- [ ] Copy + update `docker-compose.yml` volume paths to root
- [ ] Copy `docker/nginx.conf` to `restart/docker/`
- [ ] Write root `Makefile` (all targets listed above)
- [ ] Write `scripts/bump.sh`
- [ ] Write `scripts/seed.sh` — WP-CLI seeder creating: 1 demo user, 2 registries (8–10 items each, mix of purchased/unpurchased), 4–5 articles, 2–3 gifts/favorites posts, FAQ + About pages from `assets/copy/`, front page config, demo app password
- [ ] Write unified `.env.example`
- [ ] Write `.gitignore`
- [ ] Write unified `.github/workflows/ci.yml`
- [ ] Adjust `deploy-staging.yml` + `deploy-prod.yml` working-directory
- [ ] Update `release-plugin.yml` for scoped tag pattern + new build path
- [ ] Initial `git add -A` + commit: `"chore: initial monorepo structure"`

### Phase 2: Theme test scaffold
- [ ] Add `theme/composer.json`, `theme/package.json`, `theme/phpunit.xml.dist`, `theme/jest.config.js`
- [ ] Write `theme/tests/bootstrap.php`
- [ ] Write P0 theme PHP tests (shortcodes, App Password)
- [ ] Write P0 theme JS tests (start-registry.js, auth.js)
- [ ] Wire into theme Makefile

### Phase 3: Plugin test gaps
- [ ] Write `plugin/tests/integration/Controller/AccessControlTest.php` (P0)
- [ ] Write `plugin/tests/integration/Controller/MarkItemPurchasedTest.php` + email spy (P0)
- [ ] Write `plugin/tests/integration/LambdaClient/*` (P0)
- [ ] Write remaining P1 AJAX handler tests

### Phase 4: Lambda test gaps
- [ ] Add `pytest-httpx` to dev deps
- [ ] Write `lambda/tests/integration/test_wp_client_resilience.py` (P0)
- [ ] Write `lambda/tests/validation/test_xss_inputs.py` (P0)
- [ ] Write remaining P1 gaps

### Phase 5: Documentation
- [ ] Initialize MkDocs (`mkdocs.yml` + skeleton `docs/docs/`)
- [ ] Write `getting-started.md` — prerequisites, first-time setup, running the full stack (`make up && make seed`), running each component standalone
- [ ] Write `architecture.md`, `development.md`, `contributing.md`
- [ ] Write per-project overview pages
- [ ] Write `theme/screenshots.md` — capture screenshots of running theme (homepage, registry page, manage page, guest purchase flow, email notification example); add to `docs/docs/theme/screenshots/`
- [ ] Set up GitHub Pages deploy (`make docs-deploy`)
- [ ] Add `docs-screenshots` Makefile target — runs `make seed` if needed, then Playwright headless capture → `docs/docs/theme/screenshots/`

# Plan: Unified Dev Environment

## Goal
One command (`make up` or `docker compose up`) starts WordPress, the Lambda API, the Restart theme, and the plugin together.

## Approach
Replace `docker-compose.yml` in the registry repo with an all-in-one file. Docker Compose resolves `../` sibling paths to absolute paths at runtime — no symlinks or monorepo restructuring needed.

## Services
| Service | Image | Port | Notes |
|---------|-------|------|-------|
| `nginx` | nginx:1.15.12-alpine | 8083 | WordPress frontend |
| `wordpress` | wordpress:6.9.1-fpm-alpine | — | WP FPM |
| `database` | mysql:8.0 | — | internal only |
| `lambda` | python:3.14-slim | 5000 | uvicorn direct; no lambda nginx proxy in dev |

## Mounts
- Plugin: `.` → `wp-content/plugins/restart-registry` (existing)
- Theme: `../the-restart-theme` → `wp-content/themes/theRestart` (new, in nginx + wordpress)
- Lambda code: `../restart_lambda` → `/app` (new, live-reload via `--reload`)
- Lambda data: `lambda_data` named volume → `/data` (SQLite persistence)

## Env wiring
- WordPress: `RESTART_LAMBDA_URL=http://lambda:5000`
- Lambda: `WP_BASE_URL=http://nginx`

## Files
- `docker-compose.yml` — replace existing (standalone lambda compose unchanged)
- `.env.example` — documents vars; actual `.env` gitignored
- `Makefile` — `up`, `down`, `logs`, `reset` targets

## Todo
- [x] Write new `docker-compose.yml`
- [x] Write `.env.example`
- [x] Write `Makefile`
- [x] Verify `.env` in `.gitignore` (already present)
