<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class UpdatePurchaseMessageTest extends TestCase {
    use MockeryPHPUnitIntegration;

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;
    private int $futureExpiry;
    private int $pastExpiry;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/includes/');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_post_meta')->justReturn('[]');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_generate_password')->justReturn('token_abc');
        Functions\when('sanitize_textarea_field')->returnArg(1);

        $this->futureExpiry = time() + 600;
        $this->pastExpiry   = time() - 1;

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stored(array $overrides = []): string {
        return json_encode([array_merge([
            'id'                 => 'msg_test',
            'item_id'            => 1,
            'item_name'          => 'Coffee Maker',
            'item_image_url'     => '',
            'item_description'   => '',
            'purchaser_name'     => 'Jordan',
            'purchaser_note'     => 'Original note.',
            'timestamp'          => 1715000000,
            'edit_token'         => 'valid_token',
            'edit_token_expires' => $this->futureExpiry,
            'email_status'       => 'scheduled',
        ], $overrides)]);
    }

    public function test_update_with_valid_token_saves_new_note(): void {
        $saved = null;
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $this->stored() : '');
        Functions\when('update_post_meta')->alias(function($id, $key, $val) use (&$saved) {
            if ($key === 'restart_purchase_messages') $saved = json_decode($val, true);
            return true;
        });

        $ok = $this->controller->update_purchase_message(42, 'msg_test', 'Updated note!', 'valid_token');

        $this->assertTrue($ok);
        $this->assertSame('Updated note!', $saved[0]['purchaser_note']);
    }

    public function test_update_with_wrong_token_returns_false(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $this->stored() : '');
        Functions\expect('update_post_meta')->never();

        $ok = $this->controller->update_purchase_message(42, 'msg_test', 'Hacked note.', 'wrong_token');

        $this->assertFalse($ok);
    }

    public function test_update_with_expired_token_returns_false(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages'
            ? $this->stored(['edit_token_expires' => $this->pastExpiry]) : '');
        Functions\expect('update_post_meta')->never();

        $ok = $this->controller->update_purchase_message(42, 'msg_test', 'Too late.', 'valid_token');

        $this->assertFalse($ok);
    }

    public function test_update_with_no_token_owner_path_saves_note(): void {
        $saved = null;
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $this->stored() : '');
        Functions\when('update_post_meta')->alias(function($id, $key, $val) use (&$saved) {
            if ($key === 'restart_purchase_messages') $saved = json_decode($val, true);
            return true;
        });

        // Passing null for token = owner auth (caller verified ownership before this call)
        $ok = $this->controller->update_purchase_message(42, 'msg_test', 'Owner edit.', null);

        $this->assertTrue($ok);
        $this->assertSame('Owner edit.', $saved[0]['purchaser_note']);
    }

    public function test_update_reschedules_email_when_still_scheduled(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $this->stored() : '');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\expect('wp_clear_scheduled_hook')->once()->with('restart_registry_send_purchase_notification', [42, 'msg_test']);
        Functions\expect('wp_schedule_single_event')->once()->andReturn(true);

        $this->controller->update_purchase_message(42, 'msg_test', 'Edited.', 'valid_token');
    }

    public function test_update_does_not_reschedule_when_email_already_sent(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages'
            ? $this->stored(['email_status' => 'sent']) : '');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\expect('wp_clear_scheduled_hook')->never();
        Functions\expect('wp_schedule_single_event')->never();

        $ok = $this->controller->update_purchase_message(42, 'msg_test', 'After send.', null);

        $this->assertTrue($ok);
    }

    public function test_update_returns_false_for_unknown_message_id(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $this->stored() : '');
        Functions\expect('update_post_meta')->never();

        $ok = $this->controller->update_purchase_message(42, 'msg_nonexistent', 'Nope.', null);

        $this->assertFalse($ok);
    }

    public function test_get_purchase_messages_strips_edit_token(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $this->stored() : '');

        $messages = $this->controller->get_purchase_messages(42);

        $this->assertCount(1, $messages);
        $this->assertArrayNotHasKey('edit_token', $messages[0]);
        $this->assertArrayNotHasKey('edit_token_expires', $messages[0]);
        $this->assertArrayHasKey('purchaser_note', $messages[0]);
        $this->assertArrayHasKey('email_status', $messages[0]);
    }
}
