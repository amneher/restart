# Favorites Guide Builder — Design

## Problem

The "Our Favorites" page content is authored as hand-typed, nested shortcodes:

```
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
including raw image URLs. There is no visual builder — mismatched or malformed
nesting silently drops content, and adding an item means writing a
`[restart_item ...]` line from memory.

## Goal

A graphical, wp-admin-native way to build the room → row → item structure,
including WP Media Library image selection, with no hand-typed shortcode
syntax for new content going forward.

## Architecture

A new custom post type, `restart_favorites_guide` ("Favorites Guides"), stores
one guide's full room/row/item tree as a single JSON-shaped array in postmeta
(`_rr_favorites_guide_data`). The CPT's edit screen has no block editor
(`supports => ['title']` only); instead a custom meta box renders the
room/row/item builder in plain PHP + vanilla JS, matching every other admin
screen in this plugin (no Gutenberg/React build tooling exists here and this
design does not introduce one).

The existing `/our-favorites` Page keeps its current URL, template, and SEO
standing — its content becomes a single shortcode,
`[restart_favorites_guide id="123"]`, which looks up the referenced CPT post
and renders its structured data.

The four existing shortcodes (`restart_favorites_room`, `restart_favorites_row`,
`restart_favorites_filters`, `restart_item`) are **not removed** — some other
page could already use them directly. Their rendering logic is extracted into
shared, pure render functions that take plain arrays instead of
`shortcode_atts()` output; the old shortcode callbacks become thin wrappers
around those functions, and the new `restart_favorites_guide` shortcode calls
the same functions directly on the stored JSON. One render path, two entry
points.

## Data model

Stored as a PHP array in postmeta (WP handles array (de)serialization
automatically — no manual JSON encode/decode needed):

```php
[
  'rooms' => [
    [
      'title' => 'Living Room',
      'rows' => [
        [
          'title' => 'Sofa',
          'items' => [
            [
              'tier'        => 'save',   // save|spend|splurge, enum-constrained by a <select>
              'title'       => 'Budget Sofa',
              'price'       => '299.00',
              'images'      => ['https://.../sofa1.jpg'],  // always an array; carousel if >1
              'retailer'    => 'Example Shop',
              'description' => 'A solid starter sofa.',
              'url'         => 'https://example.com/budget-sofa',
              'notes'       => '',
              'quantity'    => '1',
            ],
            // ...more items (spend, splurge tiers) for this row
          ],
        ],
        // ...more rows
      ],
    ],
    // ...more rooms
  ],
]
```

Field names match `item_shortcode()`'s existing `shortcode_atts()` keys, so
`render_item()` needs no field-mapping logic — only a different input source
(array key lookup instead of `shortcode_atts()` output). The one behavior
change: `images` is always an array (the shortcode's comma-split-a-string
convention is not carried into the new data model — the media picker
naturally produces an array).

## Builder UI

New files, following the existing admin JS pattern (vanilla JS, no framework,
no build step):

- `admin/class-restart-registry-favorites-cpt.php` — registers the CPT
  (`show_in_menu` under the plugin's existing top-level admin menu),
  registers the meta box, and handles `save_post`: verify nonce → capability
  check (`edit_post`) → read one hidden JSON field → recursively sanitize →
  `update_post_meta`.
- `admin/partials/favorites-guide-builder.php` — the meta box template.
  Renders the current guide's rooms/rows/items as nested sections (room → row
  → item), each with its fields, a remove button, and ↑/↓ reorder buttons,
  plus "Add room"/"Add row"/"Add item" buttons at each level. Blank
  `<template>` tags hold the markup for a new room/row/item, cloned by JS —
  the same technique WordPress core uses for repeater-style meta boxes.
- `admin/js/restart-registry-favorites-builder.js` — handles add/remove/
  reorder clicks (clone/move/remove DOM nodes), wires each item's "Add image"
  button to `wp.media()` (WP's built-in media picker — already available in
  wp-admin, no new dependency, supports multi-select), disables an
  Add-row/Add-item button while that container's own title field is empty,
  and on form submit walks the DOM tree to build the JSON structure into one
  hidden `<input type="hidden" name="rr_favorites_guide_data">` field that
  `save_post` reads.

No drag-and-drop library, no AJAX autosave — a normal WP edit-screen save,
consistent with how every other admin screen in this plugin already works.
Reordering is up/down arrow buttons, not drag-and-drop (zero extra JS
surface, fully keyboard/screen-reader operable, same pattern the existing
custom-retailers admin table already uses).

## Validation & error handling

- `tier` is a `<select>` restricted to `save`/`spend`/`splurge` — the current
  silent "invalid tier → treated as blank" fallback in `item_shortcode()`
  becomes unreachable by construction in the builder, though the sanitize
  callback still defends against it for defense in depth.
- Room/row/item titles: an empty title is skipped at render time exactly like
  today (`if (empty($a['title'])) return '';`). The builder's JS additionally
  disables that level's own "Add" button while its title field is empty, so
  an editor gets immediate feedback instead of silently losing content on
  save.
- Images: the picker is wp.media-only (no free-text URL field), so the
  malformed/empty-URL class of bug mostly disappears by construction; the
  sanitize callback still runs `esc_url_raw()` and drops empties as a
  backstop.
- `save_post`: standard nonce check, capability check, recursive sanitize,
  `update_post_meta`. No partial/draft-guide concerns beyond normal WP post
  status (draft/publish already handled by the CPT).
- An empty guide (zero rooms) renders nothing, matching current empty-room/
  empty-row behavior.

## Migration

One WP-CLI command, `wp restart-registry migrate-favorites-page <page_id>`,
temporarily hooks the existing shortcode callbacks into a "capture" mode so
that running `do_shortcode()` on the page's actual current content builds the
JSON tree using WordPress's own shortcode parser (rather than reinventing
one) — reusable for any page still holding the old bracket syntax, not just
today's single `/our-favorites` page. It creates the new CPT post from the
captured JSON, then rewrites the source page's content to the single
`[restart_favorites_guide id="X"]`.

## Testing

- **PHP:** `render_item()` / `render_row()` / `render_room()` get direct unit
  tests on array input (simpler than today's `do_shortcode()`-nesting-based
  tests). The existing `FavoritesRowShortcodeTest.php` keeps passing
  unmodified since the old shortcode callbacks remain functional thin
  wrappers. New tests cover the sanitize callback and the
  `restart_favorites_guide` shortcode's CPT lookup (missing ID, non-existent
  post, empty guide).
- **JS (Jest/jsdom):** add/remove/reorder logic, hidden-field JSON
  serialization on submit, and `wp.media` integration (mocked the way WP core
  JS tests typically stub `wp.media`).
- **Migration command:** one integration test using a fixture matching
  `restart_favorites_shortcodes.txt`, asserting the resulting JSON tree and
  the rewritten page content.

## Out of scope

- Drag-and-drop reordering.
- A Gutenberg block / React-based builder.
- Removing the four legacy shortcodes or their `add_shortcode` registrations.
- Multi-guide-per-page embedding UI beyond the single `id` attribute (a page
  could technically place the shortcode more than once, but there's no
  guide-picker UI beyond typing the CPT's post ID).
