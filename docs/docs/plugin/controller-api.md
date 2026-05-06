# Controller API

`Restart_Registry_Controller`
(`plugin/includes/class-restart-registry-controller.php`) is the single façade
through which all registry and item operations flow. The shortcode and AJAX
handlers in `Restart_Registry_Public` only ever touch this class — never the
Lambda or post-meta directly.

The controller has two collaborators, both injectable via the constructor:

```php
public function __construct(
    ?Restart_Registry_Lambda_Client $lambda = null,
    ?Restart_Registry_Affiliate_Converter $affiliate_converter = null
) { /* ... */ }
```

This is the seam the integration test suite uses to swap in `LambdaClientFake`.

## Registry read

### `get_registry(int $registry_id)`

Loads a registry by WP post ID. Returns the registry array
(post_to_registry-shaped, with `items` populated from the Lambda) or a
`WP_Error('not_found', ...)` if the post is missing or not a `restart-registry`.

### `get_registry_by_share_key(string $key)`

Looks up by either the numeric post ID (the canonical share key) or the post
slug. Used by the `?registry=<key>` URL param flow. Returns the same shape as
`get_registry()` or a `WP_Error('not_found', ...)`.

### `get_user_registry(int $user_id): ?array`

The first registry owned by `$user_id`, or `null` if they have none. Searches
across `publish`, `private`, and `draft` statuses. Used by `[restart_registry]`
to find the logged-in user's manage page.

## Registry write

### `create_registry(int $user_id, string $title, string $description = '', bool $is_public = false)`

Creates a `restart-registry` CPT post for the user. Returns:

```php
['id' => int, 'share_key' => int]   // share_key === id
```

Returns `WP_Error('registry_exists', ...)` if the user already has one
(enforced single-registry-per-user). Initializes the four post-meta keys to
empty defaults.

### `update_registry(int $registry_id, array $data): bool`

Updates fields. Accepted keys:

| Key | Effect |
|-----|--------|
| `title` | `wp_update_post(post_title = ...)` (sanitized) |
| `description` | `wp_update_post(post_content = ...)` (textarea-sanitized) |
| `is_public` | Sets `post_status` to `publish` (true) or `private` (false) |
| `event_type` | Sets `restart_event_type` post meta |
| `event_date` | Sets `restart_event_date` post meta |

Always returns `true`.

### `delete_registry(int $registry_id): bool`

Deletes the WP post **and** every Lambda item linked via `restart_item_ids`
(best-effort). Forces deletion (skips trash). Returns the result of
`wp_delete_post`.

## Items

### `get_registry_items(int $registry_id): array`

Reads `restart_item_ids` and fans out to `Lambda_Client::get_items()`. Order
is preserved from the meta array. IDs that 404 in the Lambda are silently
skipped (the controller treats them as deleted).

### `get_item(int $item_id)`

Pass-through to `Lambda_Client::get_item()`.

### `add_item(int $registry_id, array $data)`

Required keys: `name`, `url`. Optional: `description`, `price`, `quantity`,
`image_url`.

Steps:

1. Run the URL through `Affiliate_Converter::convert_url()` — sets `retailer`,
   `affiliate_url`, `affiliate_status` on the Lambda payload when applicable.
2. Truncate the name to 100 chars on a smart separator (`truncate_name()`
   tries ` - `, ` | `, `: `, en/em-dashes, then `, `, then a word boundary).
3. POST to the Lambda.
4. Append the new item ID to `restart_item_ids` post meta.

Returns:

```php
['id' => int, 'is_affiliate' => bool, 'retailer' => string, 'html_item' => array]
```

Or a `WP_Error` if the Lambda call fails.

### `update_item(int $item_id, array $data)`

Accepted keys: `name`, `url`, `description`, `price`, `quantity` (mapped to
`quantity_needed`), `image_url`. Empty `update` payload returns
`WP_Error('no_data', ...)`.

### `delete_item(int $item_id, int $registry_id): bool`

Deletes from Lambda **and** removes the ID from `restart_item_ids`.

### `mark_item_purchased(int $item_id, int $quantity = 1, string $purchaser_name = '', string $purchaser_email = '', string $purchaser_note = '', bool $is_anonymous = false)`

Increments `quantity_purchased` on the Lambda item, then sends an email
notification to the registry owner via `send_purchase_notification()`.

- Returns `WP_Error('not_found', ...)` if the item is missing.
- Returns `WP_Error('quantity_exceeded', ...)` if the request would push
  `quantity_purchased` above `quantity_needed`.
- Honors the owner's `restart_notify_on_purchase` user meta — if set to `'0'`,
  the email is skipped.

The notification email is plain text and includes the purchaser name (or
"Someone" for anonymous), the item name, an optional note from the purchaser,
and a link back to the registry. `From:` uses the `restart_registry_email_from`
and `restart_registry_email_name` options when set.

## Invites

### `send_invite(int $registry_id, string $invitee)`

Adds `$invitee` (a username or an email) to `restart_invitees`. Returns
`WP_Error('already_invited', ...)` if it is already there. If `$invitee`
parses as an email, sends an invite email via `wp_mail`.

Returns:

```php
['invite_id' => int]   // index into the invitees array
```

### `get_registry_invites(int $registry_id): array`

Returns the invitees as `[['id' => 0, 'email' => '...'], ['id' => 1, 'email' =>
'...'], ...]` for easy iteration in templates.

## Access control

### `can_view_registry(int $registry_id, ?int $user_id = null): bool`

True when **any** of:

- The registry's post status is `publish` (public).
- `$user_id` is the post author.
- `$user_id` has the `manage_restart_registry` capability (admins).
- `$user_id`'s email or `user_login` appears in `restart_invitees`.

False when the post does not exist or is not a `restart-registry` CPT.

### `can_edit_registry(int $registry_id, int $user_id): bool`

True when `$user_id` is the post author **or** has
`manage_restart_registry`. There is no invitee-level edit access.

## Private helpers worth knowing

### `truncate_name(string $name): string`

Caps an item name at 100 chars, preferring a clean break on common
product-name separators (` - `, ` | `, `: `, ` – `, ` — `, `, `) before falling
back to a word-boundary cut. Used in `add_item()` and `update_item()`.

This is what keeps Amazon-style "Brand X 12-piece Premium Knife Set with
Wooden Block, Stainless Steel ..." titles from overflowing the UI.

### `send_purchase_notification(array $item, string $purchaser_name, string $purchaser_note): void`

Internal — invoked from `mark_item_purchased()`. Composes the email body and
sends with `wp_mail`.
