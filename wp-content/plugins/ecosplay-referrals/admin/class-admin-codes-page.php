<?php
/**
 * Admin controller rendering the referrals codes list.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-codes-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coordinates listing and maintenance actions for referral codes.
 */
class Ecosplay_Referrals_Admin_Codes_Page {
    /**
     * Business operations provider.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Cached codes listing.
     *
     * @var array<int,object>
     */
    protected $codes = array();

    /**
     * Bootstraps the controller with the service dependency.
     *
     * @param Ecosplay_Referrals_Service $service Domain logic service.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;
    }

    /**
     * Returns the tab slug handled by this controller.
     *
     * @return string
     */
    public function get_slug() {
        return 'codes';
    }

    /**
     * Returns the localized page title.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Codes de parrainage', 'ecosplay-referrals' );
    }

    /**
     * Handles admin form submissions for code maintenance.
     *
     * @return void
     */
    public function handle() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        if ( empty( $_POST['ecosplay_referrals_action'] ) ) {
            return;
        }

        $action  = sanitize_key( wp_unslash( $_POST['ecosplay_referrals_action'] ) );
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        switch ( $action ) {
            case 'regenerate_all':
                check_admin_referer( 'ecosplay_referrals_regenerate_all' );
                $count = $this->service->force_regenerate_all_codes();
                $this->add_notice( 'regenerate_all', sprintf( _n( '%d code a été régénéré.', '%d codes ont été régénérés.', $count, 'ecosplay-referrals' ), $count ) );
                break;
            case 'generate_missing':
                check_admin_referer( 'ecosplay_referrals_generate_missing' );
                $count = $this->service->generate_missing_codes_for_allowed_users();
                $this->add_notice( 'generate_missing', sprintf( _n( '%d code manquant a été généré.', '%d codes manquants ont été générés.', $count, 'ecosplay-referrals' ), $count ) );
                break;
            case 'regenerate_single':
                if ( $user_id ) {
                    check_admin_referer( 'ecosplay_referrals_regenerate_' . $user_id );
                    $code = $this->service->force_regenerate_code( $user_id );
                    if ( $code ) {
                        $this->add_notice( 'regenerate_single', __( 'Le code a été régénéré avec succès.', 'ecosplay-referrals' ) );
                    } else {
                        $this->add_notice( 'regenerate_single_error', __( 'Impossible de régénérer ce code.', 'ecosplay-referrals' ), 'error' );
                    }
                }
                break;
            case 'reset_notifications':
                $nonce = $user_id ? 'ecosplay_referrals_reset_' . $user_id : 'ecosplay_referrals_reset_all';
                check_admin_referer( $nonce );
                $this->service->reset_notifications( $user_id ? $user_id : null );
                $this->add_notice( 'reset_notifications', __( 'Les notifications ont été réinitialisées.', 'ecosplay-referrals' ) );
                break;
        }
    }

    /**
     * Includes the view template for the codes list.
     *
     * @return void
     */
    public function render() {
        $this->codes = $this->service->get_codes_overview( false );

        $codes = $this->codes;

        include ECOSPLAY_REFERRALS_ADMIN . 'views/codes.php';
    }

    /**
     * Registers a feedback notice in the admin UI.
     *
     * @param string $code    Unique notice code.
     * @param string $message Message to display.
     * @param string $type    Notice type.
     *
     * @return void
     */
    protected function add_notice( $code, $message, $type = 'updated' ) {
        add_settings_error( 'ecosplay_referrals', $code, $message, $type );
    }
}
