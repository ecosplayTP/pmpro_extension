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
    }

    /**
     * Registers WordPress hooks used by the plugin.
     *
     * @return void
     */
    private function register_hooks() {
        if ( is_admin() ) {
            new Admin_Menu();
        }
    }
}
