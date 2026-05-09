## Open TODOs

- Build `theme/templates/page-find-a-registry.html`. The "Find a Registry" page exists (created in seed at `scripts/seed.sh`) but currently falls back to `page.html` because the dedicated template was never built. The seed previously tried to assign `--page_template=page-find-a-registry` and failed; that arg was dropped to unblock seeding. Add a real template when the search/lookup UX is designed.
- Persist contact form submissions to a CPT (`rr_contact_message`) so admins can browse/audit them in WP admin even if outbound email bounces. Today the contact modal only emails `admin_email` via `wp_mail` — there's no record on the WP side. Add the CPT registration (private, not publicly queryable), write each submission as a post with name/email/subject/message in fields, and surface a basic admin list table. Keep the email path; add the CPT as a parallel sink.

---

## About Us — design follow-up

The current About Us copy is a placeholder paragraph set, written in-house. The original TODO referenced a "three-column photo grid + body text" design that hasn't been built. When real photos and a final design exist, replace the placeholder via WP admin (or update `theme/assets/copy/about-us.html` and re-run `./scripts/seed.sh`).

---

## Visual nits — deferred

Logged from the layout/alignment audit on `theme/visual-polish` (Phase 2 — header/footer global parts). Severity scores against the project rubric (10 = broken, 7 = clear visual error, 4-6 = nit, <4 = taste call). All items below scored 4-6 and were intentionally not fixed in Phase 2.

- **header — no active-page indicator on nav.** Severity 6. The `wp:navigation` block uses `kind:"custom"` for every link, which prevents WP from auto-applying `aria-current="page"` on the active item. Result: users on `/category/articles/` see no visual cue that "Articles" is the current section. Fix path: switch to typed nav links (taxonomy/post-type) so `aria-current` populates, then style `[aria-current="page"]` in the header CSS — or add a small theme-side script that adds an `is-current` class on URL match.
- **header — desktop nav text wraps at ~768px (tablet).** Severity 6. Above the 600px hamburger breakpoint but below ~900px, the 5 nav labels (FIND A REGISTRY, ARTICLES, GIFT GUIDES, OUR FAVORITES, MY ACCOUNT) don't fit on one line; each label wraps to two rows and the whole bar gets cramped. Fix path: bump the hamburger breakpoint up (override WP's hardcoded `(max-width: 600px)` in `style.css` with a higher value like 900px), or add `white-space: nowrap` to nav links and accept horizontal nav overflow on tablet.
- **footer — header gutter (~72px) ≠ footer columns gutter (~242px) on desktop.** Severity 5. Header uses `padding-inline: clamp(16px, 4vw, 40px)` on `.site-header__bar` (max-width 1200) so content starts ~72px from the viewport edge at 1280. The footer columns are inside a default-constrained block centered at ~242px from the edge. They don't visually line up. Either match `max-width` and padding, or accept the difference if intentional.
- **footer — content flush against viewport edge on mobile.** Severity 5. After F2 (`e3feb00`) stacked the columns vertically, each column spans the full footer width (~360px in a 375px viewport), so text like "the ReStart" wordmark and link labels touch the viewport edge with no breathing room. Fix path: add `padding-inline: var(--wp--preset--spacing--small)` to `.site-footer` for viewports ≤ 600px.
- **footer — Explore column noticeably wider than Account on desktop.** Severity 4. Explore has 6 links (longest: "Our Favorites"), Account has 3 (longest: "Start a Registry"). Both columns auto-size to content, so they end up visually unequal. Taste call — could equalize with `min-width` on each column or restructure the link sets, but neither is clearly better than what's there now.

### Phase 3 — per-template (theme scope)

- **front-page — hero body text legibility.** Severity 5. The 20px mint body copy ("Navigate divorce with style…") sits over the sunrise photo with a 0.5 navy overlay. Where the photo is brightest behind the text, the mint loses contrast. Fix path: add a `text-shadow: 0 1px 2px rgba(0,0,0,0.4)` on the hero copy, or bump the overlay `dimRatio` from 50 to 60–65, or swap the body color to a brighter mint/white.
- **archive-restart-registry — incomplete grid row left-heavy on desktop.** Severity 5. The 3-column post grid renders 2 cards side-by-side with the third column empty when fewer than 3 results exist (same effect on category-articles with 5 results in a 4-col grid). Fix path: `.rr-registry-grid { justify-content: center }` for incomplete rows, or switch to `auto-fit` minmax so the cards expand to fill.
- **page-my-account — account menu is plain text.** Severity 5. "MY REGISTRIES / EDIT PROFILE / LOG OUT" render as bare uppercase mint links with no surrounding affordance. Tap targets are also small (text-height only). Fix path: style as button-like list items or pad the link rows so they're at least 44px tall.
- **buttons — primary CTA color inconsistent across pages.** Severity 5. Front-page CTAs use gold (`var(--wp--preset--color--primary)`), but login `LOG IN`, start-a-registry `CREATE REGISTRY`, and the registry items `PURCHASE` buttons use teal/mint. Consider deciding whether teal is for "form submit" specifically or whether all primary actions should match — and document the rule.
