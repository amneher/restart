<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\AppPassword;

use Brain\Monkey\Functions;
use TheRestart\Tests\ThemeTestCase;
use WP_Theme;
use WP_User;

final class EnqueueStartRegistryTest extends ThemeTestCase
{
    private function invokeAllEnqueueCallbacks(): void
    {
        foreach (self::$enqueueCallbacks as $cb) {
            $cb();
        }
    }

    public function test_does_not_enqueue_when_not_on_page(): void
    {
        Functions\when('is_page')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_stylesheet_uri')->justReturn('#');
        Functions\when('wp_get_theme')->justReturn(new WP_Theme());
        Functions\when('get_stylesheet_directory_uri')->justReturn('http://example.com');
        Functions\when('admin_url')->justReturn('#');
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_localize_script')->justReturn(true);

        Functions\expect('wp_enqueue_script')
            ->never()
            ->with('restart-start-registry', \Mockery::any(), \Mockery::any(), \Mockery::any(), \Mockery::any());

        $this->invokeAllEnqueueCallbacks();
    }

    public function test_does_not_enqueue_when_not_logged_in(): void
    {
        Functions\when('is_page')->alias(fn($slug) => $slug === 'start-a-registry');
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_stylesheet_uri')->justReturn('#');
        Functions\when('wp_get_theme')->justReturn(new WP_Theme());
        Functions\when('get_stylesheet_directory_uri')->justReturn('http://example.com');
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_localize_script')->justReturn(true);

        Functions\expect('wp_enqueue_script')
            ->never()
            ->with('restart-start-registry', \Mockery::any(), \Mockery::any(), \Mockery::any(), \Mockery::any());

        $this->invokeAllEnqueueCallbacks();
    }

    public function test_reuses_existing_api_key(): void
    {
        Functions\when('is_page')->alias(function ($slug) {
            return $slug === 'start-a-registry';
        });
        Functions\when('is_user_logged_in')->justReturn(true);

        $user = new WP_User();
        $user->ID = 1;
        $user->user_login = 'alex';
        Functions\when('wp_get_current_user')->justReturn($user);

        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 1 && $key === '_restart_api_key' && $single === true) {
                return 'existing-key';
            }
            return '';
        });

        Functions\when('wp_get_theme')->justReturn(new WP_Theme());
        Functions\when('get_stylesheet_directory_uri')->justReturn('http://example.com');
        Functions\when('get_stylesheet_uri')->justReturn('#');
        Functions\when('home_url')->justReturn('http://example.com/my-account/');
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('update_user_meta')->justReturn(true);

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('restart-start-registry', \Mockery::any(), \Mockery::any(), \Mockery::any(), \Mockery::any());

        Functions\expect('wp_localize_script')
            ->once()
            ->with('restart-start-registry', 'restartRegistry', \Mockery::any());

        $this->invokeAllEnqueueCallbacks();
    }

    public function test_creates_and_stores_app_password_when_none_exists(): void
    {
        Functions\when('is_page')->alias(fn($slug) => $slug === 'start-a-registry');
        Functions\when('is_user_logged_in')->justReturn(true);

        $user = new WP_User();
        $user->ID = 1;
        $user->user_login = 'alex';
        Functions\when('wp_get_current_user')->justReturn($user);

        Functions\when('get_user_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        Functions\when('wp_get_theme')->justReturn(new WP_Theme());
        Functions\when('get_stylesheet_directory_uri')->justReturn('http://example.com');
        Functions\when('get_stylesheet_uri')->justReturn('#');
        Functions\when('home_url')->justReturn('http://example.com/my-account/');
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('wp_localize_script')->justReturn(true);

        Functions\expect('update_user_meta')
            ->once()
            ->with(1, '_restart_api_key', 'test-app-password-123');

        $this->invokeAllEnqueueCallbacks();
    }
}
