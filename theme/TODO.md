## Pages to create in WordPress

### Terms & Conditions
- Pages → Add New
- Title: "Terms & Conditions"
- Slug: `terms-and-conditions`
- Template: Default (page.html — assigned automatically)
- Content: paste from `assets/copy/terms-and-conditions.md`
- Fill in `[Your State]` in section 12 before publishing

### About Us
- Pages → Add New
- Title: "About Us"
- Slug: `about-us`
- Template: auto-assigned (page-about-us.html matches slug)
- Content: build in editor — three-column photo grid, then body text paragraph (see screenshot for reference)

### FAQ
- Pages → Add New
- Title: "FAQ"
- Slug: `faq`
- Template: auto-assigned (page-faq.html matches slug)
- Content: insert the "FAQ Content" pattern from the block inserter (therestart/faq-content)

---

## Other TODOs

- Write and publish a Privacy Policy page (referenced in T&C section 7). Slug: `/privacy-policy/`. Link in footer bottom bar alongside T&C.
- Build `theme/templates/page-find-a-registry.html`. The "Find a Registry" page exists (created in seed at `scripts/seed.sh`) but currently falls back to `page.html` because the dedicated template was never built. The seed previously tried to assign `--page_template=page-find-a-registry` and failed; that arg was dropped to unblock seeding. Add a real template when the search/lookup UX is designed.

---

## Visual nits — deferred

Logged from the layout/alignment audit on `theme/visual-polish` (Phase 2 — header/footer global parts). Severity scores against the project rubric (10 = broken, 7 = clear visual error, 4-6 = nit, <4 = taste call). All items below scored 4-6 and were intentionally not fixed in Phase 2.

- **header — no active-page indicator on nav.** Severity 6. The `wp:navigation` block uses `kind:"custom"` for every link, which prevents WP from auto-applying `aria-current="page"` on the active item. Result: users on `/category/articles/` see no visual cue that "Articles" is the current section. Fix path: switch to typed nav links (taxonomy/post-type) so `aria-current` populates, then style `[aria-current="page"]` in the header CSS — or add a small theme-side script that adds an `is-current` class on URL match.
- **header — desktop nav text wraps at ~768px (tablet).** Severity 6. Above the 600px hamburger breakpoint but below ~900px, the 5 nav labels (FIND A REGISTRY, ARTICLES, GIFT GUIDES, OUR FAVORITES, MY ACCOUNT) don't fit on one line; each label wraps to two rows and the whole bar gets cramped. Fix path: bump the hamburger breakpoint up (override WP's hardcoded `(max-width: 600px)` in `style.css` with a higher value like 900px), or add `white-space: nowrap` to nav links and accept horizontal nav overflow on tablet.
- **footer — header gutter (~72px) ≠ footer columns gutter (~242px) on desktop.** Severity 5. Header uses `padding-inline: clamp(16px, 4vw, 40px)` on `.site-header__bar` (max-width 1200) so content starts ~72px from the viewport edge at 1280. The footer columns are inside a default-constrained block centered at ~242px from the edge. They don't visually line up. Either match `max-width` and padding, or accept the difference if intentional.
- **footer — content flush against viewport edge on mobile.** Severity 5. After F2 (`e3feb00`) stacked the columns vertically, each column spans the full footer width (~360px in a 375px viewport), so text like "the ReStart" wordmark and link labels touch the viewport edge with no breathing room. Fix path: add `padding-inline: var(--wp--preset--spacing--small)` to `.site-footer` for viewports ≤ 600px.
- **footer — Explore column noticeably wider than Account on desktop.** Severity 4. Explore has 6 links (longest: "Our Favorites"), Account has 3 (longest: "Start a Registry"). Both columns auto-size to content, so they end up visually unequal. Taste call — could equalize with `min-width` on each column or restructure the link sets, but neither is clearly better than what's there now.
