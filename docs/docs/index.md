# Restart Registry

> Gift registry platform for people restarting after divorce.

[![CI](https://github.com/amneher/restart/actions/workflows/ci.yml/badge.svg)](https://github.com/amneher/restart/actions/workflows/ci.yml)
[![Deploy Staging](https://github.com/amneher/restart/actions/workflows/deploy-staging.yml/badge.svg)](https://github.com/amneher/restart/actions/workflows/deploy-staging.yml)
[![Deploy Prod](https://github.com/amneher/restart/actions/workflows/deploy-prod.yml/badge.svg)](https://github.com/amneher/restart/actions/workflows/deploy-prod.yml)

The Restart Registry is a gift registry built for people in transition — divorce,
relocation, separation, or any moment when a household is starting over and the
traditional wedding-registry framing does not fit. The codebase is a monorepo with
three deployable components.

## The three components

| Component | Tech | Version | Role |
|-----------|------|---------|------|
| **`plugin/`** | WordPress plugin (PHP) | `1.0.8` | Owns registry data as a WordPress custom post type, renders shortcodes, and handles AJAX. Talks to the Lambda over HTTP for item data. |
| **`lambda/`** | FastAPI on AWS Lambda (Python 3.14) | `1.0.3` | Persists items in SQLite on EFS. Validates every request against WordPress Basic auth via `wp-python`. |
| **`theme/`** | WordPress block theme (PHP/JS) | `1.0.1` | Full-site-editing theme `theRestart`. Owns the public site chrome, account pages, and the start-a-registry / login / register flows. |

The components ship on independent, scoped tags — `plugin/v*`, `lambda/v*`,
`theme/v*` — and have separate release pipelines, but live in one repo so a
single `make up` brings the whole stack online for development.

## Quick links

| If you want to… | Read |
|------------------|------|
| Run the stack locally for the first time | [Getting Started](getting-started.md) |
| Understand how the pieces fit together | [Architecture](architecture.md) |
| Develop, test, lint, or release a component | [Development](development.md) |
| Contribute a change | [Contributing](contributing.md) |
| Browse the source | [GitHub](https://github.com/amneher/restart) |

!!! tip "Where to start reading"
    If you have never worked on this codebase before, read the three pages in
    order: **Getting Started → Architecture → Development**. Then drop into the
    component-specific docs (Plugin, Lambda, Theme) as you touch each one.

## What makes this codebase a little unusual

- **WordPress is the database for registries.** Each registry is a `restart-registry`
  custom post type. Items live in SQLite on the Lambda — registries do not.
- **The Lambda has no user table.** Every request carries WordPress Basic auth, and
  the Lambda calls back to WordPress via `wp-python` to validate the credentials and
  resolve the user.
- **Three release tracks, one repo.** Tags are scoped (`plugin/v1.0.8`, `lambda/v1.0.3`,
  `theme/v1.0.1`) and only that component's pipeline fires.

If any of those surprise you later, that is by design — see [Architecture](architecture.md)
for the why.
