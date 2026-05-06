# Theme Testing

The theme ships **41 tests** — 23 PHP (PHPUnit 12) and 18 JS (Jest 29 +
jsdom). Both run in CI on every push.

## Layout

```
theme/tests/
├── ThemeTestCase.php            # Base TestCase — Brain\Monkey setup/teardown
├── bootstrap.php                # Composer autoload + Brain\Monkey kickoff
├── unit/
│   ├── AppPassword/             # _restart_api_key creation hook
│   ├── Filters/                 # wp_enqueue_scripts, font enqueue, etc.
│   └── Shortcodes/              # The five theme shortcodes
└── js/
    ├── auth.test.js
    ├── contact-modal.test.js
    └── start-registry.test.js
```

## Running

```bash
cd theme

# PHP
composer install
./vendor/bin/phpunit               # or: phpunit --testsuite=unit
./vendor/bin/phpunit --filter Login

# JS
npm ci
npm test                           # all Jest tests
npm test -- auth                   # only auth.test.js
```

The Make targets:

```bash
make theme-test           # PHP + JS
make theme-test-php
make theme-test-js
```

## PHP test patterns

### Brain\\Monkey for WP functions

`ThemeTestCase` runs Brain\\Monkey's `setUp()` / `tearDown()` around each
test. Inside a test you stub WP functions you intend to use:

```php
\Brain\Monkey\Functions\when('is_user_logged_in')->justReturn(true);
\Brain\Monkey\Functions\when('wp_get_current_user')->justReturn($user);
```

### `include` instead of `require_once`

`functions.php` defines its shortcodes inside `add_shortcode()` callbacks. To
re-execute the file per test (so each test gets a clean callback registry),
use **`include`** rather than `require_once`. PHPUnit caches included files,
but `include` re-runs the top-level statements:

```php
public function test_login_shortcode_renders(): void {
    \Brain\Monkey\Functions\when('is_user_logged_in')->justReturn(false);
    include __DIR__ . '/../../../functions.php';
    $output = do_shortcode('[restart_login_form]');
    $this->assertStringContainsString('<form', $output);
}
```

This pattern is used across the shortcode tests.

## JS test patterns

### jQuery Deferred for AJAX mocking

`auth.js` and friends use `$.post(...).done(...).fail(...)` chains. Jest
tests stub `$.post` to return a real jQuery `Deferred` so the chain behaves
exactly like production:

```javascript
const dfd = $.Deferred();
$.post = jest.fn().mockReturnValue(dfd);
// trigger the form submit
$('#rr-register-form').submit();
// resolve to drive the .done() branch
dfd.resolve({ success: true, data: { redirect: '/my-account/' } });
```

This catches subtle bugs that a generic `jest.fn().mockResolvedValue(...)`
would mask — for example, that the code expected `.done()` semantics, not
Promise semantics.

### `setTimeout` instead of `setImmediate`

jsdom does not provide `setImmediate`. The tests use `setTimeout(fn, 0)` to
schedule "after this tick" assertions:

```javascript
setTimeout(() => {
    expect(window.location.href).toBe('/my-account/');
    done();
}, 0);
```

### `include` equivalent in JS

JS files run their setup at module load. To re-run that setup per test, the
suite re-`require`s the module after clearing the require cache:

```javascript
beforeEach(() => {
    jest.resetModules();
    document.body.innerHTML = fixture;
    require('../../assets/js/auth.js');
});
```

This is the JS twin of the PHP `include` pattern.

## What is covered

| Suite | Coverage |
|-------|----------|
| `unit/Shortcodes/` | All five shortcodes — logged-out branch, logged-in branch, error states |
| `unit/Filters/` | `wp_enqueue_scripts` callback, font enqueue, `start-registry` script gating |
| `unit/AppPassword/` | The auto-create-application-password hook on `/start-a-registry/` |
| `js/auth.test.js` | Register form happy/failure, profile toggle, profile update happy/failure |
| `js/start-registry.test.js` | Submit happy/failure, private toggle, validation errors |
| `js/contact-modal.test.js` | Open, close on escape/backdrop/button, body scroll lock |

## CI

`make theme-test` runs in `ci.yml` on every push. There is no theme-specific
deploy workflow; theme releases are currently manual zip uploads built with
`make theme-pack`.
