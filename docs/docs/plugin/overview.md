# Plugin Overview

The `restart-registry` WordPress plugin is the system's source of truth for
**registries** (the metadata, the owner, the invitee list, the visibility
state). Items are persisted by the Lambda; the plugin keeps a JSON-encoded
list of Lambda item IDs in post meta to preserve order.

## At a glance

| | |
|--|--|
| **Slug** | `restart-registry` |
| **Bootstrap** | `plugin/restart-registry.php` |
| **Version** | `1.0.8` |
| **Custom post type** | `restart-registry` |
| **PHP minimum** | 8.2 |

## Layout

```
plugin/
├── restart-registry.php          # Plugin bootstrap (header + activation hooks)
├── includes/
│   ├── class-restart-registry.php             # Main plugin class
│   ├── class-restart-registry-loader.php      # Hook registry
│   ├── class-restart-registry-controller.php  # Registry CRUD façade
│   ├── class-lambda-api-client.php            # Lambda HTTP client
│   ├── class-affiliate-converter.php          # URL → affiliate URL
│   ├── class-retailer-api.php                 # Etsy/etc. metadata fetch
│   ├── class-restart-registry-activator.php   # CPT registration on activate
│   ├── class-restart-registry-deactivator.php
│   └── class-restart-registry-i18n.php
├── public/
│   └── class-restart-registry-public.php      # Shortcodes + AJAX handlers
├── admin/                         # WP-admin settings page
├── mu-plugins/                    # Bundled mu-plugins (installed on activation)
├── tests/
│   ├── unit/                      # Brain\Monkey-mocked PHPUnit
│   ├── integration/Controller/    # Controller against fakes
│   └── integration/LambdaClient/  # HTTP client tests
└── public/js/, public/css/        # Front-end assets
```

## Key classes

| Class | What it owns |
|-------|--------------|
| [`Restart_Registry_Controller`](controller-api.md) | Single façade for registry/item CRUD. Bridges the WP CPT and the Lambda. |
| [`Restart_Registry_Lambda_Client`](lambda-client.md) | HTTP client to FastAPI. Reads URL/key/auth from options or env. |
| [`Restart_Registry_Affiliate_Converter`](affiliate-converter.md) | Maps product URLs to affiliate URLs by retailer. |
| `Restart_Retailer_Api` | Optional richer-metadata fetch (currently Etsy). |
| `Restart_Registry_Public` | Shortcodes + AJAX endpoints. |

## Custom post type

Registered on activation by `Restart_Registry_Activator`:

- **Slug:** `restart-registry`
- **`public` / `show_ui`:** true (so admins can browse and edit registries)
- **`supports`:** `title`, `editor`, `author`
- **`capability_type`:** `restart_registry` (custom capabilities)
- **Custom capability:** `manage_restart_registry` — used for "admin can edit
  any registry" checks in the controller

## Post meta keys

| Meta key | Type | Purpose |
|----------|------|---------|
| `restart_item_ids` | JSON array of ints | Lambda item IDs in display order |
| `restart_invitees` | JSON array of strings | Usernames or emails |
| `restart_event_type` | string | e.g. `divorce`, `relocation` |
| `restart_event_date` | string (`YYYY-MM-DD`) | When the registry's occasion is |

## Shortcodes

The plugin provides three shortcodes:

| Shortcode | Use |
|-----------|-----|
| `[restart_registry]` | Manage view for owners; read view for guests; create form for new users |
| `[restart_registry_view registry="<id-or-slug>"]` | Public/invitee read view |
| `[restart_registry_create]` | Standalone "create your first registry" form |

See [Shortcodes](shortcodes.md) for full details.

## AJAX actions

All registered on `admin-ajax.php`:

| Action | Auth | Purpose |
|--------|------|---------|
| `restart_registry_add_item` | logged-in | Add an item to a registry |
| `restart_registry_delete_item` | logged-in | Remove an item |
| `restart_registry_update_item` | logged-in | Edit an item |
| `restart_registry_mark_purchased` | logged-in **and** `nopriv` | Increment `quantity_purchased`; sends an email to the owner |
| `restart_registry_send_invite` | logged-in | Add an invitee + email them |
| `restart_registry_create` | logged-in | Create a new registry |
| `restart_registry_update` | logged-in | Update registry fields |
| `restart_registry_fetch_url` | logged-in | Server-side URL metadata scrape |
| `restart_registry_update_notification_prefs` | logged-in | Toggle the owner's purchase-notification opt-out |

`restart_registry_mark_purchased` is the only public-facing action — guests can
mark an item purchased on a public registry without logging in.

## Where Lambda gets called

Only the controller and the Lambda client touch the Lambda. Everywhere else in
the plugin, registry/item data is treated as plain PHP arrays. This is the
seam the test suite exploits — `Restart_Registry_Controller` accepts an
injected `Restart_Registry_Lambda_Client`, and `LambdaClientFake` is swapped in
for tests (see [Plugin Testing](testing.md)).
