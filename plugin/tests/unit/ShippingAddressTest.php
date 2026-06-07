<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shipping address controller methods.
 */
class ShippingAddressTest extends TestCase {

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 2) . '/includes/');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('get_option')->justReturn('');

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_post(int $id): WP_Post {
        $post              = new WP_Post();
        $post->ID          = $id;
        $post->post_type   = 'restart-registry';
        $post->post_status = 'private';
        $post->post_author = 5;
        $post->post_title  = 'My Registry';
        $post->post_content = '';
        $post->post_name   = 'abc123';
        return $post;
    }

    // ── save_shipping_address ─────────────────────────────────────────────────

    public function test_save_sanitizes_fields_and_persists(): void {
        Functions\when('get_post')->justReturn($this->make_post(10));
        $saved = null;
        Functions\expect('update_post_meta')
            ->once()
            ->andReturnUsing(function($id, $key, $value) use (&$saved) {
                $saved = json_decode($value, true);
                return true;
            });

        $result = $this->controller->save_shipping_address(10, [
            'name'        => 'Alex Rivera',
            'address_1'   => '123 Main St',
            'address_2'   => 'Apt 4B',
            'city'        => 'Portland',
            'state'       => 'OR',
            'postal_code' => '97205',
            'country'     => 'US',
        ]);

        $this->assertTrue($result);
        $this->assertSame('Alex Rivera', $saved['name']);
        $this->assertSame('123 Main St', $saved['address_1']);
        $this->assertSame('Portland',    $saved['city']);
    }

    public function test_save_returns_false_when_address_1_missing(): void {
        Functions\when('get_post')->justReturn($this->make_post(10));

        $result = $this->controller->save_shipping_address(10, [
            'name'   => 'Alex Rivera',
            'city'   => 'Portland',
            'state'  => 'OR',
            'country'=> 'US',
        ]);

        $this->assertFalse($result);
    }

    public function test_save_returns_false_when_city_missing(): void {
        Functions\when('get_post')->justReturn($this->make_post(10));

        $result = $this->controller->save_shipping_address(10, [
            'address_1' => '123 Main St',
        ]);

        $this->assertFalse($result);
    }

    // ── get_shipping_address ──────────────────────────────────────────────────

    public function test_get_returns_null_when_meta_not_set(): void {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->controller->get_shipping_address(10);

        $this->assertNull($result);
    }

    public function test_get_decodes_and_returns_stored_address(): void {
        $stored = json_encode([
            'name'        => 'Alex Rivera',
            'address_1'   => '123 Main St',
            'address_2'   => '',
            'city'        => 'Portland',
            'state'       => 'OR',
            'postal_code' => '97205',
            'country'     => 'US',
        ]);
        Functions\when('get_post_meta')->justReturn($stored);

        $result = $this->controller->get_shipping_address(10);

        $this->assertIsArray($result);
        $this->assertSame('Portland',   $result['city']);
        $this->assertSame('97205',      $result['postal_code']);
    }

    // ── delete_shipping_address ───────────────────────────────────────────────

    public function test_delete_removes_meta_key(): void {
        Functions\expect('delete_post_meta')
            ->once()
            ->with(10, 'restart_shipping_address')
            ->andReturn(true);

        $result = $this->controller->delete_shipping_address(10);

        $this->assertTrue($result);
    }
}
