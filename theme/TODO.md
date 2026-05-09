## Open TODOs

- Build `theme/templates/page-find-a-registry.html`. The "Find a Registry" page exists (created in seed at `scripts/seed.sh`) but currently falls back to `page.html` because the dedicated template was never built. The seed previously tried to assign `--page_template=page-find-a-registry` and failed; that arg was dropped to unblock seeding. Add a real template when the search/lookup UX is designed.
- Persist contact form submissions to a CPT (`rr_contact_message`) so admins can browse/audit them in WP admin even if outbound email bounces. Today the contact modal only emails `admin_email` via `wp_mail` — there's no record on the WP side. Add the CPT registration (private, not publicly queryable), write each submission as a post with name/email/subject/message in fields, and surface a basic admin list table. Keep the email path; add the CPT as a parallel sink.

---

## About Us — design follow-up

The current About Us copy is a placeholder paragraph set, written in-house. The original TODO referenced a "three-column photo grid + body text" design that hasn't been built. When real photos and a final design exist, replace the placeholder via WP admin (or update `theme/assets/copy/about-us.html` and re-run `./scripts/seed.sh`).
