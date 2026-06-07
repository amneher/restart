<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Expanded tests for Restart_Registry_Affiliate_Converter
 * 
 * Covers:
 * - Edge cases in URL parsing and domain extraction
 * - Error handling for malformed URLs
 * - Idempotency (already-converted URLs)
 * - Query parameter preservation
 * - Case sensitivity in domain matching
 */
class AffiliateConverterExpandedTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_option')->returnArg(2);
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function converter(): Restart_Registry_Affiliate_Converter
    {
        return new Restart_Registry_Affiliate_Converter();
    }

    // ── URL Parsing Edge Cases ───────────────────────────────────────────────

    public function test_url_with_trailing_slash(): void
    {
        $url = 'https://www.amazon.com/dp/B09XYZ/';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_without_www_prefix(): void
    {
        $url = 'https://amazon.com/dp/B09XYZ';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_with_port_number(): void
    {
        $url = 'https://amazon.com:8080/dp/B09XYZ';
        $result = $this->converter()->convert_url($url);
        // Should extract domain correctly despite port
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_with_subdomain(): void
    {
        $url = 'https://subdomain.amazon.com/dp/B09XYZ';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_with_query_parameters(): void
    {
        $url = 'https://www.amazon.com/dp/B09XYZ?ref=sr_1_1&keywords=test';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
        // Query params should be preserved
        $this->assertStringContainsString('keywords=test', $result['affiliate_url']);
    }

    public function test_url_with_fragments(): void
    {
        $url = 'https://www.amazon.com/dp/B09XYZ#reviews';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_case_insensitive_domain(): void
    {
        $result1 = $this->converter()->convert_url('https://www.AMAZON.com/dp/B09XYZ');
        $result2 = $this->converter()->convert_url('https://www.amazon.com/dp/B09XYZ');
        $this->assertSame($result1['retailer'], $result2['retailer']);
    }

    // ── Malformed URLs ──────────────────────────────────────────────────────

    public function test_empty_string_url(): void
    {
        $result = $this->converter()->convert_url('');
        $this->assertSame('', $result['affiliate_url']);
    }

    public function test_url_missing_protocol(): void
    {
        $url = 'www.amazon.com/dp/B09XYZ';
        $result = $this->converter()->convert_url($url);
        // Should handle gracefully
        $this->assertIsArray($result);
    }

    public function test_url_with_only_domain(): void
    {
        $url = 'https://amazon.com';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_with_special_characters(): void
    {
        $url = 'https://www.amazon.com/dp/B09XYZ?tag=special-20&ref=sr_1_1';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_url_with_unicode_characters(): void
    {
        $url = 'https://www.amazon.com/dp/B09XYZ?ref=búsqueda';
        $result = $this->converter()->convert_url($url);
        $this->assertIsArray($result);
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_already_converted_url_idempotent(): void
    {
        $original = 'https://www.amazon.com/dp/B09XYZ';
        
        $result1 = $this->converter()->convert_url($original);
        $result2 = $this->converter()->convert_url($result1['affiliate_url']);

        $this->assertSame($result1['affiliate_url'], $result2['affiliate_url']);
    }

    public function test_converting_twice_produces_same_result(): void
    {
        $url = 'https://www.amazon.com/dp/B09XYZ';
        
        $result1 = $this->converter()->convert_url($url);
        $result2 = $this->converter()->convert_url($url);
        
        $this->assertSame($result1['affiliate_url'], $result2['affiliate_url']);
        $this->assertSame($result1['retailer'], $result2['retailer']);
    }

    // ── Multiple Retailers ───────────────────────────────────────────────────

    public function test_walmart_url_conversion(): void
    {
        $url = 'https://www.walmart.com/ip/123456789';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Walmart', $result['retailer']);
    }

    public function test_best_buy_url_conversion(): void
    {
        $url = 'https://www.bestbuy.com/site/123456';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Bestbuy', $result['retailer']);
    }

    public function test_custom_retailer_without_conversion(): void
    {
        $url = 'https://www.custom-store.com/product/123';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Custom-store', $result['retailer']);
        $this->assertFalse($result['is_affiliate']);
    }

    // ── Domain Extraction Edge Cases ──────────────────────────────────────────

    public function test_extract_retailer_from_long_subdomain(): void
    {
        $url = 'https://very.long.subdomain.example.com/product';
        $result = $this->converter()->convert_url($url);
        // Should extract meaningful retailer name
        $this->assertIsString($result['retailer']);
    }

    public function test_extract_retailer_from_numeric_domain(): void
    {
        $url = 'https://123store.com/product';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('123store', $result['retailer']);
    }

    // ── Real-world Scenarios ─────────────────────────────────────────────────

    public function test_amazon_associate_link(): void
    {
        // Override get_option to provide a configured Amazon tag for this test
        Functions\when('get_option')->alias(
            function ($key, $default = '') {
                if ($key === 'restart_registry_amazon_tag') { return 'mytag-20';
                }
                return $default;
            }
        );
        $url = 'https://www.amazon.com/dp/B09XYZABC12';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
        $this->assertTrue($result['is_affiliate']);
        $this->assertStringContainsString('mytag-20', $result['affiliate_url']);
    }

    public function test_etsy_affiliate_link(): void
    {
        $url = 'https://www.etsy.com/listing/123456/cute-item?ref=shop';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Etsy', $result['retailer']);
    }

    public function test_shortened_url_amazon(): void
    {
        // Test with minimal Amazon URL
        $url = 'https://amazon.com/dp/B123';
        $result = $this->converter()->convert_url($url);
        $this->assertSame('Amazon', $result['retailer']);
    }
}
