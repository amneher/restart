<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FavoritesFiltersBlockRenderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_render_php_outputs_filter_bar(): void {
        ob_start();
        require dirname(__DIR__, 2) . '/public/blocks/favorites-filters/render.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-room-pills', $html);
        $this->assertStringContainsString('data-tier-pill="save"', $html);
    }
}
