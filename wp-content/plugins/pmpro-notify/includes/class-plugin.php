<?php
/**
 * Bootstrapper for the PMPro Notify plugin.
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/includes/class-plugin.php
 */

namespace Pmpro_Notify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin orchestrator responsible for wiring dependencies.
 */
class Plugin {
    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Shared data store instance.
     *
     * @var Notify_Store|null
     */
    private $store = null;

    /**
     * Returns the shared instance of the plugin.
     *
     * @return Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Loads dependencies and registers hooks.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Prevents cloning the singleton.
     *
     * @return void
     */
    private function __clone() {
    }

    /**
     * Prevents unserializing the singleton.
     *
     * @return void
     */
    public function __wakeup() {
    }

    /**
     * Loads PHP class dependencies for the plugin.
     *
     * @return void
     */
    private function load_dependencies() {
        require_once PMPRO_NOTIFY_ADMIN . 'class-admin-menu.php';
        require_once PMPRO_NOTIFY_ADMIN . 'class-admin-campaigns-page.php';
        require_once PMPRO_NOTIFY_ADMIN . 'class-admin-stats-page.php';
        require_once PMPRO_NOTIFY_INC . 'class-notify-store.php';
        require_once PMPRO_NOTIFY_PUBLIC . 'class-floating-notice.php';
    }

    /**
     * Registers WordPress hooks used by the plugin.
     *
     * @return void
     */
    private function register_hooks() {
        $this->store = new Notify_Store();

        if ( is_admin() && ! wp_doing_ajax() ) {
            new Admin_Menu( $this->store );

            return;
        }

        new Floating_Notice( $this->store );
    }
}
