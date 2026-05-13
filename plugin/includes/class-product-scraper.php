<?php
declare(strict_types=1);

/**
 * Product scraper: pulls product metadata (name, price, image_url, description)
 * from a retailer URL.
 *
 * Designed to be testable outside of WordPress. When wp_remote_get() is
 * available (plugin runtime), it is used; otherwise we fall back to a
 * private cURL implementation so integration tests can drive real HTTP
 * requests without bootstrapping WordPress.
 *
 * The Amazon early-return path extracts ASIN + slug-title directly from
 * the URL and skips the network entirely — Amazon serves CAPTCHA or 503
 * to all server-side scrapers regardless of User-Agent.
 */
class Restart_Registry_Product_Scraper {

    private const UA_CHROME   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const UA_FACEBOOK = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /**
     * Scrape product metadata from a retailer URL.
     *
     * @return array{name:string,price:mixed,image_url:string,description:string}
     */
    public function scrape(string $url): array {
        // Amazon blocks all server-side scrapers (CAPTCHA or 503 regardless of UA).
        // Extract what we can directly from the URL — no network request needed.
        if (preg_match('/amazon\.[a-z.]+/i', $url)) {
            $asin  = '';
            $name  = '';
            $image = '';

            // ASIN: 10-char alphanumeric after /dp/ or /gp/product/
            if (preg_match('/\/(?:dp|gp\/product)\/([A-Z0-9]{10})/i', $url, $m)) {
                $asin = strtoupper($m[1]);
            }

            // Title from URL slug: "KitchenAid-Artisan-5-Qt-Stand-Mixer" → "KitchenAid Artisan 5 Qt Stand Mixer"
            if (preg_match('/amazon\.[^\/]+\/([^\/]+)\/dp\//i', $url, $m)) {
                $name = str_replace('-', ' ', rawurldecode($m[1]));
            }

            // Amazon's public product image CDN accepts ASIN directly
            if ($asin) {
                $image = "https://images-na.ssl-images-amazon.com/images/P/{$asin}.01._SL500_.jpg";
            }

            return [
                'name'        => $name,
                'price'       => '',
                'image_url'   => $image,
                'description' => '',
            ];
        }

        $body = $this->http_get($url, self::UA_CHROME, 15);
        $data = ['name' => '', 'price' => '', 'image_url' => '', 'description' => ''];

        // og:title is the curated product name — preferred over <title> which adds site suffixes
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\'][^>]*>/i', $body, $m)) {
            $data['name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            $data['name'] = preg_replace('/\s*[-|–]\s*(Etsy|Amazon\.com|Amazon|Target|Walmart|eBay)\s*$/iu', '', $data['name']);
        }
        // Fallback: <title> tag
        if (empty($data['name']) && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $data['name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            $data['name'] = preg_replace('/\s*[-|:].*(?:Amazon|Target|Walmart|eBay|Etsy).*$/i', '', $data['name']);
        }
        if (preg_match('/\$([0-9,]+\.?\d{0,2})/', $body, $m)) {
            $data['price'] = (float) str_replace(',', '', $m[1]);
        }

        // Strategy 1: og:image meta tag (attribute order varies)
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $body, $m)) {
            $data['image_url'] = $this->maybe_esc_url(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        // Strategy 2: JSON-LD Product schema
        if (empty($data['image_url']) && preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $scripts)) {
            foreach ($scripts[1] as $json_raw) {
                $ld = json_decode(trim($json_raw), true);
                if (!$ld) continue;
                foreach (isset($ld[0]) ? $ld : [$ld] as $node) {
                    if (in_array($node['@type'] ?? '', ['Product', 'ItemPage'], true) && !empty($node['image'])) {
                        $img = is_array($node['image']) ? reset($node['image']) : $node['image'];
                        if (is_array($img)) $img = $img['url'] ?? '';
                        if ($img) { $data['image_url'] = $this->maybe_esc_url($img); break 2; }
                    }
                }
            }
        }

        // Strategy 3: Amazon — parse colorImages JSON from page HTML for real CDN URL
        // (kept for parity even though the Amazon early-return above usually means we
        // never reach this branch with an amazon.* URL)
        if (empty($data['image_url']) && strpos($url, 'amazon.') !== false) {
            if (preg_match('/"large":"(https:\/\/m\.media-amazon\.com\/images\/[^"]+)"/i', $body, $m)) {
                $data['image_url'] = $this->maybe_esc_url($m[1]);
            }
        }

        // Etsy: Chrome UAs get Cloudflare-blocked; retry with a social crawler UA that Etsy allows for link previews.
        // If we get a real page, replace $body so the description extraction below can reuse it.
        if (strpos($url, 'etsy.com/listing/') !== false) {
            $etsy_body = $this->http_get($url, self::UA_FACEBOOK, 10);
            if ($etsy_body !== '') {
                $etsy_og_title = '';
                if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $etsy_body, $m) ||
                    preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\'][^>]*>/i', $etsy_body, $m)) {
                    $etsy_og_title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }
                // Only trust the result if it's a real listing page, not a bot-detection shell
                if ($etsy_og_title && !preg_match('/^etsy(\.com)?$/i', trim($etsy_og_title))) {
                    $data['name'] = preg_replace('/\s*[-|–]\s*Etsy\s*$/iu', '', $etsy_og_title);
                    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $etsy_body, $m) ||
                        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $etsy_body, $m)) {
                        $data['image_url'] = $this->maybe_esc_url(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    }
                    // Replace $body so the description extraction section below uses the real page
                    $body = $etsy_body;
                }
            }
            // Name fallback: always use URL slug when name still looks like a bare domain
            if (empty($data['name']) || preg_match('/^[\w.-]+\.\w{2,}$/', trim($data['name']))) {
                if (preg_match('/etsy\.com\/listing\/\d+\/([^?&#]+)/i', $url, $m)) {
                    $data['name'] = ucwords(str_replace('-', ' ', rawurldecode($m[1])));
                }
            }
        }

        // Description — Strategy 1: JSON-LD Product schema
        $description = '';
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $ld_blocks)) {
            foreach ($ld_blocks[1] as $json_raw) {
                $ld = json_decode(trim($json_raw), true);
                if (!$ld) continue;
                foreach (isset($ld[0]) ? $ld : [$ld] as $node) {
                    if (in_array($node['@type'] ?? '', ['Product', 'ItemPage'], true) && !empty($node['description'])) {
                        $description = $node['description'];
                        break 2;
                    }
                }
            }
        }

        // Description — Strategy 2: og:description
        if (empty($description)) {
            if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
                preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\'][^>]*>/i', $body, $m)) {
                $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        // Description — Strategy 3: meta description
        if (empty($description)) {
            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
                preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\'][^>]*>/i', $body, $m)) {
                $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        if (!empty($description)) {
            $description = trim(html_entity_decode($description, ENT_QUOTES, 'UTF-8'));
            // Strip leading "Retailer.com: " prefixes
            $description = preg_replace('/^(Amazon\.com|Amazon|Target|Walmart\.com|Walmart|eBay|Etsy)\s*[:–—-]\s*/iu', '', $description);
            // Strip trailing " - Retailer.com" suffixes
            $description = preg_replace('/\s*[-–—|]\s*(Amazon\.com|Walmart\.com|Target\.com|Etsy|eBay)\s*$/iu', '', $description);
            // Strip common retail noise
            $description = preg_replace('/\s*(Free (shipping|returns?)|Ships free|Shop now|Buy now|Order now|In stock|Add to cart)[^.]*\.?\s*$/iu', '', $description);
            $description = trim($description);
            // Truncate: try to break at a sentence end within 160 chars
            if (mb_strlen($description) > 160) {
                $short    = mb_substr($description, 0, 160);
                $last_end = max(
                    (int) strrpos($short, '. '),
                    (int) strrpos($short, '! '),
                    (int) strrpos($short, '? ')
                );
                if ($last_end > 80) {
                    $description = mb_substr($short, 0, $last_end + 1);
                } else {
                    $last_space  = (int) strrpos($short, ' ');
                    $description = ($last_space > 80 ? mb_substr($short, 0, $last_space) : $short) . '…';
                }
            }
            $data['description'] = trim($description);
        }

        return $data;
    }

    /**
     * HTTP GET using wp_remote_get() when available, cURL otherwise.
     * Always returns a string body; on failure returns ''.
     */
    private function http_get(string $url, string $ua, int $timeout): string {
        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, [
                'timeout'    => $timeout,
                'user-agent' => $ua,
                'headers'    => [
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ],
            ]);

            if (function_exists('is_wp_error') && is_wp_error($response)) {
                return '';
            }

            if (function_exists('wp_remote_retrieve_body')) {
                $body = wp_remote_retrieve_body($response);
                return is_string($body) ? $body : '';
            }

            return '';
        }

        return $this->curl_get($url, $ua, $timeout);
    }

    /**
     * Plain cURL fallback for non-WordPress contexts (CLI/tests).
     */
    private function curl_get(string $url, string $ua, int $timeout): string {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => '',   // lets curl decompress gzip/br
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }

    /**
     * Use esc_url_raw() when in WordPress, otherwise return the value as-is.
     * Test contexts don't have WordPress sanitization available.
     */
    private function maybe_esc_url(string $url): string {
        return function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
    }
}
