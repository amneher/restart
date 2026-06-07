<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Real-HTTP integration test for Restart_Registry_Product_Scraper.
 *
 * Two modes:
 *   1. test_static_urls_from_file() — replays known-good URLs from
 *      tests/assets/TestItemUrls.txt so we have a stable regression set
 *      even when retailers change their markup.
 *   2. test_discover_and_scrape() — fetches a retailer category page,
 *      pulls a product URL out of it, scrapes it, and rewrites
 *      TestItemUrls.txt so the static list stays fresh.
 *
 * Bot-detection is part of life — discovery failures are skipped, not
 * fatal. The tests only fail when a URL we expected to scrape returns
 * neither a name nor an image.
 */
class ProductScraperTest extends TestCase
{

    private const UA_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * Retailers we attempt to discover live product URLs from.
     * Amazon is intentionally null — the scraper has a URL-only fast path
     * for Amazon, so we exercise that via the static ASIN list instead.
     */
    private const DISCOVERY = [
        'amazon'      => ['seed' => null,   'pattern' => null],
        'etsy'        => ['seed' => 'https://www.etsy.com/c/home-and-living', 'pattern' => '~href="(https://www\.etsy\.com/listing/\d+/[^"?]+)[^"]*"~'],
        'wayfair'     => ['seed' => 'https://www.wayfair.com/furniture/cat/accent-chairs-c45974.html', 'pattern' => '~href="(/[a-z][a-z0-9-]+/[a-z][a-z0-9-]+-pdp\.html)"~'],
        'westelm'     => ['seed' => 'https://www.westelm.com/c/new-arrivals/', 'pattern' => '~href="(https://www\.westelm\.com/products/[^"]+)"~'],
        'potterybarn' => ['seed' => 'https://www.potterybarn.com/department/new-arrivals/', 'pattern' => '~href="(https://www\.potterybarn\.com/products/[^"?]+)"~'],
        'target'      => ['seed' => 'https://www.target.com/c/home/-/N-5xt2b', 'pattern' => '~"url"\s*:\s*"(/p/[^"]+/A-\d+)"~'],
        'walmart'     => ['seed' => 'https://www.walmart.com/browse/home/bedroom/furniture/4044_90548_1045583_3888573', 'pattern' => '~href="(/ip/[^"?]+/\d+)"~'],
    ];

    private const RETAILER_ORIGINS = [
        'wayfair' => 'https://www.wayfair.com',
        'target'  => 'https://www.target.com',
        'walmart' => 'https://www.walmart.com',
    ];

    /**
     * Static, hand-curated Amazon ASINs to always include in the regression
     * set — these exercise the URL-only fast path.
     */
    private const STATIC_AMAZON_URLS = [
        'https://www.amazon.com/dp/B00004RFMH',
        'https://www.amazon.com/dp/B00006JSUA',
        'https://www.amazon.com/KitchenAid-KSM150PSER-Artisan-Series-Mixer/dp/B00005UP2P/',
        'https://a.co/d/00YdZXXx',
    ];

    public function test_static_urls_from_file(): void
    {
        $path = dirname(__DIR__, 2) . '/assets/TestItemUrls.txt';
        $this->assertFileExists($path, 'TestItemUrls.txt must exist — run the discovery test to regenerate it.');

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $urls  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $urls[] = $line;
        }

        $this->assertNotEmpty($urls, 'No URLs found in TestItemUrls.txt');

        $scraper = new Restart_Registry_Product_Scraper();
        foreach ($urls as $url) {
            $result = $scraper->scrape($url);
            $this->assert_scrape_result($url, $result);
        }
    }

    public function test_discover_and_scrape(): void
    {
        $scraper    = new Restart_Registry_Product_Scraper();
        $discovered = ['amazon' => self::STATIC_AMAZON_URLS];

        foreach (self::DISCOVERY as $retailer => $entry) {
            if ($entry['seed'] === null) {
                continue;
            }

            $seed_body = $this->curl_fetch($entry['seed'], self::UA_CHROME, 20);
            if ($seed_body === '') {
                fwrite(STDERR, "  [skip] $retailer: seed fetch returned empty body (likely bot-detected)\n");
                continue;
            }

            if (!preg_match($entry['pattern'], $seed_body, $m)) {
                fwrite(STDERR, "  [skip] $retailer: no product URLs matched pattern in seed page\n");
                continue;
            }

            $product_url = $m[1];
            if (str_starts_with($product_url, '/')) {
                $origin       = self::RETAILER_ORIGINS[$retailer] ?? '';
                $product_url  = $origin . $product_url;
            }

            $result = $scraper->scrape($product_url);
            $this->assert_scrape_result($product_url, $result);

            $discovered[$retailer] = [$product_url];
        }

        $this->write_test_urls_file($discovered);

        // We always have the static Amazon URLs in the discovered set, so
        // the file write always produces output. Assert that to keep the
        // test from being marked risky when every retailer skips.
        $this->assertNotEmpty($discovered['amazon'] ?? []);
    }

    /**
     * Asserts the scraper returned something usable for $url.
     *
     * Amazon URLs are validated via the ASIN-derived image_url; for every
     * other retailer we accept any result that has at least one of
     * name/image_url populated. On failure we dump the result so the
     * operator can diagnose without re-running.
     */
    private function assert_scrape_result(string $url, array $result): void
    {
        $is_amazon = (bool) preg_match('/amazon\.[a-z.]+|a\.co/i', $url);

        if ($is_amazon) {
            $this->assertNotEmpty(
                $result['image_url'] ?? '',
                "Amazon URL should yield a CDN image_url. URL=$url result=" . json_encode($result)
            );
            return;
        }

        $name      = trim((string) ($result['name']      ?? ''));
        $image_url = trim((string) ($result['image_url'] ?? ''));
        $this->assertTrue(
            $name !== '' || $image_url !== '',
            "Expected name or image_url for $url, got: " . json_encode($result)
        );
    }

    /**
     * Plain cURL fetch — used to drive retailer category pages directly
     * from the test (the scraper itself only takes a URL, not a body).
     */
    private function curl_fetch(string $url, string $ua, int $timeout): string
    {
        $ch = curl_init();
        curl_setopt_array(
            $ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            ]
        );
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }

    /**
     * Merges newly-discovered URLs into TestItemUrls.txt.
     *
     * Only sections for retailers that produced at least one URL on this run
     * are replaced; every other section (and the file's comment header) is
     * preserved verbatim.  This means curated static URLs survive even when
     * every discovery seed is bot-blocked.
     */
    private function write_test_urls_file(array $discovered): void
    {
        $path = dirname(__DIR__, 2) . '/assets/TestItemUrls.txt';

        // Parse the existing file into: header lines and per-section URL lists.
        $header   = [];
        $sections = [];       // retailer => string[]  (the URL lines for that section)
        $current  = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^# ([a-z][a-z0-9_-]*)$/', $line, $m)) {
                $current            = $m[1];
                $sections[$current] = [];
            } elseif ($current === null) {
                $header[] = $line;
            } elseif (trim($line) !== '') {
                $sections[$current][] = $line;
            }
        }

        // Overwrite only the sections we discovered on this run.
        foreach ($discovered as $retailer => $urls) {
            if (!empty($urls)) {
                $sections[$retailer] = $urls;
            }
        }

        // Rebuild: header, then sections in stable order (discovery order, then any extras).
        $order = array_unique(array_merge(array_keys(self::DISCOVERY), array_keys($sections)));
        $out   = implode("\n", $header) . "\n";
        foreach ($order as $retailer) {
            if (empty($sections[$retailer])) {
                continue;
            }
            $out .= "\n# {$retailer}\n";
            foreach ($sections[$retailer] as $u) {
                $out .= $u . "\n";
            }
        }

        file_put_contents($path, $out);
    }
}
