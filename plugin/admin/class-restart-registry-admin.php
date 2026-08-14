<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://the-restart.co
 * @since      1.0.0
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/admin
 */

class Restart_Registry_Admin {

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        add_action('admin_menu',                                        array($this, 'add_admin_menu'));
        add_action('admin_init',                                        array($this, 'register_settings'));
        add_action('admin_post_restart_registry_create_pages',          array($this, 'handle_create_pages'));
        add_action('admin_post_restart_registry_admin_edit',            array($this, 'handle_registry_edit'));
        add_action('wp_ajax_restart_registry_test_lambda',              array($this, 'ajax_test_lambda'));
        add_action('wp_ajax_restart_registry_reconvert_affiliates',     array($this, 'ajax_reconvert_affiliates'));
        add_filter('get_edit_post_link',                                array($this, 'filter_edit_post_link'), 10, 3);
        add_filter('mce_external_plugins',                              array($this, 'mce_external_plugins'));
        add_filter('mce_buttons',                                       array($this, 'mce_buttons'));
    }

    public function mce_external_plugins(array $plugins): array {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['post', 'page'], true)) {
            return $plugins;
        }
        $plugins['restart_item'] = plugin_dir_url(__FILE__) . 'js/restart-registry-tinymce.js';
        return $plugins;
    }

    public function mce_buttons(array $buttons): array {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['post', 'page'], true)) {
            return $buttons;
        }
        $buttons[] = 'restart_item';
        $buttons[] = 'restart_favorites_row';
        return $buttons;
    }

    public function enqueue_styles($hook) {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/restart-registry-admin.css', array(), $this->version, 'all');

        if (isset($_GET['page']) && $_GET['page'] === 'restart-registry-edit') {
            wp_enqueue_style(
                $this->plugin_name . '-public',
                plugin_dir_url(dirname(__FILE__)) . 'public/css/restart-registry-public.css',
                array(),
                $this->version,
                'all'
            );
        }
    }

    public function enqueue_scripts($hook) {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/restart-registry-admin.js', array(), $this->version, true);
        wp_localize_script($this->plugin_name, 'rrAdmin', array(
            'ajaxurl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('restart_registry_admin_nonce'),
            // Used by the [restart_favorites_row] TinyMCE modal to call the
            // existing restart_registry_fetch_url AJAX action (same nonce
            // action the public registry-builder JS uses for that endpoint).
            'fetchNonce'  => wp_create_nonce('restart_registry_nonce'),
        ));

        if (isset($_GET['page']) && $_GET['page'] === 'restart-registry-edit') {
            wp_enqueue_media();
            wp_add_inline_script($this->plugin_name, $this->get_registry_edit_inline_js());
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Gift Registry', 'restart-registry'),
            __('Gift Registry', 'restart-registry'),
            'manage_options',
            'restart-registry',
            array($this, 'display_dashboard_page'),
            'dashicons-heart',
            30
        );

        add_submenu_page(
            'restart-registry',
            __('Dashboard', 'restart-registry'),
            __('Dashboard', 'restart-registry'),
            'manage_options',
            'restart-registry',
            array($this, 'display_dashboard_page')
        );

        add_submenu_page(
            'restart-registry',
            __('All Registries', 'restart-registry'),
            __('All Registries', 'restart-registry'),
            'manage_options',
            'restart-registry-list',
            array($this, 'display_registries_page')
        );

        add_submenu_page(
            'restart-registry',
            __('Affiliate Settings', 'restart-registry'),
            __('Affiliate Settings', 'restart-registry'),
            'manage_options',
            'restart-registry-affiliates',
            array($this, 'display_affiliates_page')
        );

        add_submenu_page(
            'restart-registry',
            __('Settings', 'restart-registry'),
            __('Settings', 'restart-registry'),
            'manage_options',
            'restart-registry-settings',
            array($this, 'display_settings_page')
        );

        add_submenu_page(
            'restart-registry',
            __('Edit Registry', 'restart-registry'),
            __('Edit Registry', 'restart-registry'),
            'manage_options',
            'restart-registry-edit',
            array($this, 'display_registry_edit_page')
        );
        // Intentionally not calling remove_submenu_page here — doing so causes
        // menu.php to rebuild $_registered_pages without our page, blocking access.
        // The menu item is hidden via CSS in restart-registry-admin.css instead.
    }

    public function register_settings() {
        register_setting('restart_registry_affiliates', 'restart_registry_amazon_tag');
        register_setting('restart_registry_affiliates', 'restart_registry_target_id');
        register_setting('restart_registry_affiliates', 'restart_registry_walmart_id');
        register_setting('restart_registry_affiliates', 'restart_registry_etsy_id');
        register_setting('restart_registry_affiliates', 'restart_registry_ebay_id');
        register_setting('restart_registry_affiliates', 'restart_registry_bestbuy_id');
        register_setting('restart_registry_affiliates', 'restart_registry_homedepot_id');
        register_setting('restart_registry_affiliates', 'restart_registry_wayfair_id');
        register_setting('restart_registry_affiliates', 'restart_registry_shareasale_id');
        register_setting('restart_registry_affiliates', 'restart_registry_shareasale_merchant');
        register_setting('restart_registry_affiliates', 'restart_registry_cj_id');
        register_setting('restart_registry_affiliates', 'restart_registry_affiliate_disclosure');

        register_setting('restart_registry_affiliates', 'restart_registry_custom_retailers', [
            'sanitize_callback' => array($this, 'sanitize_custom_retailers'),
        ]);

        // Retailer API keys
        register_setting('restart_registry_affiliates', 'restart_registry_etsy_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('restart_registry_affiliates', 'restart_registry_anthropic_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('restart_registry_settings', 'restart_registry_page_id');
        register_setting('restart_registry_settings', 'restart_registry_email_from');
        register_setting('restart_registry_settings', 'restart_registry_email_name');
        register_setting('restart_registry_settings', 'restart_registry_allow_guests');
        register_setting('restart_registry_settings', 'restart_lambda_url', [
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('restart_registry_settings', 'restart_lambda_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('restart_registry_settings', 'restart_lambda_username');
        register_setting('restart_registry_settings', 'restart_lambda_app_password');

        add_settings_section(
            'restart_registry_affiliate_section',
            __('Affiliate Program IDs', 'restart-registry'),
            array($this, 'affiliate_section_callback'),
            'restart_registry_affiliates'
        );

        $affiliate_fields = array(
            'amazon_tag' => array('label' => 'Amazon Associates Tag', 'description' => 'Your Amazon Associates tag (e.g., yourtag-20)'),
            'target_id' => array('label' => 'Target Affiliate ID', 'description' => 'Your Target affiliate ID'),
            'walmart_id' => array('label' => 'Walmart Affiliate ID', 'description' => 'Your Walmart affiliate ID'),
            'etsy_id' => array('label' => 'Etsy Affiliate ID', 'description' => 'Your Etsy affiliate ID'),
            'ebay_id' => array('label' => 'eBay Campaign ID', 'description' => 'Your eBay Partner Network campaign ID'),
            'bestbuy_id' => array('label' => 'Best Buy Affiliate ID', 'description' => 'Your Best Buy affiliate ID'),
            'homedepot_id' => array('label' => 'Home Depot Affiliate ID', 'description' => 'Your Home Depot affiliate ID'),
            'wayfair_id' => array('label' => 'Wayfair Affiliate ID', 'description' => 'Your Wayfair affiliate ID'),
        );

        foreach ($affiliate_fields as $key => $field) {
            add_settings_field(
                'restart_registry_' . $key,
                $field['label'],
                array($this, 'text_field_callback'),
                'restart_registry_affiliates',
                'restart_registry_affiliate_section',
                array(
                    'label_for' => 'restart_registry_' . $key,
                    'description' => $field['description'],
                )
            );
        }

        add_settings_field(
            'restart_registry_affiliate_disclosure',
            __('Affiliate Disclosure', 'restart-registry'),
            array($this, 'textarea_field_callback'),
            'restart_registry_affiliates',
            'restart_registry_affiliate_section',
            array(
                'label_for' => 'restart_registry_affiliate_disclosure',
                'description' => __('This disclosure will be shown on registry pages to comply with FTC guidelines.', 'restart-registry'),
            )
        );

        // ── Retailer API Keys section ─────────────────────────────────────────
        add_settings_section(
            'restart_registry_api_keys_section',
            __('Retailer API Keys', 'restart-registry'),
            array($this, 'api_keys_section_callback'),
            'restart_registry_affiliates'
        );

        add_settings_field(
            'restart_registry_etsy_api_key',
            __('Etsy API Key', 'restart-registry'),
            array($this, 'api_key_field_callback'),
            'restart_registry_affiliates',
            'restart_registry_api_keys_section',
            array(
                'label_for'   => 'restart_registry_etsy_api_key',
                'description' => __('Enables reliable title, image, price, and description fetching for Etsy items. Bypasses Cloudflare bot-detection entirely.', 'restart-registry'),
                'get_key_url' => 'https://www.etsy.com/developers/register',
                'get_key_label' => __('Get your Etsy API key →', 'restart-registry'),
            )
        );

        add_settings_field(
            'restart_registry_anthropic_api_key',
            __('Anthropic API Key', 'restart-registry'),
            array($this, 'api_key_field_callback'),
            'restart_registry_affiliates',
            'restart_registry_api_keys_section',
            array(
                'label_for'   => 'restart_registry_anthropic_api_key',
                'description' => __('Enables AI-powered product data extraction via Claude. When configured, the scraper uses Claude Haiku to reliably extract name, price, image, and description from any retailer page — no per-retailer regex maintenance needed.', 'restart-registry'),
                'get_key_url' => 'https://console.anthropic.com/settings/keys',
                'get_key_label' => __('Get your Anthropic API key →', 'restart-registry'),
            )
        );
    }

    public function sanitize_custom_retailers($value) {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        foreach ($value as $row) {
            $name     = sanitize_text_field($row['name'] ?? '');
            $domains  = sanitize_text_field($row['domains'] ?? '');
            $template = sanitize_text_field($row['template'] ?? '');
            $aff_id   = sanitize_text_field($row['affiliate_id'] ?? '');
            $merch_id = sanitize_text_field($row['merchant_id'] ?? '');
            if (empty($name) || empty($domains) || empty($template)) {
                continue;
            }
            $clean[] = [
                'name'         => $name,
                'domains'      => $domains,
                'template'     => $template,
                'affiliate_id' => $aff_id,
                'merchant_id'  => $merch_id,
            ];
        }
        return $clean;
    }

    public function affiliate_section_callback() {
        echo '<p>' . __('Enter your affiliate program IDs below. When users add products from these retailers, the links will automatically be converted to affiliate links. This is done transparently - users can see the original link and affiliate link.', 'restart-registry') . '</p>';
    }

    public function text_field_callback($args) {
        $option = get_option($args['label_for']);
        ?>
        <input type="text" 
               id="<?php echo esc_attr($args['label_for']); ?>" 
               name="<?php echo esc_attr($args['label_for']); ?>" 
               value="<?php echo esc_attr($option); ?>" 
               class="regular-text">
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function textarea_field_callback($args) {
        $option = get_option($args['label_for']);
        $default = __('Some links on this registry are affiliate links. When you purchase through these links, the registry owner may earn a small commission at no additional cost to you.', 'restart-registry');
        ?>
        <textarea id="<?php echo esc_attr($args['label_for']); ?>" 
                  name="<?php echo esc_attr($args['label_for']); ?>" 
                  rows="4" 
                  class="large-text"><?php echo esc_textarea($option ?: $default); ?></textarea>
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function api_keys_section_callback() {
        echo '<p>' . __('API keys enable reliable product data fetching for retailers that block HTML scraping. When a key is present the API is used automatically — no scraper needed.', 'restart-registry') . '</p>';
    }

    public function api_key_field_callback($args) {
        $value         = get_option($args['label_for'], '');
        $is_configured = !empty($value);
        ?>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <input type="text"
                   id="<?php echo esc_attr($args['label_for']); ?>"
                   name="<?php echo esc_attr($args['label_for']); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text"
                   autocomplete="off"
                   placeholder="<?php esc_attr_e('Paste your API key here', 'restart-registry'); ?>">
            <?php if ($is_configured): ?>
                <span style="color:#46b450;font-weight:600">&#10003; <?php _e('Configured', 'restart-registry'); ?></span>
            <?php else: ?>
                <span style="color:#999"><?php _e('Not configured', 'restart-registry'); ?></span>
            <?php endif; ?>
        </div>
        <?php if (!empty($args['description'])): ?>
            <p class="description">
                <?php echo esc_html($args['description']); ?>
                <?php if (!empty($args['get_key_url'])): ?>
                    &nbsp;<a href="<?php echo esc_url($args['get_key_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($args['get_key_label'] ?? 'Get API key →'); ?></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /** Create the "My Registry" page and redirect back to settings. */
    public function handle_create_pages() {
        check_admin_referer('restart_registry_create_pages');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'restart-registry'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-activator.php';
        Restart_Registry_Activator::create_pages();

        wp_redirect(add_query_arg(
            ['page' => 'restart-registry-settings', 'pages_created' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /** AJAX: ping the Lambda service and return its health status. */
    public function ajax_test_lambda() {
        check_ajax_referer('restart_registry_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        $url = get_option('restart_lambda_url') ?: getenv('RESTART_LAMBDA_URL') ?: '';
        if (empty($url)) {
            wp_send_json_error(['message' => __('Lambda URL is not configured.', 'restart-registry')]);
        }

        $api_key  = get_option('restart_lambda_api_key') ?: getenv('RESTART_LAMBDA_API_KEY') ?: '';
        $username = get_option('restart_lambda_username') ?: getenv('RESTART_LAMBDA_USERNAME') ?: '';
        $password = get_option('restart_lambda_app_password') ?: getenv('RESTART_LAMBDA_APP_PASSWORD') ?: '';
        $headers  = [];
        if ($api_key) {
            $headers['x-api-key'] = $api_key;
        }
        if ($username && $password) {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
        }

        $response = wp_remote_get(rtrim($url, '/') . '/health', ['timeout' => 8, 'headers' => $headers]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            wp_send_json_success(['message' => __('Connection successful!', 'restart-registry'), 'status' => $body]);
        }

        wp_send_json_error(['message' => sprintf(__('Unexpected response: HTTP %d', 'restart-registry'), $code)]);
    }

    /** AJAX: re-run the affiliate converter on every item's original URL. */
    public function ajax_reconvert_affiliates() {
        check_ajax_referer('restart_registry_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-lambda-api-client.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';

        $lambda    = new Restart_Registry_Lambda_Client();
        $converter = new Restart_Registry_Affiliate_Converter();

        if (!$lambda->is_configured()) {
            wp_send_json_error(['message' => __('Lambda API is not configured.', 'restart-registry')]);
        }

        $registry_ids = get_posts([
            'post_type'      => 'restart-registry',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'private', 'draft'],
            'fields'         => 'ids',
        ]);

        $updated      = 0;
        $skipped      = 0;
        $not_found    = 0;
        $errors       = 0;
        $first_error  = '';

        foreach ($registry_ids as $registry_id) {
            $item_ids   = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
            $clean_ids  = [];
            $meta_dirty = false;

            foreach ($item_ids as $item_id) {
                $item = $lambda->get_item((int) $item_id);
                if ($item === null) {
                    $not_found++;
                    $meta_dirty = true;
                    continue;
                }
                if (is_wp_error($item)) {
                    $errors++;
                    if (!$first_error) $first_error = $item->get_error_message();
                    $clean_ids[] = $item_id;
                    continue;
                }

                $clean_ids[] = $item_id;

                $url = $item['url'] ?? '';
                if (empty($url)) {
                    $skipped++;
                    continue;
                }

                $result = $converter->convert_url($url);

                if (!$result['is_affiliate']) {
                    $skipped++;
                    continue;
                }

                $update = $lambda->update_item((int) $item_id, [
                    'affiliate_url'    => $result['affiliate_url'],
                    'affiliate_status' => 'active',
                ]);

                if (is_wp_error($update)) {
                    $errors++;
                } else {
                    $updated++;
                }
            }

            if ($meta_dirty) {
                update_post_meta($registry_id, 'restart_item_ids', json_encode(array_values($clean_ids)));
            }
        }

        $parts = [
            sprintf(_n('%d item updated.', '%d items updated.', $updated, 'restart-registry'), $updated),
        ];
        if ($skipped)   $parts[] = sprintf(_n('%d skipped.', '%d skipped.', $skipped, 'restart-registry'), $skipped);
        if ($not_found) $parts[] = sprintf(_n('%d stale reference removed.', '%d stale references removed.', $not_found, 'restart-registry'), $not_found);
        if ($errors)    $parts[] = sprintf(_n('%d error.', '%d errors.', $errors, 'restart-registry'), $errors) . ($first_error ? " ({$first_error})" : '');

        wp_send_json_success([
            'message'   => implode(' ', $parts),
            'updated'   => $updated,
            'skipped'   => $skipped,
            'not_found' => $not_found,
            'errors'    => $errors,
        ]);
    }

    public function display_dashboard_page() {
        $registries_count = wp_count_posts('restart-registry');
        $total = ($registries_count->publish ?? 0) + ($registries_count->private ?? 0);

        $recent = get_posts([
            'post_type'      => 'restart-registry',
            'posts_per_page' => 5,
            'post_status'    => ['publish', 'private'],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="restart-registry-dashboard">
                <div class="dashboard-widgets">
                    <div class="dashboard-widget">
                        <h3><?php _e('Total Registries', 'restart-registry'); ?></h3>
                        <span class="count"><?php echo intval($total); ?></span>
                    </div>
                    <div class="dashboard-widget">
                        <h3><?php _e('Lambda API', 'restart-registry'); ?></h3>
                        <span class="count" style="font-size:14px">
                            <?php echo get_option('restart_lambda_url') ? __('Configured', 'restart-registry') : '<span style="color:#dc3545">' . __('Not set', 'restart-registry') . '</span>'; ?>
                        </span>
                    </div>
                </div>

                <div class="dashboard-recent">
                    <h2><?php _e('Recent Registries', 'restart-registry'); ?></h2>
                    <?php if ($recent): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Title', 'restart-registry'); ?></th>
                                    <th><?php _e('Owner', 'restart-registry'); ?></th>
                                    <th><?php _e('Status', 'restart-registry'); ?></th>
                                    <th><?php _e('Created', 'restart-registry'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $post):
                                    $author = get_userdata($post->post_author);
                                ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></td>
                                        <td><?php echo $author ? esc_html($author->display_name) : '—'; ?></td>
                                        <td><?php echo $post->post_status === 'publish' ? __('Public', 'restart-registry') : __('Private', 'restart-registry'); ?></td>
                                        <td><?php echo esc_html(get_the_date(get_option('date_format'), $post->ID)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php _e('No registries yet.', 'restart-registry'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function display_registries_page() {
        $registries = get_posts([
            'post_type'      => 'restart-registry',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'private'],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Title', 'restart-registry'); ?></th>
                        <th><?php _e('Owner', 'restart-registry'); ?></th>
                        <th><?php _e('Status', 'restart-registry'); ?></th>
                        <th><?php _e('Items (meta)', 'restart-registry'); ?></th>
                        <th><?php _e('Created', 'restart-registry'); ?></th>
                        <th><?php _e('Actions', 'restart-registry'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($registries): ?>
                        <?php foreach ($registries as $post):
                            $author   = get_userdata($post->post_author);
                            $item_ids = json_decode(get_post_meta($post->ID, 'restart_item_ids', true) ?: '[]', true);
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                                <td><?php echo $author ? esc_html($author->display_name) : '—'; ?></td>
                                <td><?php echo $post->post_status === 'publish' ? __('Public', 'restart-registry') : __('Private', 'restart-registry'); ?></td>
                                <td><?php echo count($item_ids); ?></td>
                                <td><?php echo esc_html(get_the_date(get_option('date_format'), $post->ID)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank"><?php _e('View', 'restart-registry'); ?></a>
                                    &nbsp;|&nbsp;
                                    <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php _e('Edit', 'restart-registry'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><?php _e('No registries found.', 'restart-registry'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function display_affiliates_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Affiliate settings saved.', 'restart-registry'); ?></p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info">
                <p><strong><?php _e('How Affiliate Links Work:', 'restart-registry'); ?></strong></p>
                <p><?php _e('When users add products from supported retailers, the plugin automatically converts the links to affiliate links using your IDs. The original link is preserved and users can see both - this ensures transparency and builds trust.', 'restart-registry'); ?></p>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields('restart_registry_affiliates');
                do_settings_sections('restart_registry_affiliates');
                submit_button(__('Save Affiliate Settings', 'restart-registry'));
                ?>
            </form>

            <hr>

            <h2><?php _e('Custom Retailers', 'restart-registry'); ?></h2>
            <p><?php _e('Add retailers not in the built-in list. Use <code>{url}</code>, <code>{affiliate_id}</code>, and <code>{merchant_id}</code> as placeholders in the URL template.', 'restart-registry'); ?></p>

            <form action="options.php" method="post" id="rr-custom-retailers-form">
                <?php settings_fields('restart_registry_affiliates'); ?>
                <table class="wp-list-table widefat fixed striped" id="rr-custom-retailers-table">
                    <thead>
                        <tr>
                            <th style="width:14%"><?php _e('Retailer Name', 'restart-registry'); ?></th>
                            <th style="width:18%"><?php _e('Domains', 'restart-registry'); ?></th>
                            <th style="width:35%"><?php _e('Affiliate URL Template', 'restart-registry'); ?></th>
                            <th style="width:13%"><?php _e('Affiliate ID', 'restart-registry'); ?></th>
                            <th style="width:13%"><?php _e('Merchant ID', 'restart-registry'); ?></th>
                            <th style="width:7%"></th>
                        </tr>
                    </thead>
                    <tbody id="rr-custom-retailers-body">
                        <?php
                        $custom_retailers = get_option('restart_registry_custom_retailers', []);
                        if (!is_array($custom_retailers)) $custom_retailers = [];
                        foreach ($custom_retailers as $i => $row):
                        ?>
                        <tr class="rr-custom-retailer-row">
                            <td><input type="text" name="restart_registry_custom_retailers[<?php echo $i; ?>][name]" value="<?php echo esc_attr($row['name'] ?? ''); ?>" class="widefat"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[<?php echo $i; ?>][domains]" value="<?php echo esc_attr($row['domains'] ?? ''); ?>" class="widefat" placeholder="example.com, shop.com"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[<?php echo $i; ?>][template]" value="<?php echo esc_attr($row['template'] ?? ''); ?>" class="widefat" placeholder="https://network.com/r?url={url}&id={affiliate_id}"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[<?php echo $i; ?>][affiliate_id]" value="<?php echo esc_attr($row['affiliate_id'] ?? ''); ?>" class="widefat"></td>
                            <td><input type="text" name="restart_registry_custom_retailers[<?php echo $i; ?>][merchant_id]" value="<?php echo esc_attr($row['merchant_id'] ?? ''); ?>" class="widefat"></td>
                            <td><button type="button" class="button rr-remove-retailer"><?php _e('Remove', 'restart-registry'); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" id="rr-add-retailer" class="button button-secondary"><?php _e('+ Add Retailer', 'restart-registry'); ?></button>
                </p>

                <?php submit_button(__('Save Custom Retailers', 'restart-registry'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('Re-convert Affiliate Links', 'restart-registry'); ?></h2>
            <p><?php _e('Re-runs the affiliate converter on every item\'s original URL using your current affiliate IDs. Use this after updating an affiliate ID above.', 'restart-registry'); ?></p>
            <button type="button" id="rr-reconvert-affiliates" class="button button-secondary">
                <?php _e('Re-convert All Affiliate Links', 'restart-registry'); ?>
            </button>
            <span id="rr-reconvert-result" style="margin-left:10px;font-style:italic"></span>

            <hr>

            <div class="supported-retailers">
                <h2><?php _e('Supported Retailers', 'restart-registry'); ?></h2>
                <ul>
                    <li><strong>Amazon</strong> - amazon.com, amazon.co.uk, amazon.ca, amazon.de, amazon.fr</li>
                    <li><strong>Target</strong> - target.com</li>
                    <li><strong>Walmart</strong> - walmart.com</li>
                    <li><strong>Etsy</strong> - etsy.com</li>
                    <li><strong>eBay</strong> - ebay.com, ebay.co.uk</li>
                    <li><strong>Best Buy</strong> - bestbuy.com</li>
                    <li><strong>Home Depot</strong> - homedepot.com</li>
                    <li><strong>Wayfair</strong> - wayfair.com</li>
                </ul>
                <p><?php _e('Additional retailers can be configured in the Custom Retailers section above.', 'restart-registry'); ?></p>
            </div>
        </div>
        <?php
    }

    public function display_settings_page() {
        $page_id   = get_option('restart_registry_page_id');
        $page_ok   = $page_id && get_post($page_id) && get_post_status($page_id) !== 'trash';
        $pages_created = isset($_GET['pages_created']);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if ($pages_created): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('"My Registry" page created successfully.', 'restart-registry'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Settings saved.', 'restart-registry'); ?></p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields('restart_registry_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="restart_registry_page_id"><?php _e('Registry Page', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_pages([
                                'name'             => 'restart_registry_page_id',
                                'selected'         => $page_id,
                                'show_option_none' => __('— Select a page —', 'restart-registry'),
                            ]);
                            ?>
                            <p class="description">
                                <?php if ($page_ok): ?>
                                    <?php printf(
                                        __('Currently set to <a href="%s" target="_blank">%s</a>.', 'restart-registry'),
                                        esc_url(get_permalink($page_id)),
                                        esc_html(get_the_title($page_id))
                                    ); ?>
                                <?php else: ?>
                                    <?php _e('No page configured. Use the button below to create one automatically.', 'restart-registry'); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_registry_email_from"><?php _e('Email From Address', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="email"
                                   id="restart_registry_email_from"
                                   name="restart_registry_email_from"
                                   value="<?php echo esc_attr(get_option('restart_registry_email_from', get_option('admin_email'))); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_registry_email_name"><?php _e('Email From Name', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="restart_registry_email_name"
                                   name="restart_registry_email_name"
                                   value="<?php echo esc_attr(get_option('restart_registry_email_name', get_bloginfo('name'))); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_lambda_url"><?php _e('Lambda API URL', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                   id="restart_lambda_url"
                                   name="restart_lambda_url"
                                   value="<?php echo esc_attr(get_option('restart_lambda_url') ?: getenv('RESTART_LAMBDA_URL') ?: ''); ?>"
                                   class="regular-text"
                                   placeholder="https://your-lambda-endpoint.execute-api.us-east-1.amazonaws.com">
                            <p class="description"><?php _e('Base URL of the Restart Lambda FastAPI service (no trailing slash). Can also be set via RESTART_LAMBDA_URL env var.', 'restart-registry'); ?></p>
                            <button type="button" id="rr-test-lambda" class="button" style="margin-top:6px">
                                <?php _e('Test Connection', 'restart-registry'); ?>
                            </button>
                            <span id="rr-lambda-test-result" style="margin-left:10px;font-style:italic"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_lambda_api_key"><?php _e('API Gateway Key', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="restart_lambda_api_key"
                                   name="restart_lambda_api_key"
                                   value="<?php echo esc_attr(get_option('restart_lambda_api_key', '')); ?>"
                                   class="regular-text"
                                   autocomplete="new-password">
                            <p class="description"><?php _e('API key from your API Gateway usage plan. Sent as x-api-key to authenticate at the gateway level.', 'restart-registry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_lambda_username"><?php _e('WP Username', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="restart_lambda_username"
                                   name="restart_lambda_username"
                                   value="<?php echo esc_attr(get_option('restart_lambda_username', '')); ?>"
                                   class="regular-text"
                                   autocomplete="off">
                            <p class="description"><?php _e('WordPress username sent to Lambda so it can authenticate back to the WP REST API.', 'restart-registry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_lambda_app_password"><?php _e('WP Application Password', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="restart_lambda_app_password"
                                   name="restart_lambda_app_password"
                                   value="<?php echo esc_attr(get_option('restart_lambda_app_password', '')); ?>"
                                   class="regular-text"
                                   autocomplete="new-password">
                            <p class="description"><?php _e('Application Password for the username above. Generate one under Users → Profile → Application Passwords.', 'restart-registry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Guest Purchases', 'restart-registry'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="restart_registry_allow_guests"
                                       value="1"
                                       <?php checked(get_option('restart_registry_allow_guests'), 1); ?>>
                                <?php _e('Allow guests to mark items as purchased without logging in', 'restart-registry'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php _e('Page Setup', 'restart-registry'); ?></h2>
            <p><?php _e('Click below to automatically create a "My Registry" page with the <code>[restart_registry]</code> shortcode.', 'restart-registry'); ?></p>
            <?php if ($page_ok): ?>
                <p><?php printf(
                    __('<strong>Page exists:</strong> <a href="%s" target="_blank">%s</a>', 'restart-registry'),
                    esc_url(get_permalink($page_id)),
                    esc_html(get_the_title($page_id))
                ); ?></p>
            <?php endif; ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="restart_registry_create_pages">
                <?php wp_nonce_field('restart_registry_create_pages'); ?>
                <?php submit_button(
                    $page_ok
                        ? __('Recreate Registry Page', 'restart-registry')
                        : __('Create "My Registry" Page', 'restart-registry'),
                    'secondary',
                    '',
                    false
                ); ?>
            </form>

            <hr>

            <h2><?php _e('Shortcodes', 'restart-registry'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><code>[restart_registry]</code></th>
                    <td><?php _e('Main registry interface — shows create form, owner manage view, or guest view depending on context.', 'restart-registry'); ?></td>
                </tr>
                <tr>
                    <th><code>[restart_registry_view registry="ID"]</code></th>
                    <td><?php _e('Read-only view of a specific registry by WP post ID or slug.', 'restart-registry'); ?></td>
                </tr>
                <tr>
                    <th><code>[restart_registry_create]</code></th>
                    <td><?php _e('Registry creation form only.', 'restart-registry'); ?></td>
                </tr>
            </table>
        </div>
        <?php
    }

    // =========================================================================
    // Custom registry edit page
    // =========================================================================

    public function filter_edit_post_link(string $link, int $post_id, string $context): string {
        if (get_post_type($post_id) === 'restart-registry') {
            return admin_url('admin.php?page=restart-registry-edit&post=' . $post_id);
        }
        return $link;
    }

    public function display_registry_edit_page(): void {
        $post_id = (int) ($_GET['post'] ?? 0);
        if (!$post_id) {
            wp_die(__('No registry specified.', 'restart-registry'));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            wp_die(__('Registry not found.', 'restart-registry'));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-controller.php';
        $controller = new Restart_Registry_Controller();

        $items    = $controller->get_registry_items($post_id);
        $messages = $controller->get_purchase_messages($post_id);
        $invitees = $controller->get_registry_invites($post_id);

        $event_type     = get_post_meta($post_id, 'restart_event_type', true) ?: '';
        $event_date     = get_post_meta($post_id, 'restart_event_date', true) ?: '';
        $raw_for_self   = get_post_meta($post_id, 'restart_is_for_self', true);
        $is_for_self    = ($raw_for_self === '' || $raw_for_self === false) ? true : (string) $raw_for_self !== '0';
        $recipient_name = get_post_meta($post_id, 'restart_recipient_name', true) ?: '';
        $recipient_rel  = get_post_meta($post_id, 'restart_recipient_relationship', true) ?: '';
        $recipient_email = get_post_meta($post_id, 'restart_recipient_email', true) ?: '';
        $thumbnail_id   = get_post_thumbnail_id($post_id) ?: 0;
        $hero_url       = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'large') : '';
        $author         = get_userdata($post->post_author);

        $allowed_statuses = ['publish', 'private', 'draft', 'restart-archived'];
        ?>
        <div class="wrap rr-admin-edit-wrap">

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Registry updated.', 'restart-registry'); ?></p>
                </div>
            <?php endif; ?>

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="rr-admin-edit-form">
                <input type="hidden" name="action" value="restart_registry_admin_edit">
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
                <?php wp_nonce_field('restart_registry_admin_edit_' . $post_id, 'rr_admin_edit_nonce'); ?>

                <!-- Toolbar -->
                <div class="rr-toolbar rr-admin-toolbar">
                    <div class="rr-admin-toolbar__meta">
                        <label for="rr-admin-post-status" class="screen-reader-text"><?php _e('Status', 'restart-registry'); ?></label>
                        <select name="post_status" id="rr-admin-post-status" class="rr-admin-status-select">
                            <option value="publish"          <?php selected($post->post_status, 'publish'); ?>><?php _e('Public', 'restart-registry'); ?></option>
                            <option value="private"          <?php selected($post->post_status, 'private'); ?>><?php _e('Private', 'restart-registry'); ?></option>
                            <option value="draft"            <?php selected($post->post_status, 'draft'); ?>><?php _e('Draft', 'restart-registry'); ?></option>
                            <option value="restart-archived" <?php selected($post->post_status, 'restart-archived'); ?>><?php _e('Archived', 'restart-registry'); ?></option>
                        </select>
                        <?php if ($author): ?>
                            <span class="rr-admin-owner">
                                <?php _e('Owner:', 'restart-registry'); ?>
                                <a href="<?php echo esc_url(get_edit_user_link($post->post_author)); ?>"><?php echo esc_html($author->display_name); ?></a>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="rr-admin-toolbar__actions">
                        <a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank" class="button button-secondary"><?php _e('View Registry ↗', 'restart-registry'); ?></a>
                        <?php submit_button(__('Save Changes', 'restart-registry'), 'primary', 'submit', false); ?>
                    </div>
                </div>

                <!-- Registry header: title + event meta + recipient -->
                <div class="rr-registry-header rr-admin-section">
                    <div class="rr-admin-title-row">
                        <input type="text" id="rr-admin-title" name="post_title"
                            class="rr-registry-title rr-admin-title-input widefat"
                            value="<?php echo esc_attr($post->post_title); ?>" required
                            placeholder="<?php esc_attr_e('Registry title…', 'restart-registry'); ?>">
                    </div>

                    <p class="rr-event-meta rr-admin-event-meta">
                        <span class="rr-event-meta__group">
                            <label class="rr-event-meta__label" for="rr-admin-event-type"><?php _e('Event:', 'restart-registry'); ?></label>
                            <input type="text" id="rr-admin-event-type" name="event_type"
                                class="rr-admin-event-input"
                                value="<?php echo esc_attr($event_type); ?>"
                                placeholder="<?php esc_attr_e('e.g. Wedding, Baby Shower…', 'restart-registry'); ?>">
                        </span>
                        <span class="rr-event-meta__group">
                            <label class="rr-event-meta__label" for="rr-admin-event-date"><?php _e('Date:', 'restart-registry'); ?></label>
                            <input type="date" id="rr-admin-event-date" name="event_date"
                                value="<?php echo esc_attr($event_date); ?>">
                        </span>
                    </p>

                    <div class="rr-admin-recipient-section">
                        <label class="rr-admin-checkbox-label">
                            <input type="checkbox" name="is_for_self" value="1" id="rr-admin-is-for-self" <?php checked($is_for_self); ?>>
                            <?php _e('Registry is for the owner (no recipient)', 'restart-registry'); ?>
                        </label>
                        <div id="rr-admin-recipient-fields" class="rr-admin-recipient-fields" <?php echo $is_for_self ? 'hidden' : ''; ?>>
                            <p class="rr-recipient rr-admin-recipient-row">
                                <label class="rr-admin-label" for="rr-admin-recipient-name"><?php _e('For', 'restart-registry'); ?></label>
                                <input type="text" id="rr-admin-recipient-name" name="recipient_name"
                                    value="<?php echo esc_attr($recipient_name); ?>"
                                    placeholder="<?php esc_attr_e("Recipient's name", 'restart-registry'); ?>">
                                <input type="text" name="recipient_relationship"
                                    value="<?php echo esc_attr($recipient_rel); ?>"
                                    placeholder="<?php esc_attr_e('Relationship (e.g. Daughter)', 'restart-registry'); ?>">
                                <input type="email" name="recipient_email"
                                    value="<?php echo esc_attr($recipient_email); ?>"
                                    placeholder="<?php esc_attr_e('Email (optional)', 'restart-registry'); ?>">
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Hero image -->
                <?php if ($hero_url): ?>
                    <div class="rr-registry-hero rr-admin-hero">
                        <img src="<?php echo esc_url($hero_url); ?>"
                            alt="<?php echo esc_attr($post->post_title); ?>"
                            id="rr-admin-hero-img" loading="lazy">
                    </div>
                <?php else: ?>
                    <div class="rr-admin-hero rr-admin-hero--empty" id="rr-admin-hero-img" hidden></div>
                <?php endif; ?>
                <input type="hidden" name="thumbnail_id" id="rr-admin-thumbnail-id" value="<?php echo esc_attr($thumbnail_id ?: ''); ?>">
                <div class="rr-admin-hero-actions">
                    <button type="button" class="rr-btn-ghost" id="rr-admin-hero-pick"><?php _e('Choose hero image', 'restart-registry'); ?></button>
                    <button type="button" class="rr-btn-ghost rr-btn-icon--danger" id="rr-admin-hero-clear" <?php echo $thumbnail_id ? '' : 'hidden'; ?>><?php _e('Remove', 'restart-registry'); ?></button>
                </div>

                <!-- Story -->
                <section class="rr-story rr-admin-section">
                    <h2 class="rr-story__heading"><?php _e('Story', 'restart-registry'); ?></h2>
                    <textarea name="post_content" id="rr-admin-story" class="rr-story__text rr-admin-story-textarea widefat" rows="6"
                        placeholder="<?php esc_attr_e('Tell visitors why this registry matters…', 'restart-registry'); ?>"><?php echo esc_textarea($post->post_content); ?></textarea>
                </section>

                <hr class="rr-divider">

                <!-- Items section -->
                <div class="rr-items-section rr-admin-section">
                    <div class="rr-items-header">
                        <span class="rr-items-heading"><?php _e('Items', 'restart-registry'); ?> <span class="rr-item-count">(<?php echo count($items); ?>)</span></span>
                    </div>
                    <?php if (!empty($items)): ?>
                        <div class="rr-items-table">
                            <div class="rr-items-table__head" aria-hidden="true">
                                <span class="rr-col-thumb"></span>
                                <span class="rr-col-item"><?php _e('Item', 'restart-registry'); ?></span>
                                <span class="rr-col-qty"><?php _e('Qty Desired', 'restart-registry'); ?></span>
                                <span class="rr-col-fulfilled"><?php _e('Fulfilled', 'restart-registry'); ?></span>
                            </div>
                            <ul class="rr-item-list">
                                <?php foreach ($items as $item):
                                    $qty_needed    = (int) ($item['quantity_needed'] ?? 1);
                                    $qty_purchased = (int) ($item['quantity_purchased'] ?? 0);
                                    $is_fulfilled  = $qty_purchased >= $qty_needed;
                                ?>
                                    <li class="rr-item-row <?php echo $is_fulfilled ? 'is-fulfilled' : ''; ?>"
                                        data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>"
                                        data-name="<?php echo esc_attr($item['name'] ?? ''); ?>"
                                        data-url="<?php echo esc_attr($item['url'] ?? ''); ?>"
                                        data-price="<?php echo esc_attr($item['price'] ?? ''); ?>"
                                        data-quantity="<?php echo esc_attr($qty_needed); ?>"
                                        data-description="<?php echo esc_attr($item['description'] ?? ''); ?>"
                                        data-notes="<?php echo esc_attr($item['notes'] ?? ''); ?>"
                                        data-image-url="<?php echo esc_attr($item['image_url'] ?? ''); ?>"
                                        data-retailer="<?php echo esc_attr($item['retailer'] ?? ''); ?>">
                                        <span class="rr-col-thumb">
                                            <?php if (!empty($item['image_url'])): ?>
                                                <img src="<?php echo esc_url($item['image_url']); ?>" alt="" loading="lazy">
                                            <?php endif; ?>
                                        </span>
                                        <span class="rr-col-item">
                                            <button type="button" class="rr-admin-edit-item rr-admin-item-name"><?php echo esc_html($item['name']); ?></button>
                                            <?php if (!empty($item['notes'])): ?>
                                                <span class="rr-item__notes"><?php echo esc_html($item['notes']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="rr-col-qty"><?php echo $qty_needed; ?></span>
                                        <span class="rr-col-fulfilled"><?php echo $qty_purchased; ?>/<?php echo $qty_needed; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <p class="rr-no-items"><?php _e('No items yet.', 'restart-registry'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Message board -->
                <?php if (!empty($messages)): ?>
                    <div class="rr-message-board rr-admin-section">
                        <h2 class="rr-message-board__title"><?php _e('Messages', 'restart-registry'); ?></h2>
                        <ul class="rr-message-board__list">
                            <?php foreach ($messages as $msg): ?>
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
                                        <blockquote class="rr-message-card__note"><?php echo esc_html($msg['purchaser_note']); ?></blockquote>
                                        <p class="rr-message-card__meta">
                                            <span class="rr-message-card__from"><?php echo esc_html($msg['purchaser_name'] ?: __('Someone', 'restart-registry')); ?></span>
                                            <span class="rr-message-card__date"><?php echo esc_html(date_i18n(get_option('date_format'), $msg['timestamp'])); ?></span>
                                        </p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Invitees -->
                <div class="rr-admin-section rr-admin-invitees">
                    <h2 class="rr-admin-section__heading"><?php _e('Invitees', 'restart-registry'); ?> <span class="rr-item-count">(<?php echo count($invitees); ?>)</span></h2>
                    <?php if (!empty($invitees)): ?>
                        <ul class="rr-invitees__list">
                            <?php foreach ($invitees as $inv): ?>
                                <li class="rr-invitees__item">
                                    <span class="rr-invitees__email"><?php echo esc_html($inv['email']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="rr-invitees__empty"><?php _e('No invitees.', 'restart-registry'); ?></p>
                    <?php endif; ?>
                </div>

            </form>

            <!-- Data for item edit AJAX -->
            <input type="hidden" id="rr-admin-update-nonce" value="<?php echo esc_attr(wp_create_nonce('restart_registry_nonce')); ?>">
            <input type="hidden" id="rr-admin-edit-registry-id" value="<?php echo esc_attr($post_id); ?>">

            <!-- Item edit modal -->
            <div class="rr-modal" id="rr-admin-item-edit-modal">
                <div class="rr-modal__backdrop"></div>
                <div class="rr-modal__dialog" role="dialog" aria-labelledby="rr-admin-item-edit-title" aria-modal="true">
                    <div class="rr-modal__header">
                        <h3 id="rr-admin-item-edit-title"><?php _e('Edit Item', 'restart-registry'); ?></h3>
                        <button type="button" class="rr-modal__close" aria-label="<?php esc_attr_e('Close', 'restart-registry'); ?>">&times;</button>
                    </div>
                    <div class="rr-modal__body">
                        <form id="rr-admin-item-edit-form" class="rr-form">
                            <input type="hidden" id="rr-admin-item-id">
                            <div class="rr-form-group">
                                <label for="rr-admin-item-name"><?php _e('Name', 'restart-registry'); ?></label>
                                <input type="text" id="rr-admin-item-name" name="name" required>
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-admin-item-url"><?php _e('URL', 'restart-registry'); ?></label>
                                <input type="url" id="rr-admin-item-url" name="url" required>
                            </div>
                            <div class="rr-form-row">
                                <div class="rr-form-group">
                                    <label for="rr-admin-item-price"><?php _e('Price', 'restart-registry'); ?></label>
                                    <input type="number" id="rr-admin-item-price" name="price" step="0.01" min="0" placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>">
                                </div>
                                <div class="rr-form-group">
                                    <label for="rr-admin-item-quantity"><?php _e('Quantity needed', 'restart-registry'); ?></label>
                                    <input type="number" id="rr-admin-item-quantity" name="quantity" min="1" value="1">
                                </div>
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-admin-item-description"><?php _e('Description', 'restart-registry'); ?></label>
                                <textarea id="rr-admin-item-description" name="description" rows="3" maxlength="500" placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>"></textarea>
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-admin-item-notes"><?php _e('Notes', 'restart-registry'); ?></label>
                                <input type="text" id="rr-admin-item-notes" name="notes" placeholder="<?php esc_attr_e('Optional — visible to gift-givers', 'restart-registry'); ?>">
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-admin-item-image-url"><?php _e('Image URL', 'restart-registry'); ?></label>
                                <input type="url" id="rr-admin-item-image-url" name="image_url" placeholder="<?php esc_attr_e('Optional', 'restart-registry'); ?>">
                            </div>
                            <div class="rr-form-group">
                                <label for="rr-admin-item-retailer"><?php _e('Retailer', 'restart-registry'); ?></label>
                                <input type="text" id="rr-admin-item-retailer" name="retailer" readonly>
                            </div>
                            <div class="rr-form-group rr-form-group--checkbox">
                                <label for="rr-admin-item-fulfilled">
                                    <input type="checkbox" id="rr-admin-item-fulfilled" name="mark_fulfilled" value="1">
                                    <?php _e('Mark as fulfilled (no more needed)', 'restart-registry'); ?>
                                </label>
                            </div>
                            <div class="rr-form-actions">
                                <button type="submit" class="rr-button"><?php _e('Save Changes', 'restart-registry'); ?></button>
                                <button type="button" class="rr-btn-ghost rr-admin-item-modal-cancel"><?php _e('Cancel', 'restart-registry'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    public function handle_registry_edit(): void {
        $post_id = (int) ($_POST['post_id'] ?? 0);

        if (!$post_id || !check_admin_referer('restart_registry_admin_edit_' . $post_id, 'rr_admin_edit_nonce')) {
            wp_die(__('Security check failed.', 'restart-registry'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Not allowed.', 'restart-registry'));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            wp_die(__('Registry not found.', 'restart-registry'));
        }

        $allowed_statuses = ['publish', 'private', 'draft', 'restart-archived'];
        $new_status = in_array($_POST['post_status'] ?? '', $allowed_statuses, true)
            ? sanitize_key($_POST['post_status'])
            : $post->post_status;

        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field($_POST['post_title'] ?? ''),
            'post_content' => sanitize_textarea_field($_POST['post_content'] ?? ''),
            'post_status'  => $new_status,
        ]);

        $is_for_self = !empty($_POST['is_for_self']);
        update_post_meta($post_id, 'restart_is_for_self',            $is_for_self ? '1' : '0');
        update_post_meta($post_id, 'restart_event_type',             sanitize_text_field($_POST['event_type'] ?? ''));
        update_post_meta($post_id, 'restart_event_date',             sanitize_text_field($_POST['event_date'] ?? ''));
        update_post_meta($post_id, 'restart_recipient_name',         sanitize_text_field($_POST['recipient_name'] ?? ''));
        update_post_meta($post_id, 'restart_recipient_relationship',  sanitize_text_field($_POST['recipient_relationship'] ?? ''));
        update_post_meta($post_id, 'restart_recipient_email',        sanitize_email($_POST['recipient_email'] ?? ''));

        $thumbnail_id = (int) ($_POST['thumbnail_id'] ?? 0);
        if ($thumbnail_id > 0) {
            set_post_thumbnail($post_id, $thumbnail_id);
        } else {
            delete_post_thumbnail($post_id);
        }

        wp_redirect(admin_url('admin.php?page=restart-registry-edit&post=' . $post_id . '&updated=1'));
        exit;
    }

    private function get_registry_edit_inline_js(): string {
        return <<<'JS'
(function($) {
    // ── Hero image picker ─────────────────────────────────────────────────────
    var frame;
    $('#rr-admin-hero-pick').on('click', function() {
        if (frame) { frame.open(); return; }
        frame = wp.media({ title: 'Choose Hero Image', button: { text: 'Use this image' }, multiple: false });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            var src = attachment.sizes && attachment.sizes.large ? attachment.sizes.large.url : attachment.url;
            var img = document.getElementById('rr-admin-hero-img');
            if (img.tagName === 'IMG') {
                img.src = src;
            } else {
                var newImg = document.createElement('img');
                newImg.id = 'rr-admin-hero-img';
                newImg.src = src;
                newImg.loading = 'lazy';
                img.parentNode.replaceChild(newImg, img);
            }
            document.getElementById('rr-admin-thumbnail-id').value = attachment.id;
            document.getElementById('rr-admin-hero-clear').removeAttribute('hidden');
        });
        frame.open();
    });

    $('#rr-admin-hero-clear').on('click', function() {
        document.getElementById('rr-admin-thumbnail-id').value = '';
        var img = document.getElementById('rr-admin-hero-img');
        if (img.tagName === 'IMG') {
            var div = document.createElement('div');
            div.id = 'rr-admin-hero-img';
            div.setAttribute('hidden', '');
            img.parentNode.replaceChild(div, img);
        }
        $(this).attr('hidden', '');
    });

    $('#rr-admin-is-for-self').on('change', function() {
        var fields = document.getElementById('rr-admin-recipient-fields');
        if (this.checked) { fields.setAttribute('hidden', ''); }
        else              { fields.removeAttribute('hidden'); }
    });

    // ── Item edit modal ───────────────────────────────────────────────────────
    var itemModal = document.getElementById('rr-admin-item-edit-modal');

    function openItemModal() {
        itemModal.classList.add('is-open');
        document.body.classList.add('rr-modal-open');
    }
    function closeItemModal() {
        itemModal.classList.remove('is-open');
        document.body.classList.remove('rr-modal-open');
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.rr-admin-edit-item');
        if (!btn) return;
        var row = btn.closest('.rr-item-row');
        var d   = row.dataset;
        document.getElementById('rr-admin-item-id').value          = d.itemId       || '';
        document.getElementById('rr-admin-item-name').value        = d.name         || '';
        document.getElementById('rr-admin-item-url').value         = d.url          || '';
        document.getElementById('rr-admin-item-price').value       = d.price        || '';
        document.getElementById('rr-admin-item-quantity').value    = d.quantity     || 1;
        document.getElementById('rr-admin-item-description').value = d.description  || '';
        document.getElementById('rr-admin-item-notes').value       = d.notes        || '';
        document.getElementById('rr-admin-item-image-url').value   = d.imageUrl     || '';
        document.getElementById('rr-admin-item-retailer').value    = d.retailer     || '';
        document.getElementById('rr-admin-item-fulfilled').checked = false;
        openItemModal();
    });

    itemModal.addEventListener('click', function(e) {
        if (e.target === itemModal || e.target.closest('.rr-modal__backdrop') || e.target.closest('.rr-modal__close') || e.target.closest('.rr-admin-item-modal-cancel')) {
            closeItemModal();
        }
    });

    var itemEditForm = document.getElementById('rr-admin-item-edit-form');
    itemEditForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var submitBtn = itemEditForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving…';

        var data = new FormData();
        data.append('action',       'restart_registry_update_item');
        data.append('nonce',        document.getElementById('rr-admin-update-nonce').value);
        data.append('registry_id',  document.getElementById('rr-admin-edit-registry-id').value);
        data.append('item_id',      document.getElementById('rr-admin-item-id').value);
        data.append('name',         document.getElementById('rr-admin-item-name').value);
        data.append('url',          document.getElementById('rr-admin-item-url').value);
        data.append('price',        document.getElementById('rr-admin-item-price').value);
        data.append('quantity',     document.getElementById('rr-admin-item-quantity').value);
        data.append('description',  document.getElementById('rr-admin-item-description').value);
        data.append('notes',        document.getElementById('rr-admin-item-notes').value);
        data.append('image_url',    document.getElementById('rr-admin-item-image-url').value);
        data.append('mark_fulfilled', document.getElementById('rr-admin-item-fulfilled').checked ? '1' : '0');

        fetch(rrAdmin.ajaxurl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    window.location.reload();
                } else {
                    alert(resp.data && resp.data.message ? resp.data.message : 'An error occurred.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Save Changes';
                }
            }).catch(function() {
                alert('An error occurred.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Changes';
            });
    });
}(jQuery));
JS;
    }
}
