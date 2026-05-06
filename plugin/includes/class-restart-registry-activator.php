<?php

/**
 * Fired during plugin activation.
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Activator {

    public static function activate() {
        self::register_registry_user_role();
        self::add_capabilities();
        self::create_pages();
        self::install_mu_plugins();
        flush_rewrite_rules();
    }

    public static function install_mu_plugins(): void {
        $src_dir = plugin_dir_path( dirname( __FILE__ ) ) . 'mu-plugins/';
        $dst_dir = WP_CONTENT_DIR . '/mu-plugins/';

        if ( ! is_dir( $dst_dir ) ) {
            wp_mkdir_p( $dst_dir );
        }

        $files     = [ 'restart-registry-cpt.php', 'restart-auth.php' ];
        $installed = [];

        foreach ( $files as $file ) {
            if ( copy( $src_dir . $file, $dst_dir . $file ) ) {
                $installed[] = $file;
            }
        }

        update_option( 'restart_registry_mu_plugins', $installed );
    }

    public static function register_registry_user_role(): void {
        if (!get_role('registry_user')) {
            add_role('registry_user', __('Registry User', 'restart-registry'), [
                'read' => true,
            ]);
        }
    }

    private static function add_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_restart_registry');
        }
    }

    /**
     * Create the "My Registry" page if it doesn't already exist.
     * Stores the page ID in restart_registry_page_id so the admin settings
     * dropdown is pre-selected on fresh installs.
     */
    public static function create_pages() {
        $existing_id = get_option('restart_registry_page_id');
        if ($existing_id && get_post($existing_id) && get_post_status($existing_id) !== 'trash') {
            return;
        }

        $page_id = wp_insert_post([
            'post_title'   => __('My Registry', 'restart-registry'),
            'post_content' => '[restart_registry]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('restart_registry_page_id', $page_id);
        }
    }
}
