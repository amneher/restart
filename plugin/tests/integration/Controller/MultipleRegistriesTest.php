<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Users may own up to MAX_REGISTRIES_PER_USER active registries; quick-add
 * must be told which one when there's more than one.
 */
class MultipleRegistriesTest extends TestCase {
    use MockeryPHPUnitIntegration;

    private Restart_Registry_Controller $controller;
    /** @var array<int, object> registry posts by ID, as get_post() would return them */
    private array $posts = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/includes/');
        Functions\when('getenv')->justReturn(false);
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_option')->alias(fn($key, $default = '') => $default ?: '');
        Functions\when('get_post')->alias(fn($id) => $this->posts[$id] ?? null);
        Functions\when('user_can')->justReturn(false);

        $this->controller = new Restart_Registry_Controller(new LambdaClientFake());
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Make get_posts() return $n active registries owned by user 7. */
    private function ownRegistries(int $n): void {
        $this->posts = [];
        for ($i = 1; $i <= $n; $i++) {
            $this->posts[100 + $i] = (object) [
                'ID' => 100 + $i, 'post_title' => "Registry $i",
                'post_type' => 'restart-registry', 'post_author' => 7,
            ];
        }
        Functions\when('get_posts')->alias(function (array $args) {
            // _generate_short_code() also queries (by 'name') for slug collisions.
            if (isset($args['name'])) {
                return [];
            }
            $this->assertSame(['publish', 'private', 'draft'], $args['post_status']);
            return array_slice(array_values($this->posts), 0, $args['posts_per_page']);
        });
    }

    // ── create_registry cap ─────────────────────────────────────────────────

    public function test_second_registry_is_allowed(): void {
        $this->ownRegistries(1);
        Functions\expect('wp_insert_post')->once()->andReturn(555);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('sanitize_email')->returnArg(1);

        $result = $this->controller->create_registry(7, 'Second one');

        $this->assertSame(555, $result['id']);
    }

    public function test_eleventh_registry_is_refused(): void {
        $this->ownRegistries(Restart_Registry_Controller::MAX_REGISTRIES_PER_USER);
        Functions\expect('wp_insert_post')->never();

        $result = $this->controller->create_registry(7, 'One too many');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('registry_limit', $result->get_error_code());
        $this->assertStringContainsString('10', $result->get_error_message());
    }

    public function test_get_user_registries_shape_and_guest(): void {
        $this->ownRegistries(2);

        $this->assertSame(
            [['id' => 101, 'title' => 'Registry 1'], ['id' => 102, 'title' => 'Registry 2']],
            $this->controller->get_user_registries(7)
        );
        $this->assertSame([], $this->controller->get_user_registries(0));
    }

    // ── resolve_target_registry ─────────────────────────────────────────────

    public function test_single_registry_used_without_asking(): void {
        $this->ownRegistries(1);
        $this->assertSame(101, $this->controller->resolve_target_registry(7));
    }

    public function test_several_registries_require_a_choice(): void {
        $this->ownRegistries(3);
        $result = $this->controller->resolve_target_registry(7);
        $this->assertSame('choose_registry', $result->get_error_code());
    }

    public function test_explicit_choice_is_used_when_owned(): void {
        $this->ownRegistries(3);
        $this->assertSame(102, $this->controller->resolve_target_registry(7, 102));
    }

    public function test_explicit_choice_rejected_when_not_owned(): void {
        $this->ownRegistries(3);
        $this->posts[900] = (object) ['ID' => 900, 'post_title' => 'Someone else', 'post_type' => 'restart-registry', 'post_author' => 8];

        $result = $this->controller->resolve_target_registry(7, 900);

        $this->assertSame('forbidden', $result->get_error_code());
    }

    public function test_no_registry(): void {
        $this->ownRegistries(0);
        $result = $this->controller->resolve_target_registry(7);
        $this->assertSame('no_registry', $result->get_error_code());
    }
}
