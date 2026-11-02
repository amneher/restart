# Brand Name Audit: Inventory

Generated 2026-11-01 by static grep across the repo (excluding `node_modules/`, `vendor/`, `coverage/`, minified files, and lockfiles).

**Canonical name: "The reStart"** (capital T, lowercase r, capital S, including mid-sentence). "The reStart.com" is also acceptable. Legal entity: "reStart Group, LLC". Alternate spellings belong only in schema.org `alternateName`.

The lowercase `restart` / `the-restart` hits (~1,300) are code identifiers, slugs, CSS classes, and URLs and are excluded. Email addresses keep the domain (see section C).

Legend: 🔧 change in the LLM-readability PR · 📝 change in the follow-up rename PR · ❓ needs a decision · ➖ leave as is

## A. In this ticket (LLM-readability PR) 🔧

| File | Lines | Current |
|---|---|---|
| `theme/assets/copy/faq.md` | 6, 15, 16, 20, 30, 31, 46, 50, 65, 70 | "reStart" |
| `theme/assets/copy/faq.html` | 5, 12, 28, 49, 56 | "the reStart" (will be regenerated from `faq.md`) |
| `theme/patterns/faq-content.php` | 14, 21, 37, 58, 65 | "the reStart" |
| **Live:** FAQ page post content | n/a | "the ReStart" (seen by live fetch); repo changes don't reach it because the activator only seeds new pages |
| **Live:** Settings → General → Site Title | n/a | "the ReStart" per `docs/data/wp-json.json:2`; feeds Yoast's schema `WebSite`/`Organization` name, `og:site_name`, and the `llms.txt` header |
| **Live:** `/llms.txt` (Yoast-generated) | n/a | header "the ReStart", body "The Restart"; comes from the Site Title + tagline |

## B. User-facing, follow-up rename PR 📝

| File | Lines | Current | Notes |
|---|---|---|---|
| `theme/parts/header.html` | 8, 9, 10 | "the reStart" | `aria-label` / `alt` on logo; screen readers announce this |
| `theme/parts/footer.html` | 12 | "the reStart" | `aria-label` |
| `theme/patterns/footer-default.php` | 21 | "the reStart" | `aria-label` |
| `theme/templates/front-page.html` | 148 | "Welcome to the reStart!" | |
| `theme/templates/front-page.html` | 178 | "Divorce is tough.The reStart" | name is right; missing space after the period |
| `theme/functions.php` | 353 | "reStart usernames" | share-form hint text |
| `theme/assets/copy/about-us.md` / `.html` | 5 / 6 | "the reStart" | |
| `theme/assets/copy/terms-and-conditions.md` | 5, 11, 31, 43 | "reStart" | `.html` lines 9, 27, 87, 125 |
| `plugin/includes/class-restart-registry-controller.php` | 728, 747 | "The Restart Team" | email sign-offs (translatable strings) |
| `content/seo/how-to-create-a-divorce-registry.md` | 3, 7, 15 | "the ReStart" | articles are already published, so the live posts need editing too |
| `content/seo/what-is-a-divorce-registry.md` | 13, 33 | "reStart" | same |
| `content/seo/why-divorce-registries-matter.md` | 37 | "reStart" | same |
| `content/seo/how-to-share-your-divorce-registry.md` | 43 | "reStart" | same |
| `scripts/seed.sh` | 40, 146 | "the ReStart" | local dev site title |
| **Live:** plugin option `restart_registry_email_name` | n/a | probably "Restart" (test fixture `MarkItemPurchasedTest.php:28` uses it) | "From" name on registry emails |
| **Live:** post titled "The Restart Divorce Registry: Rebuilding After Divorce, One Toaster at a Time" (`/divorce-registry/`) | n/a | "The Restart" | listed in live `llms.txt`; not in repo |
| **Live:** any other posts, menus, widgets, Yoast titles | n/a | unknown | search with `wp db search 'restart' --all-tables` (read-only, case-insensitive) if Bluehost SSH/wp-cli is available, or WP admin search |

## C. Needs a decision ❓

| Item | Where | Question |
|---|---|---|
| ✅ RESOLVED 2026-11-01: legal entity is "reStart Group, LLC"; legal copy updated on `fix/llm-readability`. Original finding: Legal entity name | "The Restart Co": `privacy-policy.md:5,109`, `terms-and-conditions.md:81,103` (`.html` 9/331, 239/305). "reStart Group, LLC": `footer.html:80`, `footer-default.php:89` Also "The Restart.com" as the company name in `privacy-policy.html:9` (from commit `3671275`; the `.md` says "The Restart Co") | Three different legal names. Which is the registered entity? Legal names follow the filing, not the brand style. |
| Hero tagline | `front-page.html:12`: "reStart here." + screen-reader "theRestart" | "reStart here." is wordplay and can stay; the screen-reader text "theRestart" should probably become "The reStart". |
| Email address casing | `hello@the-reStart.com` in `faq.md:105,110`, `faq-content.php:86,93`, `about-us.md:25` | Email is case-insensitive. Lowercase `hello@the-restart.com` matches the domain everywhere else. Cosmetic. |
| Theme directory `theRestart` | `header.html:9,10` asset paths | Renaming the theme directory breaks paths and the active-theme setting. Recommend leaving it. |

## D. Leave as is ➖

Internal/project names that users never see, or false positives:

- Plugin and service names: "Restart Registry" (`plugin/restart-registry.php:16`, `plugin/README.md`, `lambda/README.md:2`, `lambda/app/main.py:25`, `lambda/app/models/registry.py:57` CPT admin menu label, `docs/mkdocs.yml`, `docs/docs/index.md`, `docs/docs/lambda/overview.md:47`)
- AWS resource names: `Restart-Lambda-Staging` (`lambda/Makefile:11`, both `deploy-staging.yml`, `docs/docs/lambda/deploy.md`); renaming means redeploying infrastructure
- Lint/tooling: `theme/phpcs.xml.dist:2`, `plugin/phpcs.xml.dist:2`, `theme/composer.json:3`, `theme/README.md:3`
- Admin-only help text: `plugin/admin/class-restart-registry-admin.php:803`
- Code comments: `class-restart-registry-public.php:7`, `class-restart-registry-favorites-blocks.php:4`, `class-restart-registry-favorites-renderer.php:7`, `class-lambda-api-client.php:6`, `restart-registry-cpt.php:3`, `ActivatorCreatePagesTest.php:360`
- Not the brand: `lambda/README.md:433` ("Restart after code changes")
- Generated snapshots/fixtures: `docs/data/wp-json.json`, `lambda/tests/fixtures/wp-clean.sql`; regenerate rather than hand-edit
