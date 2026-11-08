<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Filters;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;
use WP_Block;

/**
 * Search returns posts + content pages only: never registries, never
 * account/registry-flow pages. Covers both the main query (pre_get_posts)
 * and the query loop block's own query (query_loop_block_query_vars).
 */
final class SearchScopeTest extends ThemeTestCase
{
    /** Minimal WP_Query stand-in that records set() calls. */
    private function fakeQuery(bool $main = true, bool $search = true): object
    {
        return new class($main, $search) {
            public array $vars = [];
            public function __construct(private bool $main, private bool $search) {}
            public function is_main_query(): bool { return $this->main; }
            public function is_search(): bool { return $this->search; }
            public function set(string $k, $v): void { $this->vars[$k] = $v; }
        };
    }

    private function runPreGetPosts(object $query): void
    {
        foreach (self::$actions['pre_get_posts'] ?? [] as $cb) {
            $cb($query);
        }
    }

    public function test_main_search_query_limited_to_posts_and_pages(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('get_posts')->once()->andReturnUsing(function (array $args) {
            $this->assertSame('page', $args['post_type']);
            $this->assertContains('my-account', $args['post_name__in']);
            $this->assertContains('find-a-registry', $args['post_name__in']);
            $this->assertNotContains('faq', $args['post_name__in']);
            return [11, '12'];
        });

        $q = $this->fakeQuery();
        // Simulate ?post_type=restart-registry in the URL: must be overridden.
        $q->vars['post_type'] = 'restart-registry';
        $this->runPreGetPosts($q);

        $this->assertSame(['post', 'page'], $q->vars['post_type']);
        $this->assertSame([11, 12], $q->vars['post__not_in']);
    }

    public function test_non_search_and_secondary_queries_untouched(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('get_posts')->never();

        foreach ([$this->fakeQuery(true, false), $this->fakeQuery(false, true)] as $q) {
            $this->runPreGetPosts($q);
            $this->assertSame([], $q->vars);
        }
    }

    public function test_admin_search_untouched(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\expect('get_posts')->never();

        $q = $this->fakeQuery();
        $this->runPreGetPosts($q);
        $this->assertSame([], $q->vars);
    }

    public function test_query_loop_gets_term_and_scope_on_search_page(): void
    {
        Functions\when('is_search')->justReturn(true);
        Functions\when('get_search_query')->justReturn('moving');
        Functions\when('get_posts')->justReturn([11]);

        $result = $this->invokeFilter(
            'query_loop_block_query_vars',
            ['post_type' => 'restart-registry', 'posts_per_page' => 12],
            new WP_Block(),
            1
        );

        $this->assertSame('moving', $result['s']);
        $this->assertSame(['post', 'page'], $result['post_type']);
        $this->assertSame([11], $result['post__not_in']);
        $this->assertSame(12, $result['posts_per_page']);
    }
}
