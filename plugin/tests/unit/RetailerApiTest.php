<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class RetailerApiTest extends TestCase {

    private Restart_Registry_Retailer_API $api;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->api = new Restart_Registry_Retailer_API();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── detect_retailer ──────────────────────────────────────────────────────

    public function test_detects_etsy_with_www(): void {
        $this->assertSame('etsy', $this->api->detect_retailer('https://www.etsy.com/listing/123456/some-item'));
    }

    public function test_detects_etsy_without_www(): void {
        $this->assertSame('etsy', $this->api->detect_retailer('https://etsy.com/listing/123456'));
    }

    public function test_returns_null_for_amazon(): void {
        // Amazon is not yet in RETAILER_DOMAINS (Phase 2)
        $this->assertNull($this->api->detect_retailer('https://www.amazon.com/dp/B09XYZ'));
    }

    public function test_returns_null_for_unknown_domain(): void {
        $this->assertNull($this->api->detect_retailer('https://example.com/product/123'));
    }

    public function test_returns_null_for_empty_url(): void {
        $this->assertNull($this->api->detect_retailer(''));
    }

    // ── has_api ──────────────────────────────────────────────────────────────

    public function test_has_api_returns_true_when_key_configured(): void {
        Functions\when('get_option')->justReturn('etsyapikey123');
        $this->assertTrue($this->api->has_api('etsy'));
    }

    public function test_has_api_returns_false_when_key_empty_string(): void {
        Functions\when('get_option')->justReturn('');
        $this->assertFalse($this->api->has_api('etsy'));
    }

    public function test_has_api_returns_false_when_key_is_false(): void {
        Functions\when('get_option')->justReturn(false);
        $this->assertFalse($this->api->has_api('etsy'));
    }

    // ── fetch_if_configured ──────────────────────────────────────────────────

    public function test_fetch_if_configured_returns_null_for_unrecognised_retailer(): void {
        // amazon.com not in RETAILER_DOMAINS yet, so no API key check needed
        $this->assertNull($this->api->fetch_if_configured('https://www.amazon.com/dp/B09XYZ'));
    }

    public function test_fetch_if_configured_returns_null_when_etsy_key_not_set(): void {
        Functions\when('get_option')->justReturn('');
        $this->assertNull($this->api->fetch_if_configured('https://www.etsy.com/listing/123456'));
    }

    // ── clean_description (via reflection) ───────────────────────────────────

    private function cleanDescription(string $raw): string {
        Functions\when('wp_strip_all_tags')->alias(function (string $s): string {
            return strip_tags($s);
        });
        $method = new ReflectionMethod(Restart_Registry_Retailer_API::class, 'clean_description');
        return $method->invoke($this->api, $raw);
    }

    public function test_clean_description_returns_short_text_unchanged(): void {
        $text = 'A short product description.';
        $this->assertSame($text, $this->cleanDescription($text));
    }

    public function test_clean_description_strips_html(): void {
        $result = $this->cleanDescription('<p>Hello <b>world</b></p>');
        $this->assertSame('Hello world', $result);
    }

    public function test_clean_description_truncates_at_sentence_boundary(): void {
        // Two sentences; the first ends well before 160 chars, second is very long
        $sentence1 = str_repeat('x', 85) . '. ';
        $sentence2 = str_repeat('y', 100);
        $result    = $this->cleanDescription($sentence1 . $sentence2);
        $this->assertStringEndsWith('.', $result);
        $this->assertLessThanOrEqual(160, mb_strlen($result));
    }

    public function test_clean_description_truncates_at_word_boundary_when_no_sentence(): void {
        // 50 "word " repetitions = 250 chars, no punctuation
        $long   = str_repeat('word ', 50);
        $result = $this->cleanDescription($long);
        // Should end with ellipsis and fit within 165 bytes (160 + '…' which is 3 bytes)
        $this->assertStringEndsWith('…', $result);
        $this->assertLessThanOrEqual(165, strlen($result));
    }

    public function test_clean_description_collapses_whitespace(): void {
        $result = $this->cleanDescription("line one\n\nline   two");
        $this->assertSame('line one line two', $result);
    }
}
