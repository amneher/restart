<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the `the_content` (priority 8) filter registered in
 * Restart_Registry_Public's constructor.
 *
 * That filter early-expands standalone [restart_item] shortcodes into raw HTML
 * before wpautop() runs, to dodge WP's make_clickable mangling of URLs inside
 * attribute values. But if it also expands [restart_item] tags nested inside
 * [restart_favorites_row]...[/restart_favorites_row], wpautop sees a run of
 * adjacent <div> cards with no blank-line separation and inserts stray <p>
 * tags between them — corrupting the row's CSS grid (caught via manual QA:
 * 9 grid children instead of 3, cards wrapping onto extra rows).
 *
 * The fix: leave [restart_item] tags inside a favorites_row block as literal
 * text at priority 8, so wpautop's shortcode_unautop() recognises the whole
 * enclosing [restart_favorites_row] as a standalone shortcode and leaves it
 * untouched; do_shortcode() (priority 11) then expands everything correctly.
 *
 * Brain Monkey's add_filter()/apply_filters() fakes don't dispatch registered
 * callbacks by default, so this captures the actual closure passed to
 * add_filter('the_content', ..., 8) in the constructor and invokes it directly
 * — exercising the real registered callback, not a re-implementation of it.
 */
class FavoritesRowContentFilterTest extends TestCase {

    private \Closure $filter;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->justReturn('');
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_shortcode')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('plugin_dir_path')->alias(fn($file) => rtrim(dirname((string) $file), '/') . '/');
        Functions\when('plugin_dir_url')->justReturn('https://the-restart.com/wp-content/plugins/restart-registry/');
        // Marker function standing in for the real renderer — lets us see exactly
        // which substrings the filter chose to expand vs. leave as literal text.
        Functions\when('do_shortcode')->alias(fn($s) => '{{RENDERED:' . $s . '}}');

        Functions\when('add_filter')->alias(function ($tag, $callback, $priority = 10) {
            if ($tag === 'the_content' && $priority === 8) {
                $this->filter = $callback;
            }
            return true;
        });

        new Restart_Registry_Public('restart-registry', '1.0.0');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_standalone_restart_item_is_expanded(): void {
        $content = 'before [restart_item title="Outside"] after';

        $result = ($this->filter)($content);

        $this->assertStringContainsString('{{RENDERED:[restart_item title="Outside"]}}', $result);
    }

    public function test_restart_item_nested_in_favorites_row_is_left_as_literal_text(): void {
        $content = '[restart_favorites_row title="Sofa"]'
            . '[restart_item tier="good" title="In A"]'
            . '[restart_item tier="better" title="In B"]'
            . '[/restart_favorites_row]';

        $result = ($this->filter)($content);

        $this->assertStringNotContainsString('{{RENDERED:', $result);
        $this->assertSame($content, $result);
    }

    public function test_standalone_and_nested_items_are_handled_differently_in_the_same_content(): void {
        $content = 'before [restart_item title="Outside"] middle '
            . '[restart_favorites_row title="Sofa"][restart_item tier="good" title="In A"][/restart_favorites_row]'
            . ' after';

        $result = ($this->filter)($content);

        $this->assertStringContainsString('{{RENDERED:[restart_item title="Outside"]}}', $result);
        $this->assertStringContainsString('[restart_favorites_row title="Sofa"][restart_item tier="good" title="In A"][/restart_favorites_row]', $result);
        $this->assertStringNotContainsString('{{RENDERED:[restart_item tier="good" title="In A"]}}', $result);
    }

    public function test_content_without_restart_item_is_unchanged(): void {
        $content = '<p>Just some regular content.</p>';

        $result = ($this->filter)($content);

        $this->assertSame($content, $result);
    }

    public function test_multiple_favorites_rows_both_protected(): void {
        $content = '[restart_favorites_row title="Sofa"][restart_item tier="good" title="A"][/restart_favorites_row]'
            . '[restart_favorites_row title="Chair"][restart_item tier="best" title="B"][/restart_favorites_row]';

        $result = ($this->filter)($content);

        $this->assertSame($content, $result);
    }
}
