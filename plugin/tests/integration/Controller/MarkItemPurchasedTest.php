<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class MarkItemPurchasedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/includes/');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_bloginfo')->returnArg(1);
        Functions\when('home_url')->justReturn('http://example.com/');
        Functions\when('get_permalink')->justReturn('http://example.com/registry/42/');
        Functions\when('get_option')->alias(
            fn($key, $default = '') => match ($key) {
            'restart_registry_email_from' => 'hello@example.com',
            'restart_registry_email_name' => 'Restart',
            default                       => $default ?: '',
            }
        );
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_post_meta')->justReturn('[]');
        Functions\when('update_post_meta')->justReturn(true);

        $owner               = new WP_User();
        $owner->ID           = 10;
        $owner->user_email   = 'owner@example.com';
        $owner->display_name = 'Alex';
        Functions\when('get_userdata')->alias(fn($uid) => $uid === 10 ? $owner : null);

        $post              = new WP_Post();
        $post->ID          = 42;
        $post->post_author = 10;
        $post->post_title  = 'My Registry';
        $post->post_type   = 'restart-registry';
        $post->post_status = 'private';
        Functions\when('get_post')->alias(fn($id) => $id === 42 ? $post : null);

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function fixture(array $overrides = []): array
    {
        return array_merge(
            [
            'id'                 => 1,
            'name'               => 'Coffee Maker',
            'registry_id'        => 42,
            'quantity_needed'    => 2,
            'quantity_purchased' => 0,
            ], $overrides
        );
    }

    public function test_returns_error_when_item_not_found(): void
    {
        $result = $this->controller->mark_item_purchased(999, 1);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function test_returns_error_when_lambda_get_item_errors(): void
    {
        $this->fake->setError(new WP_Error('lambda_error', 'boom'));
        $result = $this->controller->mark_item_purchased(1, 1);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_returns_error_when_quantity_exceeds_remaining(): void
    {
        $this->fake->setItem(1, $this->fixture(['quantity_needed' => 1, 'quantity_purchased' => 0]));
        $result = $this->controller->mark_item_purchased(1, 2);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('quantity_exceeded', $result->get_error_code());
    }

    public function test_successful_purchase_sends_email(): void
    {
        $this->fake->setItem(1, $this->fixture());
        Functions\expect('wp_mail')->once()->andReturn(true);

        $this->controller->mark_item_purchased(1, 1, 'Jordan');

        $updateCalls = array_filter(
            $this->fake->getCalls(),
            fn($c) => $c['method'] === 'update_item'
        );
        $this->assertCount(1, $updateCalls);
        $update = array_values($updateCalls)[0];
        $this->assertSame(1, $update['args'][0]);
        $this->assertSame(['quantity_purchased' => 1], $update['args'][1]);
    }

    public function test_email_subject_includes_purchaser_name(): void
    {
        $this->fake->setItem(1, $this->fixture());
        $captured = '';
        Functions\expect('wp_mail')->once()->andReturnUsing(
            function ($to, $subject, $body, $headers) use (&$captured) {
                $captured = $subject;
                return true;
            }
        );

        $this->controller->mark_item_purchased(1, 1, 'Jordan');

        $this->assertStringContainsString('Jordan', $captured);
    }

    public function test_email_subject_uses_someone_for_anonymous(): void
    {
        $this->fake->setItem(1, $this->fixture());
        $captured = '';
        Functions\expect('wp_mail')->once()->andReturnUsing(
            function ($to, $subject, $body, $headers) use (&$captured) {
                $captured = $subject;
                return true;
            }
        );

        $this->controller->mark_item_purchased(1, 1, '');

        $this->assertStringContainsString('Someone', $captured);
    }

    public function test_email_body_includes_purchaser_note(): void
    {
        $this->fake->setItem(1, $this->fixture());
        $captured = '';
        Functions\expect('wp_mail')->once()->andReturnUsing(
            function ($to, $subject, $body, $headers) use (&$captured) {
                $captured = $body;
                return true;
            }
        );

        $this->controller->mark_item_purchased(1, 1, 'Jordan', '', 'Thinking of you!');

        $this->assertStringContainsString('Thinking of you!', $captured);
    }

    public function test_notification_skipped_when_opted_out(): void
    {
        $this->fake->setItem(1, $this->fixture());
        Functions\when('get_user_meta')->alias(
            fn($uid, $key) =>
            $key === 'restart_notify_on_purchase' ? '0' : ''
        );
        Functions\expect('wp_mail')->never();

        $result = $this->controller->mark_item_purchased(1, 1, 'Jordan');

        $this->assertNotInstanceOf(WP_Error::class, $result);
    }

    public function test_purchase_message_stored_when_note_provided(): void
    {
        $item = $this->fixture(['name' => 'Coffee Maker', 'image_url' => 'https://img.example.com/coffee.jpg', 'description' => 'Great coffee']);
        $this->fake->setItem(1, $item);
        Functions\when('wp_mail')->justReturn(true);

        $stored = null;
        Functions\when('update_post_meta')->alias(
            function ($id, $key, $val) use (&$stored) {
                if ($key === 'restart_purchase_messages') {
                    $stored = json_decode($val, true);
                }
                return true;
            }
        );

        $this->controller->mark_item_purchased(1, 1, 'Jordan', '', 'Thinking of you!');

        $this->assertNotNull($stored, 'restart_purchase_messages should have been saved');
        $this->assertCount(1, $stored);
        $this->assertSame('Jordan', $stored[0]['purchaser_name']);
        $this->assertSame('Thinking of you!', $stored[0]['purchaser_note']);
        $this->assertSame('Coffee Maker', $stored[0]['item_name']);
        $this->assertSame(1, $stored[0]['item_id']);
        $this->assertArrayHasKey('timestamp', $stored[0]);
    }

    public function test_purchase_message_not_stored_when_note_empty(): void
    {
        $this->fake->setItem(1, $this->fixture());
        Functions\when('wp_mail')->justReturn(true);

        $messageMetaSaved = false;
        Functions\when('update_post_meta')->alias(
            function ($id, $key, $val) use (&$messageMetaSaved) {
                if ($key === 'restart_purchase_messages') {
                    $messageMetaSaved = true;
                }
                return true;
            }
        );

        $this->controller->mark_item_purchased(1, 1, 'Jordan', '', '');

        $this->assertFalse($messageMetaSaved, 'No message record should be stored for an empty note');
    }

    public function test_purchase_message_anonymous_suppresses_name(): void
    {
        $this->fake->setItem(1, $this->fixture());
        Functions\when('wp_mail')->justReturn(true);

        $stored = null;
        Functions\when('update_post_meta')->alias(
            function ($id, $key, $val) use (&$stored) {
                if ($key === 'restart_purchase_messages') {
                    $stored = json_decode($val, true);
                }
                return true;
            }
        );

        $this->controller->mark_item_purchased(1, 1, 'Jordan', '', 'Great choice!', true);

        $this->assertNotNull($stored);
        $this->assertSame('', $stored[0]['purchaser_name'], 'Anonymous purchase should store empty name');
    }

    public function test_message_persisted_even_when_lambda_update_fails(): void
    {
        $item = $this->fixture(['name' => 'Coffee Maker']);
        $this->fake->setItem(1, $item);
        $this->fake->setUpdateError(new WP_Error('lambda_auth', 'auth failed'));
        Functions\expect('wp_mail')->never();

        $stored = null;
        Functions\when('update_post_meta')->alias(
            function ($id, $key, $val) use (&$stored) {
                if ($key === 'restart_purchase_messages') {
                    $stored = json_decode($val, true);
                }
                return true;
            }
        );

        $result = $this->controller->mark_item_purchased(1, 1, 'Jordan', '', 'Thinking of you!');

        $this->assertInstanceOf(WP_Error::class, $result, 'Lambda failure should still propagate');
        $this->assertNotNull($stored, 'Message should be persisted despite Lambda failure');
        $this->assertSame('Thinking of you!', $stored[0]['purchaser_note']);
    }

    public function test_get_purchase_messages_returns_newest_first(): void
    {
        $data = json_encode(
            [
            ['item_id' => 1, 'item_name' => 'Knife', 'item_image_url' => '', 'item_description' => '',
             'purchaser_name' => 'Alice', 'purchaser_note' => 'First!', 'timestamp' => 1000],
            ['item_id' => 2, 'item_name' => 'Pan', 'item_image_url' => '', 'item_description' => '',
             'purchaser_name' => 'Bob', 'purchaser_note' => 'Second!', 'timestamp' => 2000],
            ]
        );
        Functions\when('get_post_meta')->alias(fn($id, $key) => $key === 'restart_purchase_messages' ? $data : '');

        $messages = $this->controller->get_purchase_messages(42);

        $this->assertCount(2, $messages);
        $this->assertSame('Bob', $messages[0]['purchaser_name'], 'Newest (timestamp 2000) should be first');
        $this->assertSame('Alice', $messages[1]['purchaser_name']);
    }
}
