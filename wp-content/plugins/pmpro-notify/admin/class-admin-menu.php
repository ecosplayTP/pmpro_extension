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
     * Shared data store instance.
     *
     * @var Notify_Store
     */
    private $store;

    /**
     * Campaigns admin page handler.
     *
     * @var Admin_Campaigns_Page
     */
    private $campaigns_page;

    /**
     * Statistics admin page handler.
     *
     * @var Admin_Stats_Page
     */
    private $stats_page;

    /**
     * Hooks into WordPress to register the submenu.
     *
     * @param Notify_Store $store Data store instance.
     */
    public function __construct( Notify_Store $store ) {
        $this->store          = $store;
        $this->campaigns_page = new Admin_Campaigns_Page( $this->store );
        $this->stats_page     = new Admin_Stats_Page( $this->store );

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
     * Enqueues admin assets for the plugin screens.
     *
     * @param string $hook Current admin page hook.
     *
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->page_hook ) {
            return;
        }

        wp_enqueue_style(
            'pmpro-notify-admin',
            PMPRO_NOTIFY_URL . 'assets/css/admin.css',
            array(),
            PMPRO_NOTIFY_VERSION
        );
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

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'campaigns';
        $tab = in_array( $tab, array( 'campaigns', 'stats' ), true ) ? $tab : 'campaigns';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'PMPro Notify', 'pmpro-notify' ) . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=pmpro-notify&tab=campaigns' ) ) . '" class="nav-tab ' . ( 'campaigns' === $tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Campagnes', 'pmpro-notify' ) . '</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=pmpro-notify&tab=stats' ) ) . '" class="nav-tab ' . ( 'stats' === $tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'Statistiques', 'pmpro-notify' ) . '</a>';
        echo '</nav>';

        if ( 'stats' === $tab ) {
            $this->stats_page->render();
        } else {
            $this->campaigns_page->render();
        }

        echo '</div>';
    }
}
