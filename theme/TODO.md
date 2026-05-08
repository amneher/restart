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
