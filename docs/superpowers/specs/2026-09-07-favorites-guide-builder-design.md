# Favorites Guide Builder — Design

## Problem

The "Our Favorites" page content is authored as hand-typed, nested shortcodes:

```text
[restart_favorites_filters]
[restart_favorites_room title="Living Room"]
[restart_favorites_row title="Sofa"]
[restart_item tier="save" title="Budget Sofa" price="299.00" image="https://..." retailer="Example Shop" description="..." url="https://..."]
...
[/restart_favorites_row]
[/restart_favorites_room]
```

(See `restart_favorites_shortcodes.txt` for the full example.) Every room/row/item is
a separate bracket tag with positional attributes an editor must type by hand,
including raw image URLs.

The plugin already ships a graphical builder for these exact shortcodes —
`admin/js/restart-registry-tinymce.js` registers TinyMCE toolbar buttons
("Insert Item", "Insert Favorites Row", "Insert Favorites Room", "Insert
Favorites Filters") with modal forms, including a "fetch product info"
URL-scraper button. It is dead in practice: this site has no Classic Editor
plugin installed, and the `/our-favorites` page (post ID 52) is authored with
Gutenberg **Shortcode blocks** (`<!-- wp:shortcode -->`), which are plain
textareas — the TinyMCE toolbar these buttons attach to never renders there.

## Goal

A graphical, wp-admin-native way to build the room → row → item structure,
including WP Media Library image selection, reachable from the actual
authoring surface (the block editor) — not a second unreachable tool.

## Architecture

Four native Gutenberg blocks, registered in plain JS (the `wp.blocks` /
`wp.element` / `wp.blockEditor` globals WordPress already loads in
wp-admin — no React/JSX build pipeline, no bundler, consistent with the rest
of this codebase's vanilla-JS admin/public scripts):

- `restart-registry/favorites-room` — has `InnerBlocks` restricted via
  `allowedBlocks` to only `restart-registry/favorites-row`. Attributes: `title`.
- `restart-registry/favorites-row` — has `InnerBlocks` restricted to only
  `restart-registry/favorites-item`. Attributes: `title`.
- `restart-registry/favorites-item` — a leaf block (no InnerBlocks).
  Attributes: `tier` (enum via `SelectControl`: save/spend/splurge), `title`,
  `price`, `images` (array of URLs via the core `MediaUpload` component,
  multi-select), `retailer`, `description`, `url`, `notes`, `quantity`.
- `restart-registry/favorites-filters` — no attributes, no children; inserts
  the room/tier filter bar.

**No new CPT, no postmeta JSON blob, no embedding shortcode.** Content lives
directly in the Page's own `post_content` as native block markup — Gutenberg's
block-attributes/InnerBlocks serialization *is* the data model. The
`/our-favorites` page keeps its current URL, template, and SEO standing
because it's still a normal Page; only what's inside it changes, from
Shortcode blocks holding raw bracket text to these four native blocks.

Nesting is enforced by `allowedBlocks` (a Row block cannot be dropped inside
anything but a Room block; an Item block cannot be dropped inside anything
but a Row block) — this replaces the "mismatched nesting silently drops
content" failure mode entirely, by construction, with no custom validation
code. Reordering is the block editor's own native move-up/move-down controls
and drag handle — no custom UI. Images use the core `MediaUpload` component —
no custom `wp.media()` wiring.

The four existing shortcodes (`restart_favorites_room`, `restart_favorites_row`,
`restart_favorites_filters`, `restart_item`) are **not removed** — some other
page could already use them directly. Their rendering logic is extracted into
shared, pure render functions that take plain arrays instead of
`shortcode_atts()` output; the old shortcode callbacks become thin wrappers
around those functions. Each new block's server-side `render_callback` calls
the *same* shared functions, fed by the block's attributes (for the leaf
Item block) or its parsed inner blocks (for Room/Row, which just need to
concatenate their children's rendered output inside the room/row wrapper
markup). One render path, two entry points (shortcodes and blocks).

The existing TinyMCE tool (`restart-registry-tinymce.js` and its
`mce_external_plugins`/`mce_buttons` filters in
`admin/class-restart-registry-admin.php`) is left in place, unmodified — it's
harmless dead code for editors not using a Classic block, and removing it is
out of scope for this feature.

## Data model

There is no separate data model to design — block attributes given to
`registerBlockType()` are the schema, and WordPress persists them as
serialized block-comment markup in `post_content` automatically. The item
block's attribute list matches `item_shortcode()`'s existing
`shortcode_atts()` keys directly, so `render_item()` needs no field-mapping
logic — only a different input source (block attributes instead of
`shortcode_atts()` output). The one behavior change: `images` is a native
array attribute (`type: 'array'`) fed by `MediaUpload`'s multi-select result,
not the shortcode's comma-split-a-string convention.

## Block registration & rendering

New files:

- `public/blocks/favorites-item/block.json`, `.../favorites-row/block.json`,
  `.../favorites-room/block.json`, `.../favorites-filters/block.json` — each
  declares its attributes, `"apiVersion": 3`, and a `render.php` callback path
  (WordPress's built-in dynamic-block-from-`block.json` mechanism, no
  PHP-side `register_block_type()` boilerplate needed beyond a single
  `register_block_type(__DIR__ . '/blocks/favorites-item')` call per block in
  `class-restart-registry-public.php`'s init).
- `public/blocks/favorites-item/render.php` (and one per block) — each is a
  thin PHP file that reads `$attributes`/`$block->parsed_block['innerBlocks']`
  and calls the corresponding shared render function
  (`render_item()`/`render_row()`/`render_room()`), the same functions the
  legacy shortcode callbacks call.
- `public/blocks/favorites-item/index.js` (and one per block) — the block's
  `edit()` component: form controls (`TextControl`, `SelectControl`,
  `MediaUpload`) wired to `setAttributes()`, plus `InnerBlocks`/
  `useInnerBlocksProps` with `allowedBlocks` for Room and Row.
- `public/blocks/index.js` — one entry enqueued on the block editor
  (`enqueue_block_editor_assets`) that requires/registers all four blocks'
  `edit()` definitions; kept as a single small file rather than four separate
  enqueues, since they always load together.

## Backward compatibility

Existing shortcode callbacks (`item_shortcode()`, `favorites_row_shortcode()`,
`favorites_room_shortcode()`, `favorites_filters_shortcode()`) remain
registered via `add_shortcode()`, parse `$atts` via `shortcode_atts()` as
today, then call the shared render function. Any existing page still holding
raw shortcode text keeps working unmodified.

## Migration

`/our-favorites` (post 52) currently holds its content as Shortcode blocks.
One WP-CLI command, `wp restart-registry migrate-favorites-page <page_id>`,
converts that post's shortcode text into native block markup **in the same
post** (no CPT, no new post): it temporarily hooks the existing shortcode
callbacks into a "capture" mode so that running `do_shortcode()` on the
page's current content builds a nested array tree using WordPress's own
shortcode parser (rather than reinventing one), then walks that tree building
each level's block markup via `serialize_block()`, and updates the post's
`post_content` to the resulting block markup. Reusable for any other page
still holding the old bracket syntax, not just this one.

## Validation & error handling

- `tier` is a `SelectControl` restricted to `save`/`spend`/`splurge` in the
  block inspector — the current silent "invalid tier → treated as blank"
  fallback in `item_shortcode()` becomes unreachable by construction in the
  block UI, though the shortcode path (and the block's `render.php`, as
  defense in depth) still validates against `FAVORITES_TIERS`.
- Room/row/item titles: an empty title is skipped at render time exactly like
  today (`if (empty($a['title'])) return '';`) — applies identically whether
  the source was a shortcode attribute or a block attribute.
- Nesting mismatches (a Row block outside a Room, an Item outside a Row)
  cannot occur — `allowedBlocks` prevents the drop in the inserter/canvas
  entirely.
- Images: `MediaUpload` only returns real Media Library attachment URLs, so
  the malformed/empty-URL class of bug mostly disappears by construction;
  `render.php` still runs `esc_url()` on each image URL as a backstop.

## Testing

- **PHP:** `render_item()` / `render_row()` / `render_room()` get direct unit
  tests on array input (simpler than today's `do_shortcode()`-nesting-based
  tests). The existing `FavoritesRowShortcodeTest.php` keeps passing
  unmodified since the old shortcode callbacks remain functional thin
  wrappers. New tests cover each block's `render.php` (attributes in →
  expected HTML out) and the migration command (fixture matching
  `restart_favorites_shortcodes.txt`, asserting the resulting block markup).
- **JS (Jest/jsdom):** each block's `edit()` component — attribute changes
  from `TextControl`/`SelectControl` input, `MediaUpload` selection updating
  the `images` attribute (mocked the way WP core JS tests typically stub
  `wp.media`/`MediaUpload`), and `allowedBlocks` configuration on the Room/Row
  `InnerBlocks` registration.

## Out of scope

- Removing the four legacy shortcodes, their `add_shortcode` registrations,
  or the now-superseded TinyMCE tool (`restart-registry-tinymce.js`) — left
  in place as harmless dead code.
- A settings/admin-menu screen for this feature — editing happens directly
  on the Page in the normal block editor; no new wp-admin menu item.
- Any change to the public-facing rendered HTML/CSS/JS for the favorites
  page (the `render_*()` functions produce the same markup either entry
  point calls them from).
