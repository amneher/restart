<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FavoritesRowBlockRenderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_render_php_wraps_inner_content(): void {
        $attributes = ['title' => 'Sofa'];
        $content    = '<div class="rr-article-item">card</div>';

        ob_start();
        require dirname(__DIR__, 2) . '/public/blocks/favorites-row/render.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('rr-favorites-row__title', $html);
        $this->assertStringContainsString('Sofa', $html);
        $this->assertStringContainsString('<div class="rr-article-item">card</div>', $html);
    }
}
