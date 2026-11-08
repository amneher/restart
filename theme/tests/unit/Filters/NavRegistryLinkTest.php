<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Filters;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;

final class NavRegistryLinkTest extends ThemeTestCase
{
    private function navBlock(string $className = 'rr-nav-registry'): array
    {
        return [
            'blockName' => 'core/navigation-link',
            'attrs'     => [
                'label'     => 'Find a Registry',
                'url'       => '/registry/',
                'className' => $className,
            ],
        ];
    }

    public function test_logged_out_shows_create(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\expect('get_posts')->never();

        $block = $this->invokeFilter('render_block_data', $this->navBlock());

        $this->assertSame('Create a Registry', $block['attrs']['label']);
        $this->assertSame('/start-a-registry/', $block['attrs']['url']);
    }

    public function test_logged_in_without_registry_shows_create(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('get_posts')->justReturn([]);

        $block = $this->invokeFilter('render_block_data', $this->navBlock());

        $this->assertSame('Create a Registry', $block['attrs']['label']);
        $this->assertSame('/start-a-registry/', $block['attrs']['url']);
    }

    public function test_logged_in_with_registry_shows_my_registry(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\expect('get_posts')->once()->andReturnUsing(function (array $args) {
            $this->assertSame('restart-registry', $args['post_type']);
            $this->assertSame(7, $args['author']);
            $this->assertSame('ids', $args['fields']);
            return [42];
        });

        $block = $this->invokeFilter('render_block_data', $this->navBlock());

        $this->assertSame('My Registry', $block['attrs']['label']);
        $this->assertSame('/my-registries/', $block['attrs']['url']);
    }

    public function test_matches_class_among_several(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $block = $this->invokeFilter('render_block_data', $this->navBlock('foo rr-nav-registry bar'));

        $this->assertSame('Create a Registry', $block['attrs']['label']);
    }

    public function test_untagged_nav_links_pass_through(): void
    {
        Functions\expect('is_user_logged_in')->never();

        $input = $this->navBlock('');
        $this->assertSame($input, $this->invokeFilter('render_block_data', $input));

        unset($input['attrs']['className']);
        $this->assertSame($input, $this->invokeFilter('render_block_data', $input));
    }
}
