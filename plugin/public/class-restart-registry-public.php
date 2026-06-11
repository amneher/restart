<?php

/**
 * The public-facing functionality of the plugin.
 *
 * Renders registry shortcodes and handles AJAX actions.
 * Registry data lives in the restart-registry CPT; item data lives in Lambda.
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/public
 */

class Restart_Registry_Public
{

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    /** @var Restart_Registry_Controller */
    private $controller;

    /** @var string */
    public $disclosure = '';

    public function __construct(string $plugin_name, string $version)
    {
        $this->plugin_name = $plugin_name;
        $this->disclosure = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $this->version     = $version;

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-controller.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';
        $this->controller = new Restart_Registry_Controller();
        Restart_Registry_Affiliate_Converter::instance();

        add_shortcode('restart_registry',        [$this, 'registry_shortcode']);
        add_shortcode('restart_registry_view',   [$this, 'registry_view_shortcode']);
        add_shortcode('restart_registry_create', [$this, 'registry_create_shortcode']);
        add_shortcode('restart_item',            [$this, 'item_shortcode']);

        // Process [restart_item] before WordPress's make_clickable filter (priority 9)
        // and strip any HTML tags that Gutenberg or make_clickable may have injected
        // into attribute values (e.g. URLs auto-linked to <a href="url">url</a>).
        // strip_tags() on the raw shortcode string restores clean attribute values
        // before the shortcode parser sees them, regardless of when the corruption
        // occurred (save-time Gutenberg linkification or render-time make_clickable).
        add_filter('the_content', function (string $content): string {
            if (!str_contains($content, '[restart_item')) {
                return $content;
            }
            return preg_replace_callback(
                '/\[restart_item\b.*?\]/s',
                fn($m) => do_shortcode(strip_tags($m[0])),
                $content
            );
        }, 8);

        add_action('wp_ajax_restart_registry_add_item',              [$this, 'ajax_add_item']);
        add_action('wp_ajax_restart_registry_delete_item',           [$this, 'ajax_delete_item']);
        add_action('wp_ajax_restart_registry_update_item',           [$this, 'ajax_update_item']);
        add_action('wp_ajax_restart_registry_mark_purchased',        [$this, 'ajax_mark_purchased']);
        add_action('wp_ajax_nopriv_restart_registry_mark_purchased', [$this, 'ajax_mark_purchased']);
        add_action('wp_ajax_restart_registry_send_invite',           [$this, 'ajax_send_invite']);
        add_action('wp_ajax_restart_registry_remove_invitee',        [$this, 'ajax_remove_invitee']);
        add_action('wp_ajax_restart_registry_create',                [$this, 'ajax_create_registry']);
        add_action('wp_ajax_restart_registry_update',                [$this, 'ajax_update_registry']);
        add_action('wp_ajax_restart_registry_fetch_url',                    [$this, 'ajax_fetch_url']);
        add_action('wp_ajax_restart_registry_update_notification_prefs',   [$this, 'ajax_update_notification_prefs']);
        add_action('wp_ajax_restart_registry_archive',                     [$this, 'ajax_archive_registry']);
        add_action('wp_ajax_restart_registry_restore',                     [$this, 'ajax_restore_registry']);
        add_action('wp_ajax_restart_registry_delete',                      [$this, 'ajax_delete_registry']);
        add_action('wp_ajax_restart_registry_quick_add',        [$this, 'ajax_quick_add']);
        add_action('wp_ajax_nopriv_restart_registry_quick_add', [$this, 'ajax_quick_add']);
        add_action('wp_ajax_restart_registry_save_shipping_address',   [$this, 'ajax_save_shipping_address']);
        add_action('wp_ajax_restart_registry_delete_shipping_address', [$this, 'ajax_delete_shipping_address']);
        add_action('wp_ajax_restart_registry_update_purchase_message',        [$this, 'ajax_update_purchase_message']);
        add_action('wp_ajax_nopriv_restart_registry_update_purchase_message', [$this, 'ajax_update_purchase_message']);
    }

    // =========================================================================
    // Asset enqueuing
    // =========================================================================

    public function enqueue_styles(): void
    {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/restart-registry-public.css',
            [],
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts(): void
    {
        // Owner pages need wp.media for the hero-image picker. Loading on
        // every page is wasteful; load only when viewing a registry the user
        // can edit.
        if (
            is_singular('restart-registry') && is_user_logged_in()
            && $this->controller->can_edit_registry((int) get_the_ID(), get_current_user_id())
        ) {
            wp_enqueue_media();
        }

        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/restart-registry-public.js',
            [],
            $this->version,
            true
        );

        $user_id = get_current_user_id();
        wp_localize_script($this->plugin_name, 'restartRegistry', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('restart_registry_nonce'),
            'myRegistriesUrl'   => home_url('/my-registries/'),
            'isLoggedIn'        => is_user_logged_in(),
            'hasRegistry'       => $user_id ? !empty(get_posts([
                'post_type'      => 'restart-registry',
                'author'         => $user_id,
                'posts_per_page' => 1,
                'post_status'    => ['publish', 'private', 'draft'],
                'fields'         => 'ids',
            ])) : false,
            'loginUrl'          => wp_login_url(get_permalink() ?: home_url('/')),
            'createRegistryUrl' => home_url('/start-a-registry/'),
            'strings' => [
                'confirmDelete'   => __('Are you sure you want to remove this item?', 'restart-registry'),
                'confirmPurchase' => __('Mark this item as purchased?', 'restart-registry'),
                'loading'         => __('Loading…', 'restart-registry'),
                'error'           => __('An error occurred. Please try again.', 'restart-registry'),
                'prefsSaved'      => __('Preferences saved.', 'restart-registry'),
                'heroPickerTitle' => __('Choose a hero image', 'restart-registry'),
                'heroPickerCta'   => __('Use this image', 'restart-registry'),
                'addedToRegistry' => __('Added to your registry!', 'restart-registry'),
                'added'           => __('✓ Added!', 'restart-registry'),
            ],
        ]);
    }

    // =========================================================================
    // Shortcodes
    // =========================================================================

    /**
     * [restart_registry]
     *
     * • On a single restart-registry CPT page: owner sees manage view; guests see read view.
     * • With ?registry=<post_id|slug> query param: public/invitee read view.
     * • Otherwise: logged-in user sees their own manage view (or create form).
     */
    public function registry_shortcode($atts): string
    {
        // --- Single CPT page (e.g. /registry/johns-registry/) ---
        if (is_singular('restart-registry')) {
            $post_id = get_the_ID();
            $user_id = get_current_user_id();

            if ($this->controller->can_edit_registry($post_id, $user_id)) {
                $registry = $this->controller->get_registry($post_id);
                if (is_wp_error($registry)) {
                    return '<p class="rr-error">' . esc_html($registry->get_error_message()) . '</p>';
                }
                return $this->render_manage_registry($registry);
            }

            if ($this->controller->can_view_registry($post_id, $user_id)) {
                $registry = $this->controller->get_registry($post_id);
                if (is_wp_error($registry)) {
                    return '<p class="rr-error">' . esc_html($registry->get_error_message()) . '</p>';
                }
                return $this->render_registry_view_html($registry);
            }

            if (!is_user_logged_in()) {
                return $this->render_login_prompt();
            }

            return '<p class="rr-error">' . __('You do not have permission to view this registry.', 'restart-registry') . '</p>';
        }

        // --- ?registry=<key> share link ---
        if (isset($_GET['registry'])) {
            return $this->render_registry_view(sanitize_text_field($_GET['registry']));
        }

        // --- Logged-in user's own registry page ---
        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        $user_id  = get_current_user_id();
        $registry = $this->controller->get_user_registry($user_id);

        if (!$registry) {
            return $this->render_create_form();
        }

        return $this->render_manage_registry($registry);
    }

    /**
     * [restart_registry_view registry="<post_id|slug>"]
     */
    public function registry_view_shortcode($atts): string
    {
        $atts = shortcode_atts(['registry' => ''], $atts, 'restart_registry_view');
        $key  = !empty($atts['registry'])
            ? $atts['registry']
            : (isset($_GET['registry']) ? sanitize_text_field($_GET['registry']) : '');

        if (empty($key)) {
            return '<p class="rr-error">' . __('No registry specified.', 'restart-registry') . '</p>';
        }

        return $this->render_registry_view($key);
    }

    /**
     * [restart_registry_create]
     */
    public function registry_create_shortcode($atts): string
    {
        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        $user_id  = get_current_user_id();
        $registry = $this->controller->get_user_registry($user_id);

        if ($registry) {
            return '<p class="rr-notice">' .
                __('You already have a registry.', 'restart-registry') . ' ' .
                '<a href="' . esc_url($registry['permalink']) . '">' . __('View your registry', 'restart-registry') . '</a>' .
                '</p>';
        }

        return $this->render_create_form();
    }

    // =========================================================================
    // Private rendering helpers

    /**
     * Strip inter-tag whitespace so wpautop (which runs on the_content after
     * do_blocks/do_shortcode assembles the output) cannot inject <br> or <p>
     * tags between our table-row/table-cell elements.
     */
    private function compact_html(string $html): string
    {
        return preg_replace('/>\s+</', '><', $html);
    }
    // =========================================================================

    private function render_login_prompt(): string
    {
        ob_start();
?>
        <div class="rr-login-prompt">
            <h3><?php _e('Login Required', 'restart-registry'); ?></h3>
            <p><?php _e('You need to be logged in to create or manage a gift registry.', 'restart-registry'); ?></p>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="rr-button"><?php _e('Log In', 'restart-registry'); ?></a>
            <?php if (get_option('users_can_register')): ?>
                <a href="<?php echo esc_url(wp_registration_url()); ?>" class="rr-button rr-button-secondary"><?php _e('Register', 'restart-registry'); ?></a>
            <?php endif; ?>
        </div>
    <?php
        return $this->compact_html(ob_get_clean());
    }

    private function render_create_form(): string
    {
        ob_start();
    ?>
        <div class="rr-create-form">
            <h3><?php _e('Create Your Gift Registry', 'restart-registry'); ?></h3>
            <form id="rr-create-registry-form" class="rr-form">
                <div class="rr-form-group">
                    <label for="rr-registry-title"><?php _e('Registry Title', 'restart-registry'); ?></label>
                    <input type="text" id="rr-registry-title" name="title" required
                        placeholder="<?php esc_attr_e('e.g., Wedding Registry, Baby Shower', 'restart-registry'); ?>">
                </div>
                <div class="rr-form-group">
                    <label for="rr-registry-description"><?php _e('Description (optional)', 'restart-registry'); ?></label>
                    <textarea id="rr-registry-description" name="description" rows="3"
                        placeholder="<?php esc_attr_e('Tell your guests about this registry…', 'restart-registry'); ?>"></textarea>
                </div>
                <div class="rr-form-row">
                    <div class="rr-form-group">
                        <label for="rr-registry-event-type"><?php _e('Event Type (optional)', 'restart-registry'); ?></label>
                        <input type="text" id="rr-registry-event-type" name="event_type"
                            placeholder="<?php esc_attr_e('e.g., Wedding, Baby Shower, Birthday', 'restart-registry'); ?>">
                    </div>
                    <div class="rr-form-group">
                        <label for="rr-registry-event-date"><?php _e('Event Date (optional)', 'restart-registry'); ?></label>
                        <input type="date" id="rr-registry-event-date" name="event_date">
                    </div>
                </div>
                <div class="rr-form-group">
                    <label>
                        <input type="checkbox" name="is_public" value="1">
                        <?php _e('Make this registry public', 'restart-registry'); ?>
                    </label>
                </div>
                <button type="submit" class="rr-button"><?php _e('Create Registry', 'restart-registry'); ?></button>
            </form>
        </div>
    <?php
        return $this->compact_html(ob_get_clean());
    }

    private function render_manage_registry(array $registry): string
    {
        $disclosure = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $event_type = $registry['meta']['event_type'] ?? '';
        $event_date = $registry['meta']['event_date'] ?? '';
        $hero_url   = get_the_post_thumbnail_url($registry['id'], 'large');

        ob_start();
    ?>
        <div class="rr-manage-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">

            <!-- Toolbar -->
            <div class="rr-toolbar">
                <label class="rr-toggle" title="<?php esc_attr_e('Toggle public / private', 'restart-registry'); ?>">
                    <input type="checkbox" id="rr-public-toggle" <?php checked($registry['is_public']); ?>>
                    <span class="rr-toggle__slider"></span>
                    <span class="rr-toggle__label" data-on="<?php esc_attr_e('Public', 'restart-registry'); ?>" data-off="<?php esc_attr_e('Private', 'restart-registry'); ?>"><?php echo $registry['is_public'] ? esc_html__('Public', 'restart-registry') : esc_html__('Private', 'restart-registry'); ?></span>
                    <button type="button" class="rr-toggle-help" id="rr-public-help-toggle" aria-label="<?php esc_attr_e('What does Public mean?', 'restart-registry'); ?>" title="<?php esc_attr_e('What does Public mean?', 'restart-registry'); ?>">&#9432;</button>
                </label>
                <button type="button" class="rr-btn-ghost" id="rr-share-toggle">&#8679; <?php _e('Share', 'restart-registry'); ?></button>
                <button type="button" class="rr-btn-ghost" id="rr-edit-registry">&#9881; <?php _e('Settings', 'restart-registry'); ?></button>
            </div>

            <!-- Header: title + event meta -->
            <div class="rr-registry-header">
                <h1 class="rr-registry-title"><?php echo esc_html($registry['title']); ?></h1>
                <?php
                $is_for_self    = (bool) ($registry['meta']['is_for_self'] ?? true);
                $recipient_name = $registry['meta']['recipient_name'] ?? '';
                $recipient_rel  = $registry['meta']['recipient_relationship'] ?? '';
                if (!$is_for_self && $recipient_name):
                ?>
                    <p class="rr-recipient">
                        <?php echo wp_kses_post(sprintf(
                            __('For <strong>%1$s</strong>%2$s', 'restart-registry'),
                            esc_html($recipient_name),
                            $recipient_rel ? ' (' . esc_html($recipient_rel) . ')' : ''
                        )); ?>
                    </p>
                <?php endif; ?>
                <?php if ($event_type || $event_date): ?>
                    <p class="rr-event-meta">
                        <?php if ($event_type): ?>
                            <span class="rr-event-meta__group">
                                <span class="rr-event-meta__label"><?php esc_html_e('Event:', 'restart-registry'); ?></span>
                                <span class="rr-event-type"><?php echo esc_html($event_type); ?></span>
                            </span>
                        <?php endif; ?>
                        <?php if ($event_date): ?>
                            <span class="rr-event-meta__group">
                                <span class="rr-event-meta__label"><?php esc_html_e('Date:', 'restart-registry'); ?></span>
                                <span class="rr-event-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($event_date))); ?></span>
                            </span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($hero_url): ?>
                <div class="rr-registry-hero">
                    <img src="<?php echo esc_url($hero_url); ?>"
                        alt="<?php echo esc_attr($registry['title']); ?>"
                        loading="lazy">
                </div>
            <?php endif; ?>

            <section class="rr-story">
                <h2 class="rr-story__heading"><?php _e('My Story', 'restart-registry'); ?></h2>
                <?php if (!empty($registry['description'])): ?>
                    <p class="rr-story__text"><?php echo nl2br(esc_html($registry['description'])); ?></p>
                <?php else: ?>
                    <p class="rr-story__text rr-story__text--placeholder"><?php esc_html_e('Tell visitors why this registry matters to you. Open Settings to add your story.', 'restart-registry'); ?></p>
                <?php endif; ?>
            </section>

            <hr class="rr-divider">

            <!-- Items section -->
            <div class="rr-items-section">
                <div class="rr-items-header">
                    <span class="rr-items-heading"><?php _e('My Items', 'restart-registry'); ?> <span class="rr-item-count">(<?php echo count($registry['items']); ?>)</span></span>
                    <button type="button" class="rr-btn-add" id="rr-add-item-toggle">+ <?php _e('Add Item', 'restart-registry'); ?></button>
                </div>

                <!-- Add item form (hidden) -->
                <div id="rr-add-item-panel" class="rr-add-item-panel" style="display:none">
                    <form id="rr-add-item-form">
                        <div class="rr-add-item-url-row">
                            <input type="url" id="rr-item-url" name="url" required
                                placeholder="<?php esc_attr_e('Paste a product link…', 'restart-registry'); ?>">
                            <button type="button" id="rr-fetch-url" class="rr-btn-ghost"><?php _e('Fetch', 'restart-registry'); ?></button>
                        </div>
                        <div class="rr-add-item-fields">
                            <input type="text" id="rr-item-name" name="name" required
                                placeholder="<?php esc_attr_e('Item name', 'restart-registry'); ?>">
                            <input type="number" id="rr-item-quantity" name="quantity" min="1" value="1"
                                title="<?php esc_attr_e('Quantity', 'restart-registry'); ?>">
                            <input type="number" id="rr-item-price" name="price" step="0.01" min="0.01"
                                placeholder="<?php esc_attr_e('Price', 'restart-registry'); ?>">
                            <input type="text" id="rr-item-notes" name="notes"
                                placeholder="<?php esc_attr_e('Notes (optional)', 'restart-registry'); ?>">
                            <input type="hidden" id="rr-item-description" name="description">
                            <input type="hidden" id="rr-item-image-url" name="image_url">
                            <button type="submit" class="rr-button"><?php _e('Add', 'restart-registry'); ?></button>
                            <button type="button" class="rr-btn-ghost" id="rr-add-item-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                        </div>
                    </form>
                </div>

                <!-- Items table -->
                <div class="rr-items-table">
                    <div class="rr-items-table__head" aria-hidden="true">
                        <span class="rr-col-thumb"></span>
                        <span class="rr-col-item"><?php _e('Item', 'restart-registry'); ?></span>
                        <span class="rr-col-qty"><?php _e('Qty Desired', 'restart-registry'); ?></span>
                        <span class="rr-col-fulfilled"><?php _e('Fulfilled', 'restart-registry'); ?></span>
                        <span class="rr-col-actions"></span>
                    </div>
                    <div id="rr-items-container">
                        <?php if (!empty($registry['items'])): ?>
                            <ul class="rr-item-list">
                                <?php foreach ($registry['items'] as $item): ?>
                                    <?php echo $this->render_item_row($item); ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (empty($registry['items'])): ?>
                    <p class="rr-no-items"><?php _e('No items yet — add something you need to restart.', 'restart-registry'); ?></p>
                <?php endif; ?>

                <?php if (!empty($disclosure)): ?>
                    <p class="rr-affiliate-note"><small><?php echo esc_html($disclosure); ?></small></p>
                <?php endif; ?>
            </div>

            <?php
            $purchase_messages = $this->controller->get_purchase_messages($registry['id']);
            if (!empty($purchase_messages)):
            ?>
                <div class="rr-message-board">
                    <h2 class="rr-message-board__title"><?php _e('Messages', 'restart-registry'); ?></h2>
                    <ul class="rr-message-board__list">
                        <?php foreach ($purchase_messages as $msg): ?>
                            <li class="rr-message-card"
                                data-message-id="<?php echo esc_attr($msg['id'] ?? ''); ?>"
                                data-registry-id="<?php echo esc_attr($registry['id']); ?>">
                                <?php if (!empty($msg['item_image_url'])): ?>
                                    <div class="rr-message-card__thumb">
                                        <img src="<?php echo esc_url($msg['item_image_url']); ?>"
                                            alt="<?php echo esc_attr($msg['item_name']); ?>"
                                            loading="lazy">
                                    </div>
                                <?php endif; ?>
                                <div class="rr-message-card__body">
                                    <p class="rr-message-card__item-name"><?php echo esc_html($msg['item_name']); ?></p>
                                    <?php if (!empty($msg['item_description'])): ?>
                                        <p class="rr-message-card__item-desc"><?php echo esc_html($msg['item_description']); ?></p>
                                    <?php endif; ?>
                                    <blockquote class="rr-message-card__note"><?php echo esc_html($msg['purchaser_note']); ?></blockquote>
                                    <p class="rr-message-card__meta">
                                        <span class="rr-message-card__from">
                                            <?php echo esc_html($msg['purchaser_name'] ?: __('Someone', 'restart-registry')); ?>
                                        </span>
                                        <span class="rr-message-card__date">
                                            <?php echo esc_html(date_i18n(get_option('date_format'), $msg['timestamp'])); ?>
                                        </span>
                                    </p>
                                    <?php if (!empty($msg['id'])): ?>
                                    <div class="rr-message-card__edit-area">
                                        <button type="button" class="rr-btn rr-btn--ghost rr-message-card__edit-btn"
                                            data-message-id="<?php echo esc_attr($msg['id']); ?>"
                                            aria-expanded="false">
                                            <?php _e('Edit note', 'restart-registry'); ?>
                                        </button>
                                        <form class="rr-message-card__edit-form" hidden>
                                            <textarea class="rr-message-card__edit-textarea" rows="3"
                                                spellcheck="true"><?php echo esc_textarea($msg['purchaser_note']); ?></textarea>
                                            <div class="rr-message-card__edit-actions">
                                                <button type="submit" class="rr-btn rr-btn--primary rr-btn--sm"><?php _e('Save', 'restart-registry'); ?></button>
                                                <button type="button" class="rr-btn rr-btn--ghost rr-btn--sm rr-message-card__edit-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                                            </div>
                                            <p class="rr-notice rr-message-card__edit-notice" hidden></p>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Public-toggle help modal -->
            <div class="rr-modal" id="rr-public-help-modal" aria-inert="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-public-help-modal-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-public-help-modal-title"><?php _e('Public vs. private', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <p><strong><?php _e('Private (default)', 'restart-registry'); ?></strong></p>
                        <p><?php _e('Only people you invite can view the registry. Use Share to send a private invitation by email or username — invitees see the registry when they sign in.', 'restart-registry'); ?></p>
                        <p><strong><?php _e('Public', 'restart-registry'); ?></strong></p>
                        <p><?php _e('Anyone with the link can view the registry, no sign-in required. Your registry may also appear in the Find a Registry listing if your account allows it. Your email and account details are never shown — only the registry title, story, and items.', 'restart-registry'); ?></p>
                        <p><?php _e('You can switch between public and private at any time.', 'restart-registry'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Share modal -->
            <div class="rr-modal" id="rr-share-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-share-modal-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-share-modal-title"><?php _e('Share Your Registry', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <p class="rr-modal__hint"><?php _e('Share this link with friends and family:', 'restart-registry'); ?></p>
                        <div class="rr-share-link">
                            <input type="text" readonly id="rr-share-url" value="<?php echo esc_url($registry['permalink']); ?>">
                            <button type="button" class="rr-button rr-button-small" id="rr-copy-link"><?php _e('Copy', 'restart-registry'); ?></button>
                        </div>
                        <div class="rr-modal__divider"></div>
                        <p class="rr-modal__hint"><?php _e('Or send a private invitation:', 'restart-registry'); ?></p>
                        <form id="rr-send-invite-form" class="rr-invite-form__row">
                            <input type="text" name="invitee" placeholder="<?php esc_attr_e('Email or username…', 'restart-registry'); ?>" required>
                            <button type="submit" class="rr-button rr-button-small"><?php _e('Send Invite', 'restart-registry'); ?></button>
                        </form>

                        <?php
                        $invitees = $this->controller->get_registry_invites($registry['id']);
                        ?>
                        <div class="rr-invitees" id="rr-invitees-section" data-empty-text="<?php esc_attr_e('No one invited yet.', 'restart-registry'); ?>">
                            <h4 class="rr-invitees__heading"><?php _e('Manage invitees', 'restart-registry'); ?></h4>
                            <ul class="rr-invitees__list" id="rr-invitees-list">
                                <?php if (empty($invitees)): ?>
                                    <li class="rr-invitees__empty"><?php esc_html_e('No one invited yet.', 'restart-registry'); ?></li>
                                <?php else: ?>
                                    <?php foreach ($invitees as $row): ?>
                                        <li class="rr-invitees__item" data-invitee="<?php echo esc_attr($row['email']); ?>">
                                            <span class="rr-invitees__email"><?php echo esc_html($row['email']); ?></span>
                                            <button type="button" class="rr-btn-icon rr-btn-icon--danger rr-remove-invitee" title="<?php esc_attr_e('Remove invitee', 'restart-registry'); ?>" aria-label="<?php echo esc_attr(sprintf(__('Remove %s', 'restart-registry'), $row['email'])); ?>">&#10005;</button>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Item edit modal -->
            <div class="rr-modal" id="rr-item-edit-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-item-edit-modal-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-item-edit-modal-title"><?php _e('Edit Item', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <form id="rr-edit-item-form" class="rr-form">
                            <input type="hidden" name="item_id" id="rr-edit-item-id">
                            <div class="rr-form-group">
                                <label for="rr-edit-item-name"><?php _e('Name', 'restart-registry'); ?></label>
                                <input type="text" id="rr-edit-item-name" name="name" required>
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-edit-item-url"><?php _e('URL', 'restart-registry'); ?></label>
                                <input type="url" id="rr-edit-item-url" name="url" required>
                            </div>
                            <div class="rr-form-row">
                                <div class="rr-form-group">
                                    <label for="rr-edit-item-price"><?php _e('Price', 'restart-registry'); ?></label>
                                    <input type="number" id="rr-edit-item-price" name="price" step="0.01" min="0.01"
                                        placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>">
                                </div>
                                <div class="rr-form-group">
                                    <label for="rr-edit-item-quantity"><?php _e('Quantity needed', 'restart-registry'); ?></label>
                                    <input type="number" id="rr-edit-item-quantity" name="quantity" min="1" value="1">
                                </div>
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-edit-item-notes"><?php _e('Notes', 'restart-registry'); ?></label>
                                <input type="text" id="rr-edit-item-notes" name="notes"
                                    placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>">
                            </div>
                            <input type="hidden" id="rr-edit-item-image-url" name="image_url">
                            <div class="rr-form-group rr-form-group--checkbox">
                                <label for="rr-edit-item-fulfilled">
                                    <input type="checkbox" id="rr-edit-item-fulfilled" name="mark_fulfilled" value="1">
                                    <?php _e('Mark as fulfilled (no more needed)', 'restart-registry'); ?>
                                </label>
                            </div>
                            <div class="rr-form-actions">
                                <button type="submit" class="rr-button"><?php _e('Save Changes', 'restart-registry'); ?></button>
                                <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Settings modal -->
            <div class="rr-modal" id="rr-settings-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-settings-modal-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-settings-modal-title"><?php _e('Registry Settings', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <?php
                        $is_for_self            = (bool) ($registry['meta']['is_for_self'] ?? true);
                        $recipient_name         = $registry['meta']['recipient_name'] ?? '';
                        $recipient_relationship = $registry['meta']['recipient_relationship'] ?? '';
                        $recipient_email        = $registry['meta']['recipient_email'] ?? '';
                        $thumbnail_id           = (int) get_post_thumbnail_id($registry['id']);
                        $thumbnail_url          = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : '';
                        ?>
                        <form id="rr-edit-registry-form" class="rr-form">
                            <div class="rr-form-group">
                                <label for="rr-edit-title"><?php _e('Title', 'restart-registry'); ?></label>
                                <input type="text" id="rr-edit-title" name="title"
                                    value="<?php echo esc_attr($registry['title']); ?>" required>
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-edit-description"><?php _e('Description', 'restart-registry'); ?></label>
                                <textarea id="rr-edit-description" name="description" rows="3"><?php echo esc_textarea($registry['description']); ?></textarea>
                            </div>
                            <div class="rr-form-row">
                                <div class="rr-form-group">
                                    <label for="rr-edit-event-type"><?php _e('Event Type', 'restart-registry'); ?></label>
                                    <input type="text" id="rr-edit-event-type" name="event_type"
                                        value="<?php echo esc_attr($event_type); ?>"
                                        placeholder="<?php esc_attr_e('e.g., Divorce, Fresh Start', 'restart-registry'); ?>">
                                </div>
                                <div class="rr-form-group">
                                    <label for="rr-edit-event-date"><?php _e('Event Date', 'restart-registry'); ?></label>
                                    <input type="date" id="rr-edit-event-date" name="event_date"
                                        value="<?php echo esc_attr($event_date); ?>">
                                </div>
                            </div>

                            <!-- Hero image picker — opens the WP media library; size cap enforced server-side. -->
                            <div class="rr-form-group">
                                <label><?php _e('Hero image', 'restart-registry'); ?></label>
                                <div class="rr-hero-picker">
                                    <div class="rr-hero-picker__preview <?php echo $thumbnail_url ? '' : 'is-empty'; ?>" id="rr-hero-preview">
                                        <?php if ($thumbnail_url): ?>
                                            <img src="<?php echo esc_url($thumbnail_url); ?>" alt="">
                                        <?php else: ?>
                                            <span class="rr-hero-picker__empty"><?php esc_html_e('No image set', 'restart-registry'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rr-hero-picker__actions">
                                        <button type="button" class="rr-btn-ghost" id="rr-hero-pick"><?php esc_html_e('Choose image', 'restart-registry'); ?></button>
                                        <button type="button" class="rr-btn-ghost rr-btn-icon--danger" id="rr-hero-clear" <?php echo $thumbnail_id ? '' : 'hidden'; ?>><?php esc_html_e('Remove', 'restart-registry'); ?></button>
                                    </div>
                                    <input type="hidden" id="rr-edit-hero-image-id" name="hero_image_id" value="<?php echo esc_attr($thumbnail_id ?: ''); ?>">
                                </div>
                            </div>

                            <!-- Recipient fieldset — collapsed when is_for_self=true. -->
                            <div class="rr-form-group">
                                <label class="rr-checkbox-label">
                                    <input type="checkbox" id="rr-edit-not-for-self" name="is_for_self_neg" value="1" <?php checked(!$is_for_self); ?>>
                                    <?php _e('This registry is for someone else', 'restart-registry'); ?>
                                </label>
                                <input type="hidden" id="rr-edit-is-for-self" name="is_for_self" value="<?php echo $is_for_self ? '1' : '0'; ?>">
                            </div>
                            <div class="rr-recipient-fields" id="rr-edit-recipient-fields" <?php echo $is_for_self ? 'hidden' : ''; ?>>
                                <div class="rr-form-row">
                                    <div class="rr-form-group">
                                        <label for="rr-edit-recipient-name"><?php _e('Recipient name', 'restart-registry'); ?></label>
                                        <input type="text" id="rr-edit-recipient-name" name="recipient_name"
                                            value="<?php echo esc_attr($recipient_name); ?>"
                                            placeholder="<?php esc_attr_e('e.g., Sarah', 'restart-registry'); ?>">
                                    </div>
                                    <div class="rr-form-group">
                                        <label for="rr-edit-recipient-relationship"><?php _e('Your relationship', 'restart-registry'); ?></label>
                                        <input type="text" id="rr-edit-recipient-relationship" name="recipient_relationship"
                                            value="<?php echo esc_attr($recipient_relationship); ?>"
                                            placeholder="<?php esc_attr_e('e.g., my sister', 'restart-registry'); ?>">
                                    </div>
                                </div>
                                <div class="rr-form-group">
                                    <label for="rr-edit-recipient-email"><?php _e('Recipient email (optional)', 'restart-registry'); ?></label>
                                    <input type="email" id="rr-edit-recipient-email" name="recipient_email"
                                        value="<?php echo esc_attr($recipient_email); ?>"
                                        placeholder="<?php esc_attr_e('So they can claim the registry later', 'restart-registry'); ?>">
                                </div>
                            </div>

                            <div class="rr-form-group">
                                <label class="rr-checkbox-label">
                                    <input type="checkbox" name="is_public" value="1" <?php checked($registry['is_public']); ?>>
                                    <?php _e('Make this registry public', 'restart-registry'); ?>
                                </label>
                            </div>
                            <div class="rr-form-actions">
                                <button type="submit" class="rr-button"><?php _e('Save Changes', 'restart-registry'); ?></button>
                                <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                            </div>
                        </form>
                        <?php
                        $shipping_address = $this->controller->get_shipping_address($registry['id']);
                        $has_address      = !empty($shipping_address);
                        ?>
                        <div class="rr-modal__divider"></div>
                        <div class="rr-settings-section" id="rr-address-section">
                            <h4 class="rr-settings-section__title"><?php _e('Shipping Address', 'restart-registry'); ?></h4>
                            <p class="rr-settings-section__hint"><?php _e('Save a shipping address so gift-givers can copy it before they check out. Only visible to you and people you invite.', 'restart-registry'); ?></p>
                            <form id="rr-address-form" class="rr-form">
                                <div class="rr-form-group">
                                    <label for="rr-shipping-name"><?php _e('Recipient name', 'restart-registry'); ?></label>
                                    <input type="text" id="rr-shipping-name" name="shipping_name"
                                        value="<?php echo esc_attr($shipping_address['name'] ?? ''); ?>"
                                        placeholder="<?php esc_attr_e('e.g., Alex Rivera', 'restart-registry'); ?>">
                                </div>
                                <div class="rr-form-group">
                                    <label for="rr-shipping-address-1"><?php _e('Address line 1', 'restart-registry'); ?></label>
                                    <input type="text" id="rr-shipping-address-1" name="address_1"
                                        value="<?php echo esc_attr($shipping_address['address_1'] ?? ''); ?>"
                                        placeholder="<?php esc_attr_e('Street address', 'restart-registry'); ?>">
                                </div>
                                <div class="rr-form-group">
                                    <label for="rr-shipping-address-2"><?php _e('Address line 2', 'restart-registry'); ?> <span class="rr-optional"><?php _e('(optional)', 'restart-registry'); ?></span></label>
                                    <input type="text" id="rr-shipping-address-2" name="address_2"
                                        value="<?php echo esc_attr($shipping_address['address_2'] ?? ''); ?>"
                                        placeholder="<?php esc_attr_e('Apt, suite, unit…', 'restart-registry'); ?>">
                                </div>
                                <div class="rr-form-row">
                                    <div class="rr-form-group">
                                        <label for="rr-shipping-city"><?php _e('City', 'restart-registry'); ?></label>
                                        <input type="text" id="rr-shipping-city" name="city"
                                            value="<?php echo esc_attr($shipping_address['city'] ?? ''); ?>">
                                    </div>
                                    <div class="rr-form-group">
                                        <label for="rr-shipping-state"><?php _e('State / Province', 'restart-registry'); ?></label>
                                        <input type="text" id="rr-shipping-state" name="state"
                                            value="<?php echo esc_attr($shipping_address['state'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="rr-form-row">
                                    <div class="rr-form-group">
                                        <label for="rr-shipping-postal"><?php _e('Zip / Postal code', 'restart-registry'); ?></label>
                                        <input type="text" id="rr-shipping-postal" name="postal_code"
                                            value="<?php echo esc_attr($shipping_address['postal_code'] ?? ''); ?>">
                                    </div>
                                    <div class="rr-form-group">
                                        <label for="rr-shipping-country"><?php _e('Country', 'restart-registry'); ?></label>
                                        <input type="text" id="rr-shipping-country" name="country"
                                            value="<?php echo esc_attr($shipping_address['country'] ?? ''); ?>"
                                            placeholder="<?php esc_attr_e('e.g., US', 'restart-registry'); ?>">
                                    </div>
                                </div>
                                <div class="rr-form-actions">
                                    <button type="submit" class="rr-button rr-button-small" id="rr-save-address-btn">
                                        <?php echo $has_address ? esc_html__('Update Address', 'restart-registry') : esc_html__('Save Address', 'restart-registry'); ?>
                                    </button>
                                    <?php if ($has_address): ?>
                                        <button type="button" class="rr-btn-ghost rr-button-small" id="rr-remove-address"><?php _e('Remove', 'restart-registry'); ?></button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        <div class="rr-modal__divider"></div>
                        <div class="rr-settings-danger-zone">
                            <h4 class="rr-settings-danger-zone__title"><?php _e('Danger zone', 'restart-registry'); ?></h4>
                            <div class="rr-settings-danger-zone__actions">
                                <button type="button" class="rr-button rr-button-secondary" id="rr-archive-registry-btn">
                                    <?php _e('Archive Registry', 'restart-registry'); ?>
                                </button>
                                <button type="button" class="rr-button rr-button-danger" id="rr-delete-registry-btn">
                                    <?php _e('Delete Registry', 'restart-registry'); ?>
                                </button>
                            </div>
                            <p class="rr-settings-danger-zone__hint">
                                <?php _e('Archive hides your registry and preserves all your data. Delete permanently removes everything.', 'restart-registry'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Archive confirm modal -->
            <div class="rr-modal" id="rr-archive-confirm-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-archive-confirm-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-archive-confirm-title"><?php _e('Archive this registry?', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <p><?php _e('Your registry will be hidden from everyone, including people with your link. Your items, messages, and settings are all preserved — you can restore it at any time from My Account.', 'restart-registry'); ?></p>
                        <div class="rr-form-actions">
                            <button type="button" class="rr-button" id="rr-archive-confirm-btn"><?php _e('Archive Registry', 'restart-registry'); ?></button>
                            <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete confirm modal -->
            <div class="rr-modal" id="rr-delete-confirm-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-delete-confirm-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-delete-confirm-title"><?php _e('Delete this registry?', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <p class="rr-delete-warning"><?php _e('This cannot be undone. All items, messages, and data will be permanently removed.', 'restart-registry'); ?></p>
                        <label class="rr-checkbox-label rr-delete-confirm-check">
                            <input type="checkbox" id="rr-delete-understand">
                            <?php _e('I understand this is permanent and cannot be undone', 'restart-registry'); ?>
                        </label>
                        <div class="rr-form-actions">
                            <button type="button" class="rr-button rr-button-danger" id="rr-delete-confirm-btn" disabled>
                                <?php _e('Permanently Delete', 'restart-registry'); ?>
                            </button>
                            <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Item detail modal -->
            <div class="rr-modal" id="rr-item-detail-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-item-detail-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-item-detail-title" class="rr-item-detail__title"></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <div class="rr-item-detail__image-wrap" style="display:none">
                            <img class="rr-item-detail__image" src="" alt="" loading="lazy">
                        </div>
                        <div class="rr-item-detail__meta"></div>
                        <p class="rr-item-detail__description" style="display:none"></p>
                        <div class="rr-item-detail__qty-row"></div>
                        <div class="rr-item-detail__actions">
                            <a href="#" target="_blank" rel="noopener sponsored" class="rr-button rr-purchase-btn rr-item-detail__purchase-btn" style="display:none"><?php _e('Purchase', 'restart-registry'); ?></a>
                            <button type="button" class="rr-button rr-button-small rr-button-secondary rr-mark-purchased rr-item-detail__mark-btn" style="display:none"><?php _e('Mark Purchased', 'restart-registry'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mark as purchased modal -->
            <div class="rr-modal" id="rr-purchase-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-purchase-modal-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-purchase-modal-title"><?php _e('Mark as Purchased', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <p class="rr-purchase-modal__item-name"></p>
                        <p class="rr-purchase-modal__nudge"><?php _e('Record who purchased this item.', 'restart-registry'); ?></p>
                        <form id="rr-purchase-form" class="rr-form">
                            <input type="hidden" id="rr-purchase-item-id" name="item_id">
                            <div class="rr-form-group">
                                <label for="rr-purchaser-name"><?php _e('Purchased by', 'restart-registry'); ?> <span class="rr-optional"><?php _e('(optional)', 'restart-registry'); ?></span></label>
                                <input type="text" id="rr-purchaser-name" name="purchaser_name"
                                    placeholder="<?php esc_attr_e('e.g., Aunt Carol', 'restart-registry'); ?>">
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-purchaser-note"><?php _e('Note', 'restart-registry'); ?> <span class="rr-optional"><?php _e('(optional)', 'restart-registry'); ?></span></label>
                                <textarea id="rr-purchaser-note" name="purchaser_note" rows="3" spellcheck="true"
                                    placeholder="<?php esc_attr_e('Any notes about this purchase…', 'restart-registry'); ?>"></textarea>
                            </div>
                            <div class="rr-form-actions">
                                <button type="submit" class="rr-button"><?php _e('Confirm Purchase', 'restart-registry'); ?></button>
                                <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    <?php
        return $this->compact_html(ob_get_clean());
    }

    /**
     * Render a single item as a table row for the owner's manage view.
     */
    private function render_item_row(array $item): string
    {
        $qty_needed    = (int) ($item['quantity_needed']    ?? 1);
        $qty_purchased = (int) ($item['quantity_purchased'] ?? 0);
        $remaining     = $qty_needed - $qty_purchased;
        $is_fulfilled  = $remaining <= 0;
        $item_url      = !empty($item['affiliate_url']) ? $item['affiliate_url'] : ($item['url'] ?? '');

        $thumb = !empty($item['image_url'])
            ? '<img src="' . esc_url($item['image_url']) . '" alt="' . esc_attr($item['name']) . '" loading="lazy">'
            : '<span class="rr-item-row__thumb-placeholder" aria-hidden="true"></span>';

        $name_inner = '<button type="button" class="rr-item-name-btn">' . esc_html($item['name']) . '</button>';
        if (!empty($item['retailer'])) {
            $name_inner .= '<span class="rr-item-retailer">' . esc_html($item['retailer']) . '</span>';
        }
        if (!empty($item['notes'])) {
            $name_inner .= '<span class="rr-item-row__note">' . esc_html($item['notes']) . '</span>';
        }

        // Fulfilled column: shows the existing status (✓ or n/m) and a checkbox
        // for the owner to flip "no more needed" — clamps qty_needed to
        // qty_purchased server-side. Disabled until at least one purchase exists.
        $status_inner = $is_fulfilled
            ? '<span class="rr-fulfilled-check" title="' . esc_attr__('Fulfilled', 'restart-registry') . '">&#10003;</span>'
            : esc_html($qty_purchased . ' / ' . $qty_needed);
        $checkbox_disabled = (!$is_fulfilled && $qty_purchased < 1) ? ' disabled' : '';
        $checkbox_title    = $checkbox_disabled
            ? esc_attr__('At least one must be purchased before you can mark this fulfilled.', 'restart-registry')
            : esc_attr__('Mark as fulfilled (no more needed)', 'restart-registry');
        $fulfilled_inner = '<label class="rr-fulfilled-toggle" title="' . $checkbox_title . '">'
            . '<input type="checkbox" class="rr-mark-fulfilled" data-item-id="' . esc_attr($item['id']) . '"' . $checkbox_disabled . ($is_fulfilled ? ' checked' : '') . '>'
            . '<span class="rr-fulfilled-status">' . $status_inner . '</span>'
            . '</label>';

        return '<li class="rr-item-row ' . ($is_fulfilled ? 'rr-item-row--fulfilled' : '') . '"'
            . ' data-item-id="' . esc_attr($item['id']) . '"'
            . ' data-name="' . esc_attr($item['name']) . '"'
            . ' data-url="' . esc_attr($item['url'] ?? '') . '"'
            . ' data-description="' . esc_attr($item['description'] ?? '') . '"'
            . ' data-notes="' . esc_attr($item['notes'] ?? '') . '"'
            . ' data-price="' . esc_attr($item['price'] ?? '') . '"'
            . ' data-quantity="' . esc_attr($item['quantity_needed'] ?? 1) . '"'
            . ' data-image-url="' . esc_attr($item['image_url'] ?? '') . '"'
            . ' data-retailer="' . esc_attr($item['retailer'] ?? '') . '"'
            . ' data-affiliate-url="' . esc_attr($item_url) . '"'
            . ' data-qty-purchased="' . esc_attr($qty_purchased) . '">'
            . '<span class="rr-item-row__thumb">' . $thumb . '</span>'
            . '<span class="rr-item-row__name">' . $name_inner . '</span>'
            . '<span class="rr-item-row__qty-desired">' . esc_html($qty_needed) . '</span>'
            . '<span class="rr-item-row__fulfilled ' . ($is_fulfilled ? 'rr-item-row__fulfilled--done' : '') . '">' . $fulfilled_inner . '</span>'
            . '<span class="rr-item-row__actions">'
            . (!empty($item_url) ? '<a href="' . esc_url($item_url) . '" target="_blank" rel="noopener sponsored" class="rr-purchase-btn rr-button rr-button-small">' . esc_html__('Purchase', 'restart-registry') . '</a>' : '')
            . '<button type="button" class="rr-btn-icon rr-edit-item" title="' . esc_attr__('Edit', 'restart-registry') . '">&#9998;</button>'
            . '<button type="button" class="rr-btn-icon rr-btn-icon--danger rr-delete-item" title="' . esc_attr__('Remove', 'restart-registry') . '">&#10005;</button>'
            . '</span>'
            . '</li>';
    }

    /**
     * Look up a registry by share key (post ID or slug) and render the guest view.
     */
    private function render_registry_view(string $key): string
    {
        $registry = $this->controller->get_registry_by_share_key($key);
        if (is_wp_error($registry)) {
            if ($registry->get_error_code() === 'registry_archived') {
                return '<div class="rr-archived-notice">'
                    . '<p class="rr-archived-notice__message">' . esc_html__('This registry is no longer active.', 'restart-registry') . '</p>'
                    . '<p class="rr-archived-notice__hint">' . wp_kses_post(sprintf(
                        /* translators: %s = Find a Registry page URL */
                        __('Looking for a registry? You can search on the <a href="%s">Find a Registry</a> page.', 'restart-registry'),
                        esc_url(home_url('/find-a-registry/'))
                    )) . '</p>'
                    . '</div>';
            }
            return '<p class="rr-error">' . esc_html($registry->get_error_message()) . '</p>';
        }

        $user_id = get_current_user_id();
        if (!$this->controller->can_view_registry($registry['id'], $user_id ?: null)) {
            return is_user_logged_in()
                ? '<p class="rr-error">' . __('You do not have permission to view this registry.', 'restart-registry') . '</p>'
                : $this->render_login_prompt();
        }

        return $this->render_registry_view_html($registry);
    }

    private function render_registry_view_html(array $registry): string
    {
        $user         = get_userdata($registry['user_id']);
        $owner_name   = $user ? $user->display_name : __('Someone', 'restart-registry');
        $disclosure   = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $allow_guests = get_option('restart_registry_allow_guests', 1);
        $event_type   = $registry['meta']['event_type'] ?? '';
        $event_date   = $registry['meta']['event_date'] ?? '';
        $hero_url     = get_the_post_thumbnail_url($registry['id'], 'large');

        // Shipping address — only shown to authenticated invitees.
        $current_user_id  = get_current_user_id();
        $shipping_address = $this->controller->get_shipping_address($registry['id']);
        $invitees         = $registry['meta']['invitees'] ?? [];
        $viewer           = $current_user_id ? get_userdata($current_user_id) : null;
        $is_invitee       = $viewer && (
            in_array($viewer->user_email, $invitees, true) ||
            in_array($viewer->user_login, $invitees, true)
        );
        $show_address     = $shipping_address && $is_invitee;
        $address_formatted = $show_address ? implode(', ', array_filter([
            $shipping_address['name']        ?? '',
            $shipping_address['address_1']   ?? '',
            $shipping_address['address_2']   ?? '',
            $shipping_address['city']        ?? '',
            $shipping_address['state']       ?? '',
            $shipping_address['postal_code'] ?? '',
            $shipping_address['country']     ?? '',
        ])) : '';

        ob_start();
    ?>
        <div class="rr-view-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">

            <!-- Two-column header: story left, hero right -->
            <div class="rr-registry-top <?php echo $hero_url ? 'rr-registry-top--with-hero' : ''; ?>">
                <div class="rr-registry-top__info">
                    <h1 class="rr-registry-title"><?php echo esc_html($registry['title']); ?></h1>
                    <?php
                    $is_for_self    = (bool) ($registry['meta']['is_for_self'] ?? true);
                    $recipient_name = $registry['meta']['recipient_name'] ?? '';
                    $recipient_rel  = $registry['meta']['recipient_relationship'] ?? '';
                    ?>
                    <?php if (!$is_for_self && $recipient_name): ?>
                        <p class="rr-owner"><?php echo wp_kses_post(sprintf(
                                                /* translators: 1: recipient name, 2: owner name */
                                                __('A gift registry for <strong>%1$s</strong>, created by %2$s', 'restart-registry'),
                                                esc_html($recipient_name),
                                                esc_html($owner_name)
                                            )); ?></p>
                        <?php if ($recipient_rel): ?>
                            <p class="rr-recipient">(<?php echo esc_html($recipient_rel); ?>)</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="rr-owner"><?php printf(
                                                /* translators: %s = owner display name */
                                                __('A gift registry by %s', 'restart-registry'),
                                                '<strong>' . esc_html($owner_name) . '</strong>'
                                            ); ?></p>
                    <?php endif; ?>
                    <?php if ($event_type || $event_date): ?>
                        <p class="rr-event-meta">
                            <?php if ($event_type): ?>
                                <span class="rr-event-meta__group">
                                    <span class="rr-event-meta__label"><?php esc_html_e('Event:', 'restart-registry'); ?></span>
                                    <span class="rr-event-type"><?php echo esc_html($event_type); ?></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($event_date): ?>
                                <span class="rr-event-meta__group">
                                    <span class="rr-event-meta__label"><?php esc_html_e('Date:', 'restart-registry'); ?></span>
                                    <span class="rr-event-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($event_date))); ?></span>
                                </span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <section class="rr-story">
                        <h2 class="rr-story__heading"><?php _e('Their Story', 'restart-registry'); ?></h2>
                        <?php if (!empty($registry['description'])): ?>
                            <p class="rr-story__text"><?php echo nl2br(esc_html($registry['description'])); ?></p>
                        <?php else: ?>
                            <p class="rr-story__text rr-story__text--placeholder"><?php echo esc_html(sprintf(__('%s hasn\'t shared their story yet.', 'restart-registry'), $owner_name)); ?></p>
                        <?php endif; ?>
                    </section>
                    <?php if ($show_address): ?>
                        <button type="button" class="rr-btn-ghost rr-copy-address rr-header-copy-address"
                                data-address="<?php echo esc_attr($address_formatted); ?>"><?php _e('Copy shipping address', 'restart-registry'); ?></button>
                    <?php endif; ?>
                </div>
                <?php if ($hero_url): ?>
                    <div class="rr-registry-top__hero">
                        <img src="<?php echo esc_url($hero_url); ?>"
                            alt="<?php echo esc_attr($registry['title']); ?>"
                            loading="lazy">
                    </div>
                <?php endif; ?>
            </div>

            <hr class="rr-divider">

            <!-- Items table -->
            <div class="rr-items-section">
                <div class="rr-items-table">
                    <div class="rr-items-table__head" aria-hidden="true">
                        <span class="rr-col-thumb"></span>
                        <span class="rr-col-item"><?php _e('Item', 'restart-registry'); ?></span>
                        <span class="rr-col-qty"><?php _e('Qty', 'restart-registry'); ?></span>
                        <span class="rr-col-fulfilled"><?php _e('Fulfilled', 'restart-registry'); ?></span>
                        <span class="rr-col-actions"></span>
                    </div>
                    <div class="rr-items-grid" id="rr-items-container">
                        <?php foreach ($registry['items'] as $item): ?>
                            <?php echo $this->render_item_card($item, false, (bool) $allow_guests); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (empty($registry['items'])): ?>
                    <p class="rr-no-items"><?php _e('No items in this registry yet.', 'restart-registry'); ?></p>
                <?php endif; ?>

                <?php if (!empty($disclosure)): ?>
                    <p class="rr-affiliate-note"><small><?php echo esc_html($disclosure); ?></small></p>
                <?php endif; ?>
            </div>


            <?php
            $purchase_messages = $this->controller->get_purchase_messages($registry['id']);
            if (!empty($purchase_messages)):
            ?>
                <div class="rr-message-board">
                    <h2 class="rr-message-board__title"><?php _e('Messages', 'restart-registry'); ?></h2>
                    <ul class="rr-message-board__list">
                        <?php foreach ($purchase_messages as $msg): ?>
                            <li class="rr-message-card">
                                <?php if (!empty($msg['item_image_url'])): ?>
                                    <div class="rr-message-card__thumb">
                                        <img src="<?php echo esc_url($msg['item_image_url']); ?>"
                                            alt="<?php echo esc_attr($msg['item_name']); ?>"
                                            loading="lazy">
                                    </div>
                                <?php endif; ?>
                                <div class="rr-message-card__body">
                                    <p class="rr-message-card__item-name"><?php echo esc_html($msg['item_name']); ?></p>
                                    <?php if (!empty($msg['item_description'])): ?>
                                        <p class="rr-message-card__item-desc"><?php echo esc_html($msg['item_description']); ?></p>
                                    <?php endif; ?>
                                    <blockquote class="rr-message-card__note"><?php echo esc_html($msg['purchaser_note']); ?></blockquote>
                                    <p class="rr-message-card__meta">
                                        <span class="rr-message-card__from">
                                            <?php echo esc_html($msg['purchaser_name'] ?: __('Someone', 'restart-registry')); ?>
                                        </span>
                                        <span class="rr-message-card__date">
                                            <?php echo esc_html(date_i18n(get_option('date_format'), $msg['timestamp'])); ?>
                                        </span>
                                    </p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Item detail modal -->
            <div class="rr-modal" id="rr-item-detail-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-item-detail-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-item-detail-title" class="rr-item-detail__title"></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <div class="rr-item-detail__image-wrap" style="display:none">
                            <img class="rr-item-detail__image" src="" alt="" loading="lazy">
                        </div>
                        <div class="rr-item-detail__meta"></div>
                        <p class="rr-item-detail__description" style="display:none"></p>
                        <div class="rr-item-detail__qty-row"></div>
                        <div class="rr-item-detail__actions">
                            <a href="#" target="_blank" rel="noopener sponsored" class="rr-button rr-purchase-btn rr-item-detail__purchase-btn" style="display:none"><?php _e('Purchase', 'restart-registry'); ?></a>
                            <button type="button" class="rr-button rr-button-small rr-button-secondary rr-mark-purchased rr-item-detail__mark-btn" style="display:none"><?php _e('Mark Purchased', 'restart-registry'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mark as purchased modal -->
            <div class="rr-modal" id="rr-purchase-modal" aria-hidden="true">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-purchase-modal-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-purchase-modal-title"><?php _e('Mark as Purchased', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <p class="rr-purchase-modal__item-name"></p>
                        <p class="rr-purchase-modal__nudge"><?php _e('Let them know who it\'s from!', 'restart-registry'); ?></p>
                        <form id="rr-purchase-form" class="rr-form">
                            <input type="hidden" id="rr-purchase-item-id" name="item_id">
                            <div class="rr-form-group">
                                <label for="rr-purchaser-name"><?php _e('Your name', 'restart-registry'); ?> <span class="rr-optional"><?php _e('(optional)', 'restart-registry'); ?></span></label>
                                <input type="text" id="rr-purchaser-name" name="purchaser_name"
                                    placeholder="<?php esc_attr_e('e.g., Aunt Carol', 'restart-registry'); ?>">
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-purchaser-note"><?php _e('Leave a message', 'restart-registry'); ?> <span class="rr-optional"><?php _e('(optional)', 'restart-registry'); ?></span></label>
                                <textarea id="rr-purchaser-note" name="purchaser_note" rows="3" spellcheck="true"
                                    placeholder="<?php esc_attr_e('A note for the registry owner…', 'restart-registry'); ?>"></textarea>
                            </div>
                            <div class="rr-form-actions">
                                <button type="submit" class="rr-button"><?php _e('Confirm Purchase', 'restart-registry'); ?></button>
                                <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($show_address): ?>
                <!-- Pre-purchase address modal — shown before the retailer redirect -->
                <div class="rr-modal" id="rr-pre-purchase-modal" aria-hidden="true">
                    <div class="rr-modal__backdrop"></div>
                    <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-pre-purchase-title" aria-modal="true">
                        <div class="rr-modal__header">
                            <h3 id="rr-pre-purchase-title"><?php _e('Before you check out', 'restart-registry'); ?></h3>
                            <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                        </div>
                        <div class="rr-modal__body">
                            <p class="rr-purchase-modal__address-hint"><?php _e('Copy the shipping address before you head to the store:', 'restart-registry'); ?></p>
                            <div class="rr-purchase-modal__address-row">
                                <code class="rr-purchase-modal__address-text"><?php echo esc_html($address_formatted); ?></code>
                                <button type="button" class="rr-btn-ghost rr-button-small rr-copy-address"
                                        data-address="<?php echo esc_attr($address_formatted); ?>"><?php _e('Copy', 'restart-registry'); ?></button>
                            </div>
                            <div class="rr-form-actions">
                                <a href="#" id="rr-pre-purchase-continue" target="_blank" rel="noopener sponsored" class="rr-button"><?php _e('Continue to purchase', 'restart-registry'); ?></a>
                                <button type="button" class="rr-btn-ghost rr-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    <?php
        return $this->compact_html(ob_get_clean());
    }

    /**
     * Render a single item row for the public registry view.
     *
     * Item fields (from Lambda): id, name, url, description, price,
     *   retailer, affiliate_status, quantity_needed, quantity_purchased, is_active.
     */
    private function render_item_card(array $item, bool $is_owner = false, bool $can_purchase = true): string
    {
        $qty_needed    = (int) ($item['quantity_needed']    ?? 1);
        $qty_purchased = (int) ($item['quantity_purchased'] ?? 0);
        $remaining     = $qty_needed - $qty_purchased;
        $is_fulfilled  = $remaining <= 0;
        $item_url      = !empty($item['affiliate_url']) ? $item['affiliate_url'] : ($item['url'] ?? '');

        $thumb = !empty($item['image_url'])
            ? '<img src="' . esc_url($item['image_url']) . '" alt="' . esc_attr($item['name']) . '" loading="lazy">'
            : '<span class="rr-item-row__thumb-placeholder" aria-hidden="true"></span>';

        $name_inner = '<button type="button" class="rr-item-name-btn">' . esc_html($item['name']) . '</button>';
        if (!empty($item['retailer'])) {
            $name_inner .= '<span class="rr-item-retailer">' . esc_html($item['retailer']) . '</span>';
        }
        if (!empty($item['price'])) {
            $name_inner .= '<span class="rr-item-price">$' . number_format((float) $item['price'], 2) . '</span>';
        }
        if (!empty($item['notes'])) {
            $name_inner .= '<span class="rr-item-card__note">' . esc_html($item['notes']) . '</span>';
        }

        $fulfilled_inner = $is_fulfilled
            ? '<span class="rr-fulfilled-check">&#10003; ' . esc_html__('Done', 'restart-registry') . '</span>'
            : esc_html($qty_purchased . ' / ' . $qty_needed);

        $actions = '';
        if (!$is_fulfilled && !empty($item_url)) {
            $actions .= '<a href="' . esc_url($item_url) . '" target="_blank" rel="noopener sponsored" class="rr-purchase-btn rr-button rr-button-small">'
                . esc_html__('Purchase', 'restart-registry') . '</a>';
        }
        if (!$is_fulfilled && $can_purchase) {
            $actions .= '<button type="button" class="rr-button rr-button-small rr-button-secondary rr-mark-purchased">'
                . esc_html__('Mark Purchased', 'restart-registry') . '</button>';
        }
        if ($is_owner) {
            $actions .= '<button type="button" class="rr-btn-icon rr-edit-item" title="' . esc_attr__('Edit', 'restart-registry') . '">&#9998;</button>'
                . '<button type="button" class="rr-btn-icon rr-btn-icon--danger rr-delete-item" title="' . esc_attr__('Remove', 'restart-registry') . '">&#10005;</button>';
        }

        return '<div class="rr-item-card ' . ($is_fulfilled ? 'rr-item-fulfilled' : '') . '"'
            . ' data-item-id="' . esc_attr($item['id']) . '"'
            . ' data-name="' . esc_attr($item['name']) . '"'
            . ' data-url="' . esc_attr($item['url'] ?? '') . '"'
            . ' data-description="' . esc_attr($item['description'] ?? '') . '"'
            . ' data-notes="' . esc_attr($item['notes'] ?? '') . '"'
            . ' data-price="' . esc_attr($item['price'] ?? '') . '"'
            . ' data-quantity="' . esc_attr($qty_needed) . '"'
            . ' data-image-url="' . esc_attr($item['image_url'] ?? '') . '"'
            . ' data-retailer="' . esc_attr($item['retailer'] ?? '') . '"'
            . ' data-affiliate-url="' . esc_attr($item_url) . '"'
            . ' data-qty-purchased="' . esc_attr($qty_purchased) . '">'
            . '<span class="rr-item-card__thumb">' . $thumb . '</span>'
            . '<span class="rr-item-card__name">' . $name_inner . '</span>'
            . '<span class="rr-item-card__qty">' . esc_html($qty_needed) . '</span>'
            . '<span class="rr-item-card__fulfilled ' . ($is_fulfilled ? 'rr-item-card__fulfilled--done' : '') . '">' . $fulfilled_inner . '</span>'
            . '<span class="rr-item-card__actions">' . $actions . '</span>'
            . '</div>';
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    public function ajax_create_registry(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $title = sanitize_text_field($_POST['title'] ?? '');
        if (empty($title)) {
            wp_send_json_error(['message' => __('Please enter a registry title.', 'restart-registry')]);
        }

        // Build the meta dict up-front; create_registry persists it during
        // initial creation so the row never has a "self-recipient" blink for
        // gift-from-loved-one registries.
        $meta = [];
        if (!empty($_POST['event_type']))             $meta['event_type']             = $_POST['event_type'];
        if (!empty($_POST['event_date']))             $meta['event_date']             = $_POST['event_date'];
        if (isset($_POST['is_for_self']))             $meta['is_for_self']            = $_POST['is_for_self'] === '1';
        if (!empty($_POST['recipient_name']))         $meta['recipient_name']         = $_POST['recipient_name'];
        if (!empty($_POST['recipient_relationship'])) $meta['recipient_relationship'] = $_POST['recipient_relationship'];
        if (!empty($_POST['recipient_email']))        $meta['recipient_email']        = $_POST['recipient_email'];

        $result = $this->controller->create_registry(
            get_current_user_id(),
            $title,
            sanitize_textarea_field($_POST['description'] ?? ''),
            isset($_POST['is_public']) && $_POST['is_public'] === '1',
            $meta
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'     => __('Registry created successfully!', 'restart-registry'),
            'registry_id' => $result['id'],
            'redirect'    => get_permalink($result['id']),
        ]);
    }

    public function ajax_add_item(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [
            'name'        => sanitize_text_field($_POST['name'] ?? ''),
            'url'         => esc_url_raw($_POST['url'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'notes'       => sanitize_textarea_field($_POST['notes'] ?? ''),
            'price'       => isset($_POST['price']) ? (float) $_POST['price'] : 0.01,
            'quantity'    => isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1,
            'image_url'   => !empty($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : null,
        ];

        if (empty($data['name']) || empty($data['url'])) {
            wp_send_json_error(['message' => __('Name and URL are required.', 'restart-registry')]);
        }

        $result = $this->controller->add_item($registry_id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'      => __('Item added successfully!', 'restart-registry'),
            'item_id'      => $result['id'],
            'is_affiliate' => $result['is_affiliate'],
            'retailer'     => $result['retailer'],
            'html'         => $this->render_item_row($result['html_item']),
        ]);
    }

    public function ajax_delete_item(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $item_id     = (int) ($_POST['item_id']     ?? 0);
        $registry_id = (int) ($_POST['registry_id'] ?? 0);

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $this->controller->delete_item($item_id, $registry_id);
        wp_send_json_success(['message' => __('Item removed.', 'restart-registry')]);
    }

    public function ajax_update_item(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $item_id     = (int) ($_POST['item_id']     ?? 0);
        $registry_id = (int) ($_POST['registry_id'] ?? 0);

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [];
        if (isset($_POST['name']))        $data['name']        = sanitize_text_field($_POST['name']);
        if (isset($_POST['url']))         $data['url']         = esc_url_raw($_POST['url']);
        if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field($_POST['description']);
        if (isset($_POST['notes']))       $data['notes']       = sanitize_textarea_field($_POST['notes']);
        if (isset($_POST['quantity']))    $data['quantity']    = (int) $_POST['quantity'];
        if (isset($_POST['price']))       $data['price']       = (float) $_POST['price'];
        if (isset($_POST['image_url']))   $data['image_url']   = esc_url_raw($_POST['image_url']);

        // mark_fulfilled override: clamp quantity_needed down to quantity_purchased.
        // Wins over any explicit `quantity` in the same request — if the owner
        // ticks "fulfilled" on save, that's the intended end state.
        if (!empty($_POST['mark_fulfilled']) && $_POST['mark_fulfilled'] !== '0') {
            $existing = $this->controller->get_item($item_id);
            if ($existing && !is_wp_error($existing)) {
                $qty_purchased = (int) ($existing['quantity_purchased'] ?? 0);
                if ($qty_purchased > 0) {
                    $data['quantity'] = $qty_purchased;
                }
            }
        }

        $result = $this->controller->update_item($item_id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Item updated.', 'restart-registry')]);
    }

    public function ajax_mark_purchased(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $item_id  = (int) ($_POST['item_id']  ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        // Verify the caller may view the registry that owns this item before
        // mutating it. This matters for nopriv callers (anonymous guests) who
        // should only be able to mark items in registries they can actually see.
        $item = $this->controller->get_item($item_id);
        if (!$item || is_wp_error($item)) {
            wp_send_json_error(['message' => __('Item not found.', 'restart-registry')]);
        }
        $registry_id = (int) ($item['registry_id'] ?? 0);
        if (!$registry_id || !$this->controller->can_view_registry($registry_id, get_current_user_id() ?: null)) {
            wp_send_json_error(['message' => __('You do not have permission to view this registry.', 'restart-registry')]);
        }

        $result = $this->controller->mark_item_purchased(
            $item_id,
            $quantity,
            sanitize_text_field($_POST['purchaser_name']   ?? ''),
            sanitize_email($_POST['purchaser_email']        ?? ''),
            sanitize_textarea_field($_POST['purchaser_note'] ?? ''),
            isset($_POST['is_anonymous']) && $_POST['is_anonymous'] === '1'
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $response = ['message' => __('Thank you for purchasing this gift!', 'restart-registry')];
        if (!empty($result['message'])) {
            $response['message_id']   = $result['message']['id'];
            $response['edit_token']   = $result['message']['edit_token'];
            $response['edit_expires'] = $result['message']['edit_expires'];
        }

        wp_send_json_success($response);
    }

    public function ajax_update_purchase_message(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        $message_id  = sanitize_text_field($_POST['message_id'] ?? '');
        $new_note    = sanitize_textarea_field($_POST['purchaser_note'] ?? '');
        $token       = isset($_POST['edit_token']) ? sanitize_text_field($_POST['edit_token']) : null;

        if (!$registry_id || $message_id === '') {
            wp_send_json_error(['message' => __('Invalid request.', 'restart-registry')]);
        }

        // Owner path: logged-in user who owns the registry.
        $use_owner_auth = is_user_logged_in() && $this->controller->can_edit_registry($registry_id, get_current_user_id());

        if (!$use_owner_auth && $token === null) {
            wp_send_json_error(['message' => __('You do not have permission to edit this message.', 'restart-registry')]);
        }

        $ok = $this->controller->update_purchase_message(
            $registry_id,
            $message_id,
            $new_note,
            $use_owner_auth ? null : $token
        );

        if (!$ok) {
            wp_send_json_error(['message' => __('Could not update message. The edit window may have expired.', 'restart-registry')]);
        }

        wp_send_json_success(['message' => __('Note updated.', 'restart-registry'), 'note' => $new_note]);
    }

    public function ajax_send_invite(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        // Accept both 'invitee' (new) and 'email' (legacy)
        $invitee = sanitize_text_field($_POST['invitee'] ?? $_POST['email'] ?? '');

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot manage this registry.', 'restart-registry')]);
        }
        if (empty($invitee)) {
            wp_send_json_error(['message' => __('Please enter an email or username.', 'restart-registry')]);
        }

        $result = $this->controller->send_invite($registry_id, $invitee);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Invitation sent!', 'restart-registry')]);
    }

    public function ajax_remove_invitee(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        $invitee     = sanitize_text_field($_POST['invitee'] ?? '');

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot manage this registry.', 'restart-registry')]);
        }
        if (empty($invitee)) {
            wp_send_json_error(['message' => __('Missing invitee identifier.', 'restart-registry')]);
        }

        $result = $this->controller->delete_invitee($registry_id, $invitee);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Invitee removed.', 'restart-registry')]);
    }

    public function ajax_update_registry(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [];
        if (isset($_POST['title']))                  $data['title']                  = $_POST['title'];
        if (isset($_POST['description']))            $data['description']            = $_POST['description'];
        if (isset($_POST['is_public']))              $data['is_public']              = $_POST['is_public'] === '1';
        if (isset($_POST['event_type']))             $data['event_type']             = $_POST['event_type'];
        if (isset($_POST['event_date']))             $data['event_date']             = $_POST['event_date'];
        if (isset($_POST['is_for_self']))            $data['is_for_self']            = $_POST['is_for_self'] === '1';
        if (isset($_POST['recipient_name']))         $data['recipient_name']         = $_POST['recipient_name'];
        if (isset($_POST['recipient_relationship'])) $data['recipient_relationship'] = $_POST['recipient_relationship'];
        if (isset($_POST['recipient_email']))        $data['recipient_email']        = $_POST['recipient_email'];
        if (isset($_POST['hero_image_id']))          $data['hero_image_id']          = (int) $_POST['hero_image_id'];

        $this->controller->update_registry($registry_id, $data);
        wp_send_json_success(['message' => __('Registry updated.', 'restart-registry')]);
    }

    public function ajax_update_notification_prefs(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $notify = isset($_POST['notify_on_purchase']) && $_POST['notify_on_purchase'] === '1';
        update_user_meta(get_current_user_id(), 'restart_notify_on_purchase', $notify ? '1' : '0');
        wp_send_json_success(['message' => __('Preferences saved.', 'restart-registry')]);
    }

    public function ajax_fetch_url(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $url = esc_url_raw($_POST['url'] ?? '');
        if (empty($url)) {
            wp_send_json_error(['message' => __('Please enter a URL.', 'restart-registry')]);
        }

        // Try a retailer API first when a key is configured — skips the scraper entirely
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-retailer-api.php';
        $api_data = (new Restart_Registry_Retailer_API())->fetch_if_configured($url);
        if ($api_data !== null) {
            $aff = Restart_Registry_Affiliate_Converter::instance()->convert_url($url);
            wp_send_json_success(array_merge($api_data, [
                'retailer'     => $aff['retailer'],
                'is_affiliate' => $aff['is_affiliate'],
            ]));
            return;
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-llm-extractor.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-product-scraper.php';
        $data = (new Restart_Registry_Product_Scraper())->scrape($url);

        $aff = Restart_Registry_Affiliate_Converter::instance()->convert_url($url);

        wp_send_json_success(array_merge($data, [
            'retailer'     => $aff['retailer'],
            'is_affiliate' => $aff['is_affiliate'],
        ]));
    }

    public function ajax_archive_registry(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$registry_id || !$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('Permission denied.', 'restart-registry')]);
        }
        if ($this->controller->archive_registry($registry_id)) {
            wp_send_json_success(['message' => __('Registry archived.', 'restart-registry')]);
        }
        wp_send_json_error(['message' => __('Could not archive registry.', 'restart-registry')]);
    }

    public function ajax_restore_registry(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$registry_id) {
            wp_send_json_error(['message' => __('Invalid registry.', 'restart-registry')]);
        }
        $post = get_post($registry_id);
        if (!$post || (int) $post->post_author !== get_current_user_id()) {
            wp_send_json_error(['message' => __('Permission denied.', 'restart-registry')]);
        }
        if ($this->controller->restore_registry($registry_id)) {
            wp_send_json_success(['message' => __('Registry restored.', 'restart-registry')]);
        }
        wp_send_json_error(['message' => __('Could not restore registry.', 'restart-registry')]);
    }

    public function ajax_delete_registry(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        $confirmed   = ($_POST['confirm'] ?? '') === '1';
        if (!$registry_id || !$confirmed) {
            wp_send_json_error(['message' => __('Confirmation required.', 'restart-registry')]);
        }
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('Permission denied.', 'restart-registry')]);
        }
        if ($this->controller->delete_registry($registry_id)) {
            wp_send_json_success(['message' => __('Registry deleted.', 'restart-registry'), 'redirect' => home_url('/my-registries/')]);
        }
        wp_send_json_error(['message' => __('Could not delete registry.', 'restart-registry')]);
    }

    // =========================================================================
    // [restart_item] shortcode — product card for favorites / gift-guide articles
    // =========================================================================

    /** @var bool Whether the quick-add modals have already been appended this request. */
    private static bool $quick_add_modals_printed = false;

    /**
     * [restart_item title="…" price="…" image="…" images="url1,url2" url="…"
     *               description="…" retailer="…" notes="…" quantity="1"]
     *
     * Renders a product card with image(s), details, a shop link, and an
     * "Add to My Registry" button. Multiple images render as a simple carousel.
     */
    public function item_shortcode(array $atts): string
    {
        $a = shortcode_atts([
            'title'       => '',
            'price'       => '',
            'image'       => '',
            'images'      => '',
            'description' => '',
            'url'         => '',
            'retailer'    => '',
            'notes'       => '',
            'quantity'    => '1',
        ], $atts, 'restart_item');

        if (empty($a['title'])) {
            return '';
        }

        // Normalise image list: `images` wins over `image`.
        $raw    = !empty($a['images']) ? $a['images'] : $a['image'];
        $images = array_values(array_filter(array_map('trim', explode(',', $raw))));

        // ── Image / carousel section ──────────────────────────────────────
        $media_html = '';
        if (count($images) === 1) {
            $media_html = '<div class="rr-article-item__media">'
                . '<img class="rr-article-item__img" src="' . esc_url($images[0]) . '" alt="' . esc_attr($a['title']) . '" loading="lazy">'
                . '</div>';
        } elseif (count($images) > 1) {
            $slides = '';
            $dots   = '';
            foreach ($images as $i => $src) {
                $active  = $i === 0 ? ' is-active' : '';
                $slides .= '<img class="rr-article-item__slide' . $active . '" src="' . esc_url($src) . '" alt="' . esc_attr($a['title']) . '" loading="lazy">';
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
        if (!empty($a['price'])) {
            $display    = str_starts_with(ltrim($a['price']), '$') ? $a['price'] : '$' . $a['price'];
            $price_html = '<span class="rr-article-item__price">' . esc_html($display) . '</span>';
        }

        // ── Action buttons ────────────────────────────────────────────────
        // TODO: if the URL is an affiliate link, show the retailer's logo instead of a generic "Shop Now" button. This would require normalizing known retailer URLs in the Affiliate_Converter and passing that info through here.
        // $aff = '';
        $shop_btn = '';
        $add_btn  = '';
        if (!empty($a['url'])) {
            $aff = Restart_Registry_Affiliate_Converter::instance()->convert_url($a['url']);
            $shop_btn = '<a href="' . esc_url($aff['affiliate_url']) . '" class="rr-button rr-article-item__shop-btn" target="_blank" rel="noopener sponsored">'
                . esc_html__('Shop Now', 'restart-registry') . '</a>';

            // TODO: if the URL is an affiliate link, show the retailer's logo instead of a generic "Shop Now" button. This would require normalizing known retailer URLs in the Affiliate_Converter and passing that info through here.
            $add_btn = '<button type="button" class="rr-button rr-button-secondary rr-quick-add"'
                . ' data-name="' . esc_attr($a['title']) . '"'
                . ' data-url="' . esc_attr($aff['affiliate_url']) . '"'
                . ' data-price="' . esc_attr(preg_replace('/[^0-9.]/', '', $a['price'])) . '"'
                . ' data-image-url="' . esc_attr($images[0] ?? '') . '"'
                . ' data-description="' . esc_attr($a['description']) . '"'
                . ' data-notes="' . esc_attr($a['notes']) . '"'
                . ' data-quantity="' . esc_attr($a['quantity']) . '">'
                . esc_html__('+ Add to My Registry', 'restart-registry')
                . '</button>';
        }



        // ── Full card ─────────────────────────────────────────────────────
        $retailer_html = !empty($a['retailer'])
            ? '<span class="rr-article-item__retailer rr-item-retailer">' . esc_html($a['retailer']) . '</span>'
            : '';

        $desc_html = !empty($a['description'])
            ? '<p class="rr-article-item__description">' . esc_html($a['description']) . '</p>'
            : '';

        $disc_html = !empty($this->disclosure)
            ? '<p class="rr-affiliate-note"><small>' . esc_html($this->disclosure) . '</small></p>'
            : '';
        
        $html = '<div class="rr-article-item">'
            . $media_html
            . '<div class="rr-article-item__body">'
            . '<div class="rr-article-item__header">'
            . '<h3 class="rr-article-item__title">' . esc_html($a['title']) . '</h3>'
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

        // Output shared quick-add modals once per page.
        if (!self::$quick_add_modals_printed) {
            self::$quick_add_modals_printed = true;
            $html .= $this->render_quick_add_modals();
        }

        return $html;
    }

    /**
     * Shared modals for the quick-add flow.
     * Auth modal: shown to non-logged-in visitors.
     * No-registry modal: shown to logged-in users who haven't created a registry.
     */
    private function render_quick_add_modals(): string
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
        return ob_get_clean();
    }

    /**
     * AJAX: add an item to the current user's registry without requiring
     * registry_id from the caller — looks it up automatically.
     */
    public function ajax_save_shipping_address(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }
        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }
        $address = [
            'name'        => $_POST['shipping_name'] ?? '',
            'address_1'   => $_POST['address_1']     ?? '',
            'address_2'   => $_POST['address_2']     ?? '',
            'city'        => $_POST['city']          ?? '',
            'state'       => $_POST['state']         ?? '',
            'postal_code' => $_POST['postal_code']   ?? '',
            'country'     => $_POST['country']       ?? '',
        ];
        if ($this->controller->save_shipping_address($registry_id, $address)) {
            wp_send_json_success(['message' => __('Address saved.', 'restart-registry')]);
        }
        wp_send_json_error(['message' => __('Address line 1 and city are required.', 'restart-registry')]);
    }

    public function ajax_delete_shipping_address(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }
        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }
        $this->controller->delete_shipping_address($registry_id);
        wp_send_json_success(['message' => __('Address removed.', 'restart-registry')]);
    }

    public function ajax_quick_add(): void
    {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['code' => 'not_logged_in', 'message' => __('Please sign in to add items to your registry.', 'restart-registry')]);
            return;
        }

        $registry = $this->controller->get_user_registry(get_current_user_id());
        if (!$registry) {
            wp_send_json_error(['code' => 'no_registry', 'message' => __("You don't have a registry yet.", 'restart-registry')]);
            return;
        }

        $registry_id = (int) $registry['id'];

        $name = sanitize_text_field($_POST['name'] ?? '');
        $url  = esc_url_raw($_POST['url'] ?? '');

        if (empty($name)) {
            wp_send_json_error(['message' => __('Item name is required.', 'restart-registry')]);
            return;
        }

        $data = [
            'name'        => $name,
            'url'         => $url,
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'notes'       => sanitize_textarea_field($_POST['notes'] ?? ''),
            'price'       => isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : null,
            'quantity'    => isset($_POST['quantity']) ? max(1, (int) $_POST['quantity']) : 1,
            'image_url'   => !empty($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : null,
        ];

        $result = $this->controller->add_item($registry_id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => __('Added to your registry!', 'restart-registry')]);
    }
}
