# Plugin Testing

The plugin ships **70 PHPUnit tests** — 34 unit and 36 integration. The
boundary between the two is the controller seam: anything that exercises
`Restart_Registry_Controller` against fakes is integration; anything else is
unit.

## Layout

```
plugin/tests/
├── bootstrap.php
├── Fakes/                     # Test doubles (LambdaClientFake, etc.)
├── unit/
│   ├── AffiliateConverterTest.php
│   ├── ControllerTruncateNameTest.php
│   └── RetailerApiTest.php
└── integration/
    ├── Controller/
    │   ├── AccessControlTest.php
    │   ├── MarkItemPurchasedTest.php
    │   └── InviteTest.php
    └── LambdaClient/
        ├── LambdaClientTest.php
        └── wp-clean.sql
```

## Running the suite

```bash
cd plugin
composer install
./vendor/bin/phpunit              # all tests
./vendor/bin/phpunit --testsuite=unit
./vendor/bin/phpunit --testsuite=integration
./vendor/bin/phpunit --filter MarkItemPurchased   # by class
```

The Make targets are equivalent:

```bash
make plugin-test          # PHP + JS
make plugin-test-php      # only PHP
make plugin-test-js       # only Jest (admin UI)
```

## Mocking strategy

### Brain\\Monkey for WordPress functions

Unit tests stub global WP functions (`get_post`, `update_post_meta`,
`is_email`, `wp_mail`, etc.) with [Brain\\Monkey][brain]. Each test
case extends a base that calls `Brain\\Monkey\\setUp()` /
`Brain\\Monkey\\tearDown()` around each test.

Patchwork is loaded via `patchwork.json` to allow redefining the fixed
functions used by the plugin.

[brain]: https://github.com/Brain-WP/BrainMonkey

### `LambdaClientFake` for the Lambda

Integration tests use a fake that satisfies the same interface as
`Restart_Registry_Lambda_Client` (`get_item`, `get_items`, `create_item`,
`update_item`, `delete_item`) backed by an in-memory PHP array. The fake is
injected via the controller constructor:

```php
$lambda     = new LambdaClientFake();
$controller = new Restart_Registry_Controller($lambda);
```

This is also why `LambdaClient::request()` is `private` rather than
`protected` — the boundary is the **client class**, not the HTTP method.

## What is covered

| Area | File |
|------|------|
| Affiliate URL conversion (per retailer + edge cases) | `unit/AffiliateConverterTest.php` |
| Item-name truncation logic | `unit/ControllerTruncateNameTest.php` |
| Etsy retailer API metadata fetching | `unit/RetailerApiTest.php` |
| `can_view_registry` / `can_edit_registry` matrix | `integration/Controller/AccessControlTest.php` |
| `mark_item_purchased` happy path + quantity exceeded + opt-out | `integration/Controller/MarkItemPurchasedTest.php` |
| `send_invite` + dedupe + email branching | `integration/Controller/InviteTest.php` |
| `Lambda_Client` HTTP behavior (auth headers, error mapping) | `integration/LambdaClient/LambdaClientTest.php` |

## JavaScript tests

The plugin's admin JS (settings page) is covered by Jest under
`plugin/tests/js/`. Configured in `plugin/jest.config.js`. Run with:

```bash
cd plugin
npm test
```

## Writing a new test

1. **Pick the boundary.** Pure-function or single-class? `tests/unit/`. Crosses
   the controller? `tests/integration/Controller/`. Tests HTTP wire shape?
   `tests/integration/LambdaClient/`.
2. **Stub WP functions you use.** Brain\\Monkey's `Functions\\when('foo')->...`
   keeps the test scoped to the behavior under test.
3. **Use the existing fakes.** `LambdaClientFake` should cover almost every
   integration scenario without reaching for `Mockery`.
4. **Name your test method `test_<does_what>` and keep it ≤30 lines.** Larger
   tests usually want to be split.

## CI

`make plugin-test` runs in `.github/workflows/ci.yml` on every push. The plugin
release workflow (`release-plugin.yml`) runs the suite again before building
the distribution zip.
