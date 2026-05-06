# JavaScript

The theme ships three JS files under `assets/js/`. Each is a self-contained
IIFE that depends on jQuery (the WP-bundled version) and reads its
configuration from a globally-scoped object that PHP injects via
`wp_localize_script`.

## Files

| File | Global config | Pages it activates on |
|------|---------------|------------------------|
| `auth.js` | `restartAuth` | `/register/`, `/my-account/` |
| `start-registry.js` | `restartRegistry` | `/start-a-registry/` |
| `contact-modal.js` | `restartContactModal` (or inline) | Site-wide (modal trigger anywhere) |

### Localization pattern

PHP enqueues each script and immediately calls `wp_localize_script` to inject
the runtime config:

```php
wp_enqueue_script('restart-start-registry', '.../start-registry.js', [], $version, true);
wp_localize_script('restart-start-registry', 'restartRegistry', [
    'lambdaUrl'    => defined('RESTART_LAMBDA_URL') ? constant('RESTART_LAMBDA_URL') : 'http://localhost:5000',
    'username'     => $user->user_login,
    'apiKey'       => $api_key,            // a freshly-issued WP application password
    'myAccountUrl' => home_url('/my-account/'),
]);
```

This is how the front-end gets the per-user application password it uses to
authenticate against the Lambda — there is no public token endpoint.

## `auth.js`

Powers the registration and profile-edit forms. Three jQuery delegated
handlers:

### Register form (`#rr-register-form`)

POSTs to `wp_ajax_nopriv_restart_register` with username, email, password.
On `success: true`, redirects to `res.data.redirect`. On failure, displays
the message in `#rr-register-error`.

### Profile edit toggle (`#rr-edit-profile-toggle`, `#rr-edit-profile-cancel`)

Slides `#rr-edit-profile-panel` into view when the user clicks "Edit
Profile". The cancel button closes it again. Uses jQuery `.slideDown()` /
`.slideUp()` with a 200ms duration.

### Profile update form (`#rr-profile-form`)

POSTs to `wp_ajax_restart_update_profile` (logged-in only) with display
name, email, and an optional new password. On success, displays a confirmation
message and clears the password field. On failure, displays the message in
`#rr-profile-error`.

**Config:** `restartAuth.ajaxUrl`, `restartAuth.registerNonce`.

## `start-registry.js`

Powers the create-registry form on `/start-a-registry/`. Posts directly to
the Lambda using the localized application password.

Behavior:

- Toggling the "Private Registry?" checkbox shows/hides the invitees field.
- On submit, validates required fields client-side, then POSTs to
  `${restartRegistry.lambdaUrl}/registries` with `Authorization: Basic
  base64(${restartRegistry.username}:${restartRegistry.apiKey})`.
- On success, redirects the browser to `restartRegistry.myAccountUrl` (or
  the new registry's permalink, depending on flow).
- On failure, shows the API error in `#restart-form-error`.

**Config:** `restartRegistry.lambdaUrl`, `restartRegistry.username`,
`restartRegistry.apiKey`, `restartRegistry.myAccountUrl`.

The application password is created server-side in `functions.php` the first
time a logged-in user lands on `/start-a-registry/` (cached in
`_restart_api_key` user meta), so the form is always ready to call the
Lambda.

## `contact-modal.js`

Powers a site-wide "contact us" modal. Opens when a `[data-rr-contact-open]`
trigger is clicked, closes on backdrop click, on the close button, or on
`Escape`. Submits to a contact form (configurable — defaults to the
WordPress comments form for now).

**Config:** Reads from a `window.restartContactModal` global if present, or
falls back to data attributes on the modal root.

## Test coverage

All three JS files are tested with Jest under `theme/tests/js/`:

- `auth.test.js` — register, profile toggle, profile update happy paths and
  failure paths.
- `start-registry.test.js` — form submission, private toggle, error display.
- `contact-modal.test.js` — open, close-on-escape, close-on-backdrop.

Tests use **jsdom** and load a fixture HTML snippet, then re-execute the
script via `include` so global handlers re-bind per test. jQuery `Deferred`
is used to mock `$.post` (rather than a generic Jest mock) so `.done()` /
`.fail()` chains behave naturally. `setTimeout` is used in tests instead of
`setImmediate` because `setImmediate` is not available in jsdom.

See [Theme Testing](testing.md) for the full pattern.
