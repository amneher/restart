<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Filters;

use Brain\Monkey\Functions;
use stdClass;
use TheRestart\Tests\ThemeTestCase;

final class BlockTemplateHierarchyTest extends ThemeTestCase
{
    public function test_inserts_category_templates_before_single(): void
    {
        Functions\when('is_singular')->alias(fn($t) => $t === 'post');

        $cat = new stdClass();
        $cat->slug = 'articles';
        Functions\when('get_the_category')->justReturn([$cat]);

        $result = $this->invokeFilter('block_template_hierarchy', ['singular', 'single', 'index']);

        $this->assertIsArray($result);
        $singlePos = array_search('single', $result, true);
        $categoryPos = array_search('single-category-articles', $result, true);
        $this->assertNotFalse($categoryPos);
        $this->assertNotFalse($singlePos);
        $this->assertSame($singlePos - 1, $categoryPos);
    }

    public function test_passes_through_on_non_singular(): void
    {
        Functions\when('is_singular')->justReturn(false);

        $input = ['singular', 'single', 'index'];
        $result = $this->invokeFilter('block_template_hierarchy', $input);

        $this->assertSame($input, $result);
    }

    public function test_passes_through_when_no_categories(): void
    {
        Functions\when('is_singular')->alias(fn($t) => $t === 'post');
        Functions\when('get_the_category')->justReturn([]);

        $input = ['singular', 'single', 'index'];
        $result = $this->invokeFilter('block_template_hierarchy', $input);

        $this->assertSame($input, $result);
    }
}
