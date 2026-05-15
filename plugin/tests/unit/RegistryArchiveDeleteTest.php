<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for registry archive, restore, delete, and archived-URL detection.
 */
class RegistryArchiveDeleteTest extends TestCase {

    private LambdaClientFake $fake;
    private Restart_Registry_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 2) . '/includes/');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_post_meta')->justReturn('[]');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_option')->justReturn('');

        $this->fake       = new LambdaClientFake();
        $this->controller = new Restart_Registry_Controller($this->fake);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_post(int $id, string $status = 'private'): WP_Post {
        $post              = new WP_Post();
        $post->ID          = $id;
        $post->post_type   = 'restart-registry';
        $post->post_status = $status;
        $post->post_author = 5;
        $post->post_title  = 'My Registry';
        $post->post_content = '';
        $post->post_name   = 'ab3k9z';
        return $post;
    }

    // ── archive_registry ──────────────────────────────────────────────────────

    public function test_archive_sets_post_status(): void {
        Functions\when('get_post')->justReturn($this->make_post(42));
        Functions\expect('wp_update_post')
            ->once()
            ->andReturnUsing(function($data) {
                $this->assertSame(42, $data['ID']);
                $this->assertSame('restart-archived', $data['post_status']);
                return 42;
            });

        $result = $this->controller->archive_registry(42);
        $this->assertTrue($result);
    }

    public function test_archive_returns_false_for_unknown_post(): void {
        Functions\when('get_post')->justReturn(null);
        $result = $this->controller->archive_registry(999);
        $this->assertFalse($result);
    }

    public function test_archive_returns_false_for_wrong_post_type(): void {
        $post            = $this->make_post(42);
        $post->post_type = 'page';
        Functions\when('get_post')->justReturn($post);
        $result = $this->controller->archive_registry(42);
        $this->assertFalse($result);
    }

    // ── restore_registry ──────────────────────────────────────────────────────

    public function test_restore_sets_status_to_private(): void {
        Functions\when('get_post')->justReturn($this->make_post(42, 'restart-archived'));
        Functions\expect('wp_update_post')
            ->once()
            ->andReturnUsing(function($data) {
                $this->assertSame(42, $data['ID']);
                $this->assertSame('private', $data['post_status']);
                return 42;
            });

        $result = $this->controller->restore_registry(42);
        $this->assertTrue($result);
    }

    public function test_restore_returns_false_when_not_archived(): void {
        Functions\when('get_post')->justReturn($this->make_post(42, 'private'));
        $result = $this->controller->restore_registry(42);
        $this->assertFalse($result);
    }

    public function test_restore_returns_false_for_unknown_post(): void {
        Functions\when('get_post')->justReturn(null);
        $result = $this->controller->restore_registry(999);
        $this->assertFalse($result);
    }

    // ── delete_registry ───────────────────────────────────────────────────────

    public function test_delete_removes_lambda_items_and_post(): void {
        Functions\when('get_post_meta')->alias(fn($id, $key) =>
            $key === 'restart_item_ids' ? '[10, 11]' : '[]'
        );
        Functions\when('wp_delete_post')->justReturn(new WP_Post());

        $this->fake->setItem(10, ['id' => 10, 'registry_id' => 42, 'name' => 'Knife',
            'quantity_needed' => 1, 'quantity_purchased' => 0]);
        $this->fake->setItem(11, ['id' => 11, 'registry_id' => 42, 'name' => 'Pan',
            'quantity_needed' => 1, 'quantity_purchased' => 0]);

        $result = $this->controller->delete_registry(42);

        $deleteCalls = array_filter($this->fake->getCalls(), fn($c) => $c['method'] === 'delete_item');
        $this->assertCount(2, $deleteCalls);
        $this->assertTrue($result);
    }

    // ── get_registry_by_share_key — archived detection ────────────────────────

    public function test_share_key_returns_archived_error_for_archived_registry(): void {
        // First query (publish/private) returns empty; second (archived) finds one.
        $archived_post = $this->make_post(42, 'restart-archived');
        $call_count    = 0;
        Functions\when('get_posts')->alias(function($args) use ($archived_post, &$call_count) {
            $call_count++;
            $statuses = (array) ($args['post_status'] ?? []);
            if (in_array('restart-archived', $statuses, true)) {
                return [$archived_post];
            }
            return [];
        });

        $result = $this->controller->get_registry_by_share_key('ab3k9z');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('registry_archived', $result->get_error_code());
    }

    public function test_share_key_returns_not_found_when_truly_missing(): void {
        Functions\when('get_posts')->justReturn([]);

        $result = $this->controller->get_registry_by_share_key('xxxxxx');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }
}
