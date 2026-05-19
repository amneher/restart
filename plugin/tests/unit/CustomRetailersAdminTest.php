<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class CustomRetailersAdminTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(fn($v) => is_string($v) ? trim(strip_tags($v)) : '');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function admin(): Restart_Registry_Admin {
        return new Restart_Registry_Admin('restart-registry', '1.0.0');
    }

    // ── sanitize_custom_retailers ────────────────────────────────────────────

    public function test_valid_rows_are_preserved(): void {
        $input = [
            [
                'name'         => 'Pottery Barn',
                'domains'      => 'potterybarn.com, pb.com',
                'template'     => 'https://network.com/r?url={url}&id={affiliate_id}',
                'affiliate_id' => 'AFF123',
                'merchant_id'  => 'M456',
            ],
        ];

        $result = $this->admin()->sanitize_custom_retailers($input);

        $this->assertCount(1, $result);
        $this->assertSame('Pottery Barn', $result[0]['name']);
        $this->assertSame('potterybarn.com, pb.com', $result[0]['domains']);
        $this->assertSame('https://network.com/r?url={url}&id={affiliate_id}', $result[0]['template']);
        $this->assertSame('AFF123', $result[0]['affiliate_id']);
        $this->assertSame('M456', $result[0]['merchant_id']);
    }

    public function test_non_array_input_returns_empty_array(): void {
        $this->assertSame([], $this->admin()->sanitize_custom_retailers('not-an-array'));
        $this->assertSame([], $this->admin()->sanitize_custom_retailers(null));
        $this->assertSame([], $this->admin()->sanitize_custom_retailers(42));
    }

    public function test_row_missing_name_is_dropped(): void {
        $input = [[
            'name'         => '',
            'domains'      => 'example.com',
            'template'     => 'https://network.com/r?url={url}',
            'affiliate_id' => 'AFF1',
            'merchant_id'  => '',
        ]];

        $this->assertCount(0, $this->admin()->sanitize_custom_retailers($input));
    }

    public function test_row_missing_domains_is_dropped(): void {
        $input = [[
            'name'         => 'Some Store',
            'domains'      => '',
            'template'     => 'https://network.com/r?url={url}',
            'affiliate_id' => 'AFF1',
            'merchant_id'  => '',
        ]];

        $this->assertCount(0, $this->admin()->sanitize_custom_retailers($input));
    }

    public function test_row_missing_template_is_dropped(): void {
        $input = [[
            'name'         => 'Some Store',
            'domains'      => 'somestore.com',
            'template'     => '',
            'affiliate_id' => 'AFF1',
            'merchant_id'  => '',
        ]];

        $this->assertCount(0, $this->admin()->sanitize_custom_retailers($input));
    }

    public function test_html_tags_are_stripped_from_fields(): void {
        $input = [[
            'name'         => '<b>Evil Store</b>',
            'domains'      => '<script>alert(1)</script>evil.com',
            'template'     => 'https://network.com/r?url={url}&id={affiliate_id}',
            'affiliate_id' => '<em>AFF1</em>',
            'merchant_id'  => '<img>M1',
        ]];

        $result = $this->admin()->sanitize_custom_retailers($input);

        $this->assertSame('Evil Store', $result[0]['name']);
        $this->assertSame('alert(1)evil.com', $result[0]['domains']);
        $this->assertSame('AFF1', $result[0]['affiliate_id']);
        $this->assertSame('M1', $result[0]['merchant_id']);
    }

    public function test_multiple_valid_rows_all_kept_and_reindexed(): void {
        $input = [
            5 => ['name' => 'Store A', 'domains' => 'a.com', 'template' => 'https://net.com/{url}', 'affiliate_id' => '1', 'merchant_id' => ''],
            9 => ['name' => 'Store B', 'domains' => 'b.com', 'template' => 'https://net.com/{url}', 'affiliate_id' => '2', 'merchant_id' => ''],
        ];

        $result = $this->admin()->sanitize_custom_retailers($input);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertSame('Store A', $result[0]['name']);
        $this->assertSame('Store B', $result[1]['name']);
    }

    public function test_merchant_id_is_optional(): void {
        $input = [[
            'name'         => 'Store A',
            'domains'      => 'a.com',
            'template'     => 'https://net.com/r?url={url}&id={affiliate_id}',
            'affiliate_id' => 'AFF1',
        ]];

        $result = $this->admin()->sanitize_custom_retailers($input);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['merchant_id']);
    }
}
