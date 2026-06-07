<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class LLMExtractorTest extends TestCase
{

    private Restart_Registry_LLM_Extractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->extractor = new Restart_Registry_LLM_Extractor();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── slice_html ────────────────────────────────────────────────────────────

    public function test_slice_extracts_head_section(): void
    {
        $html   = '<html><head><title>Test</title><meta name="description" content="Desc"></head><body><p>Body</p></body></html>';
        $result = $this->extractor->slice_html($html);
        $this->assertStringContainsString('<title>Test</title>', $result);
        $this->assertStringNotContainsString('<p>Body</p>', $result);
    }

    public function test_slice_extracts_json_ld_blocks(): void
    {
        $html = '<html><head></head><body>'
              . '<script type="application/ld+json">{"@type":"Product","name":"Lamp"}</script>'
              . '<p>Text</p>'
              . '</html>';
        $result = $this->extractor->slice_html($html);
        $this->assertStringContainsString('"@type":"Product"', $result);
        $this->assertStringNotContainsString('<p>Text</p>', $result);
    }

    public function test_slice_combines_head_and_json_ld(): void
    {
        $html = '<html>'
              . '<head><meta property="og:title" content="Chair"></head>'
              . '<body>'
              . '<script type="application/ld+json">{"@type":"Product"}</script>'
              . '</body></html>';
        $result = $this->extractor->slice_html($html);
        $this->assertStringContainsString('og:title', $result);
        $this->assertStringContainsString('"@type":"Product"', $result);
    }

    public function test_slice_returns_empty_string_when_no_useful_content(): void
    {
        $this->assertSame('', $this->extractor->slice_html(''));
        $this->assertSame('', $this->extractor->slice_html('<body><p>No head, no ld+json</p></body>'));
    }

    public function test_slice_handles_multiple_json_ld_blocks(): void
    {
        $html = '<html><head></head><body>'
              . '<script type="application/ld+json">{"@type":"BreadcrumbList"}</script>'
              . '<script type="application/ld+json">{"@type":"Product","name":"Chair"}</script>'
              . '</body></html>';
        $result = $this->extractor->slice_html($html);
        $this->assertStringContainsString('BreadcrumbList', $result);
        $this->assertStringContainsString('"name":"Chair"', $result);
    }

    // ── parse_response ────────────────────────────────────────────────────────

    public function test_parse_response_extracts_tool_use_block(): void
    {
        $response = json_encode(
            [
            'content' => [
                [
                    'type'  => 'tool_use',
                    'name'  => 'extract_product_data',
                    'input' => [
                        'name'        => 'Artisan Stand Mixer',
                        'price'       => 449.99,
                        'image_url'   => 'https://example.com/image.jpg',
                        'description' => 'A great mixer.',
                    ],
                ],
            ],
            ]
        );

        $result = $this->extractor->parse_response($response);

        $this->assertSame('Artisan Stand Mixer', $result['name']);
        $this->assertSame(449.99, $result['price']);
        $this->assertSame('https://example.com/image.jpg', $result['image_url']);
        $this->assertSame('A great mixer.', $result['description']);
    }

    public function test_parse_response_converts_null_price_to_empty_string(): void
    {
        $response = json_encode(
            [
            'content' => [
                [
                    'type'  => 'tool_use',
                    'name'  => 'extract_product_data',
                    'input' => ['name' => 'Item', 'price' => null, 'image_url' => '', 'description' => ''],
                ],
            ],
            ]
        );

        $result = $this->extractor->parse_response($response);
        $this->assertSame('', $result['price']);
    }

    public function test_parse_response_trims_whitespace_from_strings(): void
    {
        $response = json_encode(
            [
            'content' => [
                [
                    'type'  => 'tool_use',
                    'name'  => 'extract_product_data',
                    'input' => ['name' => '  Chair  ', 'price' => null, 'image_url' => '  https://x.com/img.jpg  ', 'description' => '  Nice chair.  '],
                ],
            ],
            ]
        );

        $result = $this->extractor->parse_response($response);
        $this->assertSame('Chair', $result['name']);
        $this->assertSame('https://x.com/img.jpg', $result['image_url']);
        $this->assertSame('Nice chair.', $result['description']);
    }

    public function test_parse_response_returns_empty_on_invalid_json(): void
    {
        $this->assertSame([], $this->extractor->parse_response('not json'));
        $this->assertSame([], $this->extractor->parse_response(''));
    }

    public function test_parse_response_returns_empty_when_no_tool_use_block(): void
    {
        $response = json_encode(
            [
            'content' => [
                ['type' => 'text', 'text' => 'Some text response.'],
            ],
            ]
        );
        $this->assertSame([], $this->extractor->parse_response($response));
    }

    public function test_parse_response_returns_empty_when_tool_name_mismatch(): void
    {
        $response = json_encode(
            [
            'content' => [
                ['type' => 'tool_use', 'name' => 'some_other_tool', 'input' => ['name' => 'X']],
            ],
            ]
        );
        $this->assertSame([], $this->extractor->parse_response($response));
    }

    public function test_parse_response_returns_empty_on_empty_content_array(): void
    {
        $this->assertSame([], $this->extractor->parse_response(json_encode(['content' => []])));
    }

    // ── extract — API key guard ───────────────────────────────────────────────

    public function test_extract_returns_empty_when_api_key_not_configured(): void
    {
        Functions\when('get_option')->justReturn('');

        $result = $this->extractor->extract('https://example.com/product', '<html></html>');
        $this->assertSame([], $result);
    }

    public function test_extract_returns_empty_when_html_yields_no_context(): void
    {
        Functions\when('get_option')->justReturn('sk-ant-test-key');

        // No <head> and no JSON-LD in this HTML
        $result = $this->extractor->extract('https://example.com/product', '<body><p>Nothing useful</p></body>');
        $this->assertSame([], $result);
    }

    // ── extract — happy path ──────────────────────────────────────────────────

    public function test_extract_returns_structured_data_on_success(): void
    {
        Functions\when('get_option')->justReturn('sk-ant-test-key');

        $api_response = json_encode(
            [
            'content' => [
                [
                    'type'  => 'tool_use',
                    'name'  => 'extract_product_data',
                    'input' => [
                        'name'        => 'West Elm Sofa',
                        'price'       => 1299.0,
                        'image_url'   => 'https://westelm.com/sofa.jpg',
                        'description' => 'A comfortable modern sofa.',
                    ],
                ],
            ],
            ]
        );

        $fake_response = ['body' => $api_response, 'response' => ['code' => 200]];
        Functions\when('wp_remote_post')->justReturn($fake_response);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($api_response);

        $html = '<html><head><title>West Elm Sofa | West Elm</title></head><body></body></html>';
        $result = $this->extractor->extract('https://www.westelm.com/products/sofa', $html);

        $this->assertSame('West Elm Sofa', $result['name']);
        $this->assertSame(1299.0, $result['price']);
        $this->assertSame('https://westelm.com/sofa.jpg', $result['image_url']);
        $this->assertSame('A comfortable modern sofa.', $result['description']);
    }

    // ── extract — failure paths ───────────────────────────────────────────────

    public function test_extract_returns_empty_on_wp_error(): void
    {
        Functions\when('get_option')->justReturn('sk-ant-test-key');
        Functions\when('wp_remote_post')->justReturn(new WP_Error('http_error', 'Connection refused'));
        Functions\when('is_wp_error')->justReturn(true);

        $html   = '<html><head><title>Product</title></head></html>';
        $result = $this->extractor->extract('https://example.com/product', $html);
        $this->assertSame([], $result);
    }

    public function test_extract_returns_empty_on_non_200_response(): void
    {
        Functions\when('get_option')->justReturn('sk-ant-test-key');
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);

        $html   = '<html><head><title>Product</title></head></html>';
        $result = $this->extractor->extract('https://example.com/product', $html);
        $this->assertSame([], $result);
    }

    public function test_extract_returns_empty_when_api_returns_no_tool_use(): void
    {
        Functions\when('get_option')->justReturn('sk-ant-test-key');

        $api_response = json_encode(['content' => [['type' => 'text', 'text' => 'I cannot extract that.']]]);
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($api_response);

        $html   = '<html><head><title>Product</title></head></html>';
        $result = $this->extractor->extract('https://example.com/product', $html);
        $this->assertSame([], $result);
    }
}
