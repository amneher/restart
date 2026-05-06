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

class Restart_Registry_Public {

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    /** @var Restart_Registry_Controller */
    private $controller;

    public function __construct(string $plugin_name, string $version) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-controller.php';
        $this->controller = new Restart_Registry_Controller();

        add_shortcode('restart_registry',        [$this, 'registry_shortcode']);
        add_shortcode('restart_registry_view',   [$this, 'registry_view_shortcode']);
        add_shortcode('restart_registry_create', [$this, 'registry_create_shortcode']);

        add_action('wp_ajax_restart_registry_add_item',              [$this, 'ajax_add_item']);
        add_action('wp_ajax_restart_registry_delete_item',           [$this, 'ajax_delete_item']);
        add_action('wp_ajax_restart_registry_update_item',           [$this, 'ajax_update_item']);
        add_action('wp_ajax_restart_registry_mark_purchased',        [$this, 'ajax_mark_purchased']);
        add_action('wp_ajax_nopriv_restart_registry_mark_purchased', [$this, 'ajax_mark_purchased']);
        add_action('wp_ajax_restart_registry_send_invite',           [$this, 'ajax_send_invite']);
        add_action('wp_ajax_restart_registry_create',                [$this, 'ajax_create_registry']);
        add_action('wp_ajax_restart_registry_update',                [$this, 'ajax_update_registry']);
        add_action('wp_ajax_restart_registry_fetch_url',                    [$this, 'ajax_fetch_url']);
        add_action('wp_ajax_restart_registry_update_notification_prefs',   [$this, 'ajax_update_notification_prefs']);
    }

    // =========================================================================
    // Asset enqueuing
    // =========================================================================

    public function enqueue_styles(): void {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/restart-registry-public.css',
            [],
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/restart-registry-public.js',
            ['jquery'],
            $this->version,
            true
        );

        wp_localize_script($this->plugin_name, 'restartRegistry', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('restart_registry_nonce'),
            'strings' => [
                'confirmDelete'   => __('Are you sure you want to remove this item?', 'restart-registry'),
                'confirmPurchase' => __('Mark this item as purchased?', 'restart-registry'),
                'loading'         => __('Loading…', 'restart-registry'),
                'error'           => __('An error occurred. Please try again.', 'restart-registry'),
                'prefsSaved'      => __('Preferences saved.', 'restart-registry'),
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
    public function registry_shortcode($atts): string {
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
    public function registry_view_shortcode($atts): string {
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
    public function registry_create_shortcode($atts): string {
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
    private function compact_html(string $html): string {
        return preg_replace('/>\s+</', '><', $html);
    }
    // =========================================================================

    private function render_login_prompt(): string {
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

    private function render_create_form(): string {
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

    private function render_manage_registry(array $registry): string {
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
                    <span class="rr-toggle__label" data-on="<?php esc_attr_e('Public', 'restart-registry'); ?>" data-off="<?php esc_attr_e('Private', 'restart-registry'); ?>">
                        <?php echo $registry['is_public'] ? __('Public', 'restart-registry') : __('Private', 'restart-registry'); ?>
                    </span>
                </label>
                <button type="button" class="rr-btn-ghost" id="rr-share-toggle">&#8679; <?php _e('Share', 'restart-registry'); ?></button>
                <button type="button" class="rr-btn-ghost" id="rr-edit-registry">&#9881; <?php _e('Settings', 'restart-registry'); ?></button>
            </div>

            <!-- Header: title + event meta -->
            <div class="rr-registry-header">
                <h1 class="rr-registry-title"><?php echo esc_html($registry['title']); ?></h1>
                <?php if ($event_type || $event_date): ?>
                    <p class="rr-event-meta">
                        <?php if ($event_type): ?><span class="rr-event-type"><?php echo esc_html($event_type); ?></span><?php endif; ?>
                        <?php if ($event_date): ?><span class="rr-event-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($event_date))); ?></span><?php endif; ?>
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

            <?php if (!empty($registry['description'])): ?>
                <section class="rr-story">
                    <h2 class="rr-story__heading"><?php _e('My Story', 'restart-registry'); ?></h2>
                    <p class="rr-story__text"><?php echo nl2br(esc_html($registry['description'])); ?></p>
                </section>
            <?php endif; ?>

            <hr class="rr-divider">

            <!-- Items section -->
            <div class="rr-items-section">
                <div class="rr-items-header">
                    <span class="rr-items-heading"><?php _e('Your Items', 'restart-registry'); ?> <span class="rr-item-count">(<?php echo count($registry['items']); ?>)</span></span>
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
                            <input type="text" id="rr-item-description" name="description"
                                   placeholder="<?php esc_attr_e('Notes (optional)', 'restart-registry'); ?>">
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

            <!-- Notification preferences -->
            <?php
            $notify_pref = get_user_meta(get_current_user_id(), 'restart_notify_on_purchase', true);
            $notify_on   = $notify_pref !== '0';
            ?>
            <section class="rr-notification-prefs">
                <h2 class="rr-notification-prefs__heading"><?php _e('Notification Preferences', 'restart-registry'); ?></h2>
                <label class="rr-checkbox-label">
                    <input type="checkbox" id="rr-notify-purchase" <?php checked($notify_on); ?>>
                    <?php _e('Email me when items are purchased', 'restart-registry'); ?>
                </label>
                <p id="rr-notify-prefs-status" class="rr-notify-status" aria-live="polite"></p>
            </section>

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
                                <label for="rr-edit-item-description"><?php _e('Notes', 'restart-registry'); ?></label>
                                <input type="text" id="rr-edit-item-description" name="description"
                                       placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>">
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-edit-item-image-url"><?php _e('Image URL', 'restart-registry'); ?></label>
                                <input type="url" id="rr-edit-item-image-url" name="image_url"
                                       placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>">
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
                            <button type="button" class="rr-button rr-button-small rr-button-secondary rr-mark-purchased rr-item-detail__mark-btn" style="display:none"><?php _e('Mark Fulfilled', 'restart-registry'); ?></button>
                        </div>
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
    private function render_item_row(array $item): string {
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
        if (!empty($item['description'])) {
            $name_inner .= '<span class="rr-item-row__note">' . esc_html($item['description']) . '</span>';
        }

        $fulfilled_inner = $is_fulfilled
            ? '<span class="rr-fulfilled-check" title="' . esc_attr__('Fulfilled', 'restart-registry') . '">&#10003;</span>'
            : esc_html($qty_purchased . ' / ' . $qty_needed);

        return '<li class="rr-item-row ' . ($is_fulfilled ? 'rr-item-row--fulfilled' : '') . '"'
            . ' data-item-id="' . esc_attr($item['id']) . '"'
            . ' data-name="' . esc_attr($item['name']) . '"'
            . ' data-url="' . esc_attr($item['url'] ?? '') . '"'
            . ' data-description="' . esc_attr($item['description'] ?? '') . '"'
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
    private function render_registry_view(string $key): string {
        $registry = $this->controller->get_registry_by_share_key($key);
        if (is_wp_error($registry)) {
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

    private function render_registry_view_html(array $registry): string {
        $user         = get_userdata($registry['user_id']);
        $owner_name   = $user ? $user->display_name : __('Someone', 'restart-registry');
        $disclosure   = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $allow_guests = get_option('restart_registry_allow_guests', 1);
        $event_type   = $registry['meta']['event_type'] ?? '';
        $event_date   = $registry['meta']['event_date'] ?? '';
        $hero_url     = get_the_post_thumbnail_url($registry['id'], 'large');

        ob_start();
        ?>
        <div class="rr-view-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">

            <!-- Two-column header: story left, hero right -->
            <div class="rr-registry-top <?php echo $hero_url ? 'rr-registry-top--with-hero' : ''; ?>">
                <div class="rr-registry-top__info">
                    <h1 class="rr-registry-title"><?php echo esc_html($registry['title']); ?></h1>
                    <p class="rr-owner"><?php printf(
                        /* translators: %s = owner display name */
                        __('A gift registry by %s', 'restart-registry'),
                        '<strong>' . esc_html($owner_name) . '</strong>'
                    ); ?></p>
                    <?php if ($event_type || $event_date): ?>
                        <p class="rr-event-meta">
                            <?php if ($event_type): ?><span class="rr-event-type"><?php echo esc_html($event_type); ?></span><?php endif; ?>
                            <?php if ($event_date): ?><span class="rr-event-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($event_date))); ?></span><?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($registry['description'])): ?>
                        <section class="rr-story">
                            <h2 class="rr-story__heading"><?php _e('Their Story', 'restart-registry'); ?></h2>
                            <p class="rr-story__text"><?php echo nl2br(esc_html($registry['description'])); ?></p>
                        </section>
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
                            <button type="button" class="rr-button rr-button-small rr-button-secondary rr-mark-purchased rr-item-detail__mark-btn" style="display:none"><?php _e('Mark Fulfilled', 'restart-registry'); ?></button>
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
                                <textarea id="rr-purchaser-note" name="purchaser_note" rows="3"
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
    private function render_item_card(array $item, bool $is_owner = false, bool $can_purchase = true): string {
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

        $fulfilled_inner = $is_fulfilled
            ? '<span class="rr-fulfilled-check">&#10003; ' . esc_html__('Done', 'restart-registry') . '</span>'
            : esc_html($qty_purchased . ' / ' . $qty_needed);

        $actions = '';
        if (!$is_fulfilled && !empty($item_url)) {
            $actions .= '<a href="' . esc_url($item_url) . '" target="_blank" rel="noopener sponsored" class="rr-purchase-btn rr-button rr-button-small">'
                . esc_html__('Purchase', 'restart-registry') . '</a>';
        }
        if (!$is_fulfilled && !$is_owner && $can_purchase) {
            $actions .= '<button type="button" class="rr-button rr-button-small rr-button-secondary rr-mark-purchased">'
                . esc_html__('Mark Fulfilled', 'restart-registry') . '</button>';
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

    public function ajax_create_registry(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $title = sanitize_text_field($_POST['title'] ?? '');
        if (empty($title)) {
            wp_send_json_error(['message' => __('Please enter a registry title.', 'restart-registry')]);
        }

        $result = $this->controller->create_registry(
            get_current_user_id(),
            $title,
            sanitize_textarea_field($_POST['description'] ?? ''),
            isset($_POST['is_public']) && $_POST['is_public'] === '1'
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $meta = [];
        if (!empty($_POST['event_type'])) $meta['event_type'] = $_POST['event_type'];
        if (!empty($_POST['event_date']))  $meta['event_date'] = $_POST['event_date'];
        if ($meta) $this->controller->update_registry($result['id'], $meta);

        wp_send_json_success([
            'message'     => __('Registry created successfully!', 'restart-registry'),
            'registry_id' => $result['id'],
            'redirect'    => get_permalink($result['id']),
        ]);
    }

    public function ajax_add_item(): void {
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

    public function ajax_delete_item(): void {
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

    public function ajax_update_item(): void {
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
        if (isset($_POST['quantity']))    $data['quantity']    = (int) $_POST['quantity'];
        if (isset($_POST['price']))       $data['price']       = (float) $_POST['price'];
        if (isset($_POST['image_url']))   $data['image_url']   = esc_url_raw($_POST['image_url']);

        $result = $this->controller->update_item($item_id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Item updated.', 'restart-registry')]);
    }

    public function ajax_mark_purchased(): void {
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

        wp_send_json_success(['message' => __('Thank you for purchasing this gift!', 'restart-registry')]);
    }

    public function ajax_send_invite(): void {
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

    public function ajax_update_registry(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [];
        if (isset($_POST['title']))       $data['title']       = $_POST['title'];
        if (isset($_POST['description'])) $data['description'] = $_POST['description'];
        if (isset($_POST['is_public']))   $data['is_public']   = $_POST['is_public'] === '1';
        if (isset($_POST['event_type'])) $data['event_type']  = $_POST['event_type'];
        if (isset($_POST['event_date']))  $data['event_date']  = $_POST['event_date'];

        $this->controller->update_registry($registry_id, $data);
        wp_send_json_success(['message' => __('Registry updated.', 'restart-registry')]);
    }

    public function ajax_update_notification_prefs(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $notify = isset($_POST['notify_on_purchase']) && $_POST['notify_on_purchase'] === '1';
        update_user_meta(get_current_user_id(), 'restart_notify_on_purchase', $notify ? '1' : '0');
        wp_send_json_success(['message' => __('Preferences saved.', 'restart-registry')]);
    }

    public function ajax_fetch_url(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $url = esc_url_raw($_POST['url'] ?? '');
        if (empty($url)) {
            wp_send_json_error(['message' => __('Please enter a URL.', 'restart-registry')]);
        }

        // Try a retailer API first when a key is configured — skips the scraper entirely
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-retailer-api.php';
        $api_data = (new Restart_Registry_Retailer_API())->fetch_if_configured($url);
        if ($api_data !== null) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';
            $aff = (new Restart_Registry_Affiliate_Converter())->convert_url($url);
            wp_send_json_success(array_merge($api_data, [
                'retailer'     => $aff['retailer'],
                'is_affiliate' => $aff['is_affiliate'],
            ]));
            return;
        }

        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => __('Could not fetch URL. Please enter details manually.', 'restart-registry')]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = ['name' => '', 'price' => '', 'image_url' => ''];

        // og:title is the curated product name — preferred over <title> which adds site suffixes
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\'][^>]*>/i', $body, $m)) {
            $data['name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            $data['name'] = preg_replace('/\s*[-|–]\s*(Etsy|Amazon\.com|Amazon|Target|Walmart|eBay)\s*$/iu', '', $data['name']);
        }
        // Fallback: <title> tag
        if (empty($data['name']) && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $data['name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            $data['name'] = preg_replace('/\s*[-|:].*(?:Amazon|Target|Walmart|eBay|Etsy).*$/i', '', $data['name']);
        }
        if (preg_match('/\$([0-9,]+\.?\d{0,2})/', $body, $m)) {
            $data['price'] = (float) str_replace(',', '', $m[1]);
        }
        // Strategy 1: og:image meta tag (attribute order varies)
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $body, $m)) {
            $data['image_url'] = esc_url_raw(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        // Strategy 2: JSON-LD Product schema
        if (empty($data['image_url']) && preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $scripts)) {
            foreach ($scripts[1] as $json_raw) {
                $ld = json_decode(trim($json_raw), true);
                if (!$ld) continue;
                foreach (isset($ld[0]) ? $ld : [$ld] as $node) {
                    if (in_array($node['@type'] ?? '', ['Product', 'ItemPage'], true) && !empty($node['image'])) {
                        $img = is_array($node['image']) ? reset($node['image']) : $node['image'];
                        if (is_array($img)) $img = $img['url'] ?? '';
                        if ($img) { $data['image_url'] = esc_url_raw($img); break 2; }
                    }
                }
            }
        }

        // Strategy 3: Amazon — parse colorImages JSON from page HTML for real CDN URL
        if (empty($data['image_url']) && strpos($url, 'amazon.') !== false) {
            if (preg_match('/"large":"(https:\/\/m\.media-amazon\.com\/images\/[^"]+)"/i', $body, $m)) {
                $data['image_url'] = esc_url_raw($m[1]);
            }
        }

        // Etsy: Chrome UAs get Cloudflare-blocked; retry with a social crawler UA that Etsy allows for link previews.
        // If we get a real page, replace $body so the description extraction below can reuse it.
        if (strpos($url, 'etsy.com/listing/') !== false) {
            $etsy_resp = wp_remote_get($url, [
                'timeout'    => 10,
                'user-agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                'headers'    => [
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ],
            ]);
            if (!is_wp_error($etsy_resp)) {
                $etsy_body    = wp_remote_retrieve_body($etsy_resp);
                $etsy_og_title = '';
                if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $etsy_body, $m) ||
                    preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\'][^>]*>/i', $etsy_body, $m)) {
                    $etsy_og_title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }
                // Only trust the result if it's a real listing page, not a bot-detection shell
                if ($etsy_og_title && !preg_match('/^etsy(\.com)?$/i', trim($etsy_og_title))) {
                    $data['name'] = preg_replace('/\s*[-|–]\s*Etsy\s*$/iu', '', $etsy_og_title);
                    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $etsy_body, $m) ||
                        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $etsy_body, $m)) {
                        $data['image_url'] = esc_url_raw(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    }
                    // Replace $body so the description extraction section below uses the real page
                    $body = $etsy_body;
                }
            }
            // Name fallback: always use URL slug when name still looks like a bare domain
            if (empty($data['name']) || preg_match('/^[\w.-]+\.\w{2,}$/', trim($data['name']))) {
                if (preg_match('/etsy\.com\/listing\/\d+\/([^?&#]+)/i', $url, $m)) {
                    $data['name'] = ucwords(str_replace('-', ' ', rawurldecode($m[1])));
                }
            }
        }

        // Description — Strategy 1: JSON-LD Product schema
        $description = '';
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $body, $ld_blocks)) {
            foreach ($ld_blocks[1] as $json_raw) {
                $ld = json_decode(trim($json_raw), true);
                if (!$ld) continue;
                foreach (isset($ld[0]) ? $ld : [$ld] as $node) {
                    if (in_array($node['@type'] ?? '', ['Product', 'ItemPage'], true) && !empty($node['description'])) {
                        $description = $node['description'];
                        break 2;
                    }
                }
            }
        }

        // Description — Strategy 2: og:description
        if (empty($description)) {
            if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
                preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\'][^>]*>/i', $body, $m)) {
                $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        // Description — Strategy 3: meta description
        if (empty($description)) {
            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $body, $m) ||
                preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\'][^>]*>/i', $body, $m)) {
                $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        if (!empty($description)) {
            $description = trim(html_entity_decode($description, ENT_QUOTES, 'UTF-8'));
            // Strip leading "Retailer.com: " prefixes
            $description = preg_replace('/^(Amazon\.com|Amazon|Target|Walmart\.com|Walmart|eBay|Etsy)\s*[:–—-]\s*/iu', '', $description);
            // Strip trailing " - Retailer.com" suffixes
            $description = preg_replace('/\s*[-–—|]\s*(Amazon\.com|Walmart\.com|Target\.com|Etsy|eBay)\s*$/iu', '', $description);
            // Strip common retail noise
            $description = preg_replace('/\s*(Free (shipping|returns?)|Ships free|Shop now|Buy now|Order now|In stock|Add to cart)[^.]*\.?\s*$/iu', '', $description);
            $description = trim($description);
            // Truncate: try to break at a sentence end within 160 chars
            if (mb_strlen($description) > 160) {
                $short    = mb_substr($description, 0, 160);
                $last_end = max(
                    (int) strrpos($short, '. '),
                    (int) strrpos($short, '! '),
                    (int) strrpos($short, '? ')
                );
                if ($last_end > 80) {
                    $description = mb_substr($short, 0, $last_end + 1);
                } else {
                    $last_space  = (int) strrpos($short, ' ');
                    $description = ($last_space > 80 ? mb_substr($short, 0, $last_space) : $short) . '…';
                }
            }
            $data['description'] = trim($description);
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';
        $affiliate_result = (new Restart_Registry_Affiliate_Converter())->convert_url($url);
        $data['retailer']     = $affiliate_result['retailer'];
        $data['is_affiliate'] = $affiliate_result['is_affiliate'];

        wp_send_json_success($data);
    }
}
