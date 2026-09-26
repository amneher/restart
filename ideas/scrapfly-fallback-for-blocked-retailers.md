# Idea: Scrapfly fallback for retailers that hard-block our scraper

Status: **proposed, not started**
Originated from: sidebar discussion while building the favorites-item Fetch-URL button (see `plans.md`, "Fetch-URL auto-populate for the favorites-item block").

---

## High-level plan

**Problem:** Some retailers (confirmed: Pottery Barn/Williams-Sonoma family; suspected: Etsy) return a 403 to every User-Agent we try, from every source IP we've tested. The block is served by Akamai as a static "restricted access" page, not a per-request bot challenge — that's the signature of a network/ASN-level ACL rule, not a UA-detection problem. No amount of UA-swapping or smarter parsing fixes this; the request itself is being rejected before our code ever sees real content. An LLM "agent" wrapped around the same HTTP client would hit the identical wall, because the problem is the network path, not the intelligence of the client.

**Fix:** Add a paid extraction fallback (Scrapfly) that only fires when our free path (retailer API → direct scraper) comes back empty. Scrapfly's `unblocker` + residential proxy pool routes the request through IPs that aren't datacenter-blocked, and its `extraction_model=product` param returns structured product JSON (name, price, images, description) in one call — no regex parsing needed for this tier.

**Guardrails so this doesn't become a surprise bill:**
- Only called when the free path returns nothing usable (no name/price/image) — not on every fetch.
- Result cached per URL for 24h so repeat clicks/retries don't re-charge.
- `cost_budget` param caps what a single call can spend even if Scrapfly's Unblocker auto-upgrades config.
- API key is optional (same `get_option()` pattern as Etsy/Anthropic keys) — feature no-ops with zero cost if no key is set.

**Effort:** ~half a day. One new class, one wiring point, one settings field, tests.

---

## Full-detail plan

### Evidence (verified 2026-09-24)

Direct `curl` against `https://www.potterybarn.com/products/pb-classic-thread-count-organic-sheet-set/` with LinkedInBot, Chrome, Googlebot, Bingbot, Twitterbot, Slackbot, mobile Safari, and bare `curl/8.0` UAs — **all returned 403**. Response:

```
HTTP/2 403
server: AkamaiNetStorage
```

Body is a static template ("Sorry, due to website restrictions we are unable to display the requested page"), with an unchanging `etag`/`last-modified` — this is Akamai serving a canned object for a blocked request class (commonly IP/ASN-range based), not a JS bot-challenge. This matches the existing GH #31 research note for Etsy ("IP reputation matters as much as UA") — Williams-Sonoma's protection appears to have tightened to the same posture since that research ran (`plans.md`, "Research: Scraper UA matrix + stable data sources").

Not yet confirmed: whether the production WordPress host (different egress IP than this dev/sandbox environment) hits the same block. Worth a one-off `curl` from the prod host before/alongside building this, to size how many retailers actually need the fallback.

### Why not "just build an agent"

An agent that fetches over HTTP inherits whatever IP it runs from. Since the block here is at the network/ASN layer (not a signature a smarter client could evade), the only thing that actually changes the outcome is the egress IP. Services like Scrapfly/Diffbot/Bright Data sell exactly that — a residential/rotating proxy pool — bundled with parsing. Building our own "agent" would mean building or renting the same proxy layer anyway, so buying it directly is less work and more reliable than reinventing it.

### Architecture

Three-tier fallback in `ajax_fetch_url()` (`plugin/public/class-restart-registry-public.php:1651`), in order:

1. **Retailer API** (`Restart_Registry_Retailer_API::fetch_if_configured()`) — existing, Etsy-only today.
2. **Direct scraper** (`Restart_Registry_Product_Scraper::scrape()`) — existing, per-retailer UA selection.
3. **NEW: Scrapfly fallback** (`Restart_Registry_Scrapfly_Client::fetch_if_configured()`) — only called when tier 2's result is empty (`empty($data['name']) && empty($data['price']) && empty($data['image_url'])`), and only when an API key is configured.

This is domain-agnostic by design — no hardcoded "known blocked" list to maintain. Any retailer that starts hard-blocking us falls through to Scrapfly automatically; retailers that still work for free never touch it.

### New file: `plugin/includes/class-scrapfly-client.php`

Mirrors the shape of `Restart_Registry_Retailer_API` and `Restart_Registry_LLM_Extractor` (same `fetch_if_configured(): ?array` contract, same `get_option()` key pattern, same `wp_remote_*` HTTP calls so it's testable with Brain\Monkey the way `RetailerApiTest.php` already mocks `wp_remote_get`).

```php
class Restart_Registry_Scrapfly_Client {

    private const API_URL = 'https://api.scrapfly.io/scrape';
    private const COST_BUDGET = 5; // credits; caps Unblocker auto-upgrade spend per call

    public function fetch_if_configured(string $url): ?array {
        $api_key = (string) get_option('restart_registry_scrapfly_api_key', '');
        if ($api_key === '') {
            return null;
        }

        $cache_key = 'rr_scrapfly_' . md5($url);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $query = http_build_query([
            'key'               => $api_key,
            'url'               => $url,
            'unblocker'         => 'true',
            'proxy_pool'        => 'public_residential_pool',
            'extraction_model'  => 'product',
            'cost_budget'       => self::COST_BUDGET,
        ]);

        $response = wp_remote_get(self::API_URL . '?' . $query, ['timeout' => 20]);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $extracted = $body['result']['extracted_data'] ?? null;
        if (empty($extracted['name']) && empty($extracted['title'])) {
            return null; // extraction failed even with Unblocker — nothing usable
        }

        $data = [
            'name'        => $extracted['name'] ?? $extracted['title'] ?? '',
            'price'       => $extracted['price'] ?? '',
            'image_url'   => $extracted['images'][0] ?? $extracted['main_image'] ?? '',
            'description' => $extracted['description'] ?? '',
        ];

        set_transient($cache_key, $data, DAY_IN_SECONDS);
        return $data;
    }
}
```

(Field names for `extracted_data` — `name`/`title`, `images`/`main_image` — need confirming against a live response during implementation; Scrapfly's product extraction model docs list name, price, images, description, variants, reviews, specifications, but the exact JSON key casing should be verified with a real API call, not assumed from docs prose.)

### Wiring change: `ajax_fetch_url()`

```php
$data = (new Restart_Registry_Product_Scraper())->scrape($url);

if (empty($data['name']) && empty($data['price']) && empty($data['image_url'])) {
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-scrapfly-client.php';
    $scrapfly_data = (new Restart_Registry_Scrapfly_Client())->fetch_if_configured($url);
    if ($scrapfly_data !== null) {
        $data = $scrapfly_data;
    }
}
```

### Admin settings

Add `restart_registry_scrapfly_api_key` to the existing "API Keys" section in `class-restart-registry-admin.php` (`register_settings()` around line 164, `add_settings_field()` around line 237) — same `api_key_field_callback` already used for Etsy and Anthropic keys, just a new `add_settings_field()` call with a signup-URL hint (`https://scrapfly.io/register` — confirm current signup path when implementing, don't hardcode from memory).

### Cost controls (in scope for v1)

- **Empty-result gate**: only fires when the free path returns nothing — bounds calls to genuinely-blocked URLs.
- **24h transient cache** keyed by URL hash — repeat Fetch clicks on the same product (typo retry, multiple admins) don't re-charge.
- **`cost_budget` param** — caps Scrapfly's own auto-upgrade spend per call regardless of our code.
- **Opt-in via API key** — no key, no calls, no cost. Nothing changes for sites that don't configure it.

### What's explicitly NOT in scope for v1

- No admin UI for a spending cap/budget across calls (just the per-call `cost_budget`) — add later if usage data shows it's needed.
- No circuit breaker / auto-disable after N consecutive failures — add if Scrapfly itself starts failing regularly.
- Not wiring Scrapfly into the scheduled price-refresh subsystem (GH #20 / Phase E in `plans.md`) — that's a different call site with different cost tradeoffs (refresh runs on a schedule across every item, not once per admin click) and deserves its own decision about whether the cost is justified there.
- Not replacing the CJ Affiliate/Rakuten integration idea already scoped in `plans.md` (GH #31) — that's still the cheaper long-term fix specifically for the Williams-Sonoma family, since Scrapfly costs money per call forever and CJ/Rakuten would be free once built. This is the stopgap for "works today, for any retailer," not a replacement for that.

### Testing

- `plugin/tests/unit/ScrapflyClientTest.php` (new, Brain\Monkey pattern matching `RetailerApiTest.php`):
  - returns `null` when no API key configured (no HTTP call made)
  - returns cached data on second call for the same URL without a second HTTP call
  - returns parsed `name`/`price`/`image_url`/`description` on a successful response
  - returns `null` on non-200 response
  - returns `null` when `extracted_data` has no name/title (failed extraction, still HTTP 200)
- `plugin/tests/unit/PublicAjaxFetchUrlTest.php` (or add to existing AJAX test file if one exists — check before creating a new file): confirms Scrapfly is only invoked when the scraper's result is empty, not on every call.

### Rollout checklist (fill in once this plan is approved and promoted to `plans.md`)

- [ ] Confirm production host also hits the 403 (not just this dev sandbox) — sizes real impact before spending on an integration
- [ ] Sign up for Scrapfly, confirm real `extracted_data` field names via a live test call against the Pottery Barn URL above
- [ ] `class-scrapfly-client.php`: `fetch_if_configured()` with transient caching
- [ ] `class-restart-registry-admin.php`: `restart_registry_scrapfly_api_key` setting + field
- [ ] `class-restart-registry-public.php`: wire into `ajax_fetch_url()` as tier 3
- [ ] Tests: `ScrapflyClientTest.php` + ajax-gating test
- [ ] Manual test: paste the Pottery Barn URL above into the favorites-item Fetch button, confirm it now populates
- [ ] `make plugin-test-php` green
- [ ] Decide: promote this file into `plans.md` as an active plan, or leave here until prioritized
