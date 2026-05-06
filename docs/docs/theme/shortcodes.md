# Theme Shortcodes

The theme registers five shortcodes in `theme/functions.php`. They are
distinct from the **plugin** shortcodes — the theme owns auth, account, and
the start-a-registry flow; the plugin owns the registry view and item
management.

## `[restart_start_registry]`

The "create a registry" form rendered on `/start-a-registry/`. POSTs directly
to the Lambda (not via WP AJAX) using a per-user application password
localized into the page from `start-registry.js`.

**Behavior:**

- Not logged in → renders a login prompt with a link to the login page.
- Logged in → renders the form.

**Form fields:**

| Field | Required | Notes |
|-------|----------|-------|
| Registry Name | yes | 1–200 chars |
| My Situation | no | One of: `divorce`, `separation`, `moving-on`, `getting-out`, `other` |
| My Date | no | `<input type="date">` |
| My Story | no | ≤2000 chars; appears on the public registry page |
| Private Registry? | no | Toggle. Reveals the invitees field when on. |
| Invite by Username | no | Comma-separated WP usernames |

**Required page:** `/start-a-registry/` — created by `make seed` with the
`templates/page-start-a-registry.html` template.

```html
[restart_start_registry]
```

The form's submit handler is in `assets/js/start-registry.js`; see
[JavaScript](js.md).

## `[restart_my_account]`

Account management page (`/my-account/`). Shows the logged-in user's profile
information and offers an "Edit Profile" toggle that exposes display name,
email, and password fields.

**Behavior:**

- Not logged in → renders a "please log in" notice with a link.
- Logged in → renders the profile card + edit panel.

**Required page:** `/my-account/` (template `templates/page-my-account.html`).

```html
[restart_my_account]
```

Form submission is handled by `assets/js/auth.js` via `wp_ajax_restart_update_profile`.

## `[restart_login_form]`

Standard login form rendered on `/login/`.

**Required page:** `/login/` (template `templates/page-login.html`).

```html
[restart_login_form]
```

Behavior is delegated to standard WP — the form posts to `wp-login.php`.
On success, the user is redirected to the page configured by WordPress
(typically `/my-account/`).

## `[restart_register_form]`

User-registration form rendered on `/register/`. Submits via AJAX to
`wp_ajax_nopriv_restart_register` (registered in `functions.php`), which
creates a `subscriber`-role user and redirects to `/my-account/`.

**Required page:** `/register/` (template `templates/page-register.html`).

```html
[restart_register_form]
```

Form submission is handled by `assets/js/auth.js`.

## `[restart_my_registries]`

A list of the logged-in user's registries on `/my-registries/`. Pulls
registry data via the WP REST API for the `restart-registry` CPT (the user
sees their own posts including drafts and private ones) and renders a card
grid with title, item count, and quick actions.

**Behavior:**

- Not logged in → renders a "please log in" notice.
- Logged in, no registries → renders a "Start your first registry"
  CTA pointing at `/start-a-registry/`.
- Logged in, has registries → renders the card grid.

**Required page:** `/my-registries/` (template `templates/page-my-registries.html`).

```html
[restart_my_registries]
```

## Required pages — summary

`make seed` creates all five so you do not have to:

| Slug | Template | Shortcode |
|------|----------|-----------|
| `/login/` | `page-login.html` | `[restart_login_form]` |
| `/register/` | `page-register.html` | `[restart_register_form]` |
| `/my-account/` | `page-my-account.html` | `[restart_my_account]` |
| `/my-registries/` | `page-my-registries.html` | `[restart_my_registries]` |
| `/start-a-registry/` | `page-start-a-registry.html` | `[restart_start_registry]` |
