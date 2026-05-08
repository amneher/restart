# Plan: Theme branding + layout pass

Branch: `theme/branding-and-layout`

## Goal
Standardize the brand on **"the ReStart"** with a single hand-built SVG logo (matching the user-supplied PNG: a circle with a refresh-arrow notch at the top and a lowercase serif "r" inside), replace ad-hoc logo references throughout the theme, ship favicon and Open Graph variants, and do a one-pass layout/alignment audit across all 21 templates.

## Source of truth for the logo
`~/Pictures/Screenshots/Screenshot From 2026-05-07 20-47-07.png`. Hand-rebuilt as SVG (geometric primitives, no trace).

## Out of scope
- New illustrations or photography. Existing `hero-sunrise.png`, `content-*.jpg` stay as-is.
- Color-palette changes. The current mint / teal / dark-navy palette holds.
- Typography changes. Libre Caslon + Montserrat stay.
- Lambda or plugin code.
# Plan: Bake wp-cli into the dev WordPress image

## Goal
`make up && make seed` works end-to-end on a fresh checkout without anyone manually installing wp-cli inside the running container. Seeding currently fails with `exec: "wp": executable file not found in $PATH` because `wordpress:6.9.1-fpm-alpine` (the upstream image) doesn't ship wp-cli.

## Constraints / context
- `scripts/seed.sh` invokes `wp` via `docker compose exec -T wordpress wp --allow-root` — many calls per seed run, so latency per call matters. `docker compose run` is ~10× slower than `exec` per invocation; rules out a one-shot service.
- Existing `wordpress` named volume already has the running site state. The fix must not require a volume reset.
- Keep the WordPress version pin (`6.9.1-fpm-alpine`) stable; this PR is only about adding wp-cli, not bumping WP.
- `docker/` already houses dev-stack config (e.g. `docker/nginx.conf`), so a Dockerfile fits there.

## Out of scope
- Production / staging deploys (those don't run wp-cli at runtime).
- Bumping the WordPress version.
- Adding a Composer-managed `roave/wp-cli` or other PHP-package approach — image-level install is simpler.

## Approach (recommended)

## Confirmed decisions
- OG description: "Gift registries for life's fresh starts."
- Mobile (≤ 480 px): wordmark hidden, icon-only.

## Phase 1 — Hand-built logo SVG + variants

Build the logo from primitives so it's crisp at any size and tiny in bytes:
- **Outer ring**: `<circle>` with stroke.
- **Refresh arrow**: short `<path>` at ~10–11 o'clock with a chevron head, breaking the ring.
- **Glyph**: serif lowercase "r" centered, drawn as a `<path>` (not `<text>`) so it renders identically without webfonts.

Files in `theme/assets/`:
- `logo.svg` — dark on transparent (replaces existing).
- `logo-light.svg` — white on transparent (replaces existing).
- `logo-mark.svg` / `logo-mark-light.svg` — same artwork at icon size with no padding (replaces existing).
- `favicon.svg` — modern browsers; same artwork, `viewBox="0 0 32 32"`.
- `favicon-32.png`, `favicon-16.png`, `apple-touch-icon.png` (180×180) — rendered from `favicon.svg` via `rsvg-convert` at build/commit time.
- `og-image.png` (1200×630) — composed: logo on the left, "the ReStart" wordmark on the right, tagline beneath. Rendered from a generator SVG via `rsvg-convert`.

Delete now-unused: `logo-white.png`, `logo.jpg`.

Quick visual sanity check: open each SVG in a browser at 16/32/64/256 px before wiring them in.

## Phase 2 — Site title + brand text

- `scripts/seed.sh`: set `blogname` to "the ReStart" and `blogdescription` to "Gift registries for life's fresh starts." via `wp option update`. Idempotent (only set if differs).
- `theme/parts/footer.html`: change `© reStart` → `© the ReStart`.
- `theme/style.css` `Theme Name` stays `theRestart` (it's the slug; not user-facing).
- `theme/patterns/*.php`: grep for "reStart"/"Restart" prose and unify casing. Email addresses (`hello@the-restart.co`) stay as the literal domain — not changed.

## Phase 3 — Wire logo into theme

- `theme/parts/header.html`: replace the inline SVG block with a `<!-- wp:html -->` block referencing `assets/logo-light.svg` via stylesheet URL. Site-title text block stays alongside it (logo + wordmark side-by-side at desktop; collapses to mark-only at mobile via CSS).
- `theme/parts/footer.html`: same pattern (light variant on dark footer).
- `theme/functions.php`:
  - `add_theme_support('custom-logo', [...])` so the Customizer can swap if needed.
  - `add_action('wp_head', ...)` that emits `<link rel="icon" type="image/svg+xml" href=".../favicon.svg">`, the PNG fallback, `<link rel="apple-touch-icon">`, and OG tags (`og:title`, `og:description`, `og:image`, `og:type`, `og:url`, `twitter:card`).
- Stylesheet: rules to size the logo (`.site-logo-mark img { height: 36px; width: auto; }`) and a media query that hides the wordmark below ~480 px so the bar doesn't crowd.

## Phase 4 — Layout / alignment audit

Single pass via the `/design-review` skill, in the live dev stack, working through the 21 templates in this order (front-page first, deepest pages last):

1. `front-page.html`
2. `page-about-us.html`
3. `page-faq.html`
4. `page-login.html`, `page-register.html`
5. `page-my-account.html`, `page-my-registries.html`
6. `page-start-a-registry.html`
7. `archive-restart-registry.html`
8. `single-restart-registry.html`
9. `index.html`, `single.html`, `page.html`
10. `category-articles.html`, `category-favorites.html`, `category-gifts.html`
11. `single-category-articles.html`, `single-category-favorites.html`, `single-category-gifts.html`
12. `taxonomy-category.html`
13. `404.html`

For each template: screenshot at desktop (1280) and mobile (375); check header/footer alignment, hero spacing, CTA buttons, list-vs-grid consistency, vertical rhythm, focus states. Fix only issues that score ≥ 7/10 importance — log lower-impact nits as follow-ups.

## Phase 5 — Verify + ship

- `make theme-test` (PHP + JS) green.
- `make seed-reset` and visually confirm the new title + logo + favicon appear.
- `make qa` (or at least a manual smoke pass of the front page + login + start-a-registry + an existing registry).
- Commit per phase with conventional prefixes (`feat(theme): ...`, `fix(theme): ...`).
- Open a PR titled "theme: branding pass + logo + layout audit."

---

## Risk / rollout
- The header SVG-via-block approach can render before the stylesheet loads, causing FOUC on first paint. Mitigate with a hard-coded `width`/`height` attr on the `<img>` so layout doesn't shift.
- Favicon caches aggressively in browsers; document `Ctrl-Shift-R` in the PR body if reviewers don't see the new icon.
- "all templates" is open-ended. Cap audit at one pass; don't recurse on stylistic taste calls.

---

## Todo

- [x] Phase 1: Hand-build `logo.svg` + variants, generate PNG fallbacks, delete dead assets. (555612f, 62edc4f, 08201b3)
- [x] Phase 2: Update `blogname`/`blogdescription` via seed; align footer text. (67c3f5f)
- [x] Phase 3: Swap header SVG to file-based; add favicon + OG meta in `functions.php`. (fe70b39)
- [~] Phase 4: Layout/alignment audit — **descoped** by user; no changes. Branding work didn't require it.
- [ ] Phase 5: Run tests, QA, open PR.
**Multi-stage Dockerfile** that pulls the official `wordpress:cli` image as a builder stage and copies just the `wp` binary into the existing `wordpress:fpm-alpine` runtime. No `apk add` calls, no curl, no shell drift.

```dockerfile
# docker/Dockerfile.wordpress
ARG WP_VERSION=6.9.1
FROM wordpress:cli-2.12.0 AS cli
FROM wordpress:${WP_VERSION}-fpm-alpine
COPY --from=cli /usr/local/bin/wp /usr/local/bin/wp
```

The `wordpress:cli` image (officially published by WordPress) ships `wp` as a phar at `/usr/local/bin/wp`. Copying it preserves the phar's executable bit; no extra setup needed. The fpm-alpine runtime already has PHP installed, which is all the phar needs.

`docker-compose.yml` change: replace `image: wordpress:6.9.1-fpm-alpine` with:
```yaml
build:
  context: ./docker
  dockerfile: Dockerfile.wordpress
  args:
    WP_VERSION: 6.9.1
image: restart-wordpress:dev
```
The named `image:` line tells compose to tag the build, so re-runs use the cached layer.

## Alternative (rejected)

Add a separate `wpcli` compose service using `wordpress:cli` directly, mounting the wordpress volume, and rewrite `seed.sh` to call `docker compose run --rm wpcli wp ...` instead of exec'ing into the wordpress container. Pros: zero Dockerfile, standard WP-Docker pattern. Con: each `wp` call spawns a fresh container — adds 1–2 minutes to a seed that currently takes ~10 seconds. Not worth it for our setup.

## Changes per file

1. **`docker/Dockerfile.wordpress`** (new) — the three-line Dockerfile above.
2. **`docker-compose.yml`** — swap the `wordpress` service's `image:` directive for a `build:` block as shown.
3. **No `seed.sh` change** — the script keeps using `exec` with the same `wp` invocation; it just works once wp-cli is in the image.
4. **No README change required** — `make up` already builds when needed; the build step is transparent.

## Risk / rollout
- First `make up` after this lands triggers a one-time image build (~30–60 s on cold pull, faster on warm). Subsequent runs use the cache.
- Pinning `wordpress:cli-2.12.0` means we control when wp-cli updates. If left as `wordpress:cli` (no tag) it follows latest, which is fine but less reproducible.
- The two stages should be on the same WordPress major to avoid db-schema drift between the cli phar's expectations and the runtime. wp-cli 2.12 supports WP 6.9 — confirmed.
- `make reset` (which does `docker compose down -v`) still works the same way; the Dockerfile builds a new image, but volumes behave identically.

## Verification
After implementing:
1. `make down -v` to reset.
2. `make up` — confirm WP container builds without error.
3. `docker exec restart-wordpress-1 wp --version --allow-root --path=/var/www/html` — should print `WP-CLI 2.12.0`.
4. `make seed` from a clean stack — should run end-to-end without the "exec: wp: file not found" error.
5. Existing `make theme-test` and `make lambda-test` still pass.

## Todo
- [ ] Add `docker/Dockerfile.wordpress` with the multi-stage definition.
- [ ] Update `docker-compose.yml` `wordpress` service to `build:` instead of `image:`.
- [ ] Verify on a `down -v` / `up` cycle and a `seed` run.
- [ ] Commit on a new branch (e.g. `dev/wp-cli-in-image`).
