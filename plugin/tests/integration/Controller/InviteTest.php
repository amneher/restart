<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class InviteTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/includes/');
        Functions\when('get_option')->justReturn('');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('home_url')->justReturn('http://example.com/');
        Functions\when('get_permalink')->justReturn('http://example.com/registry/42/');
        Functions\when('get_bloginfo')->returnArg(1);

        $author               = new WP_User();
        $author->ID           = 10;
        $author->display_name = 'Alex';
        Functions\when('get_userdata')->alias(fn($uid) => $uid === 10 ? $author : null);

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

    public function test_adds_invitee_to_meta(): void
    {
        Functions\when('get_post_meta')->justReturn('[]');
        Functions\when('is_email')->justReturn(true);

        $captured = null;
        Functions\expect('update_post_meta')->once()->andReturnUsing(
            function ($pid, $key, $value) use (&$captured) {
                $captured = $value;
                return true;
            }
        );
        Functions\when('wp_mail')->justReturn(true);

        $result = $this->controller->send_invite(42, 'friend@example.com');

        $this->assertSame(['invite_id' => 0], $result);
        $this->assertSame(['friend@example.com'], json_decode($captured, true));
    }

    public function test_returns_error_for_duplicate_invitee(): void
    {
        Functions\when('get_post_meta')->justReturn(json_encode(['friend@example.com']));

        $result = $this->controller->send_invite(42, 'friend@example.com');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('already_invited', $result->get_error_code());
    }

    public function test_sends_invite_email_to_email_address(): void
    {
        Functions\when('get_post_meta')->justReturn('[]');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('is_email')->alias(fn($v) => $v === 'friend@example.com');
        Functions\expect('wp_mail')->once()->andReturn(true);

        $this->controller->send_invite(42, 'friend@example.com');
    }

    public function test_does_not_send_email_for_username_invitee(): void
    {
        Functions\when('get_post_meta')->justReturn('[]');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('is_email')->alias(fn($v) => false);
        Functions\expect('wp_mail')->never();

        $this->controller->send_invite(42, 'username');
    }

    public function test_get_registry_invites_returns_indexed_list(): void
    {
        Functions\when('get_post_meta')->justReturn(json_encode(['a@b.com', 'user1']));

        $result = $this->controller->get_registry_invites(42);

        $this->assertSame(
            [['id' => 0, 'email' => 'a@b.com'], ['id' => 1, 'email' => 'user1']],
            $result
        );
    }
}
