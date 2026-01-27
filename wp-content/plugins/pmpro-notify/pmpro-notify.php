<?php
/**
 * Plugin Name: PMPro Notify
 * Plugin URI:  https://example.com/
 * Description: Base structure for the PMPro Notify plugin.
 * Version:     0.1.0
 * Author:      ECOSplay
 * Author URI:  https://example.com/
 * Text Domain: pmpro-notify
 * Domain Path: /languages
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/pmpro-notify.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Defines plugin constants for versioning and paths.
 *
 * @return void
 */
function pmpro_notify_define_constants() {
    if ( defined( 'PMPRO_NOTIFY_VERSION' ) ) {
        return;
    }

    define( 'PMPRO_NOTIFY_VERSION', '0.1.0' );
    define( 'PMPRO_NOTIFY_FILE', __FILE__ );
    define( 'PMPRO_NOTIFY_DIR', plugin_dir_path( __FILE__ ) );
    define( 'PMPRO_NOTIFY_URL', plugin_dir_url( __FILE__ ) );
    define( 'PMPRO_NOTIFY_INC', PMPRO_NOTIFY_DIR . 'includes/' );
    define( 'PMPRO_NOTIFY_ADMIN', PMPRO_NOTIFY_DIR . 'admin/' );
    define( 'PMPRO_NOTIFY_PUBLIC', PMPRO_NOTIFY_DIR . 'public/' );
}

pmpro_notify_define_constants();

/**
 * Creates the PMPro Notify tables on activation.
 *
 * @return void
 */
function pmpro_notify_activate() {
    require_once PMPRO_NOTIFY_INC . 'class-notify-store.php';

    $store = new \Pmpro_Notify\Notify_Store();
    $store->create_tables();
}

register_activation_hook( __FILE__, 'pmpro_notify_activate' );

require_once PMPRO_NOTIFY_INC . 'class-plugin.php';

\Pmpro_Notify\Plugin::instance();
