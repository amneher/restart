<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FavoritesItemBlockRenderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->justReturn('');
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);

        (new ReflectionProperty(Restart_Registry_Favorites_Renderer::class, 'quick_add_modals_printed'))->setValue(null, true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_render_php_outputs_item_card(): void {
        $attributes = [
            'tier'   => 'spend',
            'title'  => 'Mid Sofa',
            'price'  => '599.00',
            'images' => ['https://a.test/sofa.jpg'],
        ];

        ob_start();
        require dirname(__DIR__, 2) . '/public/blocks/favorites-item/render.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Mid Sofa', $html);
        $this->assertStringContainsString('rr-article-item__tier-badge--spend', $html);
    }
}
