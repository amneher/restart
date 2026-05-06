# Affiliate Converter

`Restart_Registry_Affiliate_Converter`
(`plugin/includes/class-affiliate-converter.php`) converts a regular product
URL into an affiliate URL when the host matches a configured retailer. It is
called from the controller's `add_item()` flow so that links saved into
registries already carry the appropriate affiliate parameters.

## Configuration

The class assembles a config map from WordPress options on construction. Each
retailer entry is `enabled` by default and reads its ID from the matching
option:

| Retailer | Option | Domains |
|----------|--------|---------|
| `amazon` | `restart_registry_amazon_tag` | amazon.com, amazon.co.uk, amazon.ca, amazon.de, amazon.fr, amzn.to, amzn.com |
| `target` | `restart_registry_target_id` | target.com |
| `walmart` | `restart_registry_walmart_id` | walmart.com |
| `etsy` | `restart_registry_etsy_id` | etsy.com |
| `ebay` | `restart_registry_ebay_id` (campaign ID) | ebay.com, ebay.co.uk |
| `bestbuy` | `restart_registry_bestbuy_id` | bestbuy.com |
| `homedepot` | `restart_registry_homedepot_id` | homedepot.com |
| `wayfair` | `restart_registry_wayfair_id` | wayfair.com |
| `shareasale` | `restart_registry_shareasale_id`, `restart_registry_shareasale_merchant` | _(network — no domain match)_ |
| `cj` | `restart_registry_cj_id` | _(network — no domain match)_ |

The list is filterable via:

```php
apply_filters('restart_registry_affiliate_configs', $defaults)
```

so themes or extension plugins can disable or reconfigure retailers without
editing the class.

!!! warning "Generators implemented vs. configured"
    The configs above include several retailers that pass through to the
    fallback (`return $url;`) because there is no `generate_*_affiliate()`
    branch for them yet. As of this version, only **Amazon**, **Target**,
    **Walmart**, **Etsy**, **eBay**, and **Best Buy** have URL builders.
    Home Depot, Wayfair, ShareASale, and CJ are recognized but leave the URL
    untouched.

## Public API

### `convert_url(string $url): array`

Parse the URL, find the matching retailer (host match, www-stripped), and
delegate to the per-retailer generator.

Returns a 3-key array:

```php
[
  'affiliate_url' => 'https://...',   // possibly the original URL
  'retailer'      => 'Amazon',         // ucfirst() of the matched key, or extracted host
  'is_affiliate'  => true,             // true when affiliate_url !== original url
]
```

For unknown hosts, `retailer` is the host's domain stripped of TLD and
capitalized (e.g. `etsy.com` → `Etsy`), `is_affiliate` is `false`, and
`affiliate_url` is the original URL.

### `is_affiliate_link(string $url): bool`

Convenience wrapper: `convert_url($url)['is_affiliate']`.

### `get_supported_retailers(): array`

Returns an array of retailer descriptors — name, domains, enabled flag —
useful for surfacing the supported retailers in the WP admin UI.

## URL building per retailer

| Retailer | Strategy |
|----------|----------|
| Amazon | Append/overwrite `tag=<your-tag>`, drop any existing `ref=` |
| Target | Append `afid=<your-id>` |
| Walmart | Append `affiliates_ad_id=<your-id>` |
| Etsy | Append `ref=aff_<your-id>` |
| eBay | Wrap in a Rover redirect: `https://rover.ebay.com/rover/1/<campaign>/1?mpre=<urlencoded>` |
| Best Buy | Append `irclickid=<your-id>` |

When the retailer's option is empty (e.g. `restart_registry_amazon_tag` is
unset), the generator returns the URL unchanged and `is_affiliate` is false.

## Cleaning descriptions

Some retailers (notably Etsy) have noisy product descriptions full of shop
boilerplate. There is also a `Restart_Retailer_Api` class
(`includes/class-retailer-api.php`) that can `fetch_if_configured()` extra
metadata for known retailers — currently only Etsy has a real implementation
— and provides a `clean_description()` helper to strip those repeated
boilerplate blocks before storing.

The `add_item()` flow does not currently invoke that retailer API
automatically; it is wired up for future work.
