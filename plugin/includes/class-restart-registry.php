<?php

/**
 * The file that defines the core plugin class
 *
 * @link       https://the-restart.co
 * @since      1.0.0
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        if (defined('RESTART_REGISTRY_VERSION')) {
            $this->version = RESTART_REGISTRY_VERSION;
        } else {
            $this->version = '1.0.0';
        }
        $this->plugin_name = 'restart-registry';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_role_hooks();
    }

    private function load_dependencies() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-loader.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-i18n.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-controller.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-restart-registry-admin.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-restart-registry-public.php';

        $this->loader = new Restart_Registry_Loader();
    }

    private function set_locale() {
        $plugin_i18n = new Restart_Registry_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    private function define_admin_hooks() {
        $plugin_admin = new Restart_Registry_Admin($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
    }

    private function define_public_hooks() {
        $plugin_public = new Restart_Registry_Public($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
    }

    private function define_role_hooks(): void {
        // Ensure the role exists even without a full activation cycle
        add_action('init', function () {
            if (!get_role('registry_user')) {
                add_role('registry_user', __('Registry User', 'restart-registry'), ['read' => true]);
            }
        });

        // Block admin page access for registry_user; AJAX must remain open
        add_action('admin_init', function () {
            if (defined('DOING_AJAX') && DOING_AJAX) {
                return;
            }
            $user = wp_get_current_user();
            if (in_array('registry_user', (array) $user->roles, true)) {
                wp_safe_redirect(home_url('/'));
                exit;
            }
        });

        // Hide the admin toolbar for registry users
        add_filter('show_admin_bar', function (bool $show): bool {
            if (!is_user_logged_in()) {
                return $show;
            }
            $user = wp_get_current_user();
            if (in_array('registry_user', (array) $user->roles, true)) {
                return false;
            }
            return $show;
        });
    }

    public function run() {
        $this->loader->run();
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_loader() {
        return $this->loader;
    }

    public function get_version() {
        return $this->version;
    }
}
