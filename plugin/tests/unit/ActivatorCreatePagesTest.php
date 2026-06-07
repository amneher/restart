<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Restart_Registry_Activator::create_pages().
 *
 * Covers:
 *  - All expected pages are created with the right slugs, titles, and templates
 *  - Pages with copy files receive the correct HTML content on creation
 *  - Pages without copy files are created with empty content
 *  - Existing pages are not re-inserted (idempotency)
 *  - Existing empty pages get content backfilled; pages with content are not overwritten
 *  - Draft pages are published
 *  - WordPress options are set correctly (front page, registration, registry page ID)
 *  - Every slug referenced in header.html / footer.html maps to a created page
 */
class ActivatorCreatePagesTest extends TestCase
{

    /**
     * Slugs → ['id', 'data'] for every wp_insert_post call during a run 
     */
    private array $inserted = [];

    /**
     * Each array passed to wp_update_post during a run 
     */
    private array $updated = [];

    /**
     * Each ['id', 'key', 'val'] passed to update_post_meta during a run 
     */
    private array $meta = [];

    /**
     * option_name → value for every update_option call during a run 
     */
    private array $options = [];

    private int $nextId = 100;

    // ── Test lifecycle ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->inserted = [];
        $this->updated  = [];
        $this->meta     = [];
        $this->options  = [];
        $this->nextId   = 100;

        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_template_directory')->justReturn(
            dirname(__DIR__, 3) . '/theme'
        );
        Functions\when('update_post_meta')->alias(
            function (int $id, string $key, mixed $val): bool {
                $this->meta[] = compact('id', 'key', 'val');
                return true;
            }
        );
        Functions\when('update_option')->alias(
            function (string $key, mixed $val): bool {
                $this->options[$key] = $val;
                return true;
            }
        );
        Functions\when('wp_update_post')->alias(
            function (array $data): int {
                $this->updated[] = $data;
                return $data['ID'];
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function nextId(): int
    {
        return $this->nextId++;
    }

    /**
     * Stub get_posts and wp_insert_post, then run create_pages().
     * Pages whose slug appears as a key in $existing_by_slug are returned as
     * pre-existing; all others return [].
     *
     * @param WP_Post[] $existing Pre-existing pages to simulate.
     */
    private function runCreatePages(array $existing = []): void
    {
        $by_slug = [];
        foreach ($existing as $post) {
            $by_slug[$post->post_name] = $post;
        }

        Functions\when('get_posts')->alias(
            function (array $args) use ($by_slug) {
                $slug = $args['name'] ?? '';
                return isset($by_slug[$slug]) ? [$by_slug[$slug]] : [];
            }
        );

        Functions\when('wp_insert_post')->alias(
            function (array $data): int {
                $id = $this->nextId();
                $this->inserted[$data['post_name']] = ['id' => $id, 'data' => $data];
                return $id;
            }
        );

        Restart_Registry_Activator::create_pages();
    }

    private function make_post(string $slug, string $content = '', string $status = 'publish'): WP_Post
    {
        $post               = new WP_Post();
        $post->ID           = $this->nextId();
        $post->post_name    = $slug;
        $post->post_content = $content;
        $post->post_status  = $status;
        return $post;
    }

    // ── Pages created ─────────────────────────────────────────────────────────

    public function test_creates_exactly_the_expected_set_of_pages(): void
    {
        $this->runCreatePages();

        $expected = [
            'home', 'login', 'register', 'my-account', 'my-registries',
            'start-a-registry', 'find-a-registry', 'faq', 'about-us',
            'terms-and-conditions', 'privacy-policy',
        ];

        sort($expected);
        $actual = array_keys($this->inserted);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_all_new_pages_are_published(): void
    {
        $this->runCreatePages();

        foreach ($this->inserted as $slug => $entry) {
            $this->assertSame(
                'publish',
                $entry['data']['post_status'],
                "Page '{$slug}' should be created with status 'publish'"
            );
        }
    }

    // ── Page templates ────────────────────────────────────────────────────────

    public function test_templated_pages_have_correct_page_template_meta(): void
    {
        $this->runCreatePages();

        $expected_templates = [
            'login'            => 'page-login',
            'register'         => 'page-register',
            'my-account'       => 'page-my-account',
            'my-registries'    => 'page-my-registries',
            'start-a-registry' => 'page-start-a-registry',
            'faq'              => 'page-faq',
            'about-us'         => 'page-about-us',
        ];

        foreach ($expected_templates as $slug => $template) {
            $page_id = $this->inserted[$slug]['id'];
            $found   = false;
            foreach ($this->meta as $m) {
                if ($m['id'] === $page_id && $m['key'] === '_wp_page_template' && $m['val'] === $template) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Page '{$slug}' should have _wp_page_template = '{$template}'");
        }
    }

    public function test_pages_without_template_do_not_get_template_meta(): void
    {
        $this->runCreatePages();

        $no_template = ['home', 'find-a-registry', 'terms-and-conditions', 'privacy-policy'];

        foreach ($no_template as $slug) {
            $page_id = $this->inserted[$slug]['id'];
            foreach ($this->meta as $m) {
                if ($m['id'] === $page_id && $m['key'] === '_wp_page_template') {
                    $this->fail("Page '{$slug}' should not have _wp_page_template set, but it does ('{$m['val']}')");
                }
            }
        }

        $this->addToAssertionCount(count($no_template)); // confirm each slug was checked
    }

    // ── Copy content ──────────────────────────────────────────────────────────

    public function test_copy_pages_are_inserted_with_html_content(): void
    {
        $this->runCreatePages();

        foreach (['faq', 'about-us', 'terms-and-conditions', 'privacy-policy'] as $slug) {
            $content = $this->inserted[$slug]['data']['post_content'] ?? '';
            $this->assertNotEmpty($content, "Page '{$slug}' should receive content from its copy file");
            $this->assertStringContainsString('<', $content, "Page '{$slug}' content should be HTML");
        }
    }

    public function test_copy_content_matches_theme_copy_files_exactly(): void
    {
        $this->runCreatePages();

        $theme = dirname(__DIR__, 3) . '/theme';
        foreach (['faq', 'about-us', 'terms-and-conditions', 'privacy-policy'] as $slug) {
            $expected = file_get_contents("{$theme}/assets/copy/{$slug}.html");
            $actual   = $this->inserted[$slug]['data']['post_content'] ?? '';
            $this->assertSame($expected, $actual, "Content of page '{$slug}' must match theme/assets/copy/{$slug}.html exactly");
        }
    }

    public function test_non_copy_pages_are_created_with_empty_content(): void
    {
        $this->runCreatePages();

        $no_copy = ['home', 'login', 'register', 'my-account', 'my-registries', 'start-a-registry', 'find-a-registry'];
        foreach ($no_copy as $slug) {
            $content = $this->inserted[$slug]['data']['post_content'] ?? '';
            $this->assertSame('', $content, "Page '{$slug}' should have no content on creation");
        }
    }

    // ── WordPress options ─────────────────────────────────────────────────────

    public function test_sets_home_page_as_site_front_page(): void
    {
        $this->runCreatePages();

        $this->assertSame('page', $this->options['show_on_front'] ?? null);
        $this->assertSame(
            $this->inserted['home']['id'],
            $this->options['page_on_front'] ?? null
        );
    }

    public function test_sets_restart_registry_page_id_to_my_registries(): void
    {
        $this->runCreatePages();

        $this->assertSame(
            $this->inserted['my-registries']['id'],
            $this->options['restart_registry_page_id'] ?? null
        );
    }

    public function test_enables_user_registration(): void
    {
        $this->runCreatePages();

        $this->assertSame(1, $this->options['users_can_register'] ?? null);
    }

    public function test_sets_default_role_to_subscriber(): void
    {
        $this->runCreatePages();

        $this->assertSame('registry-user', $this->options['default_role'] ?? null);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_existing_published_page_with_content_is_not_re_inserted(): void
    {
        $existing = $this->make_post('faq', '<p>existing</p>', 'publish');
        $this->runCreatePages([$existing]);

        $this->assertArrayNotHasKey('faq', $this->inserted);
    }

    public function test_existing_published_page_with_content_is_not_updated(): void
    {
        $existing = $this->make_post('about-us', '<p>Custom editorial copy.</p>', 'publish');
        $this->runCreatePages([$existing]);

        foreach ($this->updated as $update) {
            if (($update['ID'] ?? null) === $existing->ID) {
                $this->fail("Existing page 'about-us' with content should not be updated at all");
            }
        }

        $this->addToAssertionCount(1);
    }

    // ── Content backfill ──────────────────────────────────────────────────────

    public function test_empty_existing_copy_page_gets_content_backfilled(): void
    {
        $existing = $this->make_post('faq', '', 'publish');
        $this->runCreatePages([$existing]);

        $update = null;
        foreach ($this->updated as $u) {
            if (($u['ID'] ?? null) === $existing->ID && isset($u['post_content'])) {
                $update = $u;
                break;
            }
        }

        $this->assertNotNull($update, "Empty 'faq' page should have content backfilled via wp_update_post");
        $this->assertNotEmpty($update['post_content']);
    }

    public function test_backfilled_content_matches_copy_file(): void
    {
        $existing = $this->make_post('faq', '', 'publish');
        $this->runCreatePages([$existing]);

        $update = null;
        foreach ($this->updated as $u) {
            if (($u['ID'] ?? null) === $existing->ID && isset($u['post_content'])) {
                $update = $u;
                break;
            }
        }

        $expected = file_get_contents(dirname(__DIR__, 3) . '/theme/assets/copy/faq.html');
        $this->assertSame($expected, $update['post_content'] ?? null);
    }

    public function test_existing_page_with_content_is_not_overwritten_even_if_copy_exists(): void
    {
        $custom   = '<p>Hand-written FAQ content that must not be lost.</p>';
        $existing = $this->make_post('faq', $custom, 'publish');
        $this->runCreatePages([$existing]);

        foreach ($this->updated as $update) {
            if (($update['ID'] ?? null) === $existing->ID) {
                $this->assertArrayNotHasKey(
                    'post_content',
                    $update,
                    "wp_update_post should not include post_content when page already has content"
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    // ── Draft publishing ──────────────────────────────────────────────────────

    public function test_existing_draft_page_is_published(): void
    {
        $draft = $this->make_post('privacy-policy', '', 'draft');
        $this->runCreatePages([$draft]);

        $update = null;
        foreach ($this->updated as $u) {
            if (($u['ID'] ?? null) === $draft->ID) {
                $update = $u;
                break;
            }
        }

        $this->assertNotNull($update, "Draft 'privacy-policy' page should be updated to publish");
        $this->assertSame('publish', $update['post_status'] ?? null);
    }

    public function test_existing_draft_is_not_re_inserted(): void
    {
        $draft = $this->make_post('privacy-policy', '', 'draft');
        $this->runCreatePages([$draft]);

        $this->assertArrayNotHasKey('privacy-policy', $this->inserted);
    }

    // ── Nav slug alignment ────────────────────────────────────────────────────

    /**
     * Every slug linked in header.html and footer.html that maps to a WordPress
     * page (not a CPT archive or category) must be created by the activator.
     *
     * Note: /registry/ (Find a Registry) is the restart-registry CPT archive URL,
     * not a page, so it is intentionally excluded from this list.
     */
    public function test_all_nav_page_slugs_are_created(): void
    {
        $this->runCreatePages();

        // Collected from theme/parts/header.html and theme/parts/footer.html
        $nav_page_slugs = [
            'my-account'           => 'header + footer Account column',
            'start-a-registry'     => 'footer Account column',
            'about-us'             => 'footer Explore column',
            'faq'                  => 'footer Explore column',
            'terms-and-conditions' => 'footer legal bar',
            'privacy-policy'       => 'footer legal bar',
        ];

        foreach ($nav_page_slugs as $slug => $location) {
            $this->assertArrayHasKey(
                $slug,
                $this->inserted,
                "Slug '{$slug}' (linked in {$location}) must be created by the activator"
            );
        }
    }
}
