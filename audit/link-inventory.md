# Link & Path Audit — Inventory

Generated 2026-05-09 against the local stack at `http://localhost:8083`.

Two passes: static grep across `theme/` + `plugin/`, then live crawl of every major surface logged-out, as `demo`, and as `admin` (plugin admin pages). Every URL in this manifest was hit with `curl` and the response code recorded.

Legend: ✅ resolves correctly · ⚠️ resolves to a redirect (canonical, OK) · ❌ broken (404 / wrong destination) · 🚩 content issue (works as a URL but points somewhere wrong) · ➖ external (spot-checked only).

## Internal paths

| URL | Status | Source(s) | Notes |
|---|---|---|---|
| `/` | ✅ 200 | `theme/parts/header.html:8`, `theme/templates/404.html:39`, plugin auth redirects | |
| `/registry/` | ✅ 200 | `theme/templates/404.html:43`, `theme/templates/front-page.html:26` | archive-restart-registry template |
| `/start-a-registry/` | ✅ 200 | `theme/templates/front-page.html:22,125`, `theme/patterns/call-to-action.php:29` | |
| `/my-registries/` | ✅ 200 | rendered nav | page-my-registries template |
| `/my-account/` | ✅ 200 | `mu-plugins/restart-auth.php:30,42,83`, rendered nav | post-login redirect target |
| `/login/` | ✅ 200 | `mu-plugins/restart-auth.php:8,23,80` | |
| `/register/` | ✅ 200 | `mu-plugins/restart-auth.php:13` | |
| `/about-us/` | ✅ 200 | rendered nav | |
| `/faq/` | ✅ 200 | rendered nav | |
| `/category/articles/` | ✅ 200 | `theme/templates/front-page.html:64` | |
| `/category/guides/favorites/` | ✅ 200 | `theme/templates/front-page.html:81` | |
| `/category/guides/gifts/` | ✅ 200 | `theme/templates/front-page.html:98` | |
| `/category/articles` (no slash) | ⚠️ 301 → `/category/articles/` | — | canonical, OK |
| `/category/guides/favorites` (no slash) | ⚠️ 301 → `/category/guides/favorites/` | — | canonical, OK |
| `/category/guides/gifts` (no slash) | ⚠️ 301 → `/category/guides/gifts/` | — | canonical, OK |
| `/terms-and-conditions/` | ❌ 404 | `theme/parts/footer.html:80`, `theme/patterns/footer-default.php:89` | **fix: stub page** |
| `/privacy-policy/` | ❌ 404 | `theme/parts/footer.html:84` | **fix: stub page** |
| `/demo-article-1..5-registry-tips-and-ideas/` | ✅ 200 | rendered cards on front page | seed content |
| `/registry/alexs-new-chapter/` | ✅ 200 | rendered list | seed registry |
| `/registry/alexs-fresh-start-registry/` | ✅ 200 | rendered list | seed registry |
| `/my-registry/` | ✅ 200 | plugin admin "view page" link | plugin-created shortcode page (singular) — distinct from `/my-registries/` (plural). IA quirk, not broken. |
| `/wp-login.php` | ✅ 200 | login form action | WP core |
| `/wp-admin/admin.php?page=restart-registry` | ✅ 200 | admin menu | dashboard |
| `/wp-admin/admin.php?page=restart-registry-list` | ✅ 200 | admin menu | all registries |
| `/wp-admin/admin.php?page=restart-registry-affiliates` | ✅ 200 | admin menu | affiliate settings |
| `/wp-admin/admin.php?page=restart-registry-settings` | ✅ 200 | admin menu | plugin settings |
| Form `action="options.php"` | ✅ | `plugin/admin/class-restart-registry-admin.php:534,591` | WP settings API |
| Form `action="admin-post.php"` | ✅ | `plugin/admin/class-restart-registry-admin.php:732` | WP admin-post API |

## Content issues (flagged — no code fix)

| URL | Source | Issue |
|---|---|---|
| `https://facebook.com` / `https://instagram.com` / `https://linkedin.com` | `theme/parts/footer.html:18-22`, `theme/patterns/footer-default.php:27-31` | intentional placeholders — owner doesn't have real social URLs yet. Not flagged. |
| `#contact` | rendered Account `wp-block-navigation` (CMS-managed, not in theme files) | 🚩 fragment points to non-existent anchor on every page that renders this nav. Fix in WP admin → Appearance → Navigation. |

## Inconsistencies (code-fixable)

| Issue | Files | Fix |
|---|---|---|
| `theme/parts/footer.html` has both Terms + Privacy; `theme/patterns/footer-default.php` only has Terms | `theme/patterns/footer-default.php:89` (Privacy missing after this line) | add Privacy Policy `<p>` to the pattern to match the part |

## External (spot-checked)

| URL | Status |
|---|---|
| `https://facebook.com` | ➖ 301 (canonical to www, OK) |
| `https://instagram.com` | ➖ 301 (OK) |
| `https://linkedin.com` | ➖ 301 (OK) |
| `https://www.amazon.com/dp/B*` (item purchase links, ~8 sampled) | ➖ not validated — content URLs from registry items |

## Summary

- **2 broken URLs** (both placeholder pages: Terms & Conditions, Privacy Policy). Fix path: create stub pages on the running stack + extend the seed script so future fresh installs have them.
- **1 code inconsistency** (`footer-default.php` missing Privacy Policy `<p>`). Fix path: add the line.
- **1 content issue** to flag: `#contact` nav anchor points nowhere (CMS-managed). Social URL placeholders are intentional and not flagged.
- **0 broken plugin admin paths**.
- **0 broken auth redirects**.
