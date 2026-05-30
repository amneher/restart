# Plan: User notes field on registry items (GH #22)

## What
Add a `notes` field to registry items — separate from `description` (scraper-owned). `notes` is free text written by the registry owner (e.g. "size medium please", "any color is fine") and is visible to gift-givers.

## Scope

**Lambda** — migration v5 (`notes TEXT`), add `notes` to models and INSERT/UPDATE routes  
**Plugin PHP** — AJAX handlers pass `notes`; add-item form + edit modal wire to `notes`; render notes in owner row + guest card  
**Plugin JS** — send/read `notes` field; item detail modal shows notes  

## Todo
- [x] Lambda: migration v5 — `ALTER TABLE items ADD COLUMN notes TEXT`
- [x] Lambda: add `notes` to `ItemBase`, `ItemUpdate`, `ItemPublic` models
- [x] Lambda: update `row_to_item()` and `create_item()` INSERT in routes/items.py
- [x] Lambda: add `notes` tests (29/29 pass)
- [x] Plugin PHP: `ajax_add_item()` — read `notes` from `$_POST`
- [x] Plugin PHP: `ajax_update_item()` — read `notes` from `$_POST`
- [x] Plugin PHP: `add_item()` controller — pass `notes` in `$lambda_data`
- [x] Plugin PHP: `update_item()` controller — pass `notes` in `$update`
- [x] Plugin PHP: add-item form — change description input to notes (keep hidden description for scraper)
- [x] Plugin PHP: edit modal — change description input to notes
- [x] Plugin PHP: `render_item_row()` — show notes, add `data-notes` attr
- [x] Plugin PHP: `render_item_card()` — show notes, add `data-notes` attr
- [x] Plugin JS: fetch-url keeps populating hidden `#rr-item-description`
- [x] Plugin JS: add-item submit sends `notes`
- [x] Plugin JS: edit modal open reads `data-notes` → `#rr-edit-item-notes`
- [x] Plugin JS: edit-item submit sends `notes`
- [x] Plugin JS: item detail modal shows notes
- [x] Plugin JS tests: update for notes field (17/17 pass)
- [ ] Close GH #22

---

# Plan: Admin UI for custom affiliate retailers (GH #25)

## What
Add a "Custom Retailers" section to the Affiliate Settings admin page so new affiliate retailers can be added without touching PHP.

## What already exists
- `class-affiliate-converter.php` — 10 hard-coded retailers; already calls `apply_filters('restart_registry_affiliate_configs', $defaults)` at the end of `get_affiliate_configs()`, giving us a clean merge point.
- `class-restart-registry-admin.php` — `display_affiliates_page()` renders the affiliate settings form; `register_settings()` registers WP options; the page is at `restart-registry-affiliates`.
- `restart-registry-admin.js` — jQuery AJAX handlers for Lambda test + re-convert; pattern to follow for dynamic row JS.
- Main plugin class initialises hooks in `includes/class-restart-registry.php`.

## Storage
- WP option `restart_registry_custom_retailers` — serialized PHP array of retailer rows.
- Each row: `name` (string), `domains` (comma-separated string), `template` (URL with `{url}`, `{affiliate_id}`, `{merchant_id}` placeholders), `affiliate_id` (string), `merchant_id` (string, optional).

## Converter integration
- In `class-restart-registry.php` `run()`, register a callback on `restart_registry_affiliate_configs` that reads `restart_registry_custom_retailers` from the DB and appends custom entries to the config array (skipping keys that already exist, so built-ins take precedence on key collisions).
- Custom entries get a `url_template` key. In `generate_affiliate_url()`, detect `url_template` presence and call a new `generate_custom_affiliate()` method.
- `generate_custom_affiliate()`: str_replace `{url}` → `urlencode($url)`, `{affiliate_id}` → `affiliate_id`, `{merchant_id}` → `merchant_id` in the template; return raw URL if template or affiliate_id is empty.

## Admin UI
- In `register_settings()`: register `restart_registry_custom_retailers` with a `sanitize_callback` that validates/sanitizes each row (strip tags, esc_url_raw on template, etc.) and re-indexes the array.
- In `display_affiliates_page()`: add a "Custom Retailers" section below the existing settings form (before the Re-convert section). The section has a `<form action="options.php">` that submits `restart_registry_custom_retailers[i][name]`, `[domains]`, `[template]`, `[affiliate_id]`, `[merchant_id]` fields.
- Rendered as a table with one row per saved retailer + a blank "add" row template hidden in the DOM. "Add Retailer" button clones and appends the template. Each row has a "Remove" button.
- JS re-indexes the `[i]` placeholder on every add/remove so PHP receives a clean 0-indexed array.

## Scope
- Only `plugin/` files touched (no lambda, no theme).
- Built-in retailers are untouched.

## Todo
- [x] Branch: `feat/custom-affiliate-retailers`
- [x] `class-restart-registry.php` — add `restart_registry_affiliate_configs` filter callback to merge custom retailers
- [x] `class-affiliate-converter.php` — add `generate_custom_affiliate()` method; route to it when `url_template` key present
- [x] `class-restart-registry-admin.php` — register `restart_registry_custom_retailers` option with sanitize callback
- [x] `class-restart-registry-admin.php` — render Custom Retailers table section in `display_affiliates_page()`
- [x] `restart-registry-admin.js` — add/remove row logic + index renumbering
- [x] Tests: converter (5 cases) + admin sanitize (8 cases), 115/115 pass
- [ ] Manual test: add a custom retailer row, save, add an item URL matching its domain, confirm affiliate URL is built from template
- [ ] Close GH #25

---

# Plan: Archive and delete registry (GH #23)

## What
Let owners archive (soft-disable) or permanently delete their registry from the settings panel.

## What already exists
- `delete_registry()` (controller:272) — deletes Lambda items + WP post. Already correct, just needs an AJAX handler wired up.
- `update_registry()` handles `post_status` changes — archive can extend this pattern.
- `can_view_registry()` (controller:594) — only grants access to `publish` / owner / admin / invitee. A custom archived status is automatically hidden from public without any extra guards.
- `get_user_registry()` searches `['publish','private','draft']` — archived will be excluded automatically.
- `get_registry_by_share_key()` searches `['publish','private']` — archived returns a `not_found` WP_Error; we'll intercept and return a specific `registry_archived` error instead to show a better message.

## Archive strategy
Register a custom `restart-archived` post status on `init`. Items and purchase messages are preserved. The registry URL shows "This registry is no longer active" instead of a generic 404.

## Scope

### 1. Main plugin class — register post status
- `register_post_status('restart-archived', [...])` on the `init` hook in `class-restart-registry.php`.

### 2. Controller
- `archive_registry(int $registry_id): bool` — sets `post_status = 'restart-archived'`, forces `is_public = false` first.
- `restore_registry(int $registry_id): bool` — sets `post_status = 'private'`.
- `get_user_archived_registries(int $user_id): array` — fetches registries with `post_status = 'restart-archived'` for the user, returns array of slim registry arrays (no Lambda round-trip needed).
- `get_registry_by_share_key()`: when `get_posts(['publish','private'])` returns empty, do a second lookup with `post_status = 'restart-archived'`; if found, return `WP_Error('registry_archived')`.

### 3. AJAX handlers (public class)
- `ajax_archive_registry()` — action `restart_registry_archive`. Auth: owner only.
- `ajax_restore_registry()` — action `restart_registry_restore`. Auth: owner only.
- `ajax_delete_registry()` — action `restart_registry_delete`. Auth: owner only. Requires `confirm=1` in payload.

### 4. UI (public class + CSS)
**Settings modal** — add two buttons below existing settings:
- "Archive Registry" (secondary) — opens archive confirm modal.
- "Delete Registry" (danger/ghost) — opens delete confirm modal.

**Archive confirm modal** — brief explanation ("Your registry will be hidden. Items and messages are preserved. You can restore it any time.") + confirm button.

**Delete confirm modal** — stronger warning ("This cannot be undone. All items and data will be permanently removed.") + checkbox "I understand this is permanent" that enables the confirm button.

**My Account / My Registries** — below the active registry, add an "Archived Registries" section (collapsed by default) listing archived registries with a Restore button per row.

**Archived registry URL** — `render_registry_view_html()` / `render_registry_view()`: detect `registry_archived` WP_Error from `get_registry_by_share_key()` and render a "This registry is no longer active" message instead of the generic "not found" error.

## What's NOT in scope
- Bulk archive/delete
- Admin-triggered archive (owners only, for now)
- Auto-purge of archived registries after N days

## Todo
- [ ] Branch: `feat/archive-delete-registry`
- [ ] Main class: register `restart-archived` post status
- [ ] Controller: `archive_registry()`, `restore_registry()`, `get_user_archived_registries()`
- [ ] Controller: `get_registry_by_share_key()` — detect archived, return `registry_archived` error
- [ ] Tests: archive/restore/delete/archived-url
- [ ] Public class: `ajax_archive_registry()`, `ajax_restore_registry()`, `ajax_delete_registry()`
- [ ] Public class: archive + delete buttons in settings modal
- [ ] Public class: archive confirm modal
- [ ] Public class: delete confirm modal with checkbox gate
- [ ] Public class: archived registries section in My Account
- [ ] Public class: "no longer active" message for archived URL
- [ ] CSS: archive/delete button styles, archived section, confirm modals
- [ ] Close GH #23

---

# Plan: Purchase message board (GH #16)

## What
When a gift-giver marks an item as purchased they can leave a name and note. Currently those fields are captured in the modal and emailed to the owner, then discarded — nothing is stored. This feature persists the messages and surfaces them in a message board section on the owner's registry view.

## What already exists
- `mark_item_purchased()` (controller:391) already accepts `purchaser_name` and `purchaser_note`
- The mark-purchased modal (public.php:843) already has both input fields
- `ajax_mark_purchased()` already reads and passes both fields through to the controller
- `send_purchase_notification()` already includes the note in the email

## Storage decision
**Post meta on the registry post** (`restart_purchase_messages`): JSON array of message objects. Consistent with how `restart_invitees` and `restart_item_ids` are stored. No Lambda schema change needed. Item data (name, image_url, description) is available at purchase time in the controller and is snapshotted into the record so the message board doesn't need a Lambda round-trip to render.

Each record:
```json
{
  "item_id": 42,
  "item_name": "Chef's Knife",
  "item_image_url": "https://...",
  "item_description": "High-carbon stainless steel...",
  "purchaser_name": "Aunt Carol",
  "purchaser_note": "Hope this helps with the new kitchen!",
  "timestamp": 1715000000
}
```

Only records with a non-empty `purchaser_note` are stored (name-only purchase notifications stay email-only).

## Scope

### 1. Controller — persist message on purchase
- In `mark_item_purchased()`: after the Lambda update succeeds, if `$purchaser_note` is non-empty, append a record to `restart_purchase_messages` post meta on the registry post.
- New method `get_purchase_messages(int $registry_id): array` — returns the stored array, newest first.

### 2. Public class — message board section
- In the owner registry view, below the item list, render a `<div class="rr-message-board">` section.
- Only rendered when `get_purchase_messages()` returns at least one record.
- Each card shows:
  - Item thumbnail (from `item_image_url`)
  - Item name and description
  - Purchaser name (falls back to "Someone" if empty)
  - Date (formatted from `timestamp`)
  - Note text
- Section is owner-only — not rendered in the guest/invitee view.

### 3. CSS
- Message board section and card styles (consistent with existing `.rr-*` design system).

## What's NOT in scope
- Editing or deleting individual messages (future)
- Anonymous toggle for message board (the existing `is_anonymous` field suppresses the name in the email; same logic applies here — name shows as "Someone")
- Pagination (message counts will be small)

## Todo
- [x] Branch: `feat/purchase-message-board` (1184371)
- [x] Controller: persist message in `mark_item_purchased()` (1184371)
- [x] Controller: `get_purchase_messages()` method (1184371)
- [x] Controller test: message is stored; empty note produces no record (1184371)
- [x] Public class: message board section in owner view (1184371)
- [x] Public class: confirm board absent from guest view — in render_manage_registry() only, not render_registry_view_html()
- [x] CSS: message board and card styles (1184371)
- [ ] Close GH #16

---

# Plan: 18-item backlog (multi-PR)

This is too big for one PR — it spans CSS one-liners, schema additions, new modal UIs, and a price-scraping subsystem. Proposed staging below; sequence is rough — items can move between phases if dependencies show up.

## Inventory + classification

| # | Item | Surface | Size | Phase |
|---|---|---|---|---|
| 1 | Footer copyright: © + year + "ReStart Group, LLC" | theme | XS | A |
| 2 | Archive hero/CTA: -1/4 height | theme CSS | XS | A |
| 3 | "Your Items" → "My Items" | plugin | XS | A |
| 4 | Single article: center category vertically with title+date | theme | XS | A |
| 5 | Single article: scale comments down by half | theme | XS | A |
| 6 | Single article: category currently left-justified — see #4 | theme | — | A |
| 7 | Public label + toggle: align same line | plugin CSS | XS | A |
| 8 | Share + Settings buttons too wide on mobile | plugin CSS | XS | A |
| 9 | Add labels to event type + date on registry single | plugin | XS | A |
| 10 | Description placeholder text on registries with no story | plugin | XS | A |
| 11 | Background images on 9 pages (`category/articles`, `guides/gifts`, `guides/favorites`, `registry`, `about-us`, `start-a-registry`, `terms-and-conditions`, `privacy-policy`, single article) | theme | M | B |
| 12 | Move notification prefs from single registry → /my-account/ | plugin + theme | M | C |
| 13 | "Manage Invitees" modal in owner registry view | plugin (data exists, needs UI) | M | C |
| 14 | Public toggle tooltip → modal with explanation | plugin | S | C |
| 15 | "Fulfilled" checkbox in owner row + edit modal | plugin (UI + maybe schema) | S | C |
| 16 | Remove "Image URL" from item edit modal | plugin | XS | C |
| 17 | Call out item notes/descriptions in item listings | plugin | S | C |
| 18 | Optional registry hero image | lambda + plugin + theme | M | D |
| 19 | Optional recipient/divorcee fields (when creator ≠ recipient) | lambda + plugin + theme | M | D |
| 20 | Price scraping from URL + scheduled refresh + admin trigger | lambda (scraper) + plugin (admin UI + schedule) | L | E |
| 21 | Auto-mark item purchased after gift-giver completes checkout (currently manual — purchaser must return to site and click "purchased") | plugin + lambda | M | F |

(I split #4 and #6 because you listed them separately; they're the same fix — single article category alignment.)

## Phases (one PR each)

### Phase A — quick wins (CSS + copy)
Items 1–10. Pure presentation/text. No schema, no new files of substance. Single PR, atomic commits per item. ~30–45 min of work.

### Phase B — page background images
Item 11. Pick a fitting image per page from `theme/assets/background_images/`, add a hero/cover block to each template (or use a CSS `background-image` on the page wrapper). Theme version bump, atomic commit per page. ~60 min.

### Phase C — registry UX features (plugin-only, no schema)
Items 12–17. All bounded to plugin markup + plugin CSS + small JS. Builds on existing data shapes (invitees already exist; fulfilled = derivable from `quantity_purchased >= quantity_needed`, but might warrant an explicit field — see decision below). Single PR with atomic commits. ~2–3 hrs.

### Phase D — registry schema additions (lambda + plugin + theme)
Items 18, 19. Adds optional fields to the registry model + start-a-registry form + display in single registry. Lambda model migration, schema test additions, plugin save/render code, theme display.

Sub-decisions (#18 and #19) live below. ~3–4 hrs.

### Phase E — price scraping subsystem
Item 20 only. Its own architectural plan. Touches:
- Lambda: scraping fn (per-retailer parsers, fallback strategies), scheduled task runner
- Plugin admin: manual "Refresh prices" button, schedule UI
- DB: price_last_checked_at column
- Auth/rate-limit considerations for scraping

This deserves its own /plan-eng-review pass. I'd rather not touch it in the same PR as #18/#19. Estimating ~1 day of focused work for a working v1, plus follow-up for retailer coverage.

## Decisions (locked)

1. **Fulfilled (item 15)**: derive from quantities + a "no more needed" override that clamps `quantity_needed` down to `quantity_purchased`.
2. **Public toggle (item 14)**: ⓘ icon next to toggle; hover tooltip; click opens modal with detail.
3. **Registry hero image (item 18)**: WP media-library upload via the CPT's `thumbnail` support, **with a size limit** (TBD per Phase D — propose 5 MB).
4. **Recipient fields (item 19)**: `recipient_name`, `recipient_relationship`, **`recipient_email` (optional)**, `is_for_self` (bool).
5. **Background images (item 11)**: pick by judgment, **skip `bg-yellow*`** (clashes with palette).
6. **Price scraping (item 20)**: defer to a later /plan-eng-review session.
7. **PR cadence**: four PRs (A / B / C / D).

## What I'm not asking about (judgment calls I'll just make)

- Specific copy for placeholders (description, fulfilled labels, modal body text) — I'll write it; you can edit.
- Specific CSS values (heights, spacings, breakpoints) — same.
- Whether to use a `<details>` element or custom modal markup for the public-toggle tooltip — I'll pick the lightest option that fits.
- Where to mount the "Manage Invitees" trigger — I'll put it in the existing toolbar next to Share / Settings.

---

## Todo (filled in once decisions land)

- [x] Decisions 1–7 confirmed.
- [x] Phase A PR (items 1–10).
- [x] Phase B PR (item 11).
- [x] Phase C PR (items 12–17).
- [x] Phase D PR (items 18–19).
- [ ] Phase E plan + PR (item 20).

---

# Research: Scraper UA matrix + stable data sources (GH #31)

## What
Issue #31 asks for two things:
1. **Part 1** — a UA capability matrix: test all major UA strings against our retailers, build a per-retailer UA config
2. **Part 2** — stable data source research: evaluate affiliate APIs, third-party extractors, headless browser as alternatives to HTML scraping

## Key findings (2026-05-18)

### UA test results

| Retailer | Working UAs | Blocked UAs | Action |
|---|---|---|---|
| Brooklinen | ALL (8/8) | none | No change needed |
| West Elm | **LinkedInBot only** | Chrome, Safari, Firefox, Facebook, Twitter, Google, Bing (all 403) | Switch to LinkedInBot |
| Pottery Barn | **LinkedInBot only** | same | Switch to LinkedInBot |
| Amazon | none | all (CAPTCHA/500) | URL-only path confirmed correct |
| Etsy | inconclusive | all 403/429 from server IP | IP reputation matters as much as UA; migrate to Etsy Open API v3 |

The current scraper uses Chrome desktop UA for West Elm and Pottery Barn → **silently broken** (returns empty strings in production). Fix is immediate: use `LinkedInBot` UA for the Williams-Sonoma family.

### Stable data sources

| Source | Best for | Cost | Viability |
|---|---|---|---|
| **Etsy Open API v3** | Etsy listings | Free (10k req/day, dev account) | ✅ Viable Now |
| **CJ Affiliate GraphQL API** | West Elm, Pottery Barn | Free (publisher account) | ✅ Viable With Work (apply now) |
| **Rakuten** | West Elm, Pottery Barn | Free (publisher account) | ✅ Viable With Work |
| **Amazon Creators API** | Amazon | Free but requires 10 qualifying sales/30 days | ❌ Unreliable for small registry |
| **Scrapfly** (extraction API) | Any URL, anti-bot bypass, structured JSON | ~$30/mo starter | ✅ Best general fallback |
| **Diffbot Product API** | Any product URL, dedicated product model | Free tier (10k/mo) or $299/mo | ✅ Good; free tier tight |
| **Playwright on Lambda** | JS-rendered pages, full browser | ~$6–12/mo compute | ⚠️ High setup cost, moderate bot evasion |
| **Zinc API** | Amazon/Walmart only | $0.01/req | ❌ Wrong retailers |
| **Haunt API** | Unknown — no public pricing/benchmarks | Unknown | ⚠️ Unverified |

**Note:** Amazon PA-API 5.0 was deprecated April 30, 2026 and retires May 15, 2026. Any Amazon affiliate integration must target the new **Creators API**.

### Architecture findings (Issue #20 prereqs)

- Lambda backend is **FastAPI + Python + SQLite on EFS** (not DynamoDB). `price_last_checked_at` belongs as a column on the `items` SQLite table.
- Price refresh trigger: EventBridge cron → SQS FIFO (retailer as MessageGroupId) → worker Lambda. Manual "Refresh prices" button in plugin admin writes to the same queue.
- Scraper port: Python port of `class-product-scraper.php` into `lambda/app/services/scraper.py`. Keep PHP for interactive add-item flow; Python owns scheduled refresh.
- Fallback chain for price refresh: Python scraper → Scrapfly (on parse failure) → preserve previous value + mark `price_refresh_status = 'failed'`.

## Immediate actions before Issue #20 implementation

- [ ] **Bug fix**: update `class-product-scraper.php` to use `LinkedInBot` UA for `westelm.com` and `potterybarn.com` (and add catch for other Williams-Sonoma domains). This is a production bug, not a feature.
- [ ] Apply as CJ Affiliate publisher and apply to West Elm + Pottery Barn programs (async — kicks off the 1-2 week approval clock)
- [ ] Apply for Etsy Open API v3 developer account
- [ ] Run a 1-day Scrapfly free trial against all 5 retailers to validate extraction accuracy

## Raw data
See `plugin/tests/assets/ua-matrix/` for full JSON results and `summary.md`.

---

# Plan: Phase E — Price refresh subsystem (GH #20)

## Prerequisite
The UA research above (GH #31) must be resolved first. Specifically: LinkedInBot fix deployed, parsing-rules spec locked, Scrapfly/fallback decision made.

## What
Automatically scrape and refresh product prices on registry items so displayed prices stay accurate over time.

## Architecture (confirmed 2026-05-18)

### Data model
Add to `items` SQLite table in Lambda:
```sql
ALTER TABLE items ADD COLUMN price_last_checked_at TIMESTAMP NULL;
ALTER TABLE items ADD COLUMN price_refresh_status TEXT NULL; -- 'ok'|'stale'|'failed'|'anomaly'
ALTER TABLE items ADD COLUMN price_previous REAL NULL;
ALTER TABLE items ADD COLUMN price_refresh_error TEXT NULL;
```
Follow the migration pattern in `lambda/app/database/migrations/__init__.py`.

### Lambda architecture
- **Trigger**: EventBridge cron (hourly) + on-demand from plugin admin button
- **Enqueuer Lambda**: reads items WHERE `price_last_checked_at < now() - 6h`, pushes to SQS FIFO with retailer as MessageGroupId
- **Worker Lambda**: single Lambda with per-retailer routing (not 5 separate Lambdas); port of scraper logic in Python; concurrency capped at 5; 2s sleep between requests per retailer; exponential backoff on 4xx/5xx
- **Fallback chain**: Python scraper → Scrapfly API (on parse failure) → preserve previous + mark failed

### Plugin admin
- REST endpoint: `POST /wp-json/restart/v1/registry/{id}/price-refresh` → 202 Accepted + job_id
- REST endpoint: `GET /wp-json/restart/v1/registry/{id}/price-refresh/{job_id}` → status + per-item results
- Admin UI: "Refresh prices" button + schedule selector (Manual / Weekly / Daily) stored in `restart_price_refresh_schedule` post meta
- Owner display: price change callout ("↓ $5 from last week"), stale indicator if >14 days

### Error handling
- **Never replace a known price with a blank** on failure
- Large price delta (>50% change) → anomaly flag, email owner for confirmation, do NOT auto-apply
- Dead URL (404 after 3 retries) → auto-archive item + notify owner

## Scope
- [ ] `/plan-eng-review` pass for this architecture before implementation
- [ ] Lambda: DB migration (4 new columns on items table)
- [ ] Lambda: Python scraper port (`lambda/app/services/scraper.py`)
- [ ] Lambda: enqueuer Lambda + SQS FIFO + EventBridge cron (IaC)
- [ ] Lambda: worker Lambda with per-retailer routing + anomaly detection
- [ ] Plugin: REST endpoints for refresh job status
- [ ] Plugin admin: Refresh button + schedule UI
- [ ] Plugin admin: per-item price change indicators
- [ ] Plugin: owner email notification on price anomaly / dead URL
- [ ] Tests
- [ ] Close GH #20

---

# Plan: New branding integration

## What
Replace theme logo assets with new professional brand files from `theme/assets/Logo Files/`.
Variant chosen: **Logo 2 "the ReStart"** (lowercase t). Favicon updated too.

## Key technical notes
- All new SVGs contain a `<rect ... style="fill:#fff;"/>` background that must be stripped before use. The header has a dark background and the footer uses CSS `mask` — both break with an opaque white rect.
- New Lettermark SVGs are square (512×512 viewBox); current mobile mark is 36×40 — update header img dimensions to 40×40.
- Five files to swap, all by filename so no template path changes needed (except the img dimensions on the mark).

## File mapping
| New source | Destination |
|---|---|
| `SVG/White/The ReStart Branding_the ReStart-Logo 2-White.svg` | `assets/logo-light.svg` |
| `SVG/White/The ReStart Branding_Lettermark-White.svg` | `assets/logo-mark-light.svg` |
| `SVG/Blue Green/The ReStart Branding_The reStart-Logo 2-Blue Green.svg` | `assets/logo.svg` |
| `SVG/Blue Green/The ReStart Branding_Lettermark-Blue Green--.svg` | `assets/logo-mark.svg` |
| `SVG/Blue Green/The ReStart Branding_Lettermark-Blue Green--.svg` | `assets/favicon.svg` |

## Todo
- [x] Strip white `<rect>` background from each source SVG and write to destination
- [x] Update `parts/header.html` mark img: `width="36" height="41"` → `width="40" height="40"`
- [x] Verify visually (open site in browser)
