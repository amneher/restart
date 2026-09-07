<?php

/**
 * WP-CLI: wp restart-registry migrate-favorites-page <page_id>
 *
 * Converts a page's existing [restart_favorites_room]/[restart_favorites_row]/
 * [restart_item]/[restart_favorites_filters] shortcode text into native
 * restart-registry/favorites-* block markup, in place, on the same post.
 */
class Restart_Registry_Favorites_Migration_Command
{
    /**
     * Converts a page's nested favorites shortcodes into native blocks.
     *
     * ## OPTIONS
     *
     * <page_id>
     * : ID of the page whose content should be converted.
     *
     * [--dry-run]
     * : Print the resulting block markup without saving.
     *
     * ## EXAMPLES
     *
     *     wp restart-registry migrate-favorites-page 52
     *
     * @when after_wp_load
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        $page_id = (int) $args[0];
        $post    = get_post($page_id);

        if (!$post) {
            WP_CLI::error("No post found with ID {$page_id}.");
            return;
        }

        $has_filters = str_contains($post->post_content, '[restart_favorites_filters]');
        $rooms       = self::capture_tree($post->post_content);

        if (empty($rooms)) {
            WP_CLI::error('No [restart_favorites_room] content found on that page.');
            return;
        }

        $markup = self::build_block_markup($rooms);
        if ($has_filters) {
            $markup = "<!-- wp:restart-registry/favorites-filters /-->\n\n" . $markup;
        }

        if (!empty($assoc_args['dry-run'])) {
            WP_CLI::log($markup);
            return;
        }

        wp_update_post(['ID' => $page_id, 'post_content' => $markup]);
        WP_CLI::success("Converted page {$page_id} to native favorites blocks.");
    }

    /**
     * Temporarily swaps the real shortcode handlers for JSON-capturing ones
     * and runs do_shortcode() on the raw content, reusing WordPress's own
     * shortcode parser (attribute parsing, nesting) instead of reinventing
     * one. Each capture handler returns a JSON-encoded object instead of
     * HTML; extract_json_objects() splits an enclosing handler's expanded
     * content back into the array of objects its children produced. This
     * command runs once per WP-CLI invocation and the process exits
     * immediately after, so the real handlers never need to be restored.
     *
     * @return array<int, array{title:string, rows:array}>
     */
    public static function capture_tree(string $content): array
    {
        remove_shortcode('restart_item');
        remove_shortcode('restart_favorites_row');
        remove_shortcode('restart_favorites_room');

        add_shortcode('restart_item', function (array $atts) {
            $a = shortcode_atts([
                'title' => '', 'price' => '', 'image' => '', 'images' => '',
                'description' => '', 'url' => '', 'retailer' => '', 'notes' => '',
                'quantity' => '1', 'tier' => '',
            ], $atts, 'restart_item');

            $raw    = !empty($a['images']) ? $a['images'] : $a['image'];
            $images = array_values(array_filter(array_map('trim', explode(',', $raw))));

            return wp_json_encode([
                'tier' => $a['tier'], 'title' => $a['title'], 'price' => $a['price'],
                'images' => $images, 'retailer' => $a['retailer'],
                'description' => $a['description'], 'url' => $a['url'],
                'notes' => $a['notes'], 'quantity' => $a['quantity'],
            ]);
        });

        add_shortcode('restart_favorites_row', function (array $atts, $inner = null) {
            $a     = shortcode_atts(['title' => ''], $atts, 'restart_favorites_row');
            $items = self::extract_json_objects(do_shortcode((string) $inner));
            return wp_json_encode(['title' => $a['title'], 'items' => $items]);
        });

        add_shortcode('restart_favorites_room', function (array $atts, $inner = null) {
            $a    = shortcode_atts(['title' => ''], $atts, 'restart_favorites_room');
            $rows = self::extract_json_objects(do_shortcode((string) $inner));
            return wp_json_encode(['title' => $a['title'], 'rows' => $rows]);
        });

        $expanded = do_shortcode($content);

        return self::extract_json_objects($expanded);
    }

    /**
     * Splits a string containing zero or more concatenated top-level JSON
     * objects (produced back-to-back by capture-mode shortcode handlers,
     * possibly with whitespace/stray markup between them) into the decoded
     * array of each object. Tracks string-escaping and brace depth so JSON
     * string values containing "{" or "}" don't throw off the split.
     */
    public static function extract_json_objects(string $str): array
    {
        $objects  = [];
        $depth    = 0;
        $inString = false;
        $escaped  = false;
        $start    = null;

        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            $ch = $str[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $decoded = json_decode(substr($str, $start, $i - $start + 1), true);
                    if (is_array($decoded)) {
                        $objects[] = $decoded;
                    }
                    $start = null;
                }
            }
        }

        return $objects;
    }

    /**
     * @param array<int, array{title:string, rows:array}> $rooms
     */
    public static function build_block_markup(array $rooms): string
    {
        return implode("\n\n", array_map([self::class, 'room_markup'], $rooms));
    }

    private static function room_markup(array $room): string
    {
        $rows = implode("\n\n", array_map([self::class, 'row_markup'], $room['rows'] ?? []));
        return self::block_markup('restart-registry/favorites-room', ['title' => $room['title'] ?? ''], $rows);
    }

    private static function row_markup(array $row): string
    {
        $items = implode("\n\n", array_map([self::class, 'item_markup'], $row['items'] ?? []));
        return self::block_markup('restart-registry/favorites-row', ['title' => $row['title'] ?? ''], $items);
    }

    private static function item_markup(array $item): string
    {
        return self::block_markup('restart-registry/favorites-item', $item);
    }

    private static function block_markup(string $name, array $attrs, string $inner = ''): string
    {
        $json = $attrs ? ' ' . wp_json_encode($attrs) : '';
        if ($inner === '') {
            return '<!-- wp:' . $name . $json . ' /-->';
        }
        return '<!-- wp:' . $name . $json . ' -->' . "\n" . $inner . "\n" . '<!-- /wp:' . $name . ' -->';
    }
}
