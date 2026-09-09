<?php

/**
 * Pure rendering functions for the Our Favorites room/row/item structure.
 * Shared by the legacy [restart_favorites_room]/[restart_favorites_row]/
 * [restart_item]/[restart_favorites_filters] shortcodes (thin wrappers in
 * Restart_Registry_Public) and the restart-registry/favorites-* blocks'
 * render.php files. One render path, two entry points.
 */
class Restart_Registry_Favorites_Renderer
{
    public const TIERS = ['save', 'spend', 'splurge'];

    private static bool $quick_add_modals_printed = false;

    /**
     * @param array{tier?:string,title?:string,price?:string,images?:array,retailer?:string,description?:string,url?:string,notes?:string,quantity?:string} $item
     */
    public static function render_item(array $item): string
    {
        if (empty($item['title'])) {
            return '';
        }

        $tier = in_array($item['tier'] ?? '', self::TIERS, true) ? $item['tier'] : '';

        $images = array_values(array_filter((array) ($item['images'] ?? [])));

        // ── Image / carousel section ──────────────────────────────────────
        $media_html = '';
        if (count($images) === 1) {
            $media_html = '<div class="rr-article-item__media">'
                . '<img class="rr-article-item__img" src="' . esc_url($images[0]) . '" alt="' . esc_attr($item['title']) . '" loading="lazy">'
                . '</div>';
        } elseif (count($images) > 1) {
            $slides = '';
            $dots   = '';
            foreach ($images as $i => $src) {
                $active  = $i === 0 ? ' is-active' : '';
                $slides .= '<img class="rr-article-item__slide' . $active . '" src="' . esc_url($src) . '" alt="' . esc_attr($item['title']) . '" loading="lazy">';
                $dots   .= '<button type="button" class="rr-article-item__dot' . $active . '" aria-label="' . esc_attr(sprintf(__('Image %d', 'restart-registry'), $i + 1)) . '"></button>';
            }
            $media_html = '<div class="rr-article-item__media">'
                . '<div class="rr-article-item__carousel" data-count="' . count($images) . '">'
                . '<div class="rr-article-item__slides">' . $slides . '</div>'
                . '<button type="button" class="rr-article-item__prev" aria-label="' . esc_attr__('Previous image', 'restart-registry') . '">&#8249;</button>'
                . '<button type="button" class="rr-article-item__next" aria-label="' . esc_attr__('Next image', 'restart-registry') . '">&#8250;</button>'
                . '<div class="rr-article-item__dots">' . $dots . '</div>'
                . '</div>'
                . '</div>';
        }

        // ── Price ─────────────────────────────────────────────────────────
        $price_html = '';
        if (!empty($item['price'])) {
            $display    = str_starts_with(ltrim((string) $item['price']), '$') ? $item['price'] : '$' . $item['price'];
            $price_html = '<span class="rr-article-item__price">' . esc_html($display) . '</span>';
        }

        // ── Action buttons ────────────────────────────────────────────────
        $shop_btn = '';
        $add_btn  = '';
        if (!empty($item['url'])) {
            $aff = Restart_Registry_Affiliate_Converter::instance()->convert_url($item['url']);
            $shop_btn = '<a href="' . esc_url($aff['affiliate_url']) . '" class="rr-button rr-article-item__shop-btn" target="_blank" rel="noopener sponsored">'
                . esc_html__('Shop Now', 'restart-registry') . '</a>';

            $add_btn = '<button type="button" class="rr-button rr-button-secondary rr-quick-add"'
                . ' data-name="' . esc_attr($item['title']) . '"'
                . ' data-url="' . esc_attr($aff['affiliate_url']) . '"'
                . ' data-price="' . esc_attr(preg_replace('/[^0-9.]/', '', (string) ($item['price'] ?? ''))) . '"'
                . ' data-image-url="' . esc_attr($images[0] ?? '') . '"'
                . ' data-description="' . esc_attr($item['description'] ?? '') . '"'
                . ' data-notes="' . esc_attr($item['notes'] ?? '') . '"'
                . ' data-quantity="' . esc_attr($item['quantity'] ?? '1') . '"'
                . ($tier !== '' ? ' data-tier="' . esc_attr($tier) . '"' : '')
                . '>'
                . esc_html__('+ Add to My Registry', 'restart-registry')
                . '</button>';
        }

        $retailer_html = !empty($item['retailer'])
            ? '<span class="rr-article-item__retailer rr-item-retailer">' . esc_html($item['retailer']) . '</span>'
            : '';

        $desc_html = !empty($item['description'])
            ? '<p class="rr-article-item__description">' . esc_html($item['description']) . '</p>'
            : '';

        $disclosure = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $disc_html  = !empty($disclosure)
            ? '<p class="rr-affiliate-note"><small>' . esc_html($disclosure) . '</small></p>'
            : '';

        $tier_badge_html = $tier !== ''
            ? '<span class="rr-article-item__tier-badge rr-article-item__tier-badge--' . esc_attr($tier) . '">' . esc_html(ucfirst($tier)) . '</span>'
            : '';

        $card_class     = 'rr-article-item' . ($tier !== '' ? ' rr-article-item--tier' : '');
        $card_tier_attr = $tier !== '' ? ' data-tier="' . esc_attr($tier) . '"' : '';

        $html = '<div class="' . esc_attr($card_class) . '"' . $card_tier_attr . '>'
            . $tier_badge_html
            . $media_html
            . '<div class="rr-article-item__body">'
            . '<div class="rr-article-item__header">'
            . '<h3 class="rr-article-item__title">' . esc_html($item['title']) . '</h3>'
            . $retailer_html
            . '</div>'
            . $desc_html
            . '<div class="rr-article-item__footer">'
            . $price_html
            . '<div class="rr-article-item__actions">' . $shop_btn . $add_btn . '</div>'
            . $disc_html
            . '</div>'
            . '</div>'
            . '</div>';

        if (!self::$quick_add_modals_printed) {
            self::$quick_add_modals_printed = true;
            $html .= self::render_quick_add_modals();
        }

        return $html;
    }

    public static function render_row(string $title, ?string $content): string
    {
        if (empty($title)) {
            return '';
        }

        return '<div class="rr-favorites-row">'
            . '<h4 class="rr-favorites-row__title">' . esc_html($title) . '</h4>'
            . '<div class="rr-favorites-row__cards">' . (string) $content . '</div>'
            . '</div>';
    }

    public static function render_room(string $title, ?string $content): string
    {
        if (empty($title)) {
            return '';
        }

        $bulk_buttons = '';
        foreach (self::TIERS as $tier) {
            $bulk_buttons .= '<button type="button" class="rr-button rr-button-secondary rr-bulk-add" data-tier="' . esc_attr($tier) . '">'
                . esc_html(sprintf(__('Add all %s items', 'restart-registry'), ucfirst($tier)))
                . '</button>';
        }

        return '<section class="rr-favorites-room" data-room="' . esc_attr($title) . '">'
            . '<div class="rr-favorites-room__header">'
            . '<h3 class="rr-favorites-room__title">' . esc_html($title) . '</h3>'
            . '<div class="rr-favorites-room__bulk-actions">' . $bulk_buttons . '</div>'
            . '</div>'
            . '<div class="rr-favorites-room__rows">' . (string) $content . '</div>'
            . '</section>';
    }

    public static function render_filters(): string
    {
        $tier_pills = '';
        foreach (self::TIERS as $tier) {
            $tier_pills .= '<button type="button" class="rr-favorites-filters__pill is-active" data-tier-pill="' . esc_attr($tier) . '">'
                . esc_html(ucfirst($tier))
                . '</button>';
        }

        return '<div class="rr-favorites-filters">'
            . '<div class="rr-favorites-filters__group rr-favorites-filters__group--rooms" data-room-pills></div>'
            . '<div class="rr-favorites-filters__group rr-favorites-filters__group--tiers" data-tier-pills>' . $tier_pills . '</div>'
            . '</div>';
    }

    /**
     * Shared modals for the quick-add flow, printed once per page regardless
     * of whether items are rendered via shortcodes, blocks, or both.
     */
    private static function render_quick_add_modals(): string
    {
        ob_start();
?>

        <!-- Quick-add: auth modal (not logged in) -->
        <div class="rr-modal rr-quick-add-modal" id="rr-qa-auth-modal" aria-inert="true">
            <div class="rr-modal__backdrop"></div>
            <div class="rr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rr-qa-auth-title">
                <div class="rr-modal__header">
                    <h3 id="rr-qa-auth-title" class="rr-modal__title"><?php esc_html_e('Add to Your Registry', 'restart-registry'); ?></h3>
                    <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                </div>
                <div class="rr-modal__body">
                    <p class="rr-qa-modal__item-name"></p>
                    <p><?php esc_html_e('Sign in or create a free registry to save items you love.', 'restart-registry'); ?></p>
                    <div class="rr-modal__actions rr-qa-modal__actions">
                        <a id="rr-qa-login-link" href="<?php echo esc_url(wp_login_url()); ?>" class="rr-button"><?php esc_html_e('Sign In', 'restart-registry'); ?></a>
                        <a id="rr-qa-register-link" href="<?php echo esc_url(home_url('/start-a-registry/')); ?>" class="rr-button rr-button-secondary"><?php esc_html_e('Create a Registry', 'restart-registry'); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick-add: no-registry modal (logged in, no registry) -->
        <div class="rr-modal rr-quick-add-modal" id="rr-qa-no-registry-modal" aria-inert="true">
            <div class="rr-modal__backdrop"></div>
            <div class="rr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rr-qa-nr-title">
                <div class="rr-modal__header">
                    <h3 id="rr-qa-nr-title" class="rr-modal__title"><?php esc_html_e('Create a Registry First', 'restart-registry'); ?></h3>
                    <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                </div>
                <div class="rr-modal__body">
                    <p><?php esc_html_e("You don't have a registry yet. Start one — it only takes a minute.", 'restart-registry'); ?></p>
                    <div class="rr-modal__actions rr-qa-modal__actions">
                        <a href="<?php echo esc_url(home_url('/start-a-registry/')); ?>" class="rr-button"><?php esc_html_e('Create My Registry', 'restart-registry'); ?></a>
                        <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php esc_html_e('Maybe Later', 'restart-registry'); ?></button>
                    </div>
                </div>
            </div>
        </div>

<?php
        return (string) ob_get_clean();
    }
}
