## Open TODOs

### Next up — Phase D (in the 18-item backlog plan)
- **Optional registry hero image.** Plumb media-library uploads through start-a-registry → registry post thumbnail → owner + public single render. Decision locked: WP media library upload via the CPT's `thumbnail` support (size cap proposed 5 MB). Touches lambda model, plugin save/render, theme display.
- **Optional recipient / divorcee fields.** Add `recipient_name`, `recipient_relationship`, `recipient_email` (optional), and `is_for_self` (bool) for cases where the registry creator isn't the person rebuilding. Touches lambda model + plugin form + theme display. When `is_for_self=false`, surface recipient name/relationship in the registry header instead of (or alongside) the owner display name.

### Deferred — needs its own planning + eng review (Phase E)
- **Price scraping subsystem.** Pull item prices from URLs at item add and on a refresh cadence. Store `price_last_checked_at`. Add an admin "Refresh prices" button + a configurable schedule. Per-retailer parsers, fallback strategies, rate limiting, scrape-friendly headers — all need a /plan-eng-review pass before code. Decision locked: defer until after Phase D.

### Pre-existing
- **Build `theme/templates/page-find-a-registry.html`.** The "Find a Registry" page exists (created in seed) but falls back to `page.html` because the dedicated template was never built. Add a real one when the search / lookup UX is designed.
- **Contact form submissions CPT (`rr_contact_message`).** The contact modal currently only emails `admin_email` via `wp_mail` — no record on the WP side. Register a private (not publicly queryable) CPT, write each submission as a post with name / email / subject / message fields, surface a basic admin list table. Keep the email path; the CPT is a parallel sink.

---

## About Us — design follow-up

The current About Us copy is a placeholder paragraph set, written in-house. The original TODO referenced a "three-column photo grid + body text" design that hasn't been built. When real photos and a final design exist, replace the placeholder via WP admin (or update `theme/assets/copy/about-us.html` and re-run `./scripts/seed.sh`).

---

## Recently shipped

- PR #10 — Phase A: 10 quick wins (footer copyright, archive hero height, "My Items", post meta layout, comments scaling, public-toggle alignment, mobile button widths, event-meta labels, registry description placeholder).
- PR #11 — Phase B: page-specific hero backgrounds on 9 pages (`/registry/`, the three category archives, `/about-us/`, `/start-a-registry/`, `/terms-and-conditions/`, `/privacy-policy/`, single article).
- PR #12 (pending merge) — Phase C: registry UX features (hide image-URL field, call out item notes, public-toggle help modal, fulfilled checkbox in row + edit modal, manage invitees in share modal, notification prefs moved to `/my-account/`).
