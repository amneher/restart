<?php

/**
 * Retailer API Client
 *
 * Fetches product data directly from retailer APIs when keys are configured,
 * providing richer and more reliable data than HTML scraping.
 *
 * Usage: call fetch_if_configured($url) before falling back to the scraper.
 * Returns null when no API key is set for the URL's retailer.
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Retailer_API {

    /**
     * Retailer slug → domains map. Consistent with class-affiliate-converter.php.
     * Add new retailers here as APIs are implemented.
     */
    private const RETAILER_DOMAINS = [
        'etsy'   => ['etsy.com'],
        // 'amazon' => ['amazon.com', 'amazon.co.uk', 'amazon.ca', 'amzn.to'],  // Phase 2
    ];

    // =========================================================================
    // Public interface
    // =========================================================================

    /**
     * Detect which retailer a URL belongs to.
     *
     * @return string|null  Retailer slug ('etsy', 'amazon', …) or null.
     */
    public function detect_retailer(string $url): ?string {
        $host = strtolower(preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST) ?: ''));
        foreach (self::RETAILER_DOMAINS as $retailer => $domains) {
            foreach ($domains as $domain) {
                if (strpos($host, $domain) !== false) {
                    return $retailer;
                }
            }
        }
        return null;
    }

    /**
     * Whether an API key is configured for the given retailer slug.
     */
    public function has_api(string $retailer): bool {
        return !empty(get_option('restart_registry_' . $retailer . '_api_key'));
    }

    /**
     * Try an API fetch if a key is configured for this URL's retailer.
     *
     * Returns null when no key is configured or the URL isn't a recognised
     * retailer — caller should fall through to the HTML scraper.
     * Returns an empty array if the key is configured but the call fails —
     * caller may still fall through to the scraper in that case.
     *
     * @return array|null  Partial product data array, or null to skip API path.
     */
    public function fetch_if_configured(string $url): ?array {
        $retailer = $this->detect_retailer($url);
        if (!$retailer || !$this->has_api($retailer)) {
            return null;
        }

        $data = $this->fetch($url, $retailer);
        return empty($data) ? null : $data;
    }

    /**
     * Fetch product data from the given retailer's API.
     * Assumes the caller has already verified a key is configured.
     *
     * @return array  Subset of: name, price, image_url, description.
     */
    public function fetch(string $url, string $retailer): array {
        switch ($retailer) {
            case 'etsy':
                return $this->fetch_etsy($url);
            // Amazon: Phase 2 (requires AWS SigV4 signing)
            default:
                return [];
        }
    }

    // =========================================================================
    // Etsy — API v3
    // Docs: https://developers.etsy.com/documentation/reference#operation/getListing
    // =========================================================================

    private function fetch_etsy(string $url): array {
        $api_key    = get_option('restart_registry_etsy_api_key', '');
        $listing_id = $this->extract_etsy_listing_id($url);
        if (!$listing_id) return [];

        $response = wp_remote_get(
            'https://openapi.etsy.com/v3/application/listings/' . $listing_id . '?includes=Images',
            [
                'timeout' => 10,
                'headers' => [
                    'x-api-key' => $api_key,
                    'Accept'    => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return [];
        }

        $listing = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($listing)) return [];

        $data = [];

        if (!empty($listing['title'])) {
            $data['name'] = html_entity_decode($listing['title'], ENT_QUOTES, 'UTF-8');
        }

        // price.amount is in minor units; price.divisor converts to decimal
        if (!empty($listing['price']['amount']) && !empty($listing['price']['divisor'])) {
            $data['price'] = round(
                (float) $listing['price']['amount'] / (float) $listing['price']['divisor'],
                2
            );
        }

        // url_570xN is a good balance of quality and size for thumbnails
        if (!empty($listing['images'][0]['url_570xN'])) {
            $data['image_url'] = esc_url_raw($listing['images'][0]['url_570xN']);
        }

        if (!empty($listing['description'])) {
            $data['description'] = $this->clean_description($listing['description']);
        }

        return $data;
    }

    private function extract_etsy_listing_id(string $url): ?string {
        return preg_match('/etsy\.com\/listing\/(\d+)/i', $url, $m) ? $m[1] : null;
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Strip HTML, collapse whitespace, and truncate at a sentence boundary
     * within 160 characters. Shared by all retailer implementations.
     */
    private function clean_description(string $raw): string {
        $text = wp_strip_all_tags($raw);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= 160) {
            return $text;
        }

        $short    = mb_substr($text, 0, 160);
        $last_end = max(
            (int) strrpos($short, '. '),
            (int) strrpos($short, '! '),
            (int) strrpos($short, '? ')
        );

        if ($last_end > 80) {
            return mb_substr($short, 0, $last_end + 1);
        }

        $last_space = (int) strrpos($short, ' ');
        return ($last_space > 80 ? mb_substr($short, 0, $last_space) : $short) . '…';
    }
}
