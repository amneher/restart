# Plan: Site-wide link + path audit

Branch suggestion: `audit/links-and-paths`

## Goal

Walk every internal link, form action, and hardcoded path on the site, verify each one resolves to its intended destination (correct page, 200 response, no surprise redirect), and fix any that don't. Output: a clean PR with one atomic commit per fix and a manifest of every link checked.

## Scope (locked in)

In:
- All theme templates, parts, and patterns under `theme/templates/`, `theme/parts/`, `theme/patterns/` — hardcoded `href="..."` attributes.
- Plugin-emitted links in `plugin/public/` and `plugin/includes/` (registry pages, action URLs, share links, REST endpoints).
- The nav surfaces a real visitor traverses: header, footer, front page, registry/account pages (`/registry/`, `/start-a-registry/`, `/my-registries/`, `/my-account/`, `/login/`, `/register/`, `/about-us/`, `/faq/`).
- Form `action="..."` attributes and their handlers.
- 404 + login redirects.
- Logged-out **and** logged-in (`demo` user) traversals.
- Plugin admin (`wp-admin/admin.php?page=restart-registry` and friends) — included by user request.
- External links — spot-check a sample, not full validation.
- Placeholder pages (`/terms-and-conditions/`, `/privacy-policy/`, etc.): if they 404, **create stub pages** as part of this PR AND flag them in the PR body so the user knows to fill them in.

Out:
- Lambda S3 storage URLs and image asset paths (different problem, different fix).
- IA / nav structure changes ("should this link exist?") — fix means *make existing links resolve correctly*, not redesign navigation.

Single PR for everything (audit + fixes + stub pages).

## Approach

Two passes, in this order:

### Pass 1 — Static inventory (catches what's in the code)
Grep every `href=`, `action=`, `home_url(`, `site_url(`, `get_permalink(`, `admin_url(`, `rest_url(`, and `wp_redirect(` across `theme/` and `plugin/`. Build a manifest:

| Source file:line | URL | Type (internal/external/dynamic) | Expected destination |

This catches paths that exist in code but might never be linked from a rendered page (e.g., a footer link that's wrapped in a conditional).

### Pass 2 — Live crawl (catches what actually renders)
For each "entry surface" (front page, each major template), use `/browse` to render the page, extract every `<a href>` and `<form action>`, and check each URL with `curl -I` to capture status + final location. Do this twice — once logged out, once as `demo`.

Merge both passes into one master list. Each row gets a status:
- ✅ resolves to expected page
- ⚠️ redirects (record where to — sometimes that's correct, sometimes it's a missing slash issue)
- ❌ 404 / wrong destination
- ➖ external (note only, don't validate)

### Pass 3 — Fix
For each ❌ and any ⚠️ that's wrong:
1. Identify whether it's a code path issue (theme/plugin file emits the wrong URL) or a content issue (a CMS page slug changed).
2. Code-path fixes go in atomic commits, one per fix, with file:line referenced.
3. Content issues get flagged in the PR description for the user to handle in WP admin (we don't edit the database in this branch).

After each fix: re-run that link through curl, confirm 200, screenshot if it's a UI-affecting change.

## Risk / what could go sideways

- The `demo` user's logged-in URLs include registry slugs — those are dynamic per seed. Crawl needs to handle that (start from `/my-registries/`, follow into the first registry, etc., rather than hardcoding slugs).
- Some "broken" links might actually be intentional placeholders (`/terms-and-conditions/`, `/privacy-policy/` — I see these in the footer; they may not have backing pages yet). We surface them but you decide whether to fix or accept.
- WordPress trailing-slash normalization can make `/foo` → `/foo/` 301s look like "broken" when they're fine. We classify 301-to-canonical as ✅, not ⚠️.

---

## Todo

- [x] Pass 1: branch (`audit/links-and-paths`), static grep across theme + plugin.
- [x] Pass 2a: live crawl logged out — all major surfaces hit.
- [x] Pass 2b: live crawl as `demo` — front, my-registries, single registry, my-account, start-a-registry.
- [x] Pass 2c: live crawl plugin admin as `admin` — dashboard, list, affiliates, settings.
- [x] Pass 2d: external spot-check (3 social URLs).
- [x] Triage: 2 broken (Terms, Privacy 404s), 1 inconsistency (Privacy missing from `footer-default.php` pattern), 1 content issue to flag (`#contact` nav anchor). Social URL placeholders are intentional.
- [x] Pass 3a: footer pattern parity. (72ba535)
- [x] Pass 3b: stub pages via seed extension; Privacy reuses WP's reserved draft. (5a225bb)
- [x] Audit manifest committed at `audit/link-inventory.md`. (556c6c4)
- [x] Verify: all 11 user-facing URLs return 200; theme phpunit 23/23 green.
- [ ] Push branch + open PR.
