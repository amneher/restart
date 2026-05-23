<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class AffiliateConverterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // The constructor calls get_option() for every retailer config and apply_filters()
        Functions\when('get_option')->returnArg(2);    // return the default value
        Functions\when('apply_filters')->returnArg(2); // return the unfiltered value
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function converter(): Restart_Registry_Affiliate_Converter {
        return new Restart_Registry_Affiliate_Converter();
    }

    // ── Domain recognition ───────────────────────────────────────────────────

    public function test_recognises_amazon_domain(): void {
        $result = $this->converter()->convert_url('https://www.amazon.com/dp/B09XYZ');
        $this->assertSame('Amazon', $result['retailer']);
    }

    public function test_recognises_etsy_domain(): void {
        $result = $this->converter()->convert_url('https://www.etsy.com/listing/123456/some-item');
        $this->assertSame('Etsy', $result['retailer']);
    }

    public function test_recognises_target_domain(): void {
        $result = $this->converter()->convert_url('https://www.target.com/p/some-product/-/A-12345');
        $this->assertSame('Target', $result['retailer']);
    }

    public function test_unknown_domain_extracts_retailer_name_from_host(): void {
        $result = $this->converter()->convert_url('https://www.somestore.com/product/123');
        $this->assertSame('Somestore', $result['retailer']);
        $this->assertFalse($result['is_affiliate']);
    }

    public function test_invalid_url_returns_original(): void {
        $result = $this->converter()->convert_url('not-a-url');
        $this->assertSame('not-a-url', $result['affiliate_url']);
        $this->assertFalse($result['is_affiliate']);
    }

    // ── Amazon affiliate URL ─────────────────────────────────────────────────

    public function test_amazon_appends_tag_when_configured(): void {
        // Override get_option to return a tag for amazon
        Functions\when('get_option')
            ->alias(function (string $key, $default = false) {
                return $key === 'restart_registry_amazon_tag' ? 'mytag-20' : $default;
            });
        Functions\when('apply_filters')->returnArg(2);

        $result = $this->converter()->convert_url('https://www.amazon.com/dp/B09XYZ');
        $this->assertStringContainsString('tag=mytag-20', $result['affiliate_url']);
        $this->assertTrue($result['is_affiliate']);
    }

    public function test_amazon_returns_original_when_tag_not_configured(): void {
        $url    = 'https://www.amazon.com/dp/B09XYZ';
        $result = $this->converter()->convert_url($url);
        // get_option returns '' (the default from returnArg(2)), so no tag → original URL
        $this->assertSame($url, $result['affiliate_url']);
        $this->assertFalse($result['is_affiliate']);
    }

    // ── Etsy affiliate URL ───────────────────────────────────────────────────

    public function test_etsy_appends_ref_when_configured(): void {
        Functions\when('get_option')
            ->alias(function (string $key, $default = false) {
                return $key === 'restart_registry_etsy_id' ? 'myetsyid' : $default;
            });
        Functions\when('apply_filters')->returnArg(2);

        $result = $this->converter()->convert_url('https://www.etsy.com/listing/123456/some-item');
        $this->assertStringContainsString('ref=aff_myetsyid', $result['affiliate_url']);
        $this->assertTrue($result['is_affiliate']);
    }

    public function test_etsy_returns_original_when_id_not_configured(): void {
        $url    = 'https://www.etsy.com/listing/123456/some-item';
        $result = $this->converter()->convert_url($url);
        $this->assertSame($url, $result['affiliate_url']);
        $this->assertFalse($result['is_affiliate']);
    }

    // ── eBay rover URL ───────────────────────────────────────────────────────

    public function test_ebay_wraps_in_rover_url_when_configured(): void {
        Functions\when('get_option')
            ->alias(function (string $key, $default = false) {
                return $key === 'restart_registry_ebay_id' ? '5338-12345-6' : $default;
            });
        Functions\when('apply_filters')->returnArg(2);

        $url    = 'https://www.ebay.com/itm/123456789';
        $result = $this->converter()->convert_url($url);
        $this->assertStringStartsWith('https://rover.ebay.com/rover/1/', $result['affiliate_url']);
        $this->assertStringContainsString(urlencode($url), $result['affiliate_url']);
        $this->assertTrue($result['is_affiliate']);
    }

    // ── Custom template URL ──────────────────────────────────────────────────

    private function converterWithCustomRetailer(array $extra): Restart_Registry_Affiliate_Converter {
        Functions\when('get_option')->returnArg(2);
        Functions\when('apply_filters')
            ->alias(function (string $hook, array $configs) use ($extra): array {
                if ($hook === 'restart_registry_affiliate_configs') {
                    return array_merge($configs, $extra);
                }
                return $configs;
            });
        return new Restart_Registry_Affiliate_Converter();
    }

    public function test_custom_template_substitutes_all_placeholders(): void {
        $url       = 'https://www.potterybarn.com/products/some-item/';
        $converter = $this->converterWithCustomRetailer([
            'potterybarn' => [
                'enabled'      => true,
                'domains'      => ['potterybarn.com'],
                'affiliate_id' => 'AFF123',
                'merchant_id'  => 'MERCH456',
                'url_template' => 'https://network.com/r?id={affiliate_id}&m={merchant_id}&u={url}',
            ],
        ]);

        $result = $converter->convert_url($url);

        $this->assertStringContainsString('id=AFF123', $result['affiliate_url']);
        $this->assertStringContainsString('m=MERCH456', $result['affiliate_url']);
        $this->assertStringContainsString(urlencode($url), $result['affiliate_url']);
        $this->assertTrue($result['is_affiliate']);
    }

    public function test_custom_template_url_encodes_product_url(): void {
        $url       = 'https://www.potterybarn.com/products/item?color=red&size=xl';
        $converter = $this->converterWithCustomRetailer([
            'potterybarn' => [
                'enabled'      => true,
                'domains'      => ['potterybarn.com'],
                'affiliate_id' => 'AFF123',
                'url_template' => 'https://network.com/r?id={affiliate_id}&u={url}',
            ],
        ]);

        $result = $converter->convert_url($url);

        $this->assertStringContainsString(urlencode($url), $result['affiliate_url']);
        $this->assertTrue($result['is_affiliate']);
    }

    public function test_custom_template_returns_original_when_affiliate_id_empty(): void {
        $url       = 'https://www.potterybarn.com/products/some-item/';
        $converter = $this->converterWithCustomRetailer([
            'potterybarn' => [
                'enabled'      => true,
                'domains'      => ['potterybarn.com'],
                'affiliate_id' => '',
                'url_template' => 'https://network.com/r?id={affiliate_id}&u={url}',
            ],
        ]);

        $result = $converter->convert_url($url);

        $this->assertSame($url, $result['affiliate_url']);
        $this->assertFalse($result['is_affiliate']);
    }

    public function test_custom_template_returns_original_when_template_empty(): void {
        $url       = 'https://www.potterybarn.com/products/some-item/';
        $converter = $this->converterWithCustomRetailer([
            'potterybarn' => [
                'enabled'      => true,
                'domains'      => ['potterybarn.com'],
                'affiliate_id' => 'AFF123',
                'url_template' => '',
            ],
        ]);

        $result = $converter->convert_url($url);

        $this->assertSame($url, $result['affiliate_url']);
        $this->assertFalse($result['is_affiliate']);
    }

    public function test_custom_retailer_domain_is_recognised(): void {
        $url       = 'https://www.westelm.com/products/sofa/';
        $converter = $this->converterWithCustomRetailer([
            'westelm' => [
                'enabled'      => true,
                'domains'      => ['westelm.com'],
                'affiliate_id' => 'WE99',
                'url_template' => 'https://cj.com/link?url={url}&aff={affiliate_id}',
                'display_name' => 'West Elm',
            ],
        ]);

        $result = $converter->convert_url($url);

        $this->assertSame('Westelm', $result['retailer']);
        $this->assertTrue($result['is_affiliate']);
    }
}
