<?php
/**
 * Admin menu router for referrals screens.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-menu.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers submenu entries and delegates rendering to controllers.
 */
class Ecosplay_Referrals_Admin_Menu {
    /**
     * Domain service accessor.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Settings screen controller.
     *
     * @var Ecosplay_Referrals_Admin_Settings
     */
    protected $settings;

    /**
     * Cached page controllers keyed by slug.
     *
     * @var array<string,object>
     */
    protected $controllers = array();

    /**
     * Registered submenu hook.
     *
     * @var string
     */
    protected $page_hook = '';

    /**
     * Last resolved controller instance.
     *
     * @var object|null
     */
    protected $current = null;

    /**
     * Wires WordPress hooks for the submenu and assets.
     *
     * @param Ecosplay_Referrals_Service       $service  Business layer.
     * @param Ecosplay_Referrals_Admin_Settings $settings Settings handler.
     */
    public function __construct( Ecosplay_Referrals_Service $service, Ecosplay_Referrals_Admin_Settings $settings ) {
        $this->service  = $service;
        $this->settings = $settings;

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Registers the submenu under Paid Memberships Pro with legacy fallback.
     *
     * @return void
     */
    public function register_menu() {
        $this->page_hook = add_submenu_page(
            'pmpro-dashboard',
            __( 'Parrainages ECOSplay', 'ecosplay-referrals' ),
            __( 'Parrainages', 'ecosplay-referrals' ),
            'manage_options',
            'ecosplay-referrals',
            array( $this, 'render_page' )
        );

        if ( false === $this->page_hook ) {
            $this->page_hook = add_submenu_page(
                'pmpro-membershiplevels',
                __( 'Parrainages ECOSplay', 'ecosplay-referrals' ),
                __( 'Parrainages', 'ecosplay-referrals' ),
                'manage_options',
                'ecosplay-referrals',
                array( $this, 'render_page' )
            );
        }

        if ( $this->page_hook ) {
            add_action( 'load-' . $this->page_hook, array( $this, 'prepare_page' ) );
        }
    }

    /**
     * Resolves the requested controller prior to rendering.
     *
     * @return void
     */
    public function prepare_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'codes';

        $this->current = $this->get_controller( $tab );

        if ( $this->current && method_exists( $this->current, 'handle' ) ) {
            $this->current->handle();
        }
    }

    /**
     * Outputs the admin screen with contextual tabs.
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->current ) {
            $this->current = $this->get_controller( 'codes' );
        }

        $tabs = $this->get_tabs();
        $slug = method_exists( $this->current, 'get_slug' ) ? $this->current->get_slug() : 'codes';
        $title = method_exists( $this->current, 'get_title' ) ? $this->current->get_title() : __( 'Parrainages', 'ecosplay-referrals' );

        echo '<div class="wrap ecos-referrals-admin">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        settings_errors( 'ecosplay_referrals' );

        if ( count( $tabs ) > 1 ) {
            echo '<nav class="nav-tab-wrapper">';
            foreach ( $tabs as $tab_slug => $label ) {
                $url   = esc_url( add_query_arg( 'tab', $tab_slug ) );
                $class = 'nav-tab' . ( $tab_slug === $slug ? ' nav-tab-active' : '' );
                echo '<a class="' . esc_attr( $class ) . '" href="' . $url . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
        }

        if ( $this->current && method_exists( $this->current, 'render' ) ) {
            $this->current->render();
        }

        echo '</div>';
    }

    /**
     * Loads scripts and styles specific to the referrals pages.
     *
     * @param string $hook Current admin hook suffix.
     *
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->page_hook ) {
            return;
        }

        wp_enqueue_style(
            'ecos-referrals-admin',
            ECOSPLAY_REFERRALS_URL . 'assets/css/admin.css',
            array(),
            ECOSPLAY_REFERRALS_VERSION
        );

        wp_enqueue_script(
            'ecos-referrals-admin',
            ECOSPLAY_REFERRALS_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            ecosplay_referrals_get_asset_version( 'assets/js/admin.js' ),
            true
        );

        wp_localize_script(
            'ecos-referrals-admin',
            'ecosReferralsAdmin',
            array(
                'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
                'confirmRegenerateAll'      => __( 'Êtes-vous sûr de vouloir régénérer tous les codes ? Cette action est irréversible.', 'ecosplay-referrals' ),
                'confirmGenerateMissing'    => __( 'Générer les codes manquants pour les membres éligibles ?', 'ecosplay-referrals' ),
                'confirmResetNotifications' => __( 'Confirmez-vous la réinitialisation des notifications ?', 'ecosplay-referrals' ),
                'stripeDiagnosticAction'    => 'ecosplay_referrals_stripe_test',
                'stripeDiagnosticNonce'     => wp_create_nonce( 'ecosplay_referrals_stripe_test' ),
                'stripeDiagnosticRunning'   => __( 'Diagnostic Stripe en cours...', 'ecosplay-referrals' ),
                'stripeDiagnosticError'     => __( 'Le diagnostic Stripe n’a pas pu être chargé.', 'ecosplay-referrals' ),
            )
        );
    }

    /**
     * Provides the tab navigation labels while hiding Stripe tabs when disabled.
     *
     * @return array<string,string>
     */
    protected function get_tabs() {
        $tabs = array(
            'codes' => __( 'Codes actifs', 'ecosplay-referrals' ),
            'usage' => __( 'Historique', 'ecosplay-referrals' ),
            'stats' => __( 'Statistiques', 'ecosplay-referrals' ),
        );

        if ( ecosplay_referrals_is_stripe_enabled() ) {
            $tabs['payouts'] = __( 'Paiements', 'ecosplay-referrals' );
            $tabs['logs']    = __( 'Logs Stripe', 'ecosplay-referrals' );
        }

        if ( ecosplay_referrals_is_tremendous_enabled() ) {
            $tabs['tremendous-logs'] = __( 'Logs Tremendous', 'ecosplay-referrals' );
        }

        $tabs['settings'] = __( 'Réglages', 'ecosplay-referrals' );

        return $tabs;
    }

    /**
     * Returns or instantiates the controller for a tab while guarding Stripe dependencies.
     *
     * @param string $slug Tab identifier.
     *
     * @return object|null
     */
    protected function get_controller( $slug ) {
        $tabs = $this->get_tabs();

        if ( ! isset( $tabs[ $slug ] ) ) {
            $slug = 'codes';
        }

        if ( isset( $this->controllers[ $slug ] ) ) {
            return $this->controllers[ $slug ];
        }

        switch ( $slug ) {
            case 'usage':
                $controller = new Ecosplay_Referrals_Admin_Usage_Page( $this->service );
                break;
            case 'stats':
                $controller = new Ecosplay_Referrals_Admin_Stats_Page( $this->service );
                break;
            case 'payouts':
                $controller = ecosplay_referrals_is_stripe_enabled()
                    ? new Ecosplay_Referrals_Admin_Payouts_Page( $this->service )
                    : new Ecosplay_Referrals_Admin_Codes_Page( $this->service );
                break;
            case 'logs':
                $controller = ecosplay_referrals_is_stripe_enabled()
                    ? new Ecosplay_Referrals_Admin_Stripe_Logs_Page( $this->service )
                    : new Ecosplay_Referrals_Admin_Codes_Page( $this->service );
                break;
            case 'tremendous-logs':
                $controller = ecosplay_referrals_is_tremendous_enabled()
                    ? new Ecosplay_Referrals_Admin_Tremendous_Logs_Page( $this->service )
                    : new Ecosplay_Referrals_Admin_Codes_Page( $this->service );
                break;
            case 'settings':
                $controller = $this->settings;
                break;
            case 'codes':
            default:
                $controller = new Ecosplay_Referrals_Admin_Codes_Page( $this->service );
                break;
        }

        $this->controllers[ $slug ] = $controller;

        return $controller;
    }
}
