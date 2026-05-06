<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;

final class RegisterFormTest extends ThemeTestCase
{
    public function test_returns_already_logged_in_when_authenticated(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('home_url')->justReturn('#');

        $output = $this->invokeShortcode('restart_register_form');

        $this->assertStringContainsString('already have an account', $output);
    }

    public function test_returns_closed_message_when_registration_disabled(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_option')->alias(function ($name) {
            return $name === 'users_can_register' ? false : null;
        });

        $output = $this->invokeShortcode('restart_register_form');

        $this->assertStringContainsString('registration is currently closed', $output);
    }

    public function test_returns_form_when_guest_and_registration_open(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_option')->justReturn(true);
        Functions\when('wp_login_url')->justReturn('#');

        $output = $this->invokeShortcode('restart_register_form');

        $this->assertStringContainsString('rr-register-form', $output);
    }
}
