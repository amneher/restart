<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://the-restart.co
 * @since             1.0.0
 * @package           Restart_Registry
 *
 * @wordpress-plugin
 * Plugin Name:       Restart Registry
 * Plugin URI:        http://the-restart.co/restart-registry-uri/
 * Description:       Provides a gift registry custom post type, add item function, and associated features.
 * Version: 1.2.6
 * Author:            Andrew Neher
 * Author URI:        http://the-restart.co/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       restart-registry
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'RESTART_REGISTRY_VERSION', '1.2.6' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-restart-registry-activator.php
 */
function activate_restart_registry() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-restart-registry-activator.php';
	Restart_Registry_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-restart-registry-deactivator.php
 */
function deactivate_restart_registry() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-restart-registry-deactivator.php';
	Restart_Registry_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_restart_registry' );
register_deactivation_hook( __FILE__, 'deactivate_restart_registry' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-restart-registry.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_restart_registry() {

	$plugin = new Restart_Registry();
	$plugin->run();

}
run_restart_registry();
