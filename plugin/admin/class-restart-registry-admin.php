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
        add_action('admin_post_restart_registry_create_pages',           array($this, 'handle_create_pages'));
        add_action('wp_ajax_restart_registry_test_lambda',              array($this, 'ajax_test_lambda'));
        add_action('wp_ajax_restart_registry_reconvert_affiliates',     array($this, 'ajax_reconvert_affiliates'));
    }

    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/restart-registry-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/restart-registry-admin.js', array('jquery'), $this->version, true);
        wp_localize_script($this->plugin_name, 'rrAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('restart_registry_admin_nonce'),
        ));
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

        // Retailer API keys
        register_setting('restart_registry_affiliates', 'restart_registry_etsy_api_key', [
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
                <p><?php _e('More retailers can be added through custom filters in your theme or plugin.', 'restart-registry'); ?></p>
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
}
