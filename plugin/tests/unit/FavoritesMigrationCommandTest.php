<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

if (!class_exists('WP_CLI')) {
    class WP_CLI {
        /** @var array<int, array{level:string, message:string}> */
        public static array $calls = [];

        public static function error(string $message): void {
            self::$calls[] = ['level' => 'error', 'message' => $message];
        }

        public static function warning(string $message): void {
            self::$calls[] = ['level' => 'warning', 'message' => $message];
        }

        public static function success(string $message): void {
            self::$calls[] = ['level' => 'success', 'message' => $message];
        }

        public static function log(string $message): void {
            self::$calls[] = ['level' => 'log', 'message' => $message];
        }
    }
}

class FavoritesMigrationCommandTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('apply_filters')->returnArg(2);
        WP_CLI::$calls = [];
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_block_markup_produces_nested_block_comments(): void {
        $rooms = [
            [
                'title' => 'Living Room',
                'rows'  => [
                    [
                        'title' => 'Sofa',
                        'items' => [
                            ['tier' => 'save', 'title' => 'Budget Sofa', 'price' => '299.00', 'images' => ['https://a.test/1.jpg']],
                        ],
                    ],
                ],
            ],
        ];

        $markup = Restart_Registry_Favorites_Migration_Command::build_block_markup($rooms);

        $this->assertStringContainsString('<!-- wp:restart-registry/favorites-room {"title":"Living Room"} -->', $markup);
        $this->assertStringContainsString('<!-- wp:restart-registry/favorites-row {"title":"Sofa"} -->', $markup);
        $this->assertStringContainsString('"title":"Budget Sofa"', $markup);
        $this->assertStringContainsString('<!-- /wp:restart-registry/favorites-room -->', $markup);

        $blocks = parse_blocks_test_helper($markup);
        $this->assertSame('restart-registry/favorites-room', $blocks[0]['blockName']);
        $this->assertSame('restart-registry/favorites-row', $blocks[0]['innerBlocks'][0]['blockName']);
        $this->assertSame('restart-registry/favorites-item', $blocks[0]['innerBlocks'][0]['innerBlocks'][0]['blockName']);
        $this->assertSame('Budget Sofa', $blocks[0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['title']);
    }

    private function stubRealShortcodeCapture(): void {
        $registeredShortcodes = [];

        Functions\when('add_shortcode')->alias(function (string $tag, callable $callback) use (&$registeredShortcodes) {
            $registeredShortcodes[$tag] = $callback;
            return true;
        });
        Functions\when('remove_shortcode')->justReturn(true);
        Functions\when('shortcode_atts')->alias(function (array $defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : []);
        });
        Functions\when('do_shortcode')->alias(function (string $content) use (&$registeredShortcodes) {
            foreach ($registeredShortcodes as $tag => $callback) {
                $content = preg_replace_callback(
                    '/\[' . $tag . '\b([^\]]*)\](.*?\[\/' . $tag . '\]|)/s',
                    function ($m) use ($callback) {
                        preg_match_all('/(\w+)="([^"]*)"/', $m[1], $attrMatches, PREG_SET_ORDER);
                        $atts = [];
                        foreach ($attrMatches as $am) {
                            $atts[$am[1]] = $am[2];
                        }
                        $inner = $m[2] !== '' ? preg_replace('/^\[[^\]]*\]|\[\/[^\]]*\]$/', '', $m[2]) : null;
                        return call_user_func($callback, $atts, $inner);
                    },
                    $content
                );
            }
            return $content;
        });
    }

    public function test_capture_tree_parses_nested_shortcode_content(): void {
        $this->stubRealShortcodeCapture();

        $content = '[restart_favorites_room title="Living Room"][restart_favorites_row title="Sofa"][restart_item tier="save" title="Budget Sofa" price="299.00" image="https://a.test/1.jpg"][/restart_favorites_row][/restart_favorites_room]';

        $rooms = Restart_Registry_Favorites_Migration_Command::capture_tree($content);

        $this->assertSame('Living Room', $rooms[0]['title']);
        $this->assertSame('Sofa', $rooms[0]['rows'][0]['title']);
        $this->assertSame('Budget Sofa', $rooms[0]['rows'][0]['items'][0]['title']);
        $this->assertSame(['https://a.test/1.jpg'], $rooms[0]['rows'][0]['items'][0]['images']);
    }

    public function test_capture_tree_rejects_top_level_row_with_no_enclosing_room(): void {
        $this->stubRealShortcodeCapture();

        $content = '[restart_favorites_row title="Sofa"][restart_item tier="save" title="Budget Sofa" price="299.00" image="https://a.test/1.jpg"][/restart_favorites_row]';

        $rooms = Restart_Registry_Favorites_Migration_Command::capture_tree($content);

        $this->assertSame([], $rooms);
    }

    public function test_block_markup_escapes_characters_that_would_corrupt_block_comments(): void {
        $rooms = [
            [
                'title' => 'Living Room',
                'rows'  => [
                    [
                        'title' => 'Sofa',
                        'items' => [
                            [
                                'tier'  => 'save',
                                'title' => 'Sofa -- <Best> & Cheapest',
                                'price' => '299.00',
                                'images' => ['https://a.test/1.jpg'],
                                'url'   => 'https://a.test/p?tag=x&ref=y',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $markup = Restart_Registry_Favorites_Migration_Command::build_block_markup($rooms);

        // The raw, corrupting characters must not appear unescaped inside the attrs JSON.
        $this->assertDoesNotMatchRegularExpression('/"title":"[^"]*--[^"]*"/', $markup);
        $this->assertDoesNotMatchRegularExpression('/"title":"[^"]*<[^"]*"/', $markup);
        $this->assertDoesNotMatchRegularExpression('/"title":"[^"]*>[^"]*"/', $markup);

        $blocks = parse_blocks_test_helper($markup);
        $item   = $blocks[0]['innerBlocks'][0]['innerBlocks'][0];

        $this->assertSame('Sofa -- <Best> & Cheapest', $item['attrs']['title']);
        $this->assertSame('https://a.test/p?tag=x&ref=y', $item['attrs']['url']);
    }

    public function test_invoke_warns_when_page_has_substantial_content_outside_favorites_shortcodes(): void {
        $this->stubRealShortcodeCapture();

        $extraContent = str_repeat('This intro paragraph explains the room before the shortcodes begin. ', 3);
        $post = new WP_Post();
        $post->ID           = 52;
        $post->post_content = $extraContent . '[restart_favorites_room title="Living Room"][restart_favorites_row title="Sofa"][restart_item tier="save" title="Budget Sofa" price="299.00" image="https://a.test/1.jpg"][/restart_favorites_row][/restart_favorites_room]';

        Functions\when('get_post')->justReturn($post);
        Functions\when('wp_update_post')->justReturn(52);

        $command = new Restart_Registry_Favorites_Migration_Command();
        $command(['52'], []);

        $warnings = array_filter(WP_CLI::$calls, static fn (array $c): bool => $c['level'] === 'warning');
        $this->assertNotEmpty($warnings, 'Expected a WP_CLI::warning() call about discarded content.');
        $this->assertStringContainsString('discarded', reset($warnings)['message']);

        $successes = array_filter(WP_CLI::$calls, static fn (array $c): bool => $c['level'] === 'success');
        $this->assertNotEmpty($successes, 'Migration should still proceed despite the warning.');
    }

    public function test_invoke_does_not_warn_when_content_is_only_the_favorites_shortcodes(): void {
        $this->stubRealShortcodeCapture();

        $post = new WP_Post();
        $post->ID           = 52;
        $post->post_content = '[restart_favorites_room title="Living Room"][restart_favorites_row title="Sofa"][restart_item tier="save" title="Budget Sofa" price="299.00" image="https://a.test/1.jpg"][/restart_favorites_row][/restart_favorites_room]';

        Functions\when('get_post')->justReturn($post);
        Functions\when('wp_update_post')->justReturn(52);

        $command = new Restart_Registry_Favorites_Migration_Command();
        $command(['52'], []);

        $warnings = array_filter(WP_CLI::$calls, static fn (array $c): bool => $c['level'] === 'warning');
        $this->assertEmpty($warnings, 'Should not warn when there is no substantial content outside the shortcodes.');
    }
}

function parse_blocks_test_helper(string $markup): array {
    $blocks = [];
    $offset = 0;
    $len    = strlen($markup);

    while ($offset < $len) {
        if (preg_match('/\G\s+/', $markup, $m, 0, $offset)) {
            $offset += strlen($m[0]);
            continue;
        }
        if ($offset >= $len) {
            break;
        }
        if (!preg_match('/\G<!-- wp:([a-z0-9\/-]+)(?: (\{.*?\}))? (\/)?-->/', $markup, $m, 0, $offset)) {
            break;
        }

        $name        = $m[1];
        $attrs       = isset($m[2]) && $m[2] !== '' ? json_decode($m[2], true) : [];
        $selfClosing = ($m[3] ?? '') === '/';
        $offset += strlen($m[0]);

        if ($selfClosing) {
            $blocks[] = ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => []];
            continue;
        }

        $closeTag = '<!-- /wp:' . $name . ' -->';
        $closePos = strpos($markup, $closeTag, $offset);
        $inner    = substr($markup, $offset, $closePos - $offset);
        $offset   = $closePos + strlen($closeTag);

        $blocks[] = [
            'blockName'   => $name,
            'attrs'       => $attrs,
            'innerBlocks' => parse_blocks_test_helper(trim($inner)),
        ];
    }

    return $blocks;
}
