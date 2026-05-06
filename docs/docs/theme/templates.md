# Templates

The theme uses WordPress full-site editing (FSE). Templates are HTML files
under `templates/`, parts under `parts/`, and patterns under `patterns/` (PHP).

## Template hierarchy

WordPress's standard hierarchy applies. The theme registers custom templates
for each of the auth/account flows and for the `restart-registry` custom post
type.

| Template file | When WordPress picks it |
|---------------|--------------------------|
| `front-page.html` | Set as the static front page |
| `single-restart-registry.html` | Single CPT permalink (`/registry/<slug>/`) |
| `archive-restart-registry.html` | Archive of all public registries |
| `taxonomy-category.html` | Category archives |
| `category-articles.html`, `category-favorites.html`, `category-gifts.html` | Specific category archives |
| `single.html`, `single-category-articles.html`, `single-category-favorites.html`, `single-category-gifts.html` | Single posts |
| `page-login.html` | The page with slug `login` |
| `page-register.html` | The page with slug `register` |
| `page-my-account.html` | The page with slug `my-account` |
| `page-my-registries.html` | The page with slug `my-registries` |
| `page-start-a-registry.html` | The page with slug `start-a-registry` |
| `page-about-us.html` | The "About Us" page |
| `page-faq.html` | The "FAQ" page |
| `page.html` | Default fallback for any page |
| `index.html` | Default fallback for posts |
| `404.html` | Not-found page |

The `make seed` script creates each of those auth/account pages with the
right template assigned via `--page_template=...`.

## Template parts

`parts/` are reusable fragments that are rendered with
`<!-- wp:template-part {"slug":"<slug>"} /-->`:

| Part | Used in |
|------|---------|
| `header.html` | Every page (site logo + nav) |
| `footer.html` | Every page (footer columns + colophon) |
| `post-meta.html` | Single posts and category archives |
| `comments.html` | Single posts |

## Block patterns

`patterns/` are PHP files that register patterns via the
`/** * Title: ... * Slug: theRestart/... */` header block. They appear in
the editor's pattern picker.

| Pattern | Purpose |
|---------|---------|
| `call-to-action.php` | The recurring "Start your registry" CTA |
| `faq-content.php` | Accordion-style FAQ |
| `footer-default.php` | Default footer layout |
| `post-meta.php` | Post date + category meta block |
| `hidden-404.php` | 404 page body — used by `templates/404.html` |
| `hidden-comments.php` | Comments block — used by `parts/comments.html` |
| `hidden-heading.php` | Reusable hero heading |
| `hidden-no-results.php` | "No results found" block |

The `hidden-*` patterns have `inserter: false` in their headers — they are
used as building blocks for templates and not exposed in the editor's pattern
picker, but show up as full-fledged patterns in the block library.

## Editing templates

Two paths:

1. **Edit on disk** — change the HTML files in `templates/` or `parts/`.
   Mounted as a read-only volume into the container so changes appear on
   refresh.
2. **Edit in the WP admin** — the FSE site editor (Appearance → Editor).
   Changes are persisted as WordPress posts of type `wp_template` /
   `wp_template_part` and **shadow** the on-disk versions until you reset
   them. Useful for one-off content tweaks; not useful for shipping changes
   back into the codebase.

!!! warning "Editor changes shadow disk changes"
    If a template was edited in the admin, on-disk edits to the same template
    won't appear until you click "Clear customizations" in the editor. This
    catches everyone at least once.
