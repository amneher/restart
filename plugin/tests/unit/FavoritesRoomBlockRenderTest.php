<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FavoritesRoomBlockRenderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_render_php_wraps_inner_content(): void {
        $attributes = ['title' => 'Living Room'];
        $content    = '<div class="rr-favorites-row">row</div>';

        ob_start();
        require dirname(__DIR__, 2) . '/public/blocks/favorites-room/render.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-room="Living Room"', $html);
        $this->assertStringContainsString('<div class="rr-favorites-row">row</div>', $html);
        $this->assertStringContainsString('rr-bulk-add', $html);
    }
}
