<?php
/**
 * Admin menu registration for PMPro Notify.
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/admin/class-admin-menu.php
 */

namespace Pmpro_Notify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the PMPro Notify submenu and renders the admin screen.
 */
class Admin_Menu {
    /**
     * Holds the registered submenu hook.
     *
     * @var string
     */
    private $page_hook = '';

    /**
     * Hooks into WordPress to register the submenu.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    /**
     * Registers the submenu under the PMPro dashboard with a legacy fallback.
     *
     * @return void
     */
    public function register_menu() {
        $this->page_hook = add_submenu_page(
            'pmpro-dashboard',
            __( 'PMPro Notify', 'pmpro-notify' ),
            __( 'Notifications PMPro', 'pmpro-notify' ),
            'manage_options',
            'pmpro-notify',
            array( $this, 'render_page' )
        );

        if ( false === $this->page_hook ) {
            $this->page_hook = add_submenu_page(
                'pmpro-membershiplevels',
                __( 'PMPro Notify', 'pmpro-notify' ),
                __( 'Notifications PMPro', 'pmpro-notify' ),
                'manage_options',
                'pmpro-notify',
                array( $this, 'render_page' )
            );
        }
    }

    /**
     * Outputs the admin screen placeholder for the plugin.
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.', 'pmpro-notify' ) );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'PMPro Notify', 'pmpro-notify' ) . '</h1>';
        echo '<p>' . esc_html__( 'Configuration à venir pour les notifications PMPro.', 'pmpro-notify' ) . '</p>';
        echo '</div>';
    }
}
