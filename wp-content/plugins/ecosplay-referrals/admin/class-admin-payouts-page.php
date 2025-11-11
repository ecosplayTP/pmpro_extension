<?php
/**
 * Admin controller orchestrating payouts oversight.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-payouts-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides actions to manage Stripe payouts for referrers.
 */
class Ecosplay_Referrals_Admin_Payouts_Page {
    /**
     * Referrals domain service.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Cached overview rows for rendering.
     *
     * @var array<int,object>
     */
    protected $rows = array();

    /**
     * Stores payouts grouped by user.
     *
     * @var array<int,array<int,object>>
     */
    protected $payouts = array();

    /**
     * Injects the referrals service.
     *
     * @param Ecosplay_Referrals_Service $service Domain logic service.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;
    }

    /**
     * Returns the handled tab slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'payouts';
    }

    /**
     * Returns the page title.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Gestion des paiements', 'ecosplay-referrals' );
    }

    /**
     * Handles form submissions for payout actions.
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
            case 'send_onboarding':
                if ( $user_id ) {
                    check_admin_referer( 'ecosplay_referrals_onboard_' . $user_id );
                    $link = $this->service->generate_account_link( $user_id, $this->get_return_url(), $this->get_return_url() );

                    if ( is_wp_error( $link ) || empty( $link['url'] ) ) {
                        $message = is_wp_error( $link ) ? $link->get_error_message() : __( 'Aucun lien de création disponible.', 'ecosplay-referrals' );
                        $this->add_notice( 'send_onboarding_error', $message, 'error' );
                    } else {
                        $url     = esc_url( $link['url'] );
                        $message = sprintf( __( 'Lien d\'onboarding prêt : <a href="%1$s" target="_blank" rel="noopener">ouvrir</a>.', 'ecosplay-referrals' ), $url );
                        $this->add_notice( 'send_onboarding_success', $message );
                    }
                }
                break;
            case 'open_dashboard':
                if ( $user_id ) {
                    check_admin_referer( 'ecosplay_referrals_dashboard_' . $user_id );
                    $link = $this->service->generate_login_link( $user_id );

                    if ( is_wp_error( $link ) || empty( $link['url'] ) ) {
                        $message = is_wp_error( $link ) ? $link->get_error_message() : __( 'Aucun lien de dashboard disponible.', 'ecosplay-referrals' );
                        $this->add_notice( 'open_dashboard_error', $message, 'error' );
                    } else {
                        $url     = esc_url( $link['url'] );
                        $message = sprintf( __( 'Dashboard Express : <a href="%1$s" target="_blank" rel="noopener">ouvrir</a>.', 'ecosplay-referrals' ), $url );
                        $this->add_notice( 'open_dashboard_success', $message );
                    }
                }
                break;
            case 'trigger_transfer':
                if ( $user_id ) {
                    check_admin_referer( 'ecosplay_referrals_transfer_' . $user_id );
                    $amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;

                    if ( $amount <= 0 ) {
                        $this->add_notice( 'trigger_transfer_error', __( 'Merci de saisir un montant valide.', 'ecosplay-referrals' ), 'error' );
                        break;
                    }

                    $result = $this->service->handle_batch_payout(
                        $user_id,
                        $amount,
                        'eur',
                        array(
                            'source' => 'admin_manual',
                        )
                    );

                    if ( is_wp_error( $result ) ) {
                        $this->add_notice( 'trigger_transfer_error', $result->get_error_message(), 'error' );
                    } else {
                        $this->add_notice( 'trigger_transfer_success', sprintf( __( 'Transfert de %s€ demandé.', 'ecosplay-referrals' ), number_format_i18n( $amount, 2 ) ) );
                    }
                }
                break;
            case 'mark_manual':
                if ( $user_id ) {
                    check_admin_referer( 'ecosplay_referrals_manual_' . $user_id );
                    $amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
                    $note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

                    if ( $amount <= 0 ) {
                        $this->add_notice( 'mark_manual_error', __( 'Merci de saisir un montant valide.', 'ecosplay-referrals' ), 'error' );
                        break;
                    }

                    $result = $this->service->record_manual_payout( $user_id, $amount, 'eur', $note );

                    if ( is_wp_error( $result ) ) {
                        $this->add_notice( 'mark_manual_error', $result->get_error_message(), 'error' );
                    } else {
                        $this->add_notice( 'mark_manual_success', sprintf( __( 'Paiement manuel de %s€ enregistré.', 'ecosplay-referrals' ), number_format_i18n( $amount, 2 ) ) );
                    }
                }
                break;
            case 'cancel_transfer':
                if ( $user_id ) {
                    check_admin_referer( 'ecosplay_referrals_cancel_' . $user_id );
                    $transfer_id = isset( $_POST['transfer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['transfer_id'] ) ) : '';

                    if ( '' === $transfer_id ) {
                        $this->add_notice( 'cancel_transfer_error', __( 'Identifiant de transfert manquant.', 'ecosplay-referrals' ), 'error' );
                        break;
                    }

                    $result = $this->service->cancel_transfer( $transfer_id );

                    if ( is_wp_error( $result ) ) {
                        $this->add_notice( 'cancel_transfer_error', $result->get_error_message(), 'error' );
                    } else {
                        $this->add_notice( 'cancel_transfer_success', __( 'Le transfert a été annulé.', 'ecosplay-referrals' ) );
                    }
                }
                break;
        }
    }

    /**
     * Renders the payouts overview view.
     *
     * @return void
     */
    public function render() {
        $this->rows = $this->service->get_payouts_overview();
        $rows       = array();

        foreach ( $this->rows as $row ) {
            $row->kyc_status          = $this->describe_capabilities( isset( $row->stripe_capabilities ) ? $row->stripe_capabilities : '' );
            $row->last_activity_human = $this->format_last_activity( isset( $row->last_activity ) ? $row->last_activity : null );
            $row->balance             = isset( $row->balance ) ? (float) $row->balance : 0.0;
            $rows[]                   = $row;
            $this->payouts[ (int) $row->user_id ] = $this->service->get_user_payouts( (int) $row->user_id );
        }

        $payouts   = $this->payouts;
        $return_url = $this->get_return_url();

        include ECOSPLAY_REFERRALS_ADMIN . 'views/payouts.php';
    }

    /**
     * Registers an admin notice.
     *
     * @param string $code    Notice identifier.
     * @param string $message Notice content.
     * @param string $type    Notice type.
     *
     * @return void
     */
    protected function add_notice( $code, $message, $type = 'updated' ) {
        add_settings_error( 'ecosplay_referrals', $code, $message, $type );
    }

    /**
     * Converts Stripe capabilities into a readable label.
     *
     * @param mixed $raw Capabilities payload.
     *
     * @return string
     */
    protected function describe_capabilities( $raw ) {
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );

            if ( json_last_error() === JSON_ERROR_NONE ) {
                $raw = $decoded;
            }
        }

        if ( empty( $raw ) || ! is_array( $raw ) ) {
            return __( 'Onboarding requis', 'ecosplay-referrals' );
        }

        $transfers = isset( $raw['transfers'] ) ? $raw['transfers'] : null;

        if ( is_array( $transfers ) ) {
            if ( ! empty( $transfers['active'] ) ) {
                return __( 'Compte actif', 'ecosplay-referrals' );
            }

            if ( ! empty( $transfers['pending'] ) ) {
                return __( 'Vérification en cours', 'ecosplay-referrals' );
            }
        } elseif ( 'active' === $transfers ) {
            return __( 'Compte actif', 'ecosplay-referrals' );
        }

        return __( 'En attente', 'ecosplay-referrals' );
    }

    /**
     * Formats the last activity timestamp.
     *
     * @param int|null $timestamp Unix timestamp.
     *
     * @return string
     */
    protected function format_last_activity( $timestamp ) {
        if ( empty( $timestamp ) ) {
            return __( 'Aucune activité', 'ecosplay-referrals' );
        }

        $timestamp = (int) $timestamp;
        $delta     = human_time_diff( $timestamp, current_time( 'timestamp' ) );

        return sprintf( __( 'Il y a %s', 'ecosplay-referrals' ), $delta );
    }

    /**
     * Builds the return URL used for Stripe onboarding links.
     *
     * @return string
     */
    protected function get_return_url() {
        return esc_url_raw( add_query_arg( array( 'page' => 'ecosplay-referrals', 'tab' => 'payouts' ), admin_url( 'admin.php' ) ) );
    }
}
