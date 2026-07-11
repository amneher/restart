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
        Functions\when('get_post')->justReturn($this->makePost(10, 'Starting Over: A Guide'));
        Functions\when('get_the_title')->justReturn('Starting Over: A Guide');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('Starting Over: A Guide', $output);
    }

    public function test_renders_excerpt_when_present(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        Functions\when('get_post')->justReturn($this->makePost(10, 'Starting Over'));
        Functions\when('get_the_title')->justReturn('Starting Over');
        Functions\when('get_the_excerpt')->justReturn('A helpful guide for moving on.');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('A helpful guide for moving on.', $output);
    }

    public function test_omits_excerpt_paragraph_when_empty(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10]);
        Functions\when('get_post')->justReturn($this->makePost(10, 'Starting Over'));
        Functions\when('get_the_title')->justReturn('Starting Over');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringNotContainsString('color:#4d6a72', $output);
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
        Functions\when('get_post')->justReturn($this->makePost(10, 'Starting Over'));
        Functions\when('get_the_title')->justReturn('Starting Over');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('<hr ', $output);
        $this->assertStringContainsString('Dive into helpful resources.', $output);
        $this->assertStringContainsString('Everything you need to move forward.', $output);
    }

    public function test_renders_a_card_for_each_linked_post(): void
    {
        Functions\when('get_the_ID')->justReturn(1);
        Functions\when('get_post_meta')->justReturn([10, 20]);

        $post1 = $this->makePost(10, 'First Post');
        $post2 = $this->makePost(20, 'Second Post');

        Functions\when('get_post')->alias(fn(int $id) => $id === 10 ? $post1 : $post2);
        Functions\when('get_the_title')->alias(fn(stdClass $p) => $p->post_title);
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('internal_links');

        $this->assertStringContainsString('First Post', $output);
        $this->assertStringContainsString('Second Post', $output);
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
}
