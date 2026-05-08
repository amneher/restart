# Plan: Bake wp-cli into the dev WordPress image

## Goal
`make up && make seed` works end-to-end on a fresh checkout without anyone manually installing wp-cli inside the running container. Seeding currently fails with `exec: "wp": executable file not found in $PATH` because `wordpress:6.9.1-fpm-alpine` (the upstream image) doesn't ship wp-cli.

## Constraints / context
- `scripts/seed.sh` invokes `wp` via `docker compose exec -T wordpress wp --allow-root` — many calls per seed run, so latency per call matters. `docker compose run` is ~10× slower than `exec` per invocation; rules out a one-shot service.
- Existing `wordpress` named volume already has the running site state. The fix must not require a volume reset.
- Keep the WordPress version pin (`6.9.1-fpm-alpine`) stable; this PR is only about adding wp-cli, not bumping WP.
- `docker/` already houses dev-stack config (e.g. `docker/nginx.conf`), so a Dockerfile fits there.

## Out of scope
- Production / staging deploys (those don't run wp-cli at runtime).
- Bumping the WordPress version.
- Adding a Composer-managed `roave/wp-cli` or other PHP-package approach — image-level install is simpler.

## Approach (recommended)

**Multi-stage Dockerfile** that pulls the official `wordpress:cli` image as a builder stage and copies just the `wp` binary into the existing `wordpress:fpm-alpine` runtime. No `apk add` calls, no curl, no shell drift.

```dockerfile
# docker/Dockerfile.wordpress
ARG WP_VERSION=6.9.1
FROM wordpress:cli-2.12.0 AS cli
FROM wordpress:${WP_VERSION}-fpm-alpine
COPY --from=cli /usr/local/bin/wp /usr/local/bin/wp
```

The `wordpress:cli` image (officially published by WordPress) ships `wp` as a phar at `/usr/local/bin/wp`. Copying it preserves the phar's executable bit; no extra setup needed. The fpm-alpine runtime already has PHP installed, which is all the phar needs.

`docker-compose.yml` change: replace `image: wordpress:6.9.1-fpm-alpine` with:
```yaml
build:
  context: ./docker
  dockerfile: Dockerfile.wordpress
  args:
    WP_VERSION: 6.9.1
image: restart-wordpress:dev
```
The named `image:` line tells compose to tag the build, so re-runs use the cached layer.

## Alternative (rejected)

Add a separate `wpcli` compose service using `wordpress:cli` directly, mounting the wordpress volume, and rewrite `seed.sh` to call `docker compose run --rm wpcli wp ...` instead of exec'ing into the wordpress container. Pros: zero Dockerfile, standard WP-Docker pattern. Con: each `wp` call spawns a fresh container — adds 1–2 minutes to a seed that currently takes ~10 seconds. Not worth it for our setup.

## Changes per file

1. **`docker/Dockerfile.wordpress`** (new) — the three-line Dockerfile above.
2. **`docker-compose.yml`** — swap the `wordpress` service's `image:` directive for a `build:` block as shown.
3. **No `seed.sh` change** — the script keeps using `exec` with the same `wp` invocation; it just works once wp-cli is in the image.
4. **No README change required** — `make up` already builds when needed; the build step is transparent.

## Risk / rollout
- First `make up` after this lands triggers a one-time image build (~30–60 s on cold pull, faster on warm). Subsequent runs use the cache.
- Pinning `wordpress:cli-2.12.0` means we control when wp-cli updates. If left as `wordpress:cli` (no tag) it follows latest, which is fine but less reproducible.
- The two stages should be on the same WordPress major to avoid db-schema drift between the cli phar's expectations and the runtime. wp-cli 2.12 supports WP 6.9 — confirmed.
- `make reset` (which does `docker compose down -v`) still works the same way; the Dockerfile builds a new image, but volumes behave identically.

## Verification
After implementing:
1. `make down -v` to reset.
2. `make up` — confirm WP container builds without error.
3. `docker exec restart-wordpress-1 wp --version --allow-root --path=/var/www/html` — should print `WP-CLI 2.12.0`.
4. `make seed` from a clean stack — should run end-to-end without the "exec: wp: file not found" error.
5. Existing `make theme-test` and `make lambda-test` still pass.

## Todo
- [ ] Add `docker/Dockerfile.wordpress` with the multi-stage definition.
- [ ] Update `docker-compose.yml` `wordpress` service to `build:` instead of `image:`.
- [ ] Verify on a `down -v` / `up` cycle and a `seed` run.
- [ ] Commit on a new branch (e.g. `dev/wp-cli-in-image`).
