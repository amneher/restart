# Plan: Test coverage checking in GitHub CI

## Goal
Measure and enforce test coverage for all five test suites in CI (`plugin-php`, `plugin-js`, `lambda`, `theme-php`, `theme-js`), surface coverage on every PR, and fail the build when coverage regresses below an agreed floor.

## Constraints / context
- CI is `.github/workflows/ci.yml` — five jobs, each scoped to one suite.
- PHPUnit jobs currently use `coverage: none` on `shivammathur/setup-php@v2` (no driver installed → can't generate reports).
- Lambda uses `uv` + pytest; no `pytest-cov` dep yet.
- Jest is stock; coverage is a `--coverage` flag away.
- No coverage service (Codecov, Coveralls) wired up. Decide whether to add one.

## Out of scope
- Mutation testing.
- Coverage for end-to-end tests against staging/prod (only unit + integration suites).
- Coverage badges in README (can follow once thresholds hold).

---

## Decisions (confirmed)

1. **Coverage driver for PHPUnit:** **pcov**.
2. **Coverage reporter:** **artifacts-only** — each job uploads its coverage report via `actions/upload-artifact@v4`. No third-party service.
3. **Floor thresholds:** **0% on first land** to observe baselines; ratchet in a follow-up.
4. **Failure mode:** hard-fail at the threshold (currently 0%, so it never fires).

---

## Changes

### 1. Lambda (pytest)
- Add `pytest-cov` to `[dependency-groups].dev` in `lambda/pyproject.toml`.
- Add `[tool.coverage.run]` (`source = ["app"]`, omit tests) and `[tool.coverage.report]` (exclude `if TYPE_CHECKING:` and `if __name__ == "__main__":`).
- Update CI step: `uv run pytest tests/ -v --cov=app --cov-report=xml --cov-report=term --cov-fail-under=70`.
- Update local `make lambda-test` and `lambda/Makefile` `test` target so dev parity holds.

### 2. Plugin — PHPUnit
- Add `<coverage>` block to `plugin/phpunit.xml` declaring `<include><directory>includes</directory><directory>public</directory><directory>admin</directory></include>` and reporters (Clover XML + text).
- Change CI `setup-php` `coverage: none` → `coverage: pcov`.
- CI step: `vendor/bin/phpunit --coverage-clover=coverage.xml --coverage-text` then enforce the floor via PHPUnit 12's `--min-coverage` flag (or, if absent in our minor version, a small Clover-XML-parsing post-step).
- Document the threshold in `plugin/phpunit.xml` so local runs respect it too.

### 3. Plugin — Jest
- In `plugin/jest.config.js`: add `collectCoverageFrom: ['public/js/**/*.js', 'admin/js/**/*.js']` (verify paths) and `coverageThreshold: { global: { lines: 40 } }`.
- CI step: `npm test -- --coverage --coverageReporters=lcov --coverageReporters=text`. Threshold failure is automatic.
- Keep local `npm test` snappy by only producing coverage when invoked with `--coverage` (no config-level `collectCoverage: true`).

### 4. Theme — PHPUnit
- Same shape as plugin-php with thresholds at 30%. `<include>` covers `functions.php`, `parts/`, `templates/`, `patterns/` as appropriate (verify which directories actually contain testable PHP).

### 5. Theme — Jest
- Same shape as plugin-js with 30% threshold. Verify `collectCoverageFrom` glob matches the real source dirs.

### 6. Coverage reporting (if Codecov chosen)
- Add `codecov/codecov-action@v5` step after each suite, uploading the report file with a `flags:` tag (`plugin-php`, `plugin-js`, `lambda`, `theme-php`, `theme-js`).
- Add `codecov.yml` at repo root with per-flag targets matching the floors above and `comment.layout: "reach,diff,flags"`.
- Add `CODECOV_TOKEN` repo secret (one-time, manual).

### 6-alt. Artifacts-only fallback
- Each job uses `actions/upload-artifact@v4` to publish its coverage report. No PR comment, no trend graph.

### 7. Local-dev parity
- Add brief notes to root `Makefile` help text on running coverage locally (`pytest --cov=app`, `vendor/bin/phpunit --coverage-text`, `npm test -- --coverage`). No new `make` targets unless requested.

---

## Risk / rollout
- First CI run after adding thresholds will likely fail until floors are tuned. Recommend: land the wiring with `--cov-fail-under=0` / no thresholds in the same PR, observe the actual numbers, then ratchet thresholds in a follow-up PR.
- pcov is line-coverage-only. If we ever want branch coverage we'll switch to xdebug.
- Codecov outage shouldn't fail the build — set `fail_ci_if_error: false` on the upload action.

---

## Local baselines observed before push

| Suite       | Lines  | Notes                                              |
| ----------- | ------ | -------------------------------------------------- |
| lambda      | 91 %   | Strong; routes/registry.py is the only weak spot.  |
| plugin-js   | ~31 %  | Public JS at 34 %, admin JS at 0 %.                |
| theme-js    | 90 %   | All three asset modules well covered.              |
| plugin-php  | n/a    | No local pcov; will surface in CI run.             |
| theme-php   | n/a    | No local pcov; will surface in CI run.             |

## Todo

- [x] Confirm decisions: pcov, artifacts-only, 0% floor.
- [x] Lambda: add `pytest-cov`, update `pyproject.toml` coverage config, update CI + Makefile.
- [x] Plugin-php: enable pcov in CI, add `<source>` block to `phpunit.xml`, run with `--coverage-clover`.
- [x] Plugin-js: add `collectCoverageFrom` to `jest.config.js`, run `npm test -- --coverage` in CI.
- [x] Theme-php: same as plugin-php (in `phpunit.xml.dist`).
- [x] Theme-js: same as plugin-js.
- [x] Upload coverage reports as CI artifacts in each job.
- [ ] Push branch + open PR; observe CI baselines for plugin-php and theme-php.
- [ ] Follow-up PR: ratchet `--cov-fail-under` (lambda), `coverageThreshold` (Jest), and `--min-coverage` (PHPUnit) to agreed floors.
