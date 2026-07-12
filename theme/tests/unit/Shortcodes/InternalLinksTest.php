<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use stdClass;
use TheRestart\Tests\ThemeTestCase;

final class InternalLinksTest extends ThemeTestCase
{
    private function makePost(int $id, string $title, string $status = 'publish'): stdClass
    {
        $post = new stdClass();
        $post->ID = $id;
        $post->post_title = $title;
        $post->post_status = $status;
        return $post;
    }

    /** @param int[] $ids */
    private function stubPosts(array $ids, string $excerpt = ''): void
    {
        $posts = [];
        foreach ($ids as $id) {
            $posts[$id] = $this->makePost($id, "Post $id");
        }
        Functions\when('get_post')->alias(fn(int $id) => $posts[$id] ?? null);
        Functions\when('get_the_title')->alias(fn(stdClass $p) => $p->post_title);
        Functions\when('get_the_excerpt')->justReturn($excerpt);
        Functions\when('get_permalink')->justReturn('#');
        Functions\when('wp_trim_words')->returnArg(1);
    }

    public function test_returns_empty_when_no_ids(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([]);

        $this->assertSame('', $this->invokeShortcode('internal_links'));
    }

    public function test_returns_empty_when_meta_is_falsy(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn(false);

        $this->assertSame('', $this->invokeShortcode('internal_links'));
    }

    public function test_returns_empty_when_linked_post_not_found(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([99]);
        Functions\when('get_post')->justReturn(null);

        $this->assertSame('', $this->invokeShortcode('internal_links'));
    }

    public function test_returns_empty_when_linked_post_is_not_published(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([42]);
        Functions\when('get_post')->justReturn($this->makePost(42, 'Draft Post', 'draft'));

        $this->assertSame('', $this->invokeShortcode('internal_links'));
    }

    public function test_renders_post_title_in_card(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        $this->stubPosts([10]);

        $this->assertStringContainsString('Post 10', $this->invokeShortcode('internal_links'));
    }

    public function test_renders_excerpt_when_present(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        Functions\when('wp_trim_words')->returnArg(1);
        $this->stubPosts([10], 'A helpful guide for moving on.');

        $this->assertStringContainsString('A helpful guide for moving on.', $this->invokeShortcode('internal_links'));
    }

    public function test_truncates_excerpt_to_30_words(): void
    {
        $long = str_repeat('word ', 50);

        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        Functions\when('get_post')->justReturn($this->makePost(10, 'Post 10'));
        Functions\when('get_the_title')->justReturn('Post 10');
        Functions\when('get_the_excerpt')->justReturn($long);
        Functions\when('get_permalink')->justReturn('#');
        Functions\when('wp_trim_words')->alias(
            fn(string $text, int $num) => implode(' ', array_slice(explode(' ', trim($text)), 0, $num)) . '...'
        );

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringNotContainsString($long, $output);
        $this->assertStringContainsString('...', $output);
    }

    public function test_omits_excerpt_paragraph_when_empty(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        $this->stubPosts([10]);

        $this->assertStringNotContainsString('color:#4d6a72', $this->invokeShortcode('internal_links'));
    }

    public function test_card_links_to_post_permalink(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        Functions\when('get_post')->justReturn($this->makePost(10, 'Starting Over'));
        Functions\when('get_the_title')->justReturn('Starting Over');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_permalink')->justReturn('https://example.com/starting-over/');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('href="https://example.com/starting-over/"', $output);
        $this->assertStringContainsString('Read more', $output);
    }

    public function test_includes_separator_and_section_headings(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        $this->stubPosts([10]);

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('<hr ', $output);
        $this->assertStringContainsString('What other mountains lie ahead?', $output);
        $this->assertStringContainsString('You might also find these articles interesting:', $output);
    }

    public function test_uses_three_column_grid(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        $this->stubPosts([10]);

        $output = $this->invokeShortcode('internal_links');
        $this->assertStringContainsString('rr-il-grid', $output);
        $this->assertStringContainsString('grid-template-columns:repeat(3,1fr)', $output);
        $this->assertStringContainsString('grid-template-columns:repeat(2,1fr)', $output);
        $this->assertStringContainsString('grid-template-columns:1fr', $output);
    }

    public function test_renders_a_card_for_each_linked_post(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20]);
        $this->stubPosts([10, 20]);

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('Post 10', $output);
        $this->assertStringContainsString('Post 20', $output);
    }

    public function test_skips_unpublished_posts_among_multiple(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20]);

        $published = $this->makePost(10, 'Published Post');
        $draft     = $this->makePost(20, 'Draft Post', 'draft');

        Functions\when('get_post')->alias(fn(int $id) => $id === 10 ? $published : $draft);
        Functions\when('get_the_title')->alias(fn(stdClass $p) => $p->post_title);
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('Published Post', $output);
        $this->assertStringNotContainsString('Draft Post', $output);
    }

    public function test_no_pagination_when_three_or_fewer_posts(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20, 30]);
        $this->stubPosts([10, 20, 30]);

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringNotContainsString('rr-il-prev', $output);
        $this->assertStringNotContainsString('rr-il-next', $output);
    }

    public function test_pagination_appears_when_more_than_three_posts(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20, 30, 40]);
        $this->stubPosts([10, 20, 30, 40]);

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('rr-il-prev', $output);
        $this->assertStringContainsString('rr-il-next', $output);
    }

    public function test_pagination_shows_correct_page_count(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20, 30, 40, 50, 60, 70]);
        $this->stubPosts([10, 20, 30, 40, 50, 60, 70]);

        // 7 posts / 3 per page = 3 pages
        $this->assertStringContainsString('pages=3', $this->invokeShortcode('internal_links'));
    }

    public function test_all_cards_rendered_in_dom_for_pagination(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20, 30, 40]);
        $this->stubPosts([10, 20, 30, 40]);

        $output = $this->invokeShortcode('internal_links');

        // All cards exist in the markup even though JS hides later pages
        $this->assertSame(4, substr_count($output, 'class="rr-il-card"'));
    }
}
