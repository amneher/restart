# Plugin Shortcodes

The plugin registers three shortcodes — all in
`public/class-restart-registry-public.php`.

## `[restart_registry]`

The polymorphic, do-the-right-thing shortcode. It picks a render mode based on
context:

| Context | Behavior |
|---------|----------|
| On a single `restart-registry` CPT page, viewer can edit it | Manage view (add/edit/delete items, manage invitees) |
| On a single `restart-registry` CPT page, viewer can view it | Read view (public-facing item list with "mark purchased") |
| `?registry=<id-or-slug>` query param present | Public/invitee read view |
| Logged-in user, no other context | Their own registry's manage view (or the create form if they have none) |
| Not logged in, no other context | Login prompt |

Permission checks delegate to the controller —
`can_edit_registry($post_id, $user_id)` and `can_view_registry($post_id,
$user_id)`. See [Controller API](controller-api.md).

```html
<!-- Drop into any page or template part -->
[restart_registry]
```

!!! tip "Recommended placement"
    Put `[restart_registry]` on the `single-restart-registry.html` template
    (the theme already does this) so each individual registry permalink renders
    the right view.

## `[restart_registry_view registry="..."]`

Strictly read-only. Use this on shareable pages where the owner wants to embed
their registry without giving the viewer any management UI.

| Attribute | Required | Description |
|-----------|----------|-------------|
| `registry` | yes (or `?registry=` URL param) | Numeric WP post ID, or the post slug |

```html
[restart_registry_view registry="42"]
[restart_registry_view registry="alex-fresh-start-registry"]
```

If neither the attribute nor the query parameter is provided, the shortcode
emits an error notice ("No registry specified.").

## `[restart_registry_create]`

The standalone create-a-registry form. Useful on a dedicated landing page when
you want to keep the create flow separate from the manage flow.

Behavior:

- Not logged in → renders the login prompt.
- Logged in, already has a registry → renders a "you already have a registry,
  view it →" notice.
- Logged in, no registry → renders the create form.

```html
[restart_registry_create]
```

!!! note "One registry per user"
    The plugin currently enforces a single registry per WP user
    (`Restart_Registry_Controller::create_registry` returns a
    `registry_exists` `WP_Error` if the user already has one). If you need
    multi-registry support, the constraint lives in `create_registry()`.

## Pages auto-created by `make seed`

The seed script creates a standard set of pages. The plugin shortcodes are
typically used on:

- **`/start-a-registry/`** — uses the *theme* shortcode `[restart_start_registry]`,
  not a plugin shortcode. The theme's flow POSTs directly to the Lambda for
  create.
- **`/registry/<slug>/`** — `single-restart-registry.html` template renders
  `[restart_registry]`, which detects the CPT page and dispatches.

The plugin shortcodes are most useful when you want to *embed* registry UI in
custom non-CPT pages.
