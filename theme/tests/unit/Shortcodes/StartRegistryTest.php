<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;

final class StartRegistryTest extends ThemeTestCase
{
    public function test_returns_login_prompt_when_not_logged_in(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_login_url')->justReturn('#login');
        Functions\when('get_permalink')->justReturn('#');

        $output = $this->invokeShortcode('restart_start_registry');

        $this->assertStringContainsString('restart-login-prompt', $output);
        $this->assertStringContainsString('Log In', $output);
        $this->assertStringNotContainsString('restart-registry-form', $output);
    }

    public function test_returns_form_when_logged_in(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $output = $this->invokeShortcode('restart_start_registry');

        $this->assertStringContainsString('restart-registry-form', $output);
        $this->assertStringContainsString('registry-title', $output);
        $this->assertStringContainsString('event-type', $output);
        $this->assertStringContainsString('is-private', $output);
    }
}
