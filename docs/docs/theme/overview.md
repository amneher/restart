# Theme Overview

`theRestart` is a WordPress block theme — full-site editing (FSE) — that
provides the public site chrome, the account/auth pages, and the
start-a-registry flow. It is the layer the user actually sees; the plugin
provides the registry data and the Lambda provides item data.

## At a glance

| | |
|--|--|
| **Slug** | `theRestart` |
| **Template engine** | WordPress block theme (FSE, `theme.json` v3) |
| **Version** | `1.0.1` |
| **PHP minimum** | 8.2 |

## Layout

```
theme/
├── style.css                # Theme header (Name, Version, ...)
├── theme.json               # Color palette, font sizes, spacing scale
├── functions.php            # Shortcodes, enqueues, AJAX handlers
├── templates/               # FSE templates (HTML)
│   ├── front-page.html
│   ├── single-restart-registry.html
│   ├── archive-restart-registry.html
│   ├── page-login.html
│   ├── page-register.html
│   ├── page-my-account.html
│   ├── page-my-registries.html
│   ├── page-start-a-registry.html
│   └── ...
├── parts/                   # Reusable template parts
│   ├── header.html
│   ├── footer.html
│   └── post-meta.html
├── patterns/                # PHP-defined block patterns
│   ├── call-to-action.php
│   ├── faq-content.php
│   ├── footer-default.php
│   └── ...
└── assets/
    ├── js/
    │   ├── auth.js              # Register form + profile edit
    │   ├── start-registry.js    # Create-registry form (talks to Lambda)
    │   └── contact-modal.js     # Site-wide contact modal
    ├── *.png, *.svg, *.jpg      # Brand assets
    └── copy/                    # Long-form copy snippets
```

## Design system (`theme.json`)

### Color palette

| Slug | Hex | Where it shows up |
|------|-----|-------------------|
| `primary` | `#47b4b0` | Buttons, accents — a calm teal |
| `dark` | `#193540` | Body text, headings on light backgrounds |
| `accent` | `#ebd060` | Highlight color (CTAs, badges) |
| `mid` | `#8a9ea0` | Muted text |
| `mint` | `#9fd4b3` | Page background — soft mint green |
| `green` | `#8ad2a0` | Secondary accent |
| `base` | `#ffffff` | Card surfaces, button text |

`color.custom = false` — only the palette colors are exposed in the editor,
keeping content authors on-brand.

### Typography

Three font families, all loaded from Google Fonts:

| Family | Slug | Used for |
|--------|------|----------|
| Libre Caslon Display | `libre-caslon-display` | h1 headlines |
| Libre Caslon Text | `libre-caslon-text` | Body |
| Montserrat | `montserrat` | h2–h4, buttons |

Loaded via `wp_enqueue_style('therestart-fonts', $fonts_url, [], null)` in
`functions.php`. The URL is the Google Fonts CSS endpoint:

```
https://fonts.googleapis.com/css2?family=Libre+Caslon+Display&family=Libre+Caslon+Text:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,500;0,700;0,900;1,400;1,500;1,700;1,900&display=swap
```

!!! tip "Privacy-friendly proxy"
    For production, consider routing the fonts through a privacy-friendly
    proxy like Bunny Fonts (`fonts.bunny.net`). The CSS URL is a single
    `wp_enqueue_style` call so the swap is one-line — point `$fonts_url` at
    the proxy.

### Layout

`contentSize: 780px`, `wideSize: 1200px`. Spacing scale uses `clamp()`-based
values from `x-small` to `x-large` so spacing scales smoothly between mobile
and desktop without media queries.

## Custom templates

The theme ships templates for the FSE template hierarchy:

| Template | Purpose |
|----------|---------|
| `front-page.html` | Home page (hero + features + CTA) |
| `single-restart-registry.html` | Single registry view (renders `[restart_registry]`) |
| `archive-restart-registry.html` | Registry archive (when public) |
| `page-login.html` | Login page (renders `[restart_login_form]`) |
| `page-register.html` | Register page (renders `[restart_register_form]`) |
| `page-my-account.html` | Account management (renders `[restart_my_account]`) |
| `page-my-registries.html` | List user's registries (renders `[restart_my_registries]`) |
| `page-start-a-registry.html` | Create flow (renders `[restart_start_registry]`) |
| `category-articles.html`, `category-favorites.html`, `category-gifts.html` | Blog categories |
| `single.html`, `single-category-*` | Single posts |
| `page.html`, `index.html`, `404.html` | Standard fallbacks |

## Patterns

`patterns/` contains PHP-defined block patterns that can be inserted in the
editor:

- `call-to-action.php` — The recurring "start your registry" CTA
- `faq-content.php` — Accordion FAQ content
- `footer-default.php` — Default site footer
- `hidden-*.php` — Hidden patterns used as building blocks for templates
  (404 layout, comments, headings, no-results)
- `post-meta.php` — Post metadata (date + categories)

## Where the theme talks to the rest of the system

- **The plugin:** by including the plugin's shortcodes inside FSE templates
  (e.g. `[restart_registry]` on `single-restart-registry.html`).
- **The Lambda:** via `assets/js/start-registry.js`, which posts directly to
  the Lambda using a per-user application password localized into the page.
  See [JavaScript](js.md).
