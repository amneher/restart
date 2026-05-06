<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;

final class LoginFormTest extends ThemeTestCase
{
    protected function tearDown(): void
    {
        unset($_GET['login']);
        parent::tearDown();
    }

    public function test_returns_already_logged_in_when_authenticated(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('home_url')->justReturn('#');

        $output = $this->invokeShortcode('restart_login_form');

        $this->assertStringContainsString('already logged in', $output);
    }

    public function test_shows_error_on_failed_login(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('home_url')->justReturn('#');
        Functions\when('site_url')->justReturn('#');
        Functions\when('wp_registration_url')->justReturn('#');

        $_GET['login'] = 'failed';

        $output = $this->invokeShortcode('restart_login_form');

        $this->assertStringContainsString('Incorrect username or password', $output);
    }

    public function test_returns_clean_form_by_default(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('home_url')->justReturn('#');
        Functions\when('site_url')->justReturn('#');
        Functions\when('wp_registration_url')->justReturn('#');

        $output = $this->invokeShortcode('restart_login_form');

        $this->assertStringContainsString('user_login', $output);
        $this->assertStringContainsString('user_pass', $output);
        $this->assertStringNotContainsString('Incorrect username or password', $output);
    }
}
