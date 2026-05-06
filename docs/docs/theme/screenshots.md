# Screenshots

The theme's visual reference is captured automatically by a Playwright script
under `docs/scripts/screenshots.js`. Pages are rendered against a running
local stack and saved as full-page PNGs.

!!! tip "Regenerating"
    Run `make up && make seed && make docs-screenshots` (in that order) to
    regenerate. The seed step is not optional — the screenshots show the
    `demo` user's seeded registries, which only exist after `make seed`.

## What gets captured

| Page | Output |
|------|--------|
| Homepage (`/`) | `docs/docs/theme/screenshots/homepage.png` |
| Login (`/login/`) | `docs/docs/theme/screenshots/login.png` |
| Register (`/register/`) | `docs/docs/theme/screenshots/register.png` |
| My Registries (`/my-registries/`, logged in as `demo`) | `docs/docs/theme/screenshots/my-registries.png` |
| Start a Registry (`/start-a-registry/`, logged in as `demo`) | `docs/docs/theme/screenshots/start-registry.png` |

Each is a `{ fullPage: true }` capture so the entire scroll is recorded.

!!! note "Screenshots not yet generated"
    These images are created by running `make up && make seed && make docs-screenshots`.
    They are not committed to the repository. The sections below will render once
    the script has been run against a live stack.

## How the script works

`docs/scripts/screenshots.js` does the following:

1. Checks that `playwright` is importable; prints install instructions if not.
2. Creates `docs/docs/theme/screenshots/` if needed.
3. Launches a Chromium browser with a 1280×800 viewport.
4. Navigates to each public page and screenshots it.
5. Logs in via `/login/` (POSTs `demo` / `demo`), then captures the
   authenticated pages.
6. Closes the browser.

If the stack is not running, the script fails with a connection error on the
first navigation — start it with `make up && make seed` and retry.
