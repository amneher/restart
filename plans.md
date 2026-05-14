# Plan: 18-item backlog (multi-PR)

This is too big for one PR — it spans CSS one-liners, schema additions, new modal UIs, and a price-scraping subsystem. Proposed staging below; sequence is rough — items can move between phases if dependencies show up.

## Inventory + classification

| # | Item | Surface | Size | Phase |
| --- | --- | --- | --- | --- |
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
