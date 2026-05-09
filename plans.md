# Plan: Layout / alignment audit + visual nit-picking

Branch suggestion: `theme/visual-polish`

## Goal

One disciplined pass across all 21 templates at desktop and mobile widths. Catch alignment, spacing, vertical rhythm, hierarchy, and obvious visual nits. Fix the high-impact issues; log the rest as follow-ups so the audit stays bounded.

This is the descoped Phase 4 from the branding PR (`94e986e`), now run on its own.

## Scope

- All 21 templates in `theme/templates/` plus shared `theme/parts/{header,footer}.html`.
- Two viewports: **1280 px (desktop)** and **375 px (mobile)**. Add **768 px (tablet)** spot-check only if desktop and mobile disagree on a given page.
- Both unauthenticated and authenticated states (auth-only pages: `page-my-account`, `page-my-registries`, and the "edit my registry" view of `single-restart-registry`).

## Out of scope

- Color / palette changes (mint / teal / dark-navy holds).
- Typography swaps (Libre Caslon + Montserrat hold).
- New illustrations, new copy, new sections.
- Component / pattern refactors. We're polishing what's there, not rebuilding.
- Lambda, plugin, or seed logic.
- A11y deep-dive (logged separately if found, not fixed here).

## Severity rubric

Score each issue 0–10. Fix-bar is **≥ 7**.

- **9–10**: broken layout, overlap, off-screen content, unreadable contrast.
- **7–8**: clear visual error a user would notice (misaligned CTA, wrong padding step, busted grid).
- **4–6**: nits — uneven optical centering, slightly inconsistent gap, minor rhythm drift. Logged in `TODO.md`, not fixed.
- **0–3**: taste calls. Ignored.

Drift-prevention: cap at **one fix pass per template**. No recursing on stylistic taste calls.

## Approach

Use the `/design-review` skill against the live dev stack. It captures before/after screenshots and commits atomically per fix, which keeps the diff reviewable and lets us bail out early if the audit balloons.

Working order — front-page first, deepest templates last, so global issues surface before per-page ones:

1. `parts/header.html`, `parts/footer.html` (global — fix once, propagates)
2. `front-page.html`
3. `page-about-us.html`, `page-faq.html`
4. `page-login.html`, `page-register.html`
5. `page-start-a-registry.html`
6. `page-my-account.html`, `page-my-registries.html` *(authed)*
7. `archive-restart-registry.html`
8. `single-restart-registry.html` *(public + edit views)*
9. `index.html`, `page.html`, `single.html`
10. `category-articles.html`, `category-favorites.html`, `category-gifts.html`
11. `single-category-articles.html`, `single-category-favorites.html`, `single-category-gifts.html`
12. `taxonomy-category.html`
13. `404.html`

For each template, the checklist:

- Header / footer alignment with page content (gutters match).
- Hero spacing (top padding, image crop, headline leading).
- CTA buttons: same height, same padding, same hover/focus state across pages.
- List vs grid consistency on archives and category pages.
- Vertical rhythm — no orphan single-line gaps, no double-margin stacking from adjacent block patterns.
- Focus rings present and visible on all interactive elements (logged if missing — not fixed in this pass beyond trivial CSS).
- Mobile: nothing horizontally scrolling, tap targets ≥ 44 px, wordmark hides under 480 px (already shipped, just verify).

## Phases

### Phase 1 — Stand up the dev stack

- `make up && make seed-reset` to land on known seed state.
- Confirm authed flows work: log in as `admin` and `demo`, hit `my-account` and `my-registries`.
- **Pre-capture skipped** — `/design-review` captures per-template before/after as part of Phase 3, so a separate baseline pass would be redundant. PR will use design-review evidence for visual proof.

### Phase 2 — Global parts (header / footer)

- Audit + fix issues in `parts/header.html`, `parts/footer.html`, and any header/footer CSS in `theme/style.css` / `theme/assets/css/*`.
- Re-screenshot every template after the fix (cheap — header/footer change has site-wide blast radius, so we re-baseline once before the per-template pass).

### Phase 3 — Per-template pass

- Walk the working order above. For each template:
  - `/design-review` at desktop + mobile.
  - Triage issues against the severity rubric.
  - Fix ≥ 7s in place; commit atomically (`fix(theme): <template> — <what>`).
  - Append < 7 nits to `TODO.md` under a new "Visual nits — deferred" section with the template name.
- Hard cap: **one pass per template.** Do not loop.

### Phase 4 — Verify + ship

- `make theme-test` green (PHP + JS).
- Manual smoke: front page, login → my-registries, start-a-registry, an existing registry public view.
- Open PR titled "theme: layout + visual polish pass."
- PR body: link to before/after screenshots, list of templates touched, list of deferred nits with severity scores.

## Risk / rollout

- Header/footer changes have site-wide blast radius — that's why Phase 2 happens first and re-baselines before per-template work. A regression caught in Phase 3 attributed to Phase 2 means we revert the header/footer commit, not the per-template commit.
- "21 templates × 2 viewports × visual judgment" is the kind of scope that grows. The severity rubric and the one-pass cap exist specifically to prevent it.
- `/design-review` commits atomically per fix. If a fix turns out wrong, revert that single commit — don't unwind the whole branch.

## Decisions locked in

- **Auth accounts**: use the two users `scripts/seed.sh` already creates — `admin/admin` (administrator) and `demo/demo` (regular subscriber). No new user needed.
- **Deferred nits**: appended to `TODO.md` under a new "Visual nits — deferred" section, one bullet per nit with template name and severity score.
- **Tablet (768 px)**: spot-check only, used when desktop and mobile disagree on a given page. Not a first-class viewport.

---

## Todo

- [x] Phase 1a: `make up && make seed-reset`; verify `admin` and `demo` log in. (4807fda, 46d16b8 — fixed two latent seed bugs en route)
- [~] Phase 1b: **Skipped** — relying on `/design-review`'s built-in per-template before/after captures instead of a separate baseline pass.
- [x] Phase 2: Audit + fix `parts/header.html`, `parts/footer.html`, related CSS. (0e4f54d, e3feb00 — 2 fixes ≥7; f6cd3e0 — 5 nits 4-6 logged to `theme/TODO.md`)
- [x] Phase 3: Per-template pass — covered all 21 templates (some via shared shells); 0 theme-CSS fixes met the ≥ 7 bar; 8 nits logged to `theme/TODO.md` split between theme scope and plugin scope. (3ba9db7)
- [x] Phase 4: `make theme-test` green (PHP 23/23, JS 18/18), smoke verified F1/F2 hold and no desktop regressions, PR opened.
