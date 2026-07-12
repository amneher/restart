<?php

declare(strict_types=1);

namespace TheRestart\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class ThemeTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected static array $shortcodes = [];
    protected static array $enqueueCallbacks = [];
    protected static array $footerCallbacks = [];
    protected static array $filters = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        self::$shortcodes = [];
        self::$enqueueCallbacks = [];
        self::$footerCallbacks = [];
        self::$filters = [];

        Functions\when('add_shortcode')->alias(function ($tag, $cb) {
            self::$shortcodes[$tag] = $cb;
        });

        Functions\when('add_action')->alias(function ($hook, $cb) {
            if ($hook === 'wp_enqueue_scripts') {
                self::$enqueueCallbacks[] = $cb;
            } elseif ($hook === 'wp_footer') {
                self::$footerCallbacks[] = $cb;
            }
        });

        Functions\when('add_filter')->alias(function ($hook, $cb) {
            self::$filters[$hook][] = $cb;
        });

        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('wp_add_inline_style')->justReturn(true);

        include THEME_DIR . '/functions.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    protected function invokeShortcode(string $tag, array $atts = [], string $content = ''): string
    {
        return (string) (self::$shortcodes[$tag])($atts, $content);
    }

    protected function invokeFilter(string $hook, mixed $value, ...$args): mixed
    {
        if (empty(self::$filters[$hook])) {
            return $value;
        }
        foreach (self::$filters[$hook] as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }
}
