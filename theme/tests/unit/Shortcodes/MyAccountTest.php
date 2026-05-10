<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;
use WP_User;

final class MyAccountTest extends ThemeTestCase
{
    public function test_returns_login_prompt_when_not_logged_in(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_login_url')->justReturn('#');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('restart_my_account');

        $this->assertStringContainsString('log in', $output);
    }

    public function test_returns_account_panel_with_display_name(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $user = new WP_User();
        $user->ID = 1;
        $user->user_login = 'alex';
        $user->user_email = 'alex@example.com';
        $user->display_name = 'Alex';

        Functions\when('wp_get_current_user')->justReturn($user);
        Functions\when('home_url')->justReturn('#');
        Functions\when('wp_logout_url')->justReturn('#');
        Functions\when('wp_create_nonce')->justReturn('test-nonce');
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('checked')->justReturn('');

        $output = $this->invokeShortcode('restart_my_account');

        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('Alex', $output);
        $this->assertStringContainsString('restart-my-account', $output);
    }
}
