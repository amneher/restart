# Plan: Registry Email Notifications

## Goal
Email the registry owner when an item is marked as purchased, including the purchaser's name if provided. Give owners control over notification preferences from a dedicated section on their account management page.

## Scope
- Purchase notifications only (invite emails already exist)
- Purchaser name is optional (anonymous purchases remain allowed)
- No purchaser email capture for now
- One registry per user assumed; preferences stored as user meta

## Out of Scope
- Digest emails / batched notifications
- "Registry is nearly complete" alerts
- View tracking
- Requiring purchaser login

---

## Changes

### 1. Upgrade the "Mark as Purchased" modal
**File**: `public/js/restart-registry-public.js`, `public/class-restart-registry-public.php`

Replace the `prompt()` dialog with a proper modal:
- Name field (text input, optional, with nudge copy: "Let them know who it's from!")
- Note/message field (textarea, optional, placeholder "Leave a message for the registry owner (optional)")
- "Mark as Purchased" confirm button
- Cancel button
- Submits via the existing `restart_registry_mark_purchased` AJAX action with `purchaser_name`, `purchaser_note`, and `is_anonymous` fields

### 2. Send notification email on purchase
**File**: `includes/class-restart-registry-controller.php`

- Add `private function send_purchase_notification(array $item, string $purchaser_name, string $purchaser_note): void`
  - Looks up the registry post and owner
  - Checks owner's `restart_notify_on_purchase` user meta (default: true)
  - Sends `wp_mail()` to owner with:
    - Warm intro: "Great news! Someone just marked a gift as purchased from your registry."
    - Item name + quantity purchased
    - Purchaser name (or "Someone" if anonymous)
    - Purchaser note (if provided, displayed as a blockquote-style indented block)
    - Closing copy + registry link: "Head to your registry to see what's still needed."
- Update `mark_item_purchased()` signature to accept `$purchaser_note` and call `send_purchase_notification()` after a successful Lambda update

### 3. Notification preferences section on the manage page
**File**: `public/class-restart-registry-public.php`

Add a "Notification Preferences" section rendered within `render_manage_registry()` (below the items list). Initially one setting:
- "Email me when items are purchased" (checkbox, default on)

Persisted as WordPress user meta key `restart_notify_on_purchase` (bool).

### 4. AJAX handler to save notification preferences
**File**: `public/class-restart-registry-public.php`

- Add `ajax_update_notification_prefs()` handler
- Register on `wp_ajax_restart_registry_update_notification_prefs`
- Validates nonce + login, saves user meta

### 5. JS for notification prefs
**File**: `public/js/restart-registry-public.js`

- Wire up the notification prefs checkbox to call the new AJAX handler on change
- Show a brief success/error notice inline

---

## Todo

- [x] Add "Mark as Purchased" modal HTML to guest view (name field with nudge copy, note textarea)
- [x] Replace `prompt()` in JS with modal open + form submit, pass `purchaser_note`
- [x] Add `purchaser_note` param to `ajax_mark_purchased()` and `mark_item_purchased()`
- [x] Add `send_purchase_notification()` to controller
- [x] Call `send_purchase_notification()` from `mark_item_purchased()` after successful update
- [x] Add Notification Preferences section HTML to `render_manage_registry()`
- [x] Add `ajax_update_notification_prefs()` to public class + register hook
- [x] Wire up prefs checkbox in JS
- [ ] Manual test: mark item purchased as guest → owner receives email
- [ ] Manual test: opt out via prefs → no email sent
- [ ] Manual test: anonymous purchase (no name) → email says "Someone"

---

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
- [x] Add `AWS_ROLE_ARN` secret to new repo (repo level + staging + prod environments)
- [x] IAM role trust policy already pointed at `repo:amneher/restart:*` — no change needed

### Phase 1: Restructure
- [x] Create `restart/` directory + `git init`
- [x] Copy `restart-registry/` → `restart/plugin/` (rsync, excluding .git)
- [x] Copy `restart_lambda/` → `restart/lambda/` (rsync, excluding .git)
- [x] Copy `the-restart-theme/` → `restart/theme/` (rsync, excluding .git)
- [x] Copy + update `docker-compose.yml` volume paths to root
- [x] Copy `docker/nginx.conf` to `restart/docker/`
- [x] Write root `Makefile` (all targets listed above)
- [x] Write `scripts/bump.sh`
- [x] Write `scripts/seed.sh` — WP-CLI seeder creating: 1 demo user, 2 registries (8–10 items each, mix of purchased/unpurchased), 4–5 articles, 2–3 gifts/favorites posts, FAQ + About pages from `assets/copy/`, front page config, demo app password
- [x] Write unified `.env.example`
- [x] Write `.gitignore`
- [x] Write unified `.github/workflows/ci.yml`
- [x] Adjust `deploy-staging.yml` + `deploy-prod.yml` working-directory
- [x] Update `release-plugin.yml` for scoped tag pattern + new build path
- [x] Initial commit pushed to github.com/amneher/restart (e37324c)

### Phase 2: Theme test scaffold
- [x] Add `theme/composer.json`, `theme/package.json`, `theme/phpunit.xml.dist`, `theme/jest.config.js`
- [x] Write `theme/tests/bootstrap.php` + `ThemeTestCase.php`
- [x] Write P0 theme PHP tests — 23 tests: shortcodes ×5, AppPassword, Filters ×2 (46b0954)
- [x] Write P0 theme JS tests — 18 tests: start-registry.js, auth.js, contact-modal.js (46b0954)
- [x] Wire into theme Makefile (install, test, test-php, test-js targets)

### Phase 3: Plugin test gaps
- [x] Write `plugin/tests/integration/Controller/AccessControlTest.php` (P0) (34a346d)
- [x] Write `plugin/tests/integration/Controller/MarkItemPurchasedTest.php` + email spy (P0) (34a346d)
- [x] Write `plugin/tests/integration/LambdaClient/*` (P0) (34a346d)
- [ ] Write remaining P1 AJAX handler tests

### Phase 4: Lambda test gaps
- [x] Write `lambda/tests/integration/test_wp_client_resilience.py` (P0) (ae26761)
- [x] Write `lambda/tests/validation/test_xss_inputs.py` (P0) (ae26761)
- [ ] Write remaining P1 gaps (concurrent SQLite writes, orphaned items, env var validation, pagination cap)

### Phase 5: Documentation
- [x] Initialize MkDocs (`mkdocs.yml` + skeleton `docs/docs/`) (9db82ec)
- [x] Write `getting-started.md` (9db82ec)
- [x] Write `architecture.md`, `development.md`, `contributing.md` (9db82ec)
- [x] Write per-project overview pages (9db82ec)
- [x] Write `theme/screenshots.md` with Playwright capture script (9db82ec)
- [x] `docs-screenshots` Makefile target + `docs/scripts/screenshots.js` (9db82ec)
- [x] Set up GitHub Pages — `docs.yml` GHA workflow deploys on every `docs/**` push (3f2ea26)
- [x] All CI workflows passing — staging auto-deploys on push, prod via `workflow_dispatch` (fd3fdf0)
- [x] Bump all GHA actions to Node.js 24 compatible versions (6bab70e)
- [x] Add `workflow_dispatch` to `deploy-staging.yml` for manual re-runs (ab0dae2)

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
