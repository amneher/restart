# Plan: Plugin visual fixes — registry single + my-registries list

Branch suggestion: `plugin/visual-polish`

## Goal

Fix the four plugin-rendered visual issues we surfaced during the theme audit (PR #3) and logged in `theme/TODO.md` under "Phase 3 — out of scope (plugin-rendered)". These were intentionally deferred at the time because the theme PR scope ended at theme code; now we cross the boundary.

## Scope

In: `plugin/public/css/restart-registry-public.css`, `plugin/public/class-restart-registry-public.php`, and possibly `theme/style.css` for the my-registries list rule (the class name `restart-registry-list__*` is plugin-namespaced but the actual CSS rule lives in the theme — see Findings below).

Out: lambda code, plugin admin views, plugin data model, theme templates.

## The four issues

Severity scores against the project rubric (10 = broken, 7 = clear visual error, 4-6 = nit). Severities re-confirmed during the audit; not editorialized here.

### 1. Single registry — items table drops QTY DESIRED + FULFILLED on mobile (severity 7)

File: `plugin/public/css/restart-registry-public.css:826-829`

```css
.rr-item-row > .rr-item-row__qty-desired,
.rr-item-row > .rr-item-row__fulfilled,
.rr-item-card > .rr-item-card__qty,
.rr-item-card > .rr-item-card__fulfilled { display: none; }
```

These two columns are explicitly hidden under `@media (max-width: 700px)`. The desktop view has them as table columns with QTY 1, FULFILLED ✓ / 0/1 / 1/2 etc. On mobile the registry owner can't see how many of each item are needed, how many have been fulfilled, or which items are already done — only a subtle 50% opacity on already-fulfilled rows hints at it.

Compounding it: the PURCHASE button still renders on every row including fulfilled ones, with only opacity:0.5 on the parent row marking the difference. A guest viewing the registry on mobile could click PURCHASE on something that's already fulfilled and not realize it.

**Fix path:** Don't hide the columns — surface their data inline below the item name on small viewports. The plugin already emits both `<span class="rr-item-row__qty-desired">N</span>` and `<span class="rr-item-row__fulfilled">M</span>` on every row. On mobile, restyle them as a compact metadata row under the name (e.g., "Needed: 1 · Fulfilled: 1/2") instead of hiding them. This is CSS-only — no markup change.

For the "PURCHASE button on fulfilled items" sub-issue: hide the actions cell entirely when the row is `.rr-item-row--fulfilled` or `.rr-item-fulfilled`, or replace the button with a "✓ Fulfilled" pill. Also CSS-only.

### 2. Single registry — toolbar row cramped on mobile (severity 5)

File: `plugin/public/class-restart-registry-public.php:259-269`, plus `.rr-toolbar` rules in plugin CSS.

The PUBLIC/PRIVATE toggle, Share button, and Settings button all sit in a single flex row inside `.rr-toolbar`. At 375px they squeeze tight. The plugin CSS already does `.rr-toolbar { flex-wrap: wrap; }` on mobile (line 836), but the elements still try to fit on one line when there's enough room.

**Fix path:** Below ~480px, force the toggle onto its own line and let Share + Settings sit on a second line. Or push everything to vertical stack. Either is CSS-only via tightening the existing breakpoint.

### 3. Single registry — "Notification Preferences" h2 too large on mobile (severity 5)

File: `plugin/public/class-restart-registry-public.php:364`

```php
<h2 class="rr-notification-prefs__heading"><?php _e('Notification Preferences', 'restart-registry'); ?></h2>
```

The h2 inherits the global heading scale, which on mobile renders the two-word heading as a 28-32px title that wraps to two lines and dominates the page.

**Fix path:** Add a `.rr-notification-prefs__heading` rule that overrides the inherited h2 scale — `font-size: 18px` or similar. CSS-only.

### 4. My-registries list — metadata wraps inconsistently (severity 6)

File: `theme/style.css:328-336` (the rule lives in the theme even though the class is plugin-namespaced — `restart-registry-list__item`).

```css
.restart-registry-list__item {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.875rem 0;
    border-bottom: 1px solid #e5eaea;
    flex-wrap: wrap;
}
```

`flex-wrap: wrap` causes short titles to keep meta inline on the same row, and longer titles push meta to a wrapped second row. Result: rows have visually inconsistent vertical heights and the "PUBLIC" badges end up at different x-positions per row.

**Fix path:** Under `(max-width: 600px)`, force `flex-direction: column; align-items: flex-start;` so every item stacks identically. CSS-only.

## Approach

All four are CSS-only fixes. None require touching PHP markup or JS. Pattern matches the theme audit: one atomic commit per fix, severity-gated decisions, theme version bumps in style.css to bust browser cache during verification.

The plugin doesn't have a per-asset version constant the way the theme does, so cache-busting requires a version bump in `restart-registry.php` (the plugin header) — `wp_enqueue_style` uses the plugin version as the `?ver=` query string. We'll bump it once at the start of the branch and rely on that for the run.

For Issue 4 the rule lives in `theme/style.css`, not plugin CSS. Logically it's a plugin concern (the class is plugin-namespaced) but operationally it's a theme edit. Keep the fix in `theme/style.css` rather than duplicating the rule into plugin CSS — moving the rule across the boundary is a bigger refactor than this branch warrants.

## Phases

### Phase 1 — Stand up + verify reproduction
- `make up` (or confirm running stack from prior session is healthy).
- Login as `demo`, view `/registry/alexs-new-chapter/` at 375 and confirm the four issues reproduce.
- Capture before-screenshots for the PR.

### Phase 2 — Fixes (one atomic commit each)
- **Issue 1** — surface qty/fulfilled inline on mobile + hide actions on fulfilled rows.
- **Issue 2** — tighten toolbar layout at narrow widths.
- **Issue 3** — scale down `.rr-notification-prefs__heading` on mobile.
- **Issue 4** — force column stack on `.restart-registry-list__item` ≤ 600px.

After each fix: reload the relevant page at 375px, verify the targeted issue is resolved, screenshot, commit.

### Phase 3 — Verify + ship
- `make plugin-test` and `make theme-test` green.
- Re-screenshot all four affected views at 375px and 1280px, confirm desktop is unchanged.
- Manual smoke as `demo`: my-registries → single registry → toggle public, open share modal, open settings modal.
- Open PR titled "plugin: visual fixes from theme audit follow-up".
- PR body lists the four issues with severity, file:line of the fix, and before/after notes.

## Risk / rollout

- All four fixes are scoped to mobile media queries (`max-width: 600` or `700`). Desktop behavior should not change. Smoke step verifies that explicitly.
- The "hide PURCHASE button on fulfilled rows" sub-fix in Issue 1 changes affordance for guests, not just owners. Need to confirm: on a guest view (no login, just `?registry=<key>` URL), does the actions cell still render PURCHASE for fulfilled items? If yes, the fix benefits guests too. If guests already see no PURCHASE on fulfilled items (different code path), the fix scope reduces.
- Theme version is currently 1.0.3. Plugin version — TBD; check `restart-registry.php` header before the first commit.

## Decisions locked in

- **Issue 1 sub-fix** — in scope. Hide the PURCHASE button on already-fulfilled rows so a guest viewing on mobile can't accidentally try to "purchase" something already done. Follow the bug end-to-end.
- **One PR** for the audit follow-up. Issues 1-3 touch `plugin/public/css/restart-registry-public.css`; Issue 4 touches `theme/style.css`. Both come along in one branch with a single PR.
- **Plugin version bump**: patch level, 1.0.12 → 1.0.13. Bump the `Version:` header in `plugin/restart-registry.php` and the `RESTART_REGISTRY_VERSION` constant on the same line. Done with the first commit on the branch (the way the theme version bump traveled with F1).

---

## Todo

- [x] Phase 1: stack up, reproduce all four at 375, capture before-screenshots; plugin version landed at 1.0.16 (cache surprises along the way).
- [x] Phase 2.1: Issue 1 — qty/fulfilled inline on mobile + hide PURCHASE on fulfilled rows. (240e498)
- [x] Phase 2.2: Issue 2 — toolbar buttons even out via `flex: 1` on mobile. (9401ec4)
- [x] Phase 2.3: Issue 3 — notification prefs heading matches `.rr-story__heading` eyebrow pattern. (485423a)
- [x] Phase 2.4: Issue 4 — `.restart-registry-list__item` stacks under 600px. (d55b329)
- [x] Phase 3: tests green (plugin PHP 70/70, plugin JS 17/17, theme PHP 23/23, theme JS 18/18); smoke verified mobile + desktop; PR opened.
