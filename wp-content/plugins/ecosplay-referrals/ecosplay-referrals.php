<?php
/**
 * Plugin Name: ECOSplay Referrals
 * Plugin URI:  https://example.com/
 * Description: Base structure for the ECOSplay referrals management plugin.
 * Version:     0.1.0
 * Author:      ECOSplay
 * Author URI:  https://example.com/
 * Text Domain: ecosplay-referrals
 * Domain Path: /languages
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/ecosplay-referrals.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Defines plugin-wide constants for paths and metadata.
 *
 * @return void
 */
function ecosplay_referrals_define_constants() {
    if ( defined( 'ECOSPLAY_REFERRALS_VERSION' ) ) {
        return;
    }

    define( 'ECOSPLAY_REFERRALS_VERSION', '0.1.0' );
    define( 'ECOSPLAY_REFERRALS_FILE', __FILE__ );
    define( 'ECOSPLAY_REFERRALS_DIR', plugin_dir_path( __FILE__ ) );
    define( 'ECOSPLAY_REFERRALS_URL', plugin_dir_url( __FILE__ ) );
    define( 'ECOSPLAY_REFERRALS_INC', ECOSPLAY_REFERRALS_DIR . 'includes/' );
    define( 'ECOSPLAY_REFERRALS_ADMIN', ECOSPLAY_REFERRALS_DIR . 'admin/' );
    define( 'ECOSPLAY_REFERRALS_PUBLIC', ECOSPLAY_REFERRALS_DIR . 'public/' );
    define( 'ECOSPLAY_REFERRALS_ASSETS', ECOSPLAY_REFERRALS_DIR . 'assets/' );
}

ecosplay_referrals_define_constants();

/**
 * Autoloads plugin classes located in the plugin subdirectories.
 *
 * @param string $class Requested class name.
 *
 * @return void
 */
function ecosplay_referrals_autoload( $class ) {
    if ( 0 !== strpos( $class, 'Ecosplay_Referrals_' ) ) {
        return;
    }

    $relative = strtolower( str_replace( 'Ecosplay_Referrals_', '', $class ) );
    $relative = str_replace( '\\', '/', $relative );
    $relative = str_replace( '_', '-', $relative );

    $paths = array(
        ECOSPLAY_REFERRALS_INC . $relative . '.php',
        ECOSPLAY_REFERRALS_ADMIN . $relative . '.php',
        ECOSPLAY_REFERRALS_PUBLIC . $relative . '.php',
    );

    foreach ( $paths as $path ) {
        if ( is_readable( $path ) ) {
            require_once $path;
            return;
        }
    }
}

spl_autoload_register( 'ecosplay_referrals_autoload' );

/**
 * Returns the referrals data store singleton.
 *
 * @return Ecosplay_Referrals_Store
 */
function ecosplay_referrals_store() {
    static $store = null;

    if ( null === $store ) {
        require_once ECOSPLAY_REFERRALS_INC . 'class-referrals-store.php';
        $store = new Ecosplay_Referrals_Store();
    }

    return $store;
}

/**
 * Returns the referrals domain service singleton.
 *
 * @return Ecosplay_Referrals_Service
 */
function ecosplay_referrals_service() {
    static $service = null;

    if ( null === $service ) {
        require_once ECOSPLAY_REFERRALS_INC . 'class-referrals-service.php';
        $service = new Ecosplay_Referrals_Service( ecosplay_referrals_store() );
    }

    return $service;
}

/**
 * Performs installation logic on plugin activation.
 *
 * @return void
 */
function ecosplay_referrals_activate() {
    ecosplay_referrals_store()->install();
}

/**
 * Cleans up on plugin deactivation.
 *
 * @return void
 */
function ecosplay_referrals_deactivate() {
    // Placeholder for deactivation routines (e.g., remove scheduled events).
}

register_activation_hook( __FILE__, 'ecosplay_referrals_activate' );
register_deactivation_hook( __FILE__, 'ecosplay_referrals_deactivate' );

/**
 * Boots the referrals service after plugins are loaded.
 *
 * @return void
 */
function ecosplay_referrals_boot() {
    $service = ecosplay_referrals_service();

    if ( is_admin() ) {
        require_once ECOSPLAY_REFERRALS_ADMIN . 'class-admin-settings.php';
        require_once ECOSPLAY_REFERRALS_ADMIN . 'class-admin-menu.php';
        require_once ECOSPLAY_REFERRALS_ADMIN . 'class-admin-codes-page.php';
        require_once ECOSPLAY_REFERRALS_ADMIN . 'class-admin-usage-page.php';
        require_once ECOSPLAY_REFERRALS_ADMIN . 'class-admin-stats-page.php';

        $settings = new Ecosplay_Referrals_Admin_Settings( $service );
        new Ecosplay_Referrals_Admin_Menu( $service, $settings );
    }

    require_once ECOSPLAY_REFERRALS_PUBLIC . 'class-floating-notice.php';
    require_once ECOSPLAY_REFERRALS_INC . 'class-referrals-shortcodes.php';

    new Ecosplay_Referrals_Floating_Notice( $service );
    new Ecosplay_Referrals_Shortcodes( $service );
}

add_action( 'plugins_loaded', 'ecosplay_referrals_boot' );
