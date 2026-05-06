<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Filters;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;
use WP_Block;
use WP_Term;

final class QueryLoopVarsTest extends ThemeTestCase
{
    public function test_adds_cat_on_category_archive(): void
    {
        Functions\when('is_category')->justReturn(true);
        Functions\when('is_tag')->justReturn(false);
        Functions\when('is_tax')->justReturn(false);

        $term = new WP_Term();
        $term->term_id = 5;
        Functions\when('get_queried_object')->justReturn($term);

        $result = $this->invokeFilter('query_loop_block_query_vars', [], new WP_Block(), 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cat', $result);
        $this->assertSame(5, $result['cat']);
    }

    public function test_adds_tag_id_on_tag_archive(): void
    {
        Functions\when('is_category')->justReturn(false);
        Functions\when('is_tag')->justReturn(true);
        Functions\when('is_tax')->justReturn(false);

        $term = new WP_Term();
        $term->term_id = 3;
        Functions\when('get_queried_object')->justReturn($term);

        $result = $this->invokeFilter('query_loop_block_query_vars', [], new WP_Block(), 1);

        $this->assertArrayHasKey('tag_id', $result);
        $this->assertSame(3, $result['tag_id']);
    }

    public function test_passes_through_on_regular_page(): void
    {
        Functions\when('is_category')->justReturn(false);
        Functions\when('is_tag')->justReturn(false);
        Functions\when('is_tax')->justReturn(false);

        $input = ['post_type' => 'page'];
        $result = $this->invokeFilter('query_loop_block_query_vars', $input, new WP_Block(), 1);

        $this->assertSame($input, $result);
    }
}
