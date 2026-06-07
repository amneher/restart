<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for shipping address visibility and access control.
 */
class ShippingAddressControllerTest extends TestCase {

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/includes/');
        Functions\when('get_option')->justReturn('');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('update_post_meta')->justReturn(true);

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_post(int $id, int $author = 5): WP_Post {
        $post              = new WP_Post();
        $post->ID          = $id;
        $post->post_type   = 'restart-registry';
        $post->post_status = 'private';
        $post->post_author = $author;
        $post->post_title  = 'My Registry';
        $post->post_content = '';
        $post->post_name   = 'abc123';
        return $post;
    }

    private function make_user(int $id, string $email, string $login = ''): WP_User {
        $user             = new WP_User();
        $user->ID         = $id;
        $user->user_email = $email;
        $user->user_login = $login ?: 'user' . $id;
        return $user;
    }

    private function valid_address(): array {
        return [
            'name'        => 'Alex Rivera',
            'address_1'   => '123 Main St',
            'address_2'   => 'Apt 4B',
            'city'        => 'Portland',
            'state'       => 'OR',
            'postal_code' => '97205',
            'country'     => 'US',
        ];
    }

    // ── Owner can save, retrieve, and delete ──────────────────────────────────

    public function test_owner_can_save_address(): void {
        Functions\when('get_post')->justReturn($this->make_post(10));

        $result = $this->controller->save_shipping_address(10, $this->valid_address());

        $this->assertTrue($result);
    }

    public function test_owner_can_retrieve_address(): void {
        $stored = json_encode($this->valid_address());
        Functions\when('get_post_meta')->justReturn($stored);

        $result = $this->controller->get_shipping_address(10);

        $this->assertIsArray($result);
        $this->assertSame('Portland', $result['city']);
        $this->assertSame('97205',    $result['postal_code']);
    }

    public function test_owner_can_delete_address(): void {
        Functions\when('delete_post_meta')->justReturn(true);

        $result = $this->controller->delete_shipping_address(10);

        $this->assertTrue($result);
    }

    // ── Missing required fields ───────────────────────────────────────────────

    public function test_save_fails_without_required_fields(): void {
        Functions\when('get_post')->justReturn($this->make_post(10));

        $result = $this->controller->save_shipping_address(10, [
            'name'  => 'Alex Rivera',
            'state' => 'OR',
        ]);

        $this->assertFalse($result);
    }

    // ── Visibility: address absent from unauthenticated render path ───────────

    public function test_get_returns_null_for_registry_with_no_address(): void {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->controller->get_shipping_address(99);

        $this->assertNull($result);
    }

    // ── Visibility: invitee check ─────────────────────────────────────────────

    public function test_invitee_can_view_registry_and_address_is_accessible(): void {
        $post = $this->make_post(10, 5);
        Functions\when('get_post')->justReturn($post);
        Functions\when('user_can')->justReturn(false);

        $invitees_json = json_encode(['guest@example.com']);
        Functions\when('get_post_meta')->alias(function($_id, $key) use ($invitees_json) {
            if ($key === 'restart_invitees') return $invitees_json;
            if ($key === 'restart_shipping_address') return json_encode([
                'name' => 'Alex', 'address_1' => '1 St', 'address_2' => '',
                'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97205', 'country' => 'US',
            ]);
            return '[]';
        });

        $invitee = $this->make_user(20, 'guest@example.com');
        Functions\when('get_userdata')->justReturn($invitee);

        // The invitee can view the registry.
        $this->assertTrue($this->controller->can_view_registry(10, 20));

        // The shipping address is readable via get_shipping_address.
        $address = $this->controller->get_shipping_address(10);
        $this->assertIsArray($address);
        $this->assertSame('Portland', $address['city']);
    }
}
