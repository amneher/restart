<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Restart_Registry_Controller::truncate_name().
 *
 * The controller constructor spins up Lambda + Affiliate clients, so we
 * instantiate without invoking it and access the private method via reflection.
 */
class ControllerTruncateNameTest extends TestCase {

    private object $controller;
    private ReflectionMethod $truncate;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Stub WP functions called at class-file load time (none currently,
        // but keeps Brain\Monkey happy if any are added later).

        $ref              = new ReflectionClass(Restart_Registry_Controller::class);
        $this->controller = $ref->newInstanceWithoutConstructor();
        $this->truncate   = $ref->getMethod('truncate_name');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function truncate(string $name): string {
        return $this->truncate->invoke($this->controller, $name);
    }

    // ── Short names pass through unchanged ───────────────────────────────────

    public function test_short_name_is_unchanged(): void {
        $this->assertSame('Skate Rack', $this->truncate('Skate Rack'));
    }

    public function test_exactly_100_chars_is_unchanged(): void {
        $name = str_repeat('x', 100);
        $this->assertSame($name, $this->truncate($name));
    }

    // ── Separator extraction ──────────────────────────────────────────────────

    public function test_dash_separator_extracts_core_name(): void {
        $name = 'Ray-Ban Meta Wayfarer - (Gen 1) Matte Black, Clear to Graphite Green Transitions: Smart Eyewear with Bluetooth, Wi-Fi, Meta AI';
        $this->assertSame('Ray-Ban Meta Wayfarer', $this->truncate($name));
    }

    public function test_pipe_separator_extracts_core_name(): void {
        $name = 'Nike Air Max 270 | Men\'s Running Shoes | Lightweight, Breathable Mesh Upper | Available in Black, White, and Red';
        $this->assertSame("Nike Air Max 270", $this->truncate($name));
    }

    public function test_colon_separator_extracts_core_name(): void {
        $name = 'Ember Temperature Control Smart Mug 2: 10 oz, Stainless Steel, Personalized with App Control, Copper/Silver';
        $this->assertSame('Ember Temperature Control Smart Mug 2', $this->truncate($name));
    }

    public function test_comma_separator_used_when_leading_segment_is_long_enough(): void {
        // First segment is 51 chars — well above the 20-char minimum
        $name = 'Apple AirPods Pro (2nd Generation) Wireless Earbuds, ANC Headphones, H2 Chip, Adaptive Audio, Long String Here That Goes Over 100 Characters For Sure';
        $this->assertSame('Apple AirPods Pro (2nd Generation) Wireless Earbuds', $this->truncate($name));
    }

    public function test_comma_separator_skipped_when_leading_segment_is_too_short(): void {
        // First segment is only 5 chars — below 20-char minimum, skip comma split
        $name = 'Brand, Some Very Long Product Name That Goes Well Past One Hundred Characters Total In This String';
        // Falls through to word-boundary truncation
        $result = $this->truncate($name);
        $this->assertLessThanOrEqual(100, mb_strlen($result));
        // Does NOT split at the short comma segment
        $this->assertStringNotContainsString('Brand', substr($result, 0, 10) === 'Brand' ? 'Brand' : '');
    }

    // ── Word-boundary fallback ────────────────────────────────────────────────

    public function test_word_boundary_fallback_when_no_separator(): void {
        $name   = str_repeat('word ', 30); // 150 chars, no separator
        $result = $this->truncate($name);
        $this->assertLessThanOrEqual(100, mb_strlen($result));
        // Should end on a whole word, not with a trailing space
        $this->assertSame(rtrim($result), $result);
        // Should end with the word "word" (no mid-word cut)
        $this->assertStringEndsWith('word', $result);
    }

    public function test_result_never_exceeds_100_chars(): void {
        $inputs = [
            str_repeat('a', 150),
            str_repeat('ab ', 60),
            'Product - ' . str_repeat('x', 200),
            'Short: ' . str_repeat('y', 200),
        ];
        foreach ($inputs as $input) {
            $this->assertLessThanOrEqual(100, mb_strlen($this->truncate($input)), "Failed for: $input");
        }
    }
}
