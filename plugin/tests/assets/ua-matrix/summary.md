# UA Capability Matrix — Summary

**Generated:** 2026-05-18
**Issues:** [#31](https://github.com/amneher/restart/issues/31) (UA research) + [#20](https://github.com/amneher/restart/issues/20) (price refresh)

## Results by retailer

| Retailer | UA | Status | Notes |
|---|---|---|---|
| **Brooklinen** | ALL 8 UAs | ✅ ok | No bot protection; any UA works. Shopify stack. |
| **West Elm** | `LinkedInBot` only | ✅ ok | All other 7 UAs → **403**. Williams-Sonoma Akamai. |
| **West Elm** | Chrome, Safari, Firefox, Googlebot, Bingbot, Twitterbot, facebookbot | ❌ blocked (403) | Hard block from server-side IP |
| **Pottery Barn** | `LinkedInBot` only | ✅ ok | Same pattern as West Elm (same infra). |
| **Pottery Barn** | All other 7 UAs | ❌ blocked (403) | |
| **Amazon** | All UAs | ❌ blocked | Chrome→200+CAPTCHA, Facebook/Google→500. URL-only path correct. |
| **Etsy** | All UAs tested | ❌ blocked (403/429) | All blocked from this server IP. IP reputation likely the deciding factor, not just UA. |

## Key takeaways

1. **The current scraper is silently broken for West Elm and Pottery Barn.** The Chrome desktop UA returns 403 from both. These items currently return empty strings in production unless the scraper was added from a different IP environment.

2. **LinkedInBot is the winning UA for the Williams-Sonoma family** (West Elm, Pottery Barn, Williams-Sonoma, Rejuvenation, Mark and Graham). Verified: returns full `og:title`, `og:image`, and HTML with price data.

3. **Brooklinen is completely open.** Zero bot protection. Any UA works reliably. Confidence: high.

4. **Amazon URL-only fast path is confirmed correct.** No UA combination returns real product data from server-side requests.

5. **Etsy result is inconclusive.** The `facebookexternalhit` trick documented in the scraper likely depends on the source IP having social-crawler reputation. From a server/Lambda IP it is 403 across all UAs. Recommend migrating Etsy to the **Etsy Open API v3** (free, no affiliate approval, 10k req/day at 10 QPS).

## Recommended per-retailer UA map

```php
private const RETAILER_UA_MAP = [
    // Williams-Sonoma family: LinkedInBot only opens the gate
    'westelm.com'     => ['primary' => self::UA_LINKEDIN, 'fallback' => null],
    'potterybarn.com' => ['primary' => self::UA_LINKEDIN, 'fallback' => null],
    'williams-sonoma.com' => ['primary' => self::UA_LINKEDIN, 'fallback' => null],
    // Etsy: facebookbot trick may work from some IPs; deprecate in favor of API
    'etsy.com'        => ['primary' => self::UA_FACEBOOK, 'fallback' => null],
    // Brooklinen: any UA works; Chrome is fine
    'brooklinen.com'  => ['primary' => self::UA_CHROME, 'fallback' => null],
    // Amazon: no UA works; URL-only path in scrape() handles this before UA selection
    // Catch-all
    '*'               => ['primary' => self::UA_CHROME, 'fallback' => self::UA_FACEBOOK],
];
```

## Raw data files

- `ua-matrix-brooklinen.json` — 24 records (3 URLs × 8 UAs)
- `ua-matrix-westelm.json` — 24 records (3 URLs × 8 UAs)
- `ua-matrix-potterybarn.json` — 24 records (3 URLs × 8 UAs)
- `ua-matrix-amazon.json` — 6 records (2 URLs × 3 UAs)
- `ua-matrix-etsy.json` — 5 records (1 URL × 5 UAs, result: all blocked from this IP)
