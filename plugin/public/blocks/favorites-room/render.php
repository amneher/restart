<?php
/**
 * Server-side render for restart-registry/favorites-room.
 *
 * @var array  $attributes Block attributes: title.
 * @var string $content    Pre-rendered inner blocks (favorites-row children), provided by WordPress.
 */

echo Restart_Registry_Favorites_Renderer::render_room((string) ($attributes['title'] ?? ''), $content);
