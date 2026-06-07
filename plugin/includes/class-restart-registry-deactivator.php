<?php

/**
 * Fired during plugin deactivation
 *
 * @link  http://example.com
 * @since 1.0.0
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 * @author     Your Name <email@example.com>
 */
class Restart_Registry_Deactivator
{

    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since 1.0.0
     */
    public static function deactivate()
    {
        self::remove_mu_plugins();
        remove_role('registry_user');
    }

    public static function remove_mu_plugins(): void
    {
        $installed = get_option('restart_registry_mu_plugins', []);
        $dst_dir   = WP_CONTENT_DIR . '/mu-plugins/';

        foreach ( $installed as $file ) {
            $path = $dst_dir . $file;
            if (file_exists($path) ) {
                unlink($path);
            }
        }

        delete_option('restart_registry_mu_plugins');
    }

}
