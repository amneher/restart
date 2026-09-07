<?php
declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FavoritesMigrationCommandTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('apply_filters')->returnArg(2);
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

    public function test_capture_tree_parses_nested_shortcode_content(): void {
        Functions\when('add_shortcode')->justReturn(true);
        Functions\when('remove_shortcode')->justReturn(true);
        Functions\when('shortcode_atts')->alias(function (array $defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : []);
        });
        Functions\when('do_shortcode')->alias(function (string $content) {
            $tags = [
                'restart_item'           => 'test_capture_item_cb',
                'restart_favorites_row'  => 'test_capture_row_cb',
                'restart_favorites_room' => 'test_capture_room_cb',
            ];
            foreach ($tags as $tag => $fn) {
                $content = preg_replace_callback(
                    '/\[' . $tag . '\b([^\]]*)\](.*?\[\/' . $tag . '\]|)/s',
                    function ($m) use ($tag, $fn) {
                        preg_match_all('/(\w+)="([^"]*)"/', $m[1], $attrMatches, PREG_SET_ORDER);
                        $atts = [];
                        foreach ($attrMatches as $am) {
                            $atts[$am[1]] = $am[2];
                        }
                        $inner = $m[2] !== '' ? preg_replace('/^\[[^\]]*\]|\[\/[^\]]*\]$/', '', $m[2]) : null;
                        return call_user_func($fn, $atts, $inner);
                    },
                    $content
                );
            }
            return $content;
        });

        $content = '[restart_favorites_room title="Living Room"][restart_favorites_row title="Sofa"][restart_item tier="save" title="Budget Sofa" price="299.00" image="https://a.test/1.jpg"][/restart_favorites_row][/restart_favorites_room]';

        $rooms = Restart_Registry_Favorites_Migration_Command::capture_tree($content);

        $this->assertSame('Living Room', $rooms[0]['title']);
        $this->assertSame('Sofa', $rooms[0]['rows'][0]['title']);
        $this->assertSame('Budget Sofa', $rooms[0]['rows'][0]['items'][0]['title']);
        $this->assertSame(['https://a.test/1.jpg'], $rooms[0]['rows'][0]['items'][0]['images']);
    }
}

function test_capture_item_cb(array $atts): string {
    $raw    = !empty($atts['images']) ? $atts['images'] : ($atts['image'] ?? '');
    $images = array_values(array_filter(array_map('trim', explode(',', $raw))));
    return json_encode([
        'tier' => $atts['tier'] ?? '', 'title' => $atts['title'] ?? '', 'price' => $atts['price'] ?? '',
        'images' => $images, 'retailer' => $atts['retailer'] ?? '', 'description' => $atts['description'] ?? '',
        'url' => $atts['url'] ?? '', 'notes' => $atts['notes'] ?? '', 'quantity' => $atts['quantity'] ?? '1',
    ]);
}

function test_capture_row_cb(array $atts, ?string $inner): string {
    $items = Restart_Registry_Favorites_Migration_Command::extract_json_objects(do_shortcode((string) $inner));
    return json_encode(['title' => $atts['title'] ?? '', 'items' => $items]);
}

function test_capture_room_cb(array $atts, ?string $inner): string {
    $rows = Restart_Registry_Favorites_Migration_Command::extract_json_objects(do_shortcode((string) $inner));
    return json_encode(['title' => $atts['title'] ?? '', 'rows' => $rows]);
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
