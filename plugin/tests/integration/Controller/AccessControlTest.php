<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AccessControlTest extends TestCase {
    use MockeryPHPUnitIntegration;

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

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makePost(int $id, string $status, int $author = 5, string $type = 'restart-registry'): WP_Post {
        $post              = new WP_Post();
        $post->ID          = $id;
        $post->post_status = $status;
        $post->post_author = $author;
        $post->post_type   = $type;
        return $post;
    }

    private function makeUser(int $id, string $email = '', string $login = ''): WP_User {
        $user             = new WP_User();
        $user->ID         = $id;
        $user->user_email = $email;
        $user->user_login = $login;
        return $user;
    }

    // ── can_view_registry ────────────────────────────────────────────────────

    public function test_returns_false_for_missing_post(): void {
        Functions\when('get_post')->justReturn(null);
        $this->assertFalse($this->controller->can_view_registry(1));
    }

    public function test_returns_false_for_wrong_post_type(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'publish', 5, 'page'));
        $this->assertFalse($this->controller->can_view_registry(1));
    }

    public function test_returns_true_for_public_registry(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'publish'));
        $this->assertTrue($this->controller->can_view_registry(1, null));
    }

    public function test_returns_false_for_private_registry_without_user(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private'));
        $this->assertFalse($this->controller->can_view_registry(1, null));
    }

    public function test_returns_true_for_owner_of_private_registry(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->justReturn(false);
        $this->assertTrue($this->controller->can_view_registry(1, 5));
    }

    public function test_returns_true_for_admin(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->alias(fn($uid, $cap) => $uid === 3 && $cap === 'manage_restart_registry');
        $this->assertTrue($this->controller->can_view_registry(1, 3));
    }

    public function test_returns_true_for_invitee_by_email(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->justReturn(false);
        Functions\when('get_post_meta')->alias(fn($id, $key) =>
            $key === 'restart_invitees' ? json_encode(['invited@example.com']) : ''
        );
        Functions\when('get_userdata')->alias(fn($uid) =>
            $uid === 7 ? $this->makeUser(7, 'invited@example.com', 'someuser') : null
        );
        $this->assertTrue($this->controller->can_view_registry(1, 7));
    }

    public function test_returns_true_for_invitee_by_username(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->justReturn(false);
        Functions\when('get_post_meta')->alias(fn($id, $key) =>
            $key === 'restart_invitees' ? json_encode(['inviteduser']) : ''
        );
        Functions\when('get_userdata')->alias(fn($uid) =>
            $uid === 7 ? $this->makeUser(7, 'other@example.com', 'inviteduser') : null
        );
        $this->assertTrue($this->controller->can_view_registry(1, 7));
    }

    public function test_returns_false_for_non_invitee(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->justReturn(false);
        Functions\when('get_post_meta')->alias(fn($id, $key) =>
            $key === 'restart_invitees' ? json_encode(['someone-else@example.com']) : ''
        );
        Functions\when('get_userdata')->alias(fn($uid) =>
            $this->makeUser($uid, 'stranger@example.com', 'stranger')
        );
        $this->assertFalse($this->controller->can_view_registry(1, 9));
    }

    // ── can_edit_registry ────────────────────────────────────────────────────

    public function test_edit_returns_false_for_missing_post(): void {
        Functions\when('get_post')->justReturn(null);
        $this->assertFalse($this->controller->can_edit_registry(1, 5));
    }

    public function test_edit_returns_true_for_owner(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->justReturn(false);
        $this->assertTrue($this->controller->can_edit_registry(1, 5));
    }

    public function test_edit_returns_false_for_non_owner(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->justReturn(false);
        $this->assertFalse($this->controller->can_edit_registry(1, 9));
    }

    public function test_edit_returns_true_for_admin(): void {
        Functions\when('get_post')->justReturn($this->makePost(1, 'private', 5));
        Functions\when('user_can')->alias(fn($uid, $cap) => $uid === 3 && $cap === 'manage_restart_registry');
        $this->assertTrue($this->controller->can_edit_registry(1, 3));
    }
}
