# Lambda Client

`Restart_Registry_Lambda_Client`
(`plugin/includes/class-lambda-api-client.php`) is the only place in PHP that
makes HTTP calls to the FastAPI service. It uses `wp_remote_request` (so it
honors WP HTTP filters and proxies) and returns plain associative arrays or
`WP_Error`.

## Construction

```php
new Restart_Registry_Lambda_Client();
```

The constructor is parameter-free and reads its configuration from WP options
(checked first) or environment variables (fallback):

| Setting | WP option | Env var |
|---------|-----------|---------|
| Base URL (no trailing slash) | `restart_lambda_url` | `RESTART_LAMBDA_URL` |
| API Gateway key (`x-api-key`) | `restart_lambda_api_key` | `RESTART_LAMBDA_API_KEY` |
| Basic-auth username | `restart_lambda_username` | `RESTART_LAMBDA_USERNAME` |
| Basic-auth app password | `restart_lambda_app_password` | `RESTART_LAMBDA_APP_PASSWORD` |

The base URL is `rtrim`-ed to remove any trailing `/`. Username + password are
only used if **both** are set; the Authorization header is built as
`Basic <base64(user:pass)>`.

!!! note "Two headers go on every authenticated request"
    The plugin sends both `x-api-key` (so API Gateway lets it through to the
    Lambda) **and** `Authorization: Basic <...>` (so the Lambda's
    `get_current_user` dependency validates against WordPress). See
    [Authentication](../lambda/auth.md).

### `is_configured(): bool`

True when a base URL has been set. The class returns
`WP_Error('lambda_not_configured', ...)` from any HTTP method when this is
false.

## Public methods

All return either an associative array (the parsed JSON body), `null` (only
`get_item()` — on a 404), or `WP_Error`.

### `get_item(int $item_id)`

GET `/items/{id}`. Returns the item array (the `data` key of the FastAPI
envelope, falling back to the body itself), `null` on 404, or `WP_Error` on
any 4xx/5xx other than 404.

### `get_items(array $item_ids): array`

Iterates over the IDs and calls `get_item()` for each. **Skips IDs that 404 or
return errors** so a stale `restart_item_ids` array does not break a registry
view.

### `create_item(array $data)`

POST `/items`. The `$data` array is JSON-encoded and sent as the body.
Required: `name`, `url`. Optional: `description`, `retailer`,
`affiliate_status`, `affiliate_url`, `image_url`, `price`,
`quantity_needed`, `registry_id`.

Returns the created item (`data` key) or `WP_Error`.

### `update_item(int $item_id, array $data)`

PUT `/items/{id}`. Sends only the keys present in `$data`. Accepted keys:
`name`, `description`, `price`, `quantity_needed`, `quantity_purchased`,
`is_active`, `retailer`, `affiliate_url`, `affiliate_status`, `image_url`,
`url`.

### `delete_item(int $item_id)`

DELETE `/items/{id}`. The Lambda performs a soft delete (sets `is_active = 0`)
and returns the (now inactive) item.

## Internal: `request()`

A thin wrapper around `wp_remote_request`:

- Sets `Content-Type: application/json` always.
- Adds `x-api-key` when configured.
- Adds `Authorization: Basic ...` when configured.
- 10-second timeout (private property `$timeout`; not configurable via the public API).
- JSON-encodes the body when present.
- Maps the response: 404 → `null`; ≥400 → `WP_Error('lambda_error', $detail,
  ['status' => $code])` where `$detail` is the FastAPI `detail` field; otherwise
  the decoded JSON body.

This is the surface that the plugin's `LambdaClientFake` mimics during
integration testing — see [Plugin Testing](testing.md).
