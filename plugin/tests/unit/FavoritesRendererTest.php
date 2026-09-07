<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FavoritesRendererTest extends TestCase {

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

    public function test_render_item_returns_empty_string_without_title(): void {
        $html = Restart_Registry_Favorites_Renderer::render_item(['tier' => 'save']);
        $this->assertSame('', $html);
    }

    public function test_render_item_renders_tier_badge(): void {
        $html = Restart_Registry_Favorites_Renderer::render_item(['title' => 'Budget Sofa', 'tier' => 'save']);

        $this->assertStringContainsString('rr-article-item--tier', $html);
        $this->assertStringContainsString('rr-article-item__tier-badge--save', $html);
    }

    public function test_render_item_ignores_invalid_tier(): void {
        $html = Restart_Registry_Favorites_Renderer::render_item(['title' => 'Item', 'tier' => 'amazing']);

        $this->assertStringNotContainsString('rr-article-item__tier-badge', $html);
    }

    public function test_render_item_renders_multiple_images_as_carousel(): void {
        $html = Restart_Registry_Favorites_Renderer::render_item([
            'title'  => 'Sofa',
            'images' => ['https://a.test/1.jpg', 'https://a.test/2.jpg'],
        ]);

        $this->assertStringContainsString('rr-article-item__carousel', $html);
        $this->assertStringContainsString('data-count="2"', $html);
    }

    public function test_render_row_returns_empty_string_without_title(): void {
        $this->assertSame('', Restart_Registry_Favorites_Renderer::render_row('', '<div>card</div>'));
    }

    public function test_render_row_wraps_content(): void {
        $html = Restart_Registry_Favorites_Renderer::render_row('Sofa', '<div>card</div>');

        $this->assertStringContainsString('rr-favorites-row__title', $html);
        $this->assertStringContainsString('Sofa', $html);
        $this->assertStringContainsString('<div>card</div>', $html);
    }

    public function test_render_room_returns_empty_string_without_title(): void {
        $this->assertSame('', Restart_Registry_Favorites_Renderer::render_room('', '<div>rows</div>'));
    }

    public function test_render_room_wraps_content_and_has_bulk_buttons(): void {
        $html = Restart_Registry_Favorites_Renderer::render_room('Living Room', '<div>rows</div>');

        $this->assertStringContainsString('data-room="Living Room"', $html);
        foreach (Restart_Registry_Favorites_Renderer::TIERS as $tier) {
            $this->assertStringContainsString('data-tier="' . $tier . '"', $html);
        }
    }

    public function test_render_filters_renders_pills(): void {
        $html = Restart_Registry_Favorites_Renderer::render_filters();

        $this->assertStringContainsString('data-room-pills', $html);
        foreach (Restart_Registry_Favorites_Renderer::TIERS as $tier) {
            $this->assertStringContainsString('data-tier-pill="' . $tier . '"', $html);
        }
    }
}
