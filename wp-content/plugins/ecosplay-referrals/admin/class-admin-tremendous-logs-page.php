<?php
/**
 * Admin controller displaying Tremendous webhook logs.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-tremendous-logs-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides filtering and documentation for Tremendous webhook logs.
 */
class Ecosplay_Referrals_Admin_Tremendous_Logs_Page {
    /**
     * Referrals domain service.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Filtered log entries.
     *
     * @var array<int,object>
     */
    protected $logs = array();

    /**
     * Applied filters.
     *
     * @var array<string,string>
     */
    protected $filters = array();

    /**
     * Available event types.
     *
     * @var array<int,string>
     */
    protected $event_types = array();

    /**
     * Documented Tremendous event notes.
     *
     * @var array<string,string>
     */
    protected $event_notes = array();

    /**
     * Stores the service dependency.
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
        return 'tremendous-logs';
    }

    /**
     * Returns the page title.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Logs Tremendous', 'ecosplay-referrals' );
    }

    /**
     * Captures filter parameters from the query string.
     *
     * @return void
     */
    public function handle() {
        $type  = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '';
        $from  = isset( $_GET['from'] ) ? $this->normalise_date( wp_unslash( $_GET['from'] ) ) : '';
        $to    = isset( $_GET['to'] ) ? $this->normalise_date( wp_unslash( $_GET['to'] ) ) : '';
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

        $this->filters = array(
            'type'     => $type,
            'from'     => $from,
            'to'       => $to,
            'state'    => $state,
            'limit'    => 100,
            'provider' => 'tremendous',
        );
    }

    /**
     * Renders the Tremendous logs table and documentation.
     *
     * @return void
     */
    public function render() {
        if ( empty( $this->filters ) ) {
            $this->handle();
        }

        $this->event_types = $this->service->get_webhook_event_types( 'tremendous' );
        $this->logs        = $this->service->get_webhook_logs( $this->filters );
        $this->event_notes = $this->get_event_notes();

        $logs        = $this->logs;
        $filters     = $this->filters;
        $event_types = $this->event_types;
        $event_notes = $this->event_notes;

        include ECOSPLAY_REFERRALS_ADMIN . 'views/tremendous-logs.php';
    }

    /**
     * Normalises a date string into Y-m-d format.
     *
     * @param string $value Raw date string.
     *
     * @return string
     */
    protected function normalise_date( $value ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        $timestamp = strtotime( $value );

        if ( false === $timestamp ) {
            return '';
        }

        return gmdate( 'Y-m-d', $timestamp );
    }

    /**
     * Returns human-readable notes for common Tremendous events.
     *
     * @return array<string,string>
     */
    protected function get_event_notes() {
        return array(
            'CONNECTED_ORGANIZATIONS.APPROVED' => __( 'Organisation connectée validée : le compte peut émettre des récompenses.', 'ecosplay-referrals' ),
            'CONNECTED_ORGANIZATIONS.PENDING'  => __( 'Organisation connectée en vérification : surveillez les documents requis.', 'ecosplay-referrals' ),
            'CONNECTED_ORGANIZATIONS.REJECTED' => __( 'Organisation connectée refusée : contactez Tremendous pour lever le blocage.', 'ecosplay-referrals' ),
            'ORDERS.CREATED'                   => __( 'Commande enregistrée : une récompense vient d’être demandée via l’API.', 'ecosplay-referrals' ),
            'ORDERS.FULFILLED'                 => __( 'Commande livrée : le bénéficiaire a reçu la récompense.', 'ecosplay-referrals' ),
            'ORDERS.CANCELED'                  => __( 'Commande annulée : la récompense a été interrompue avant envoi.', 'ecosplay-referrals' ),
            'TOPUPS.CREATED'                   => __( 'Rechargement initié : des fonds ont été demandés pour alimenter le solde.', 'ecosplay-referrals' ),
            'TOPUPS.FULLY_CREDITED'            => __( 'Rechargement crédité : les fonds sont disponibles sur Tremendous.', 'ecosplay-referrals' ),
            'TOPUPS.FAILED'                    => __( 'Rechargement échoué : rapprochez-vous du support Tremendous.', 'ecosplay-referrals' ),
        );
    }
}
