<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the decorator additions to Restart_Registry_Affiliate_Converter:
 * instance(), convert_url_string(), convert_content(), wrap().
 */
class AffiliateConverterDecoratorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_option')->returnArg(2);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('add_filter')->justReturn(true);
    }

    protected function tearDown(): void {
        $ref = new ReflectionProperty(Restart_Registry_Affiliate_Converter::class, 'instance');
        $ref->setValue(null, null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function withAmazonTag(): void {
        Functions\when('get_option')
            ->alias(function (string $key, $default = false) {
                return $key === 'restart_registry_amazon_tag' ? 'testtag-20' : $default;
            });
        Functions\when('apply_filters')->returnArg(2);
    }

    // ── Singleton ────────────────────────────────────────────────────────────

    public function test_instance_returns_same_object(): void {
        $a = Restart_Registry_Affiliate_Converter::instance();
        $b = Restart_Registry_Affiliate_Converter::instance();
        $this->assertSame($a, $b);
    }

    public function test_instance_is_correct_class(): void {
        $this->assertInstanceOf(
            Restart_Registry_Affiliate_Converter::class,
            Restart_Registry_Affiliate_Converter::instance()
        );
    }

    // ── convert_url_string ───────────────────────────────────────────────────

    public function test_convert_url_string_returns_string(): void {
        $result = (new Restart_Registry_Affiliate_Converter())
            ->convert_url_string('https://www.somestore.com/product/123');
        $this->assertIsString($result);
    }

    public function test_convert_url_string_returns_affiliate_url(): void {
        $this->withAmazonTag();
        $result = (new Restart_Registry_Affiliate_Converter())
            ->convert_url_string('https://www.amazon.com/dp/B08N5WRWNW');
        $this->assertSame('https://www.amazon.com/dp/B08N5WRWNW?tag=testtag-20', $result);
    }

    public function test_convert_url_string_returns_original_for_non_affiliate(): void {
        $url = 'https://www.somestore.com/product/123';
        $this->assertSame(
            $url,
            (new Restart_Registry_Affiliate_Converter())->convert_url_string($url)
        );
    }

    // ── convert_content ──────────────────────────────────────────────────────

    public function test_convert_content_fast_paths_html_with_no_anchor(): void {
        $html = '<p>No links here.</p>';
        $this->assertSame(
            $html,
            (new Restart_Registry_Affiliate_Converter())->convert_content($html)
        );
    }

    public function test_convert_content_fast_paths_empty_string(): void {
        $this->assertSame(
            '',
            (new Restart_Registry_Affiliate_Converter())->convert_content('')
        );
    }

    public function test_convert_content_rewrites_affiliate_href(): void {
        $this->withAmazonTag();
        $result = (new Restart_Registry_Affiliate_Converter())->convert_content(
            '<a href="https://www.amazon.com/dp/B08N5WRWNW">Buy it</a>'
        );
        $this->assertStringContainsString('tag=testtag-20', $result);
        $this->assertStringContainsString('>Buy it</a>', $result);
    }

    public function test_convert_content_leaves_non_affiliate_href_unchanged(): void {
        $result = (new Restart_Registry_Affiliate_Converter())->convert_content(
            '<a href="https://www.somestore.com/product/123">Shop</a>'
        );
        $this->assertStringContainsString('href="https://www.somestore.com/product/123"', $result);
    }

    public function test_convert_content_rewrites_only_matching_links(): void {
        $this->withAmazonTag();
        $result = (new Restart_Registry_Affiliate_Converter())->convert_content(
            '<a href="https://www.amazon.com/dp/B08N5WRWNW">Amazon</a>'
            . '<a href="https://www.somestore.com/product/123">Other</a>'
        );
        $this->assertStringContainsString('tag=testtag-20', $result);
        $this->assertStringContainsString('href="https://www.somestore.com/product/123"', $result);
    }

    public function test_convert_content_preserves_anchor_attributes(): void {
        $this->withAmazonTag();
        $result = (new Restart_Registry_Affiliate_Converter())->convert_content(
            '<a href="https://www.amazon.com/dp/B08N5WRWNW" class="btn" target="_blank" rel="noopener">Buy</a>'
        );
        $this->assertStringContainsString('class="btn"', $result);
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener"', $result);
    }

    public function test_convert_content_skips_anchor_without_href(): void {
        $result = (new Restart_Registry_Affiliate_Converter())->convert_content(
            '<a name="top">Back to top</a>'
        );
        $this->assertStringContainsString('Back to top', $result);
    }

    // ── wrap ─────────────────────────────────────────────────────────────────

    public function test_wrap_converts_plain_url_from_callable(): void {
        $this->withAmazonTag();
        $result = (new Restart_Registry_Affiliate_Converter())
            ->wrap(fn() => 'https://www.amazon.com/dp/B08N5WRWNW');
        $this->assertStringContainsString('tag=testtag-20', $result);
    }

    public function test_wrap_converts_links_in_html_from_callable(): void {
        $this->withAmazonTag();
        $result = (new Restart_Registry_Affiliate_Converter())->wrap(
            fn() => '<a href="https://www.amazon.com/dp/B08N5WRWNW">Buy</a>'
        );
        $this->assertStringContainsString('tag=testtag-20', $result);
    }

    public function test_wrap_passes_args_to_callable(): void {
        $url    = 'https://www.somestore.com/product/123';
        $result = (new Restart_Registry_Affiliate_Converter())
            ->wrap(fn(string $u) => $u, $url);
        $this->assertSame($url, $result);
    }

    public function test_wrap_returns_html_without_links_unchanged(): void {
        $html = '<p>No links here.</p>';
        $this->assertSame(
            $html,
            (new Restart_Registry_Affiliate_Converter())->wrap(fn() => $html)
        );
    }

    public function test_wrap_returns_non_affiliate_url_unchanged(): void {
        $url = 'https://www.somestore.com/product/123';
        $this->assertSame(
            $url,
            (new Restart_Registry_Affiliate_Converter())->wrap(fn() => $url)
        );
    }
}
