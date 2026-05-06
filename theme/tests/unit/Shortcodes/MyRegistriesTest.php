<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use stdClass;
use TheRestart\Tests\ThemeTestCase;
use WP_User;

final class MyRegistriesTest extends ThemeTestCase
{
    public function test_returns_login_prompt_when_not_logged_in(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_login_url')->justReturn('#');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('restart_my_registries');

        $this->assertStringContainsString('log in', $output);
    }

    public function test_shows_empty_state_when_no_registries(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $user = new WP_User();
        $user->ID = 1;
        $user->user_login = 'alex';
        $user->user_email = 'alex@example.com';

        Functions\when('wp_get_current_user')->justReturn($user);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('home_url')->justReturn('#');

        $output = $this->invokeShortcode('restart_my_registries');

        $this->assertStringContainsString("haven't created any registries", $output);
    }

    public function test_lists_owned_registries(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $user = new WP_User();
        $user->ID = 1;
        $user->user_login = 'alex';
        $user->user_email = 'alex@example.com';

        Functions\when('wp_get_current_user')->justReturn($user);

        $post = new stdClass();
        $post->ID = 1;
        $post->post_title = 'My Registry';
        $post->post_status = 'publish';
        $post->post_author = 1;

        $callCount = 0;
        Functions\when('get_posts')->alias(function () use ($post, &$callCount) {
            $callCount++;
            return $callCount === 1 ? [$post] : [];
        });

        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_permalink')->justReturn('#');
        Functions\when('home_url')->justReturn('#');

        $output = $this->invokeShortcode('restart_my_registries');

        $this->assertStringContainsString('My Registry', $output);
    }
}
