# Contributing

Thanks for taking the time to look. This page covers the local conventions:
branch naming, commit messages, the PR checklist, and per-component test
requirements.

## Branch naming

Use a short, kebab-cased prefix that maps to the type of change:

| Prefix | When to use |
|--------|-------------|
| `feat/` | New user-visible behavior |
| `fix/` | Bug fix |
| `chore/` | Tooling, build, dependency updates, infra |
| `docs/` | Documentation-only changes |
| `test/` | Adding or refactoring tests with no production change |
| `refactor/` | Internal restructuring with no behavior change |

Examples: `feat/affiliate-clean-description`, `fix/lambda-busy-timeout`,
`docs/getting-started`.

## Commit messages

Use **Conventional Commits**. The first line is `type(optional-scope):
subject`, all lower-case, no trailing period, ≤72 chars:

```
feat(plugin): truncate item names on " | " separator
fix(lambda): handle missing post_meta in registry endpoint
chore(ci): bump setup-node to v6 and cache to v5
docs(plugin): document Lambda Client API
test(theme): cover contact-modal close on outside click
```

Recognized types in this repo: `feat`, `fix`, `chore`, `docs`, `test`,
`refactor`, `perf`, `build`, `ci`, `style`. The optional scope is usually one of
`plugin`, `lambda`, `theme`, `ci`, `release`, `docs`.

Body paragraphs (separated by a blank line) are encouraged for non-trivial
changes — explain *why*, not *what*.

## PR checklist

Before opening a PR:

- [ ] **Tests pass.** `make test` is green locally. CI re-runs everything on push.
- [ ] **Lint passes.** `make lint` is green.
- [ ] **Tests added or updated.** Bug fixes get a regression test; new features
      get coverage at the layer where the behavior lives (see below).
- [ ] **Docs updated** if you changed a public API, env var, shortcode, AJAX
      action, or Lambda endpoint.
- [ ] **`plans.md` updated** if you are working from one. Check off completed
      items, add commit refs.
- [ ] **No version bump in the same PR as the feature.** Bump in a separate
      `chore(release): bump <component> to vX.Y.Z` commit/PR after merge, using
      `make bump-<component>-<patch|minor|major>`.

## Test requirements per component

### Plugin

- **Unit tests** (`plugin/tests/unit/`) for pure functions and small classes —
  `Restart_Registry_Affiliate_Converter`, `Restart_Retailer_Api`, controller
  helpers like `truncate_name`, activator page-creation logic. Brain\\Monkey
  stubs out WordPress globals.
- **Integration tests** (`plugin/tests/integration/Controller/`,
  `plugin/tests/integration/LambdaClient/`) for anything that crosses the
  controller seam — access control, mark-purchased, invites, the Lambda HTTP
  client. Use `LambdaClientFake` to keep the Lambda out of the loop.
- **Scraper tests** (`plugin/tests/integration/Scraper/`) make real HTTP
  requests to live retailer pages. Run with `make plugin-test-scraper`. These
  are intentionally excluded from the default PHPUnit suite and from CI —
  bot-detection on retailer sites is expected and causes individual tests to
  be skipped, not failed.
- A bug fix must add a failing-then-passing test in the matching directory.

### Lambda

- **Route tests** in `lambda/tests/test_*.py` use `TestClient` with the
  `client` / `admin_client` / `unauthed_client` fixtures, which override
  `get_current_user` so no network call is made.
- **Validation tests** in `lambda/tests/validation/` cover XSS / escaping /
  pydantic length limits.
- **Integration tests** in `lambda/tests/integration/` exercise wp-python
  resilience.
- E2E tests (`tests/test_registry_e2e.py`,
  `tests/test_registry_wp_integration.py`) require a live local WordPress and
  are skipped from the default suite invocation in this repo's docs.

### Theme

- **PHP** tests (`theme/tests/unit/`) cover the five shortcodes, filters, and
  the application-password creation hook. Brain\\Monkey stubs WP functions.
- **JS** tests (`theme/tests/js/`) cover `auth.js`, `start-registry.js`,
  `contact-modal.js` against a jsdom-rendered fixture HTML.

## Releasing

1. Open a PR with `chore(release): bump <component> to vX.Y.Z`.
2. After merge, push the matching tag: `git push origin <component>/vX.Y.Z`.
   Pipeline-equipped components (plugin, lambda) deploy automatically; theme
   is currently manual.
