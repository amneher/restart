<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Covers the [restart_item tier="..."] column-card variant and the
 * enclosing [restart_favorites_row] shortcode used on the Our Favorites page.
 */
class FavoritesRowShortcodeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->justReturn('');
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_shortcode')->justReturn(true);
        Functions\when('plugin_dir_path')->alias(fn($file) => rtrim(dirname((string) $file), '/') . '/');
        Functions\when('plugin_dir_url')->justReturn('https://the-restart.com/wp-content/plugins/restart-registry/');
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html_e')->alias(function ($s) { echo htmlspecialchars((string) $s, ENT_QUOTES); });
        Functions\when('esc_attr_e')->alias(function ($s) { echo htmlspecialchars((string) $s, ENT_QUOTES); });
        Functions\when('do_shortcode')->returnArg(1);
        Functions\when('shortcode_atts')->alias(function (array $defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : []);
        });

        // The quick-add modals (auth/no-registry) are appended once per page by
        // item_shortcode() and depend on a large slice of WP (wp_login_url(),
        // nonces, etc.) unrelated to the tier/favorites-row feature under test.
        // Mark them already-printed so item_shortcode() skips that branch.
        (new ReflectionProperty(Restart_Registry_Public::class, 'quick_add_modals_printed'))->setValue(null, true);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function public_class(): Restart_Registry_Public {
        return new Restart_Registry_Public('restart-registry', '1.0.0');
    }

    // ── [restart_item tier="..."] column variant ────────────────────────────

    public function test_no_tier_badge_when_tier_absent(): void {
        $html = $this->public_class()->item_shortcode(['title' => 'Chef Knife']);

        $this->assertStringNotContainsString('rr-article-item__tier-badge', $html);
        $this->assertStringNotContainsString('rr-article-item--tier', $html);
    }

    public function test_tier_badge_rendered_for_valid_tier(): void {
        $html = $this->public_class()->item_shortcode(['title' => 'Budget Sofa', 'tier' => 'good']);

        $this->assertStringContainsString('rr-article-item--tier', $html);
        $this->assertStringContainsString('rr-article-item__tier-badge--good', $html);
        $this->assertStringContainsString('Good', $html);
    }

    public function test_tier_badge_reflects_each_valid_tier(): void {
        foreach (['good', 'better', 'best'] as $tier) {
            $html = $this->public_class()->item_shortcode(['title' => 'Item', 'tier' => $tier]);
            $this->assertStringContainsString('rr-article-item__tier-badge--' . $tier, $html);
        }
    }

    public function test_invalid_tier_value_is_ignored(): void {
        $html = $this->public_class()->item_shortcode(['title' => 'Item', 'tier' => 'amazing']);

        $this->assertStringNotContainsString('rr-article-item__tier-badge', $html);
        $this->assertStringNotContainsString('rr-article-item--tier', $html);
    }

    public function test_tier_card_still_returns_empty_without_title(): void {
        $html = $this->public_class()->item_shortcode(['tier' => 'good']);

        $this->assertSame('', $html);
    }

    // ── [restart_favorites_row] ─────────────────────────────────────────────

    public function test_returns_empty_string_when_title_missing(): void {
        $html = $this->public_class()->favorites_row_shortcode([], '[restart_item title="X" tier="good"]');

        $this->assertSame('', $html);
    }

    public function test_wraps_content_with_title_heading(): void {
        $html = $this->public_class()->favorites_row_shortcode(['title' => 'Sofa'], '<div>card</div>');

        $this->assertStringContainsString('rr-favorites-row', $html);
        $this->assertStringContainsString('rr-favorites-row__title', $html);
        $this->assertStringContainsString('Sofa', $html);
        $this->assertStringContainsString('rr-favorites-row__cards', $html);
        $this->assertStringContainsString('<div>card</div>', $html);
    }

    public function test_escapes_title(): void {
        $html = $this->public_class()->favorites_row_shortcode(['title' => '<script>x</script>'], 'content');

        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_null_content_does_not_error(): void {
        $html = $this->public_class()->favorites_row_shortcode(['title' => 'Sofa'], null);

        $this->assertStringContainsString('rr-favorites-row__cards"></div>', $html);
    }
}
