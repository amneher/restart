<?php

/**
 * Registers the restart-registry/favorites-* blocks (Room > Row > Item,
 * plus Filters) used on the Our Favorites page.
 */
class Restart_Registry_Favorites_Blocks
{
    public function register_blocks(): void
    {
        wp_register_script(
            'restart-registry-favorites-blocks',
            plugin_dir_url(__FILE__) . 'blocks/index.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            RESTART_REGISTRY_VERSION,
            true
        );

        wp_localize_script('restart-registry-favorites-blocks', 'restartRegistryFavoritesBlocks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('restart_registry_nonce'),
        ]);

        foreach (['favorites-item', 'favorites-row', 'favorites-room', 'favorites-filters'] as $block) {
            register_block_type(plugin_dir_path(__FILE__) . 'blocks/' . $block);
        }
    }
}
