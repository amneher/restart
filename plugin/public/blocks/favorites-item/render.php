<?php
/**
 * Server-side render for restart-registry/favorites-item.
 *
 * @var array $attributes Block attributes: tier, title, price, images, retailer, description, url, notes, quantity.
 */

echo Restart_Registry_Favorites_Renderer::render_item($attributes);
