# Endpoints

Full reference for the Lambda HTTP surface. Every authenticated endpoint
expects an `Authorization: Basic <base64(user:app_pwd)>` header (a WordPress
application password). Production additionally requires the API Gateway
`x-api-key` header.

OpenAPI is exposed at `/docs` (Swagger UI) and `/openapi.json` while the
container is running locally.

## Health

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/` | none | Welcome message |
| `GET` | `/health` | none | `{"status": "healthy", "service": "aws-lambda-fastapi"}` |

## Items (`app/routes/items.py`)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/items` | yes | List active items (paginated, `skip`, `limit`) |
| `GET` | `/items/{item_id}` | yes | Get a single item; 404 if missing or `is_active = 0` |
| `POST` | `/items` | yes | Create an item — `ItemCreate` (must include `registry_id`) |
| `PUT` | `/items/{item_id}` | yes | Partial update — `ItemUpdate` |
| `DELETE` | `/items/{item_id}` | yes | Soft delete (`is_active = 0`) |

### Admin vs. non-admin response

| Endpoint | Admin response | Non-admin response |
|----------|----------------|--------------------|
| `GET /items` | `list[Item]` | `list[ItemPublic]` |
| `GET /items/{id}` | `ItemResponse` (`{success, data: Item, message}`) | `ItemPublicResponse` (`{success, data: ItemPublic, message}`) |
| `POST /items` | `ItemResponse` (201) | `ItemPublicResponse` (201) |
| `PUT /items/{id}` | `ItemResponse` | `ItemPublicResponse` |
| `DELETE /items/{id}` | `ItemResponse` | `ItemPublicResponse` |

`ItemPublic` omits `affiliate_url` and `affiliate_status` so non-admin viewers
of public registries cannot scrape your affiliate links.

### Item request body shape (`ItemCreate`)

```json
{
  "registry_id": 42,
  "name": "Chef's Knife",
  "description": "8-inch high-carbon",
  "url": "https://example.com/knife",
  "retailer": "Amazon",
  "affiliate_url": "https://example.com/knife?tag=...",
  "affiliate_status": "active",
  "image_url": "https://example.com/knife.jpg",
  "price": 79.99,
  "quantity_needed": 1,
  "quantity_purchased": 0,
  "is_active": true
}
```

Constraints: `name` 1–100 chars, `description` ≤500 chars, `url` 10–2000 chars,
`price > 0`, `quantity_needed >= 1`, `quantity_purchased >= 0`. `affiliate_status`
is one of `active | inactive | expired | none`.

## Registries (`app/routes/registry.py`)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/registries` | yes | List registries owned by the authenticated user (paginated). Sets `X-WP-Total` and `X-WP-TotalPages` headers from WP. |
| `GET` | `/registries/{id}` | yes | Get a registry. Must be owner, admin, or invitee. |
| `POST` | `/registries` | yes | Create a registry. Authenticated user becomes the owner. |
| `PATCH` | `/registries/{id}` | yes | Update fields (title, status, story, meta). Owner or admin. |
| `DELETE` | `/registries/{id}` | yes | Force-delete the WP post. Owner or admin. 204 on success. |

Registries are stored as the `restart-registry` WP custom post type — the
Lambda proxies through `wp_python.WordPressClient` rather than touching
SQLite for these operations.

## Registry items (nested)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/registries/{id}/items` | yes (owner / admin / invitee) | List active items for a registry. Returns `list[Item]` for admin, `list[ItemPublic]` otherwise. |
| `POST` | `/registries/{id}/items` | yes (owner / admin) | Create an item linked to the registry. Body is `ItemRegistryCreate` (no `registry_id` — comes from the URL). Side-effect: syncs `restart_item_ids` post meta. |
| `DELETE` | `/registries/{id}/items/{item_id}` | yes (owner / admin) | Soft delete an item from this registry. 204 on success. Side-effect: syncs post meta. |

## Invitees

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/registries/{id}/invitees` | owner / admin / invitee | List invitees |
| `POST` | `/registries/{id}/invitees` | owner / admin | Body: `{"invitees": ["alice", "bob@example.com"]}`. Adds and dedupes. |
| `DELETE` | `/registries/{id}/invitees/{invitee}` | owner / admin | Remove a single invitee |

All three return `InviteesResponse` — `{"invitees": [...]}` — except DELETE
which returns the updated list.

## Affiliate links

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/registries/{id}/items/{item_id}/affiliate` | registry access | Get the current affiliate URL/status |
| `PUT` | `/registries/{id}/items/{item_id}/affiliate` | owner / admin | Set/update — `{"affiliate_url": "...", "affiliate_status": "active"}` |
| `DELETE` | `/registries/{id}/items/{item_id}/affiliate` | owner / admin | Clear (`affiliate_url = NULL`, `affiliate_status = NULL`). 204 on success. |

`AffiliateUpdate.affiliate_url` is 10–500 chars; `affiliate_status` is ≤50
chars (default `"active"`).

## Error envelope

FastAPI's default — non-success responses are JSON with a `detail` key:

```json
{ "detail": "Registry not found" }
```

The Lambda Client in the plugin maps `>=400` responses to
`WP_Error('lambda_error', $detail, ['status' => $code])` and `404` to
`null`. See [Lambda Client](../plugin/lambda-client.md).
