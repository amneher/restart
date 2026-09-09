<?php
/**
 * Server-side render for restart-registry/favorites-row.
 *
 * @var array  $attributes Block attributes: title.
 * @var string $content    Pre-rendered inner blocks (favorites-item children), provided by WordPress.
 */

echo Restart_Registry_Favorites_Renderer::render_row((string) ($attributes['title'] ?? ''), $content);
